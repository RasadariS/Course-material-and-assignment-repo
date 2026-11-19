<?php
declare(strict_types=1);

session_start();

function adminProfileEscape($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$userRole = strtolower((string) ($_SESSION['role'] ?? ''));
if ($userId === false) {
  header('Location: ../login.php');
  exit;
}
if (!in_array($userRole, ['admin', 'administrator', 'system administrator'], true)) {
  http_response_code(403);
  $errorMessage = 'This page is available to administrator accounts only.';
  $profile = null;
} else {
  try {
    $pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
    $lookup = $pdo->prepare(
      'SELECT u.full_name, u.nic_number, u.email, u.contact_number,
              a.access_level, a.profile_photo
       FROM users u
       INNER JOIN system_administrators a ON a.user_id = u.user_id
       WHERE u.user_id = ? AND LOWER(u.role) IN (\'admin\', \'administrator\', \'system administrator\')
       LIMIT 1'
    );
    $lookup->execute([$userId]);
    $profile = $lookup->fetch();
    if (!$profile) {
      http_response_code(404);
      $errorMessage = 'No administrator profile was found for this account.';
    }
  } catch (PDOException $error) {
    error_log('Administrator profile database error: ' . $error->getMessage());
    http_response_code(500);
    $profile = null;
    $errorMessage = 'The administrator profile could not be loaded. Check the database configuration and PHP error log.';
  }
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

    <?php if (isset($errorMessage)): ?>
      <div class="alert alert-danger" role="alert"><?php echo adminProfileEscape($errorMessage); ?></div>
    <?php elseif ($profile): ?>
      <section class="card shadow-sm mx-auto" aria-label="Administrator profile details">
        <div class="card-body p-4">
          <div class="text-center mb-4">
            <img class="rounded-circle border bg-light mb-2" src="<?php echo adminProfileEscape($photoUrl); ?>"
                 alt="Administrator profile photo" width="120" height="120">
            <h2 class="h5 mb-0"><?php echo adminProfileEscape($profile['full_name']); ?></h2>
          </div>
          <dl class="row mb-0">
            <dt class="col-sm-4">NIC</dt>
            <dd class="col-sm-8"><?php echo adminProfileEscape($profile['nic_number']); ?></dd>
            <dt class="col-sm-4">Email Address</dt>
            <dd class="col-sm-8"><?php echo adminProfileEscape($profile['email']); ?></dd>
            <dt class="col-sm-4">Contact Number</dt>
            <dd class="col-sm-8"><?php echo adminProfileEscape($profile['contact_number'] ?: 'Not provided'); ?></dd>
            <dt class="col-sm-4">Access Level</dt>
            <dd class="col-sm-8"><?php echo adminProfileEscape($profile['access_level']); ?></dd>
          </dl>
        </div>
      </section>
    <?php endif; ?>
  </main>

  <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
  <script src="../assets/js/common.js" data-page="admin/adminprofile.php"></script>
</body>
</html>
