<?php
declare(strict_types=1);

session_start();

function escapeHtml($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function removeInstructorPhoto(?string $relativePath): bool
{
  if (!$relativePath || !preg_match('#^uploads/instructors/[a-f0-9]{32}\.(jpg|png|gif|webp)$#D', $relativePath)) {
    return true;
  }

  $filename = basename($relativePath);
  $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'instructors' . DIRECTORY_SEPARATOR . $filename;
  if (!is_file($absolutePath)) {
    return true;
  }

  return unlink($absolutePath);
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$message = '';
$success = '';
$instructor = ['employee_code' => '', 'department' => '', 'profile_photo' => null];
$photoPathToClean = null;
$cleanupWarning = false;

if (empty($_SESSION['instructor_profile_csrf'])) {
  $_SESSION['instructor_profile_csrf'] = bin2hex(random_bytes(32));
}

if ($userId === false) {
  http_response_code(401);
  $message = 'Please sign in again. The authenticated PHP session does not contain a valid user ID.';
} else {
  try {
    $pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      $submittedToken = $_POST['csrf_token'] ?? '';
      if (!is_string($submittedToken) || !hash_equals($_SESSION['instructor_profile_csrf'], $submittedToken)) {
        http_response_code(400);
        throw new RuntimeException('Your form session expired. Reload the page and try again.');
      }

      $action = $_POST['action'] ?? '';
      if ($action === 'delete') {
        $find = $pdo->prepare('SELECT instructor_id, profile_photo FROM instructors WHERE user_id = ? ORDER BY instructor_id DESC LIMIT 1');
        $find->execute([$userId]);
        $existing = $find->fetch();
        if ($existing) {
          $delete = $pdo->prepare('DELETE FROM instructors WHERE instructor_id = ?');
          $delete->execute([$existing['instructor_id']]);
          $photoPathToClean = $existing['profile_photo'];
        }

        if ($photoPathToClean && !removeInstructorPhoto($photoPathToClean)) {
          $cleanupWarning = true;
          error_log('Unable to remove instructor profile photo: ' . $photoPathToClean);
        }
        header('Location: instructorprofile.php?status=deleted' . ($cleanupWarning ? '&cleanup=failed' : ''));
        exit;
      }

      if ($action !== 'save') {
        http_response_code(400);
        throw new RuntimeException('Unsupported form action.');
      }

      $employeeCodeInput = $_POST['employee_code'] ?? '';
      $departmentInput = $_POST['department'] ?? '';
      if (!is_string($employeeCodeInput) || !is_string($departmentInput)) {
        throw new RuntimeException('Enter a valid employee code and department.');
      }
      $employeeCode = trim($employeeCodeInput);
      $department = trim($departmentInput);
      $instructor['employee_code'] = $employeeCode;
      $instructor['department'] = $department;

      if ($employeeCode === '' || strlen($employeeCode) > 50) {
        throw new RuntimeException('Employee code is required and must be 50 characters or fewer.');
      }
      if ($department === '' || strlen($department) > 100) {
        throw new RuntimeException('Department is required and must be 100 characters or fewer.');
      }

      $find = $pdo->prepare('SELECT instructor_id, profile_photo FROM instructors WHERE user_id = ? ORDER BY instructor_id DESC LIMIT 1');
      $find->execute([$userId]);
      $existing = $find->fetch();
      $oldPhotoPath = $existing['profile_photo'] ?? null;
      $newPhotoPath = null;

      if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
          throw new RuntimeException('The profile photo could not be uploaded.');
        }
        if ($_FILES['photo']['size'] > 2 * 1024 * 1024 || !is_uploaded_file($_FILES['photo']['tmp_name'])) {
          throw new RuntimeException('Choose an image smaller than 2 MB.');
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['photo']['tmp_name']);
        $imageExtensions = [
          'image/jpeg' => '.jpg',
          'image/png' => '.png',
          'image/gif' => '.gif',
          'image/webp' => '.webp',
        ];
        if (!isset($imageExtensions[$mimeType])) {
          throw new RuntimeException('Choose a JPEG, PNG, GIF, or WebP profile photo.');
        }

        $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'instructors';
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
          throw new RuntimeException('The profile photo upload directory is unavailable.');
        }

        $newPhotoPath = 'uploads/instructors/' . bin2hex(random_bytes(16)) . $imageExtensions[$mimeType];
        $absolutePhotoPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $newPhotoPath);
        if (!move_uploaded_file($_FILES['photo']['tmp_name'], $absolutePhotoPath)) {
          throw new RuntimeException('The profile photo could not be saved.');
        }
      }

      $photoPath = $newPhotoPath ?? $oldPhotoPath;
      try {
        if ($existing) {
          $save = $pdo->prepare('UPDATE instructors SET employee_code = ?, department = ?, profile_photo = ? WHERE instructor_id = ?');
          $save->execute([$employeeCode, $department, $photoPath, $existing['instructor_id']]);
        } else {
          $save = $pdo->prepare('INSERT INTO instructors (user_id, employee_code, department, profile_photo) VALUES (?, ?, ?, ?)');
          $save->execute([$userId, $employeeCode, $department, $photoPath]);
        }
      } catch (PDOException $error) {
        if ($newPhotoPath !== null) {
          removeInstructorPhoto($newPhotoPath);
        }
        throw $error;
      }

      if ($newPhotoPath !== null && $oldPhotoPath && !removeInstructorPhoto($oldPhotoPath)) {
        $cleanupWarning = true;
        error_log('Unable to remove replaced instructor profile photo: ' . $oldPhotoPath);
      }

      header('Location: instructorprofile.php?status=saved' . ($cleanupWarning ? '&cleanup=failed' : ''));
      exit;
    }

    $find = $pdo->prepare('SELECT employee_code, department, profile_photo FROM instructors WHERE user_id = ? ORDER BY instructor_id DESC LIMIT 1');
    $find->execute([$userId]);
    $savedInstructor = $find->fetch();
    if ($savedInstructor) {
      $instructor = $savedInstructor;
    }

    if (($_GET['status'] ?? '') === 'saved') {
      $success = 'Instructor profile saved.';
    } elseif (($_GET['status'] ?? '') === 'deleted') {
      $success = 'Instructor profile deleted.';
    }
    if (($_GET['cleanup'] ?? '') === 'failed') {
      $message = 'The profile was updated, but an old photo file could not be removed. Check the PHP error log.';
    }
  } catch (RuntimeException $error) {
    $message = $error->getMessage();
  } catch (PDOException $error) {
    error_log('Instructor profile database error: ' . $error->getMessage());
    http_response_code(500);
    $message = 'The instructor profile could not be saved or loaded. Check the database connection and PHP error log.';
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['employee_code'])) {
  $instructor['employee_code'] = trim($_POST['employee_code']);
  $instructor['department'] = trim($_POST['department'] ?? '');
}

