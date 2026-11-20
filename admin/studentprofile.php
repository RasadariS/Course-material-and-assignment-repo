<?php
declare(strict_types=1);

session_start();

function studentProfileEscape($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$userRole = strtolower((string) ($_SESSION['role'] ?? ''));
$isAdministrator = in_array($userRole, ['admin', 'administrator', 'system administrator'], true);
$isStudent = $userRole === 'student';

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
$students = [];

if (($_GET['updated'] ?? '') === '1') {
  $successMessage = 'Student profile successfully updated.';
} elseif (($_GET['deleted'] ?? '') === '1') {
  $successMessage = 'Profile photo successfully deleted.';
}

if (!$isStudent && !$isAdministrator) {
  http_response_code(403);
  $errorMessage = 'This page is available to students and administrators only.';
} else {
  if (empty($_SESSION['student_profile_csrf'])) {
    $_SESSION['student_profile_csrf'] = bin2hex(random_bytes(32));
  }

  try {
    $pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

    if ($isAdministrator) {
      $studentList = $pdo->query(
        'SELECT u.user_id, u.full_name, s.registration_number
         FROM users u
         INNER JOIN students s ON s.user_id = u.user_id
         WHERE LOWER(u.role) = \'student\'
         ORDER BY u.full_name'
      );
      $students = $studentList->fetchAll();
    }

    if ($isAdministrator && $_SERVER['REQUEST_METHOD'] === 'POST' && $profileUserId === null) {
      http_response_code(400);
      $errorMessage = 'Choose a student before submitting the form.';
    } elseif ($isAdministrator && isset($_GET['user_id']) && $profileUserId === null) {
      http_response_code(400);
      $errorMessage = 'Choose a valid student.';
    }

    if ($profileUserId !== null && $errorMessage === '') {
      $profileLookup = $pdo->prepare(
        'SELECT u.nic_number, u.full_name, u.email, u.contact_number,
                s.registration_number, s.intake_year, s.semester_enrolled, s.profile_photo
         FROM users u
         INNER JOIN students s ON s.user_id = u.user_id
         WHERE u.user_id = ? AND LOWER(u.role) = \'student\'
         LIMIT 1'
      );
      $profileLookup->execute([$profileUserId]);
      $profile = $profileLookup->fetch();
      if (!$profile) {
        http_response_code(404);
        $errorMessage = 'No student profile was found for this account.';
      }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $profile) {
      if (!$isAdministrator) {
        http_response_code(403);
        $errorMessage = 'Only administrators can update student profiles.';
      } else {
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!is_string($submittedToken) || !hash_equals($_SESSION['student_profile_csrf'], $submittedToken)) {
          $errorMessage = 'Your profile form expired. Reload the page and try again.';
        } else {
          $action = $_POST['action'] ?? 'update';

          if ($action === 'delete_photo') {
            $oldPhoto = $profile['profile_photo'];

            if (is_string($oldPhoto) &&
                preg_match('#^uploads/students/[a-f0-9]{32}\.(jpg|png|gif|webp)$#D', $oldPhoto)) {
              $photoFile = dirname(__DIR__) . DIRECTORY_SEPARATOR .
                str_replace('/', DIRECTORY_SEPARATOR, $oldPhoto);
              if (is_file($photoFile) && !unlink($photoFile)) {
                error_log('Unable to delete student profile photo: ' . $oldPhoto);
              }
            }

            $clearPhoto = $pdo->prepare('UPDATE students SET profile_photo = NULL WHERE user_id = ?');
            $clearPhoto->execute([$profileUserId]);
            $redirect = 'studentprofile.php?deleted=1';
            if ($isAdministrator) {
              $redirect .= '&user_id=' . $profileUserId;
            }
            header('Location: ' . $redirect);
            exit;
          } else {
            $nicNumber = is_string($_POST['nic_number'] ?? null) ? trim($_POST['nic_number']) : '';
            $fullName = is_string($_POST['full_name'] ?? null) ? trim($_POST['full_name']) : '';
            $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
            $contactNumber = is_string($_POST['contact_number'] ?? null) ? trim($_POST['contact_number']) : '';
            $registrationNumber = is_string($_POST['registration_number'] ?? null)
              ? trim($_POST['registration_number']) : '';
            $intakeYearInput = $_POST['intake_year'] ?? '';
            $semesterInput = $_POST['semester_enrolled'] ?? '';

            $intakeYear = filter_var($intakeYearInput, FILTER_VALIDATE_INT, [
              'options' => ['min_range' => 1901, 'max_range' => 2100],
            ]);
            $semester = filter_var($semesterInput, FILTER_VALIDATE_INT, [
              'options' => ['min_range' => 1, 'max_range' => 4],
            ]);

            if ($nicNumber === '' || strlen($nicNumber) > 20 ||
                $fullName === '' || strlen($fullName) > 150 ||
                $email === '' || strlen($email) > 150 ||
                !filter_var($email, FILTER_VALIDATE_EMAIL) ||
                strlen($contactNumber) > 20 ||
                $registrationNumber === '' || strlen($registrationNumber) > 50 ||
                $intakeYear === false || $semester === false) {
              $errorMessage = 'Check the required fields, year, semester, and email address.';
            } else {
            $duplicateUser = $pdo->prepare(
              'SELECT user_id FROM users
               WHERE (nic_number = ? OR email = ?) AND user_id <> ?
               LIMIT 1'
            );
            $duplicateUser->execute([$nicNumber, $email, $profileUserId]);

            $duplicateRegistration = $pdo->prepare(
              'SELECT student_id FROM students
               WHERE registration_number = ? AND user_id <> ?
               LIMIT 1'
            );
            $duplicateRegistration->execute([$registrationNumber, $profileUserId]);

            if ($duplicateUser->fetch()) {
              $errorMessage = 'Another account already uses that NIC or email address.';
            } elseif ($duplicateRegistration->fetch()) {
              $errorMessage = 'That registration number is already assigned to another student.';
            }
            }

            $newPhotoPath = null;
            if ($errorMessage === '' && isset($_FILES['photo']) &&
                $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
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
                $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR .
                  'uploads' . DIRECTORY_SEPARATOR . 'students';
                if (!is_dir($uploadDirectory) &&
                    !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                  $errorMessage = 'The profile photo folder is unavailable.';
                } else {
                  $newPhotoPath = 'uploads/students/' . bin2hex(random_bytes(16)) . $extensions[$mimeType];
                  $photoFile = dirname(__DIR__) . DIRECTORY_SEPARATOR .
                    str_replace('/', DIRECTORY_SEPARATOR, $newPhotoPath);
                  if (!move_uploaded_file($_FILES['photo']['tmp_name'], $photoFile)) {
                    $errorMessage = 'The profile photo could not be uploaded.';
                    $newPhotoPath = null;
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
                'UPDATE users
                 SET nic_number = ?, full_name = ?, email = ?, contact_number = ?
                 WHERE user_id = ?'
              );
              $updateUser->execute([
                $nicNumber,
                $fullName,
                $email,
                $contactNumber === '' ? null : $contactNumber,
                $profileUserId,
              ]);

              $updateStudent = $pdo->prepare(
                'UPDATE students
                 SET registration_number = ?, intake_year = ?, semester_enrolled = ?,
                     profile_photo = COALESCE(?, profile_photo)
                 WHERE user_id = ?'
              );
              $updateStudent->execute([
                $registrationNumber,
                $intakeYear,
                $semester,
                $newPhotoPath,
                $profileUserId,
              ]);
              $pdo->commit();
            } catch (Throwable $error) {
              if ($pdo->inTransaction()) {
                $pdo->rollBack();
              }
              if ($newPhotoPath !== null) {
                $photoFile = dirname(__DIR__) . DIRECTORY_SEPARATOR .
                  str_replace('/', DIRECTORY_SEPARATOR, $newPhotoPath);
                if (is_file($photoFile)) {
                  unlink($photoFile);
                }
              }
              throw $error;
            }

            if ($newPhotoPath !== null && is_string($oldPhoto) &&
                preg_match('#^uploads/students/[a-f0-9]{32}\.(jpg|png|gif|webp)$#D', $oldPhoto)) {
              $oldPhotoFile = dirname(__DIR__) . DIRECTORY_SEPARATOR .
                str_replace('/', DIRECTORY_SEPARATOR, $oldPhoto);
              if (is_file($oldPhotoFile) && !unlink($oldPhotoFile)) {
                error_log('Unable to delete replaced student profile photo: ' . $oldPhoto);
              }
            }

            if ($isStudent) {
              $_SESSION['full_name'] = $fullName;
            }
            $redirect = 'studentprofile.php?updated=1';
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

  } catch (PDOException $error) {
    error_log('Student profile database error: ' . $error->getMessage());
    http_response_code(500);
    $profile = null;
    $errorMessage = 'The student profile could not be loaded or saved.';
  }
}

$photoUrl = '../assets/images/person-fill.svg';
if ($profile && is_string($profile['profile_photo']) &&
    preg_match('#^uploads/students/[a-f0-9]{32}\.(jpg|png|gif|webp)$#D', $profile['profile_photo'])) {
  $photoUrl = '../' . $profile['profile_photo'];
}
$viewPath = 'studentprofileview.php';
if ($isAdministrator && $profileUserId !== null) {
  $viewPath .= '?user_id=' . $profileUserId;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Student Profile - DTET</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/common.css" rel="stylesheet">
  <link href="../assets/css/studentprofile.css" rel="stylesheet">
</head>
<body class="bg-white page-admin-studentprofile-php">
  <?php
  $headerBasePath = '../';
  require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'header.php';
  ?>

  <main class="container py-4">
    <h1 class="page-title mb-4"><?php echo $isAdministrator ? 'Manage Student Profiles' : 'Student Profile'; ?></h1>

    <?php if ($isAdministrator): ?>
      <form class="card card-body shadow-sm mb-4" action="studentprofile.php" method="get">
        <div class="form-row align-items-end">
          <div class="form-group col-md-8 mb-md-0">
            <label for="studentUserId">Choose a student</label>
            <select class="form-control" id="studentUserId" name="user_id" required>
              <option value="">Select a student</option>
              <?php foreach ($students as $student): ?>
                <option value="<?php echo studentProfileEscape($student['user_id']); ?>"
                  <?php echo $profileUserId === (int) $student['user_id'] ? 'selected' : ''; ?>>
                  <?php echo studentProfileEscape($student['full_name'] . ' (' . $student['registration_number'] . ')'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group col-md-4 mb-0">
            <button class="btn btn-primary" type="submit">Load Student</button>
          </div>
        </div>
      </form>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
      <div class="alert alert-danger" role="alert"><?php echo studentProfileEscape($errorMessage); ?></div>
    <?php endif; ?>
    <?php if ($profile): ?>
      <form class="profile-card card border-0 shadow-sm p-4" action="studentprofile.php"
            method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo studentProfileEscape($_SESSION['student_profile_csrf']); ?>">
        <?php if ($isAdministrator): ?>
          <input type="hidden" name="profile_user_id" value="<?php echo studentProfileEscape($profileUserId); ?>">
        <?php endif; ?>

        <div class="photo-section mb-4">
          <img class="profile-photo" id="profilePhoto"
               src="<?php echo studentProfileEscape($photoUrl); ?>"
               data-photo-url="<?php echo studentProfileEscape($photoUrl); ?>"
               alt="Student profile photo">
          <label class="photo-label" for="photoUpload">Profile Photo</label>
          <input class="form-control-file photo-input" id="photoUpload" name="photo" type="file"
                 accept="image/jpeg,image/png,image/gif,image/webp" disabled>
        </div>

        <div class="form-row">
          <div class="form-group col-md-6">
            <label for="nic">NIC</label>
            <input class="form-control" id="nic" name="nic_number" type="text" maxlength="20" required
                   value="<?php echo studentProfileEscape($profile['nic_number']); ?>" disabled>
          </div>
          <div class="form-group col-md-6">
            <label for="studentName">Full Name</label>
            <input class="form-control" id="studentName" name="full_name" type="text" maxlength="150" required
                   value="<?php echo studentProfileEscape($profile['full_name']); ?>" disabled>
          </div>
          <div class="form-group col-md-6">
            <label for="registrationNumber">Registration Number</label>
            <input class="form-control" id="registrationNumber" name="registration_number" type="text"
                   maxlength="50" required value="<?php echo studentProfileEscape($profile['registration_number']); ?>" disabled>
          </div>
          <div class="form-group col-md-6">
            <label for="intakeYear">Intake Year</label>
            <input class="form-control" id="intakeYear" name="intake_year" type="number" min="1901" max="2100"
                   required value="<?php echo studentProfileEscape($profile['intake_year']); ?>" disabled>
          </div>
          <div class="form-group col-md-6">
            <label for="semesterEnrolled">Semester Enrolled</label>
            <input class="form-control" id="semesterEnrolled" name="semester_enrolled" type="number"
                   min="1" max="4" required value="<?php echo studentProfileEscape($profile['semester_enrolled']); ?>" disabled>
          </div>
          <div class="form-group col-md-6">
            <label for="contactNumber">Contact Number</label>
            <input class="form-control" id="contactNumber" name="contact_number" type="tel" maxlength="20"
                   value="<?php echo studentProfileEscape($profile['contact_number']); ?>" disabled>
          </div>
          <div class="form-group col-md-6">
            <label for="emailAddress">Email Address</label>
            <input class="form-control" id="emailAddress" name="email" type="email" maxlength="150" required
                   value="<?php echo studentProfileEscape($profile['email']); ?>" disabled>
          </div>
          <div class="form-group col-md-6 mb-0">
            <label for="role">Account Type</label>
            <input class="form-control" id="role" type="text" value="Student" readonly>
          </div>
        </div>

        <div class="profile-actions">
          <a class="profile-button profile-button-view" href="<?php echo studentProfileEscape($viewPath); ?>">View</a>
          <?php if ($isAdministrator): ?>
            <button class="profile-button profile-button-edit" id="editProfile" type="button"
                    onclick="editStudentProfile()">Edit</button>
            <button class="profile-button profile-button-update" id="updateProfile" type="submit" disabled
                    onclick="return confirm('Do you want to update the database?');">Update</button>
            <button class="profile-button profile-button-delete" type="submit" name="action" value="delete_photo"
                    onclick="return confirm('Do you want to delete your profile photo from the database?');">Delete Photo</button>
          <?php endif; ?>
        </div>
      </form>
    <?php endif; ?>
  </main>

  <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
  <script>
    function editStudentProfile() {
      document.getElementById('nic').disabled = false;
      document.getElementById('studentName').disabled = false;
      document.getElementById('registrationNumber').disabled = false;
      document.getElementById('intakeYear').disabled = false;
      document.getElementById('semesterEnrolled').disabled = false;
      document.getElementById('contactNumber').disabled = false;
      document.getElementById('emailAddress').disabled = false;
      document.getElementById('photoUpload').disabled = false;
      document.getElementById('editProfile').disabled = true;
      document.getElementById('updateProfile').disabled = false;
    }

    var photoUpload = document.getElementById('photoUpload');
    if (photoUpload) {
      photoUpload.addEventListener('change', function () {
        if (this.files.length > 0) {
          document.getElementById('profilePhoto').src = URL.createObjectURL(this.files[0]);
        } else {
          var image = document.getElementById('profilePhoto');
          image.src = image.getAttribute('data-photo-url');
        }
      });
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
