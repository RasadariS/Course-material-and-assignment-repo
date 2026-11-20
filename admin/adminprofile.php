<?php
declare(strict_types=1);

session_start();

function adminProfileEscape($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, [
  'options' => ['min_range' => 1],
]);
$userRole = strtolower((string) ($_SESSION['role'] ?? ''));

if ($userId === false) {
  header('Location: ../login.php');
  exit;
}

if (!in_array($userRole, ['admin', 'administrator', 'system administrator'], true)) {
  http_response_code(403);
  exit('This page is available to administrator accounts only.');
}

if (empty($_SESSION['admin_profile_csrf'])) {
  $_SESSION['admin_profile_csrf'] = bin2hex(random_bytes(32));
}

$profile = null;
$errorMessage = '';
$successMessage = ($_GET['updated'] ?? '') === '1' ? 'Administrator profile updated successfully.' : '';

try {
  $pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) ||
        !hash_equals($_SESSION['admin_profile_csrf'], $submittedToken)) {
      http_response_code(400);
      $errorMessage = 'Your form expired. Reload the page and try again.';
    } else {
      $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'update';

      if ($action === 'deactivate') {
        $deactivateAccount = $pdo->prepare(
          'UPDATE users
           SET status = \'inactive\'
           WHERE user_id = ? AND status = \'active\'
             AND LOWER(role) IN (\'admin\', \'administrator\', \'system administrator\')'
        );
        $deactivateAccount->execute([$userId]);

        if ($deactivateAccount->rowCount() !== 1) {
          $errorMessage = 'The administrator account could not be deactivated.';
        } else {
          $_SESSION = [];
          if (!session_destroy()) {
            http_response_code(500);
            exit('The account was deactivated, but the session could not be closed. Close the browser and sign in again.');
          }
          header('Location: ../login.php?deactivated=1');
          exit;
        }
      } elseif ($action === 'update') {
        $fullName = is_string($_POST['full_name'] ?? null) ? trim($_POST['full_name']) : '';
        $nicNumber = is_string($_POST['nic_number'] ?? null) ? trim($_POST['nic_number']) : '';
        $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
        $contactNumber = is_string($_POST['contact_number'] ?? null) ? trim($_POST['contact_number']) : '';

        if ($fullName === '' || strlen($fullName) > 150 ||
            $nicNumber === '' || strlen($nicNumber) > 20 ||
            $email === '' || strlen($email) > 150 ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            strlen($contactNumber) > 20) {
          $errorMessage = 'Enter a name, NIC, valid email address, and contact number up to 20 characters.';
        } else {
          $duplicateUser = $pdo->prepare(
            'SELECT user_id
             FROM users
             WHERE (nic_number = ? OR email = ?) AND user_id <> ?
             LIMIT 1'
          );
          $duplicateUser->execute([$nicNumber, $email, $userId]);

          if ($duplicateUser->fetch()) {
            $errorMessage = 'Another account already uses that NIC or email address.';
          } else {
            $updateProfile = $pdo->prepare(
              'UPDATE users
               SET full_name = ?, nic_number = ?, email = ?, contact_number = ?
               WHERE user_id = ? AND status = \'active\''
            );
            $updateProfile->execute([
              $fullName,
              $nicNumber,
              $email,
              $contactNumber === '' ? null : $contactNumber,
              $userId,
            ]);

            $_SESSION['full_name'] = $fullName;
            header('Location: adminprofile.php?updated=1');
            exit;
          }
        }
      } else {
        $errorMessage = 'Choose a valid profile action.';
      }
    }
  }

  $profileLookup = $pdo->prepare(
    'SELECT u.full_name, u.nic_number, u.email, u.contact_number,
            a.access_level, a.profile_photo
     FROM users u
     INNER JOIN system_administrators a ON a.user_id = u.user_id
     WHERE u.user_id = ? AND u.status = \'active\'
       AND LOWER(u.role) IN (\'admin\', \'administrator\', \'system administrator\')
     LIMIT 1'
  );
  $profileLookup->execute([$userId]);
  $profile = $profileLookup->fetch();

  if (!$profile) {
    http_response_code(404);
    $errorMessage = 'No active administrator profile was found for this account.';
  }
} catch (PDOException $error) {
  error_log('Administrator profile database error: ' . $error->getMessage());
  http_response_code(500);
  $profile = null;
  $errorMessage = 'The administrator profile could not be loaded or saved. Check the database setup and PHP error log.';
}

$photoUrl = '../assets/images/person-fill.svg';
if ($profile && is_string($profile['profile_photo']) &&
    preg_match('#^uploads/admins/[a-f0-9]{32}\.(jpg|png|gif|webp)$#D', $profile['profile_photo'])) {
  $photoUrl = '../' . $profile['profile_photo'];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Administrator Profile - DTET</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/common.css" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column min-vh-100">
  <?php
  $headerBasePath = '../';
  require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'header.php';
  ?>

  <main class="container py-4 flex-fill">
    <h1 class="h3 font-weight-bold mb-4">Administrator Profile</h1>

    <?php if ($errorMessage !== ''): ?>
      <div class="alert alert-danger" role="alert"><?php echo adminProfileEscape($errorMessage); ?></div>
    <?php endif; ?>
    <?php if ($successMessage !== ''): ?>
      <div class="alert alert-success" role="status"><?php echo adminProfileEscape($successMessage); ?></div>
    <?php endif; ?>

    <?php if ($profile): ?>
      <form class="card shadow-sm mx-auto" action="adminprofile.php" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo adminProfileEscape($_SESSION['admin_profile_csrf']); ?>">
        <div class="card-body p-4">
          <div class="text-center mb-4">
            <img class="rounded-circle border bg-light mb-2"
                 src="<?php echo adminProfileEscape($photoUrl); ?>"
                 alt="Administrator profile photo" width="120" height="120">
            <h2 class="h5 mb-0"><?php echo adminProfileEscape($profile['full_name']); ?></h2>
          </div>

          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="fullName">Full Name</label>
              <input class="form-control" id="fullName" name="full_name" type="text"
                     maxlength="150" required value="<?php echo adminProfileEscape($profile['full_name']); ?>" disabled>
            </div>
            <div class="form-group col-md-6">
              <label for="nicNumber">NIC</label>
              <input class="form-control" id="nicNumber" name="nic_number" type="text"
                     maxlength="20" required value="<?php echo adminProfileEscape($profile['nic_number']); ?>" disabled>
            </div>
            <div class="form-group col-md-6">
              <label for="email">Email Address</label>
              <input class="form-control" id="email" name="email" type="email"
                     maxlength="150" required value="<?php echo adminProfileEscape($profile['email']); ?>" disabled>
            </div>
            <div class="form-group col-md-6">
              <label for="contactNumber">Contact Number</label>
              <input class="form-control" id="contactNumber" name="contact_number" type="tel"
                     maxlength="20" value="<?php echo adminProfileEscape($profile['contact_number']); ?>" disabled>
            </div>
            <div class="form-group col-md-6 mb-0">
              <label for="accessLevel">Access Level</label>
              <input class="form-control" id="accessLevel" type="text"
                     value="<?php echo adminProfileEscape($profile['access_level']); ?>" readonly>
            </div>
          </div>
        </div>

        <div class="card-footer bg-white d-flex justify-content-end flex-wrap">
          <a class="btn btn-secondary mr-2 mb-1" href="adminprofileview.php">View</a>
          <button class="btn btn-primary mr-2 mb-1" id="editAdminProfile" type="button"
                  onclick="enableAdminProfileEditing()">Edit</button>
          <button class="btn btn-success mr-2 mb-1" id="updateAdminProfile"
                  type="submit" name="action" value="update" disabled
                  onclick="return confirm('Do you want to update the database?');">Update</button>
          <button class="btn btn-danger mb-1" type="submit" name="action" value="deactivate"
                  onclick="return confirm('Deactivate this administrator account? It will no longer be able to sign in.');">
            Delete Account
          </button>
        </div>
      </form>

      <p class="small text-muted mt-3">
        Delete Account marks the account inactive in the database. It does not permanently erase the record.
      </p>
    <?php endif; ?>
  </main>

  <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
  <script>
    function enableAdminProfileEditing() {
      document.getElementById('fullName').disabled = false;
      document.getElementById('nicNumber').disabled = false;
      document.getElementById('email').disabled = false;
      document.getElementById('contactNumber').disabled = false;
      document.getElementById('editAdminProfile').disabled = true;
      document.getElementById('updateAdminProfile').disabled = false;
    }
  </script>
</body>
</html>