$photoPath = $instructor['profile_photo'] ?? '';
$profilePhotoUrl = is_string($photoPath) && strpos($photoPath, 'uploads/instructors/') === 0
  ? '../' . $photoPath
  : '../assets/images/person-fill.svg';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Instructor Profile - DTET</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/common.css" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column min-vh-100 page-admin-instructorprofile-html">
  <div id="header-placeholder"></div>

  <main class="container py-4 flex-fill">
    <h1 class="h3 font-weight-bold mb-4">Instructor Profile</h1>

    <?php if ($message !== ''): ?>
      <div class="alert alert-danger" role="alert"><?php echo escapeHtml($message); ?></div>
    <?php elseif ($success !== ''): ?>
      <div class="alert alert-success" role="status"><?php echo escapeHtml($success); ?></div>
    <?php endif; ?>
    <p class="text-muted">This profile is linked to your signed-in account and stores employee code, department, and profile photo.</p>

    <form class="card shadow-sm mx-auto common-inline-admin-instructorprofile-html-1" action="instructorprofile.php" method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($_SESSION['instructor_profile_csrf']); ?>">
      <div class="card-body p-4">
        <div class="text-center mb-4">
          <img class="rounded-circle border bg-light mb-2 common-inline-admin-instructorprofile-html-2" id="profilePhoto"
               src="<?php echo escapeHtml($profilePhotoUrl); ?>" alt="Instructor profile photo"
               width="120" height="120">
          <label class="d-block font-weight-bold mb-2" for="photoUpload">Profile Photo</label>
          <input class="form-control-file" id="photoUpload" name="photo" type="file" accept="image/jpeg,image/png,image/gif,image/webp">
        </div>

        <div class="form-row">
          <div class="form-group col-md-6">
            <label for="employeeCode">Employee Code</label>
            <input class="form-control" id="employeeCode" name="employee_code" type="text" maxlength="50" required
                   value="<?php echo escapeHtml($instructor['employee_code']); ?>">
          </div>
          <div class="form-group col-md-6 mb-0">
            <label for="department">Department</label>
            <input class="form-control" id="department" name="department" type="text" maxlength="100" required
                   value="<?php echo escapeHtml($instructor['department']); ?>">
          </div>
        </div>
      </div>

      <div class="card-footer bg-white d-flex justify-content-end flex-wrap">
        <button class="btn btn-secondary mr-2 mb-1" id="viewInstructorProfile" type="button">View</button>
        <button class="btn btn-success mr-2 mb-1" type="submit" name="action" value="save">Save profile</button>
        <?php if (!empty($instructor['employee_code'])): ?>
          <button class="btn btn-danger mb-1" type="submit" name="action" value="delete"
                  formnovalidate onclick="return confirm('Delete this instructor profile?');">Delete</button>
        <?php endif; ?>
      </div>
    </form>
  </main>

  <div id="footer-placeholder"></div>

  <script src="../assets/js/common.js" data-page="admin/instructorprofile.php"></script>
</body>
</html>