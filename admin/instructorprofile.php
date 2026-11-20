<?php
declare(strict_types=1);

session_start();

function instructorEscape($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$userRole = strtolower((string) ($_SESSION['role'] ?? ''));
$isAdministrator = in_array($userRole, ['admin', 'administrator', 'system administrator'], true);
$isInstructor = $userRole === 'instructor';
if ($userId === false) {
  header('Location: ../login.php');
  exit;
}

$profileUserId = $userId;
if ($isAdministrator) {
  $requestedProfileId = filter_var(
    $_POST['profile_user_id'] ?? ($_GET['user_id'] ?? null),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
  );
  $profileUserId = $requestedProfileId === false ? null : $requestedProfileId;
}

$errorMessage = '';
$successMessage = '';
$profile = null;
$instructors = [];
if (!$isInstructor && !$isAdministrator) {
  http_response_code(403);
  $errorMessage = 'This page is available to instructors and administrators only.';
} else {
  if (empty($_SESSION['instructor_profile_csrf'])) {
    $_SESSION['instructor_profile_csrf'] = bin2hex(random_bytes(32));
  }

  if (($_GET['updated'] ?? '') === '1') {
    $successMessage = 'Instructor profile successfully updated.';
  } elseif (($_GET['deleted'] ?? '') === '1') {
    $successMessage = 'Profile photo successfully deleted.';
  }

  try {
    $pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

    if ($isAdministrator) {
      $instructorList = $pdo->query(
        'SELECT u.user_id, u.full_name, i.employee_code
         FROM users u
         INNER JOIN instructors i ON i.user_id = u.user_id
         WHERE LOWER(u.role) = \'instructor\'
         ORDER BY u.full_name'
      );
      $instructors = $instructorList->fetchAll();
    }

    if ($isAdministrator && $_SERVER['REQUEST_METHOD'] === 'POST' && $profileUserId === null) {
      http_response_code(400);
      $errorMessage = 'Choose an instructor before submitting the form.';
    } elseif ($isAdministrator && isset($_GET['user_id']) && $profileUserId === null) {
      http_response_code(400);
      $errorMessage = 'Choose a valid instructor.';
    }

    if ($profileUserId !== null && $errorMessage === '') {
      $lookup = $pdo->prepare(
        'SELECT u.nic_number, u.full_name, u.contact_number, u.email,
                i.employee_code, i.department, i.profile_photo
         FROM users u
         INNER JOIN instructors i ON i.user_id = u.user_id
         WHERE u.user_id = ? AND LOWER(u.role) = \'instructor\'
         LIMIT 1'
      );
      $lookup->execute([$profileUserId]);
      $profile = $lookup->fetch();
      if (!$profile) {
        http_response_code(404);
        $errorMessage = 'No instructor profile was found for this account.';
      }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $profile) {
      if (!$isAdministrator) {
        http_response_code(403);
        $errorMessage = 'Only administrators can update instructor profiles.';
      } else {
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!is_string($submittedToken) || !hash_equals($_SESSION['instructor_profile_csrf'], $submittedToken)) {
          $errorMessage = 'Your profile form expired. Reload the page and try again.';
        } else {
          $action = $_POST['action'] ?? 'update';

        if ($action === 'delete_photo') {
          $oldPhoto = $profile['profile_photo'];

          if (is_string($oldPhoto) && preg_match('#^uploads/instructors/[a-f0-9]{32}\.(jpg|png|gif|webp)$#D', $oldPhoto)) {
            $absolutePhoto = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $oldPhoto);
            if (is_file($absolutePhoto)) {
              unlink($absolutePhoto);
            }
          }

          $clearPhoto = $pdo->prepare('UPDATE instructors SET profile_photo = NULL WHERE user_id = ?');
          $clearPhoto->execute([$profileUserId]);
          $redirect = 'instructorprofile.php?deleted=1';
          if ($isAdministrator) {
            $redirect .= '&user_id=' . $profileUserId;
          }
          header('Location: ' . $redirect);
          exit;
        } else {
          $nicNumber = trim((string) ($_POST['nic_number'] ?? ''));
          $fullName = trim((string) ($_POST['full_name'] ?? ''));
          $contactNumber = trim((string) ($_POST['contact_number'] ?? ''));
          $email = trim((string) ($_POST['email'] ?? ''));
          $employeeCode = trim((string) ($_POST['employee_code'] ?? ''));
          $department = trim((string) ($_POST['department'] ?? ''));

          if ($nicNumber === '' || strlen($nicNumber) > 20 ||
              $fullName === '' || strlen($fullName) > 150 ||
              $contactNumber === '' || strlen($contactNumber) > 20 ||
              $email === '' || strlen($email) > 150 ||
              !filter_var($email, FILTER_VALIDATE_EMAIL) ||
              $employeeCode === '' || strlen($employeeCode) > 50 ||
              $department === '' || strlen($department) > 100) {
            $errorMessage = 'Check the required fields and enter a valid email address.';
          } else {
            $photoPath = null;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
              if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK ||
                  $_FILES['photo']['size'] > 2 * 1024 * 1024 ||
                  !is_uploaded_file($_FILES['photo']['tmp_name'])) {
                $errorMessage = 'Choose a profile photo smaller than 2 MB.';
              } else {
                $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['photo']['tmp_name']);
                $extensions = [
                  'image/jpeg' => '.jpg',
                  'image/png' => '.png',
                  'image/gif' => '.gif',
                  'image/webp' => '.webp',
                ];
                if (!isset($extensions[$mimeType])) {
                  $errorMessage = 'Choose a JPEG, PNG, GIF, or WebP photo.';
                } else {
                  $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'instructors';
                  if (!is_dir($uploadDirectory) &&
                      !mkdir($uploadDirectory, 0755, true) &&
                      !is_dir($uploadDirectory)) {
                    $errorMessage = 'The profile photo upload directory is unavailable.';
                  } else {
                    $photoPath = 'uploads/instructors/' . bin2hex(random_bytes(16)) . $extensions[$mimeType];
                    $absolutePhoto = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $photoPath);
                    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $absolutePhoto)) {
                      $errorMessage = 'The profile photo could not be uploaded.';
                      $photoPath = null;
                    }
                  }
                }
              }
            }

            if ($errorMessage === '') {
              $oldPhoto = $profile['profile_photo'];

              try {
                $pdo->beginTransaction();
                $updateUser = $pdo->prepare(
                  'UPDATE users SET nic_number = ?, full_name = ?, email = ?, contact_number = ? WHERE user_id = ?'
                );
                $updateUser->execute([$nicNumber, $fullName, $email, $contactNumber, $profileUserId]);

                $updateInstructor = $pdo->prepare(
                  'UPDATE instructors SET employee_code = ?, department = ?, profile_photo = COALESCE(?, profile_photo)
                   WHERE user_id = ?'
                );
                $updateInstructor->execute([$employeeCode, $department, $photoPath, $profileUserId]);
                $pdo->commit();
              } catch (PDOException $error) {
                if ($pdo->inTransaction()) {
                  $pdo->rollBack();
                }
                throw $error;
              }

              if ($photoPath !== null && is_string($oldPhoto) &&
                  preg_match('#^uploads/instructors/[a-f0-9]{32}\.(jpg|png|gif|webp)$#D', $oldPhoto)) {
                $oldAbsolutePhoto = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $oldPhoto);
                if (is_file($oldAbsolutePhoto)) {
                  unlink($oldAbsolutePhoto);
                }
              }

              if ($isInstructor) {
                $_SESSION['full_name'] = $fullName;
              }
              $redirect = 'instructorprofile.php?updated=1';
              if ($isAdministrator) {
                $redirect .= '&user_id=' . $profileUserId;
              }
              header('Location: ' . $redirect);
              exit;
            }
          }
        }
      }
    }
    }

  } catch (PDOException $error) {
    error_log('Instructor profile database error: ' . $error->getMessage());
    http_response_code(500);
    $profile = null;
    $errorMessage = 'The instructor profile could not be loaded or saved.';
  }
}

$photoUrl = '../assets/images/person-fill.svg';
if ($profile && is_string($profile['profile_photo']) &&
    preg_match('#^uploads/instructors/[a-f0-9]{32}\.(jpg|png|gif|webp)$#D', $profile['profile_photo'])) {
  $photoUrl = '../' . $profile['profile_photo'];
}
$viewPath = 'instructorprofileview.php';
if ($isAdministrator && $profileUserId !== null) {
  $viewPath .= '?user_id=' . $profileUserId;
}
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
<body class="bg-light d-flex flex-column min-vh-100">
  <?php
  $headerBasePath = '../';
  require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'header.php';
  ?>

  <main class="container py-4 flex-fill">
    <h1 class="h3 font-weight-bold mb-4"><?php echo $isAdministrator ? 'Manage Instructor Profiles' : 'Instructor Profile'; ?></h1>

    <?php if ($isAdministrator): ?>
      <form class="card card-body shadow-sm mb-4" action="instructorprofile.php" method="get">
        <div class="form-row align-items-end">
          <div class="form-group col-md-8 mb-md-0">
            <label for="instructorUserId">Choose an instructor</label>
            <select class="form-control" id="instructorUserId" name="user_id" required>
              <option value="">Select an instructor</option>
              <?php foreach ($instructors as $instructor): ?>
                <option value="<?php echo instructorEscape($instructor['user_id']); ?>"
                  <?php echo $profileUserId === (int) $instructor['user_id'] ? 'selected' : ''; ?>>
                  <?php echo instructorEscape($instructor['full_name'] . ' (' . $instructor['employee_code'] . ')'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group col-md-4 mb-0">
            <button class="btn btn-primary" type="submit">Load Instructor</button>
          </div>
        </div>
      </form>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
      <div class="alert alert-danger" role="alert"><?php echo instructorEscape($errorMessage); ?></div>
    <?php endif; ?>
    <?php if ($profile): ?>
      <form class="card shadow-sm mx-auto" action="instructorprofile.php" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo instructorEscape($_SESSION['instructor_profile_csrf']); ?>">
        <?php if ($isAdministrator): ?>
          <input type="hidden" name="profile_user_id" value="<?php echo instructorEscape($profileUserId); ?>">
        <?php endif; ?>
        <div class="card-body p-4">
          <div class="text-center mb-4">
            <img class="rounded-circle border bg-light mb-2" id="profilePhoto" src="<?php echo instructorEscape($photoUrl); ?>"
                 alt="Instructor profile photo" width="120" height="120">
            <label class="d-block font-weight-bold mb-2" for="photoUpload">Profile Photo</label>
            <input class="form-control-file" id="photoUpload" name="photo" type="file"
                   accept="image/jpeg,image/png,image/gif,image/webp" disabled>
          </div>

          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="nic">NIC</label>
              <input class="form-control" id="nic" name="nic_number" type="text" maxlength="20" required
                     value="<?php echo instructorEscape($profile['nic_number']); ?>" disabled>
            </div>
            <div class="form-group col-md-6">
              <label for="instructorName">Name</label>
              <input class="form-control" id="instructorName" name="full_name" type="text" maxlength="150" required
                     value="<?php echo instructorEscape($profile['full_name']); ?>" disabled>
            </div>
            <div class="form-group col-md-6">
              <label for="contactNumber">Contact Number</label>
              <input class="form-control" id="contactNumber" name="contact_number" type="tel" maxlength="20" required
                     value="<?php echo instructorEscape($profile['contact_number']); ?>" disabled>
            </div>
            <div class="form-group col-md-6">
              <label for="emailAddress">Email Address</label>
              <input class="form-control" id="emailAddress" name="email" type="email" maxlength="150" required
                     value="<?php echo instructorEscape($profile['email']); ?>" disabled>
            </div>
            <div class="form-group col-md-6">
              <label for="employeeCode">Employee Code</label>
              <input class="form-control" id="employeeCode" name="employee_code" type="text" maxlength="50" required
                     value="<?php echo instructorEscape($profile['employee_code']); ?>" disabled>
            </div>
            <div class="form-group col-md-6">
              <label for="department">Department</label>
              <input class="form-control" id="department" name="department" type="text" maxlength="100" required
                     value="<?php echo instructorEscape($profile['department']); ?>" disabled>
            </div>
            <div class="form-group col-md-6 mb-0">
              <label for="role">Account Type</label>
              <input class="form-control" id="role" name="role" type="text" value="Instructor" readonly>
            </div>
          </div>
        </div>

        <div class="card-footer bg-white d-flex justify-content-end flex-wrap">
          <a class="btn btn-secondary mr-2 mb-1" id="viewInstructorProfile" href="<?php echo instructorEscape($viewPath); ?>">View</a>
          <?php if ($isAdministrator): ?>
            <button class="btn btn-primary mr-2 mb-1" id="editInstructorProfile" type="button"
                    onclick="enableProfileEditing()">Edit</button>
            <button class="btn btn-success mr-2 mb-1" id="updateInstructorProfile" type="submit" disabled
                    onclick="return confirm('Do you want to update the database?');">Update</button>
            <button class="btn btn-danger mb-1" type="submit" name="action" value="delete_photo"
                    onclick="return confirm('Do you want to delete your profile photo from the database?');">Delete Photo</button>
          <?php endif; ?>
        </div>
      </form>
    <?php endif; ?>
  </main>

  <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
  <script>
    function enableProfileEditing() {
      document.getElementById('nic').disabled = false;
      document.getElementById('instructorName').disabled = false;
      document.getElementById('contactNumber').disabled = false;
      document.getElementById('emailAddress').disabled = false;
      document.getElementById('employeeCode').disabled = false;
      document.getElementById('department').disabled = false;
      document.getElementById('photoUpload').disabled = false;
      document.getElementById('editInstructorProfile').disabled = true;
      document.getElementById('updateInstructorProfile').disabled = false;
    }
  </script>
  <?php if ($successMessage !== ''): ?>
    <script>
      alert(<?php echo json_encode($successMessage); ?>);
      window.location.href = '../index.php';
    </script>
  <?php endif; ?>
</body>
</html>
