<?php
declare(strict_types=1);

session_start();

function studentProfileViewEscape($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$userRole = strtolower((string) ($_SESSION['role'] ?? ''));

if ($userId === false) {
  header('Location: ../login.php');
  exit;
}

$profile = null;
$errorMessage = '';

if ($userRole !== 'student') {
  http_response_code(403);
  $errorMessage = 'This page is available to student accounts only.';
} else {
  try {
    $pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
    $lookup = $pdo->prepare(
      'SELECT u.nic_number, u.full_name, u.email, u.contact_number,
              s.registration_number, s.intake_year, s.semester_enrolled, s.profile_photo
       FROM users u
       INNER JOIN students s ON s.user_id = u.user_id
       WHERE u.user_id = ? AND LOWER(u.role) = \'student\'
       LIMIT 1'
    );
    $lookup->execute([$userId]);
    $profile = $lookup->fetch();

    if (!$profile) {
      http_response_code(404);
      $errorMessage = 'No student profile was found for this account.';
    }
  } catch (PDOException $error) {
    error_log('Student profile view database error: ' . $error->getMessage());
    http_response_code(500);
    $errorMessage = 'The student profile could not be loaded.';
  }
}

$photoUrl = '../assets/images/person-fill.svg';
if ($profile && is_string($profile['profile_photo']) &&
    preg_match('#^uploads/students/[a-f0-9]{32}\.(jpg|png|gif|webp)$#D', $profile['profile_photo'])) {
  $photoUrl = '../' . $profile['profile_photo'];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>View Student Profile - DTET</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/common.css" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column min-vh-100">
  <?php
  $headerBasePath = '../';
  require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'header.php';
  ?>

  <main class="container py-4 flex-fill">
    <h1 class="h3 font-weight-bold mb-4">Student Profile</h1>

    <?php if ($errorMessage !== ''): ?>
      <div class="alert alert-danger" role="alert"><?php echo studentProfileViewEscape($errorMessage); ?></div>
    <?php elseif ($profile): ?>
      <section class="card shadow-sm mx-auto" aria-label="Student profile details">
        <div class="card-body p-4">
          <div class="text-center mb-4">
            <img class="rounded-circle border bg-light mb-2"
                 src="<?php echo studentProfileViewEscape($photoUrl); ?>"
                 alt="Student profile photo" width="120" height="120">
            <h2 class="h5 mb-0"><?php echo studentProfileViewEscape($profile['full_name']); ?></h2>
          </div>

          <dl class="row mb-0">
            <dt class="col-sm-4">NIC</dt>
            <dd class="col-sm-8"><?php echo studentProfileViewEscape($profile['nic_number']); ?></dd>

            <dt class="col-sm-4">Registration Number</dt>
            <dd class="col-sm-8"><?php echo studentProfileViewEscape($profile['registration_number']); ?></dd>

            <dt class="col-sm-4">Contact Number</dt>
            <dd class="col-sm-8"><?php echo studentProfileViewEscape($profile['contact_number'] ?: 'Not provided'); ?></dd>

            <dt class="col-sm-4">Email Address</dt>
            <dd class="col-sm-8"><?php echo studentProfileViewEscape($profile['email']); ?></dd>

            <dt class="col-sm-4">Intake Year</dt>
            <dd class="col-sm-8"><?php echo studentProfileViewEscape($profile['intake_year']); ?></dd>

            <dt class="col-sm-4">Semester Enrolled</dt>
            <dd class="col-sm-8"><?php echo studentProfileViewEscape($profile['semester_enrolled']); ?></dd>

            <dt class="col-sm-4">Account Type</dt>
            <dd class="col-sm-8">Student</dd>
          </dl>
        </div>
        <div class="card-footer bg-white text-right">
          <a class="btn btn-secondary" href="studentprofile.php">Back to profile</a>
        </div>
      </section>
    <?php endif; ?>
  </main>

  <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
</body>
</html>