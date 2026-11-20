<?php
declare(strict_types=1);

session_start();

function instructorViewEscape($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$userRole = strtolower((string) ($_SESSION['role'] ?? ''));
$isAdministrator = in_array($userRole, ['admin', 'administrator', 'system administrator'], true);

if ($userId === false) {
  header('Location: ../login.php');
  exit;
}

$profile = null;
$errorMessage = '';
$profileUserId = $userId;
if ($isAdministrator) {
  $requestedProfileId = filter_var(
    $_GET['user_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
  );
  $profileUserId = $requestedProfileId === false ? null : $requestedProfileId;
}

if ($userRole !== 'instructor' && !$isAdministrator) {
  http_response_code(403);
  $errorMessage = 'This page is available to instructors and administrators only.';
} elseif ($isAdministrator && $profileUserId === null) {
  http_response_code(400);
  $errorMessage = 'Choose an instructor to view.';
} else {
  try {
    $pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
    $lookup = $pdo->prepare(
      'SELECT u.nic_number, u.full_name, u.email, u.contact_number,
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
  } catch (PDOException $error) {
    error_log('Instructor profile view database error: ' . $error->getMessage());
    http_response_code(500);
    $errorMessage = 'The instructor profile could not be loaded.';
  }
}

$backPath = 'instructorprofile.php';
if ($isAdministrator && $profileUserId !== null) {
  $backPath .= '?user_id=' . $profileUserId;
}

$photoUrl = '../assets/images/person-fill.svg';
if ($profile && is_string($profile['profile_photo']) &&
    preg_match('#^uploads/instructors/[a-f0-9]{32}\.(jpg|png|gif|webp)$#D', $profile['profile_photo'])) {
  $photoUrl = '../' . $profile['profile_photo'];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>View Instructor Profile - DTET</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/common.css" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column min-vh-100">
  <?php
  $headerBasePath = '../';
  require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'header.php';
  ?>

  <main class="container py-4 flex-fill">
    <h1 class="h3 font-weight-bold mb-4">Instructor Profile</h1>

    <?php if ($errorMessage !== ''): ?>
      <div class="alert alert-danger" role="alert"><?php echo instructorViewEscape($errorMessage); ?></div>
    <?php elseif ($profile): ?>
      <section class="card shadow-sm mx-auto" aria-label="Instructor profile details">
        <div class="card-body p-4">
          <div class="text-center mb-4">
            <img class="rounded-circle border bg-light mb-2" src="<?php echo instructorViewEscape($photoUrl); ?>"
                 alt="Instructor profile photo" width="120" height="120">
            <h2 class="h5 mb-0"><?php echo instructorViewEscape($profile['full_name']); ?></h2>
          </div>

          <dl class="row mb-0">
            <dt class="col-sm-4">NIC</dt>
            <dd class="col-sm-8"><?php echo instructorViewEscape($profile['nic_number']); ?></dd>

            <dt class="col-sm-4">Contact Number</dt>
            <dd class="col-sm-8"><?php echo instructorViewEscape($profile['contact_number'] ?: 'Not provided'); ?></dd>

            <dt class="col-sm-4">Email Address</dt>
            <dd class="col-sm-8"><?php echo instructorViewEscape($profile['email']); ?></dd>

            <dt class="col-sm-4">Employee Code</dt>
            <dd class="col-sm-8"><?php echo instructorViewEscape($profile['employee_code']); ?></dd>

            <dt class="col-sm-4">Department</dt>
            <dd class="col-sm-8"><?php echo instructorViewEscape($profile['department']); ?></dd>

            <dt class="col-sm-4">Account Type</dt>
            <dd class="col-sm-8">Instructor</dd>
          </dl>
        </div>
        <div class="card-footer bg-white text-right">
          <a class="btn btn-secondary" href="<?php echo instructorViewEscape($backPath); ?>">Back to profile</a>
        </div>
      </section>
    <?php endif; ?>
  </main>

  <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
</body>
</html>
