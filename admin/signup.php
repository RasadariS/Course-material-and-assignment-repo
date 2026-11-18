<?php
declare(strict_types=1);

session_start();

function signupEscapeHtml($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function removeSignupPhoto(?string $relativePath): bool
{
  if (!$relativePath || !preg_match('#^uploads/(students|instructors|admins)/[a-f0-9]{32}\.(jpg|png|gif|webp)$#D', $relativePath)) {
    return true;
  }

  $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . basename(dirname($relativePath));
  $absolutePath = $directory . DIRECTORY_SEPARATOR . basename($relativePath);
  if (!is_file($absolutePath)) {
    return true;
  }

  return unlink($absolutePath);
}

if (empty($_SESSION['signup_csrf'])) {
  $_SESSION['signup_csrf'] = bin2hex(random_bytes(32));
}

$signedInUserId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$isAdministrator = $signedInUserId !== false && in_array(
  strtolower((string) ($_SESSION['role'] ?? '')),
  ['admin', 'administrator', 'system administrator'],
  true
);
$errorMessage = '';
$administratorCreated = $isAdministrator && ($_GET['administrator_created'] ?? '') === '1';
$account = [
  'role' => '',
  'full_name' => '',
  'nic_number' => '',
  'contact_number' => '',
  'email' => '',
  'registration_number' => '',
  'intake_year' => '',
  'semester_enrolled' => '',
  'employee_code' => '',
  'department' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submittedToken = $_POST['csrf_token'] ?? '';
  if (!is_string($submittedToken) || !hash_equals($_SESSION['signup_csrf'], $submittedToken)) {
    http_response_code(400);
    $errorMessage = 'Your signup form expired. Reload the page and try again.';
  } else {
    $fieldNames = array_keys($account);
    foreach ($fieldNames as $fieldName) {
      $value = $_POST[$fieldName] ?? '';
      if (!is_string($value)) {
        $errorMessage = 'Enter valid account details.';
        break;
      }
      $account[$fieldName] = trim($value);
    }

    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    if (!is_string($password) || !is_string($confirmPassword)) {
      $errorMessage = 'Enter a valid password and confirmation.';
    }

    try {
      if ($errorMessage === '') {
        if (!in_array($account['role'], ['student', 'instructor', 'admin'], true)) {
          throw new RuntimeException('Choose a valid account type.');
        }
        if ($account['role'] === 'admin' && !$isAdministrator) {
          http_response_code(403);
          throw new RuntimeException('Only a signed-in administrator can create administrator accounts.');
        }
        if ($account['full_name'] === '' || strlen($account['full_name']) > 150) {
          throw new RuntimeException('Full name is required and must be 150 characters or fewer.');
        }
        if ($account['nic_number'] === '' || strlen($account['nic_number']) > 20) {
          throw new RuntimeException('NIC is required and must be 20 characters or fewer.');
        }
        if ($account['email'] === '' || strlen($account['email']) > 150 || !filter_var($account['email'], FILTER_VALIDATE_EMAIL)) {
          throw new RuntimeException('Enter a valid email address (up to 150 characters).');
        }
        if ($account['contact_number'] === '' || strlen($account['contact_number']) > 20) {
          throw new RuntimeException('Contact number is required and must be 20 characters or fewer.');
        }
        if (strlen($password) < 8 || strlen($password) > 72) {
          throw new RuntimeException('Password must be between 8 and 72 characters.');
        }
        if (!hash_equals($password, $confirmPassword)) {
          throw new RuntimeException('Passwords do not match.');
        }

        if ($account['role'] === 'student') {
          if ($account['registration_number'] === '' || strlen($account['registration_number']) > 50) {
            throw new RuntimeException('Registration number is required and must be 50 characters or fewer.');
          }
          $intakeYear = filter_var($account['intake_year'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1901, 'max_range' => 2100]]);
          if ($intakeYear === false) {
            throw new RuntimeException('Enter a valid intake year between 1901 and 2100.');
          }
          $semester = filter_var($account['semester_enrolled'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4]]);
          if ($semester === false) {
            throw new RuntimeException('Semester enrolled must be between 1 and 4.');
          }
          $roleName = 'Student';
          $photoDirectoryName = 'students';
        } elseif ($account['role'] === 'instructor') {
          if ($account['employee_code'] === '' || strlen($account['employee_code']) > 50) {
            throw new RuntimeException('Employee code is required and must be 50 characters or fewer.');
          }
          if ($account['department'] === '' || strlen($account['department']) > 100) {
            throw new RuntimeException('Department is required and must be 100 characters or fewer.');
          }
          $roleName = 'Instructor';
          $photoDirectoryName = 'instructors';
        } else {
          $roleName = 'Admin';
          $photoDirectoryName = 'admins';
        }

        $pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
        $duplicateUser = $pdo->prepare('SELECT user_id FROM users WHERE nic_number = ? OR email = ? LIMIT 1');
        $duplicateUser->execute([$account['nic_number'], $account['email']]);
        if ($duplicateUser->fetch()) {
          throw new RuntimeException('An account already exists with that NIC or email address.');
        }

        if ($account['role'] === 'student') {
          $duplicateRegistration = $pdo->prepare('SELECT student_id FROM students WHERE registration_number = ? LIMIT 1');
          $duplicateRegistration->execute([$account['registration_number']]);
          if ($duplicateRegistration->fetch()) {
            throw new RuntimeException('That registration number is already assigned to a student.');
          }
        } elseif ($account['role'] === 'instructor') {
          $duplicateEmployeeCode = $pdo->prepare('SELECT instructor_id FROM instructors WHERE employee_code = ? LIMIT 1');
          $duplicateEmployeeCode->execute([$account['employee_code']]);
          if ($duplicateEmployeeCode->fetch()) {
            throw new RuntimeException('That employee code is already assigned to an instructor.');
          }
        }

        if ($account['role'] === 'admin') {
          $roleQuery = $pdo->prepare(
            "SELECT role_id FROM roles
             WHERE LOWER(role_name) IN ('admin', 'administrator', 'system administrator')
             LIMIT 1"
          );
          $roleQuery->execute();
        } else {
          $roleQuery = $pdo->prepare('SELECT role_id FROM roles WHERE role_name = ? LIMIT 1');
          $roleQuery->execute([$roleName]);
        }
        $roleId = $roleQuery->fetchColumn();
        if ($roleId === false) {
          throw new RuntimeException('The selected account role is not configured in the database.');
        }

        $profilePhotoPath = null;
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

          $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $photoDirectoryName;
          if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
            throw new RuntimeException('The profile photo upload directory is unavailable.');
          }

          $profilePhotoPath = 'uploads/' . $photoDirectoryName . '/' . bin2hex(random_bytes(16)) . $imageExtensions[$mimeType];
          $absolutePhotoPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $profilePhotoPath);
          if (!move_uploaded_file($_FILES['photo']['tmp_name'], $absolutePhotoPath)) {
            throw new RuntimeException('The profile photo could not be saved.');
          }
        }

        try {
          $pdo->beginTransaction();
          $insertUser = $pdo->prepare(
            'INSERT INTO users (nic_number, full_name, email, contact_number, password_hash, role_id, role)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
          );
          $insertUser->execute([
            $account['nic_number'],
            $account['full_name'],
            $account['email'],
            $account['contact_number'],
            password_hash($password, PASSWORD_DEFAULT),
            $roleId,
            strtolower($account['role']),
          ]);
          $userId = (int) $pdo->lastInsertId();

          if ($account['role'] === 'student') {
            $insertProfile = $pdo->prepare(
              'INSERT INTO students (user_id, registration_number, intake_year, semester_enrolled, profile_photo)
               VALUES (?, ?, ?, ?, ?)'
            );
            $insertProfile->execute([
              $userId,
              $account['registration_number'],
              $intakeYear,
              $semester,
              $profilePhotoPath,
            ]);
          } elseif ($account['role'] === 'instructor') {
            $insertProfile = $pdo->prepare(
              'INSERT INTO instructors (user_id, employee_code, department, profile_photo)
               VALUES (?, ?, ?, ?)'
            );
            $insertProfile->execute([
              $userId,
              $account['employee_code'],
              $account['department'],
              $profilePhotoPath,
            ]);
          } else {
            $insertProfile = $pdo->prepare(
              'INSERT INTO system_administrators (user_id, access_level, profile_photo)
               VALUES (?, ?, ?)'
            );
            $insertProfile->execute([
              $userId,
              'System Administrator',
              $profilePhotoPath,
            ]);
          }
          $pdo->commit();
        } catch (PDOException $error) {
          if ($pdo->inTransaction()) {
            $pdo->rollBack();
          }
          if ($profilePhotoPath !== null && !removeSignupPhoto($profilePhotoPath)) {
            error_log('Unable to remove profile photo after signup rollback: ' . $profilePhotoPath);
          }
          throw $error;
        }

        if ($account['role'] === 'admin') {
          header('Location: signup.php?administrator_created=1');
        } else {
          header('Location: ../login.php?registered=1');
        }
        exit;
      }
    } catch (RuntimeException $exception) {
      $errorMessage = $exception->getMessage();
    } catch (PDOException $exception) {
      error_log('Signup database error: ' . $exception->getMessage());
      http_response_code(500);
      $errorMessage = 'Your account could not be created. Check the database configuration and PHP error log.';
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign Up - DTET</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">

  <link href="../assets/css/common.css" rel="stylesheet">
</head>
<body class="bg-light page-admin-signup-html">
  <div id="header-placeholder"></div>

  <main class="container py-4">
    <h1 class="h3 font-weight-bold mb-4">Create an Account</h1>

    <form class="signup-card card shadow-sm" action="signup.php" method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?php echo signupEscapeHtml($_SESSION['signup_csrf']); ?>">
      <div class="card-body p-4">
        <?php if ($errorMessage !== ''): ?>
          <div class="alert alert-danger" role="alert"><?php echo signupEscapeHtml($errorMessage); ?></div>
        <?php endif; ?>
        <?php if ($administratorCreated): ?>
          <div class="alert alert-success" role="status">Administrator account created successfully.</div>
        <?php endif; ?>
        <div class="form-group">
          <label class="font-weight-bold" for="accountType">Account Type</label>
          <select class="form-control" id="accountType" name="role" required>
            <option value=""<?php echo $account['role'] === '' ? ' selected' : ''; ?> disabled>Select account type</option>
            <option value="student"<?php echo $account['role'] === 'student' ? ' selected' : ''; ?>>Student</option>
            <option value="instructor"<?php echo $account['role'] === 'instructor' ? ' selected' : ''; ?>>Instructor</option>
            <?php if ($isAdministrator): ?>
              <option value="admin"<?php echo $account['role'] === 'admin' ? ' selected' : ''; ?>>Administrator</option>
            <?php endif; ?>
          </select>
        </div>

        <div class="text-center mb-4">
          <img class="signup-photo rounded-circle border bg-white mb-2" id="profilePhoto"
               src="../assets/images/person-fill.svg" alt="Profile photo preview">
          <label class="d-block font-weight-bold mb-2" for="photoUpload">Profile Photo</label>
          <input class="form-control-file" id="photoUpload" name="photo" type="file" accept="image/jpeg,image/png,image/gif,image/webp">
        </div>

        <h2 class="form-section-title mb-3">User Details</h2>
        <div class="form-row">
          <div class="form-group col-md-6">
            <label for="fullName">Full Name</label>
            <input class="form-control" id="fullName" name="full_name" type="text" maxlength="150" autocomplete="name" required
                   value="<?php echo signupEscapeHtml($account['full_name']); ?>">
          </div>
          <div class="form-group col-md-6">
            <label for="nic">NIC</label>
            <input class="form-control" id="nic" name="nic_number" type="text" maxlength="20" autocomplete="off" required
                   value="<?php echo signupEscapeHtml($account['nic_number']); ?>">
          </div>
          <div class="form-group col-md-6">
            <label for="contactNumber">Contact Number</label>
            <input class="form-control" id="contactNumber" name="contact_number" type="tel" maxlength="20" autocomplete="tel" required
                   value="<?php echo signupEscapeHtml($account['contact_number']); ?>">
          </div>
          <div class="form-group col-md-6">
            <label for="emailAddress">Email Address</label>
            <input class="form-control" id="emailAddress" name="email" type="email" maxlength="150" autocomplete="email" required
                   value="<?php echo signupEscapeHtml($account['email']); ?>">
          </div>
        </div>

        <section id="studentFields" hidden aria-labelledby="studentDetailsTitle">
          <h2 class="form-section-title mb-3" id="studentDetailsTitle">Student Details</h2>
          <div class="form-row">
            <div class="form-group col-md-4">
              <label for="registrationNumber">Registration Number</label>
              <input class="form-control" id="registrationNumber" name="registration_number" type="text" maxlength="50"
                     value="<?php echo signupEscapeHtml($account['registration_number']); ?>">
            </div>
            <div class="form-group col-md-4">
              <label for="intakeYear">Intake Year</label>
              <input class="form-control" id="intakeYear" name="intake_year" type="number" min="1901" max="2100"
                     value="<?php echo signupEscapeHtml($account['intake_year']); ?>">
            </div>
            <div class="form-group col-md-4 mb-0">
              <label for="semesterEnrolled">Semester Enrolled</label>
              <input class="form-control" id="semesterEnrolled" name="semester_enrolled" type="number" min="1" max="4"
                     value="<?php echo signupEscapeHtml($account['semester_enrolled']); ?>">
            </div>
          </div>
        </section>

        <section id="instructorFields" hidden aria-labelledby="instructorDetailsTitle">
          <h2 class="form-section-title mb-3" id="instructorDetailsTitle">Instructor Details</h2>
          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="employeeCode">Employee Code</label>
              <input class="form-control" id="employeeCode" name="employee_code" type="text" maxlength="50"
                     value="<?php echo signupEscapeHtml($account['employee_code']); ?>">
            </div>
            <div class="form-group col-md-6 mb-0">
              <label for="department">Department</label>
              <input class="form-control" id="department" name="department" type="text" maxlength="100"
                     value="<?php echo signupEscapeHtml($account['department']); ?>">
            </div>
          </div>
        </section>

        <hr class="my-4">
        <h2 class="form-section-title mb-3">Initial Login Details</h2>
        <div class="form-row">
          <div class="form-group col-md-6">
            <label for="password">Initial Password</label>
            <input class="form-control" id="password" name="password" type="password"
                   autocomplete="new-password" minlength="8" maxlength="72" required>
            <small class="form-text text-muted">Create a password with at least 8 characters. The server must hash it before storing it as password_hash.</small>
          </div>
          <div class="form-group col-md-6 mb-0">
            <label for="confirmPassword">Confirm Password</label>
            <input class="form-control" id="confirmPassword" name="confirm_password" type="password"
                   autocomplete="new-password" minlength="8" maxlength="72" required>
          </div>
        </div>
      </div>

      <div class="card-footer bg-white signup-actions d-flex justify-content-between align-items-center flex-wrap">
        <a href="../login.php">Already have an account? Log in</a>
        <button class="btn btn-primary mt-2 mt-sm-0" type="submit">Create Account</button>
      </div>
    </form>
  </main>

  <div id="footer-placeholder"></div>

  <script src="../assets/js/common.js" data-page="admin/signup.php"></script>
</body>
</html>