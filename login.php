<?php
declare(strict_types=1);

session_start();

function loginEscapeHtml($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($userId !== false) {
  header('Location: index.php');
  exit;
}

if (empty($_SESSION['login_csrf'])) {
  $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}

$errorMessage = '';
$identifier = '';
$registrationSuccess = ($_GET['registered'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submittedToken = $_POST['csrf_token'] ?? '';
  if (!is_string($submittedToken) || !hash_equals($_SESSION['login_csrf'], $submittedToken)) {
    http_response_code(400);
    $errorMessage = 'Your login form expired. Reload the page and try again.';
  } else {
    $identifierInput = $_POST['identifier'] ?? '';
    $password = $_POST['password'] ?? '';
    if (!is_string($identifierInput) || !is_string($password)) {
      $errorMessage = 'Enter a valid user name and password.';
    } else {
      $identifier = trim($identifierInput);
      if ($identifier === '' || strlen($identifier) > 150 || $password === '' || strlen($password) > 4096) {
        $errorMessage = 'Enter a valid user name and password.';
      } else {
        try {
          $pdo = require __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
          $lookup = $pdo->prepare(
            'SELECT u.user_id, u.full_name, u.password_hash, u.role
             FROM users u
             LEFT JOIN students s ON s.user_id = u.user_id
             WHERE (LOWER(u.role) = \'student\' AND s.registration_number = ?)
                OR (LOWER(u.role) <> \'student\' AND (u.email = ? OR u.nic_number = ?))
             LIMIT 2'
          );
          $lookup->execute([$identifier, $identifier, $identifier]);
          $matches = $lookup->fetchAll();

          if (count($matches) === 1 && password_verify($password, $matches[0]['password_hash'])) {
            if (!session_regenerate_id(true)) {
              throw new RuntimeException('The login session could not be secured. Please try again.');
            }

            $_SESSION = [];
            $_SESSION['user_id'] = (int) $matches[0]['user_id'];
            $_SESSION['full_name'] = $matches[0]['full_name'];
            $_SESSION['role'] = $matches[0]['role'];
            header('Location: index.php');
            exit;
          }

          if (count($matches) > 1) {
            error_log('Login rejected because the submitted identifier matches multiple user records.');
          }
          $errorMessage = 'Invalid user name or password.';
        } catch (RuntimeException $exception) {
          $errorMessage = $exception->getMessage();
        } catch (PDOException $exception) {
          error_log('Login database error: ' . $exception->getMessage());
          http_response_code(500);
          $errorMessage = 'Login is temporarily unavailable. Check the server configuration and PHP error log.';
        }
      }
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login - DTET</title>
  <link href="assets/css/bootstrap.min.css" rel="stylesheet">

  <link href="assets/css/common.css" rel="stylesheet">
</head>
<body class="page-login-html">
  <main class="login-card" aria-label="Login">
    <img class="image-placeholder" src="assets/images/DTET logo.jpg" alt="DTET Sri Lanka logo">

    <form class="login-form" action="login.php" method="post">
      <input type="hidden" name="csrf_token" value="<?php echo loginEscapeHtml($_SESSION['login_csrf']); ?>">
      <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger" role="alert"><?php echo loginEscapeHtml($errorMessage); ?></div>
      <?php endif; ?>
      <?php if ($registrationSuccess): ?>
        <div class="alert alert-success" role="status">Your account was created. Sign in to continue.</div>
      <?php endif; ?>
      <div class="field-group">
        <div class="field-control">
          <label for="loginName">User Name</label>
          <input id="loginName" name="identifier" type="text" maxlength="150" required
                 value="<?php echo loginEscapeHtml($identifier); ?>"
                 autocomplete="username" aria-describedby="loginNameHelp">
          <button class="clear-field" type="button" aria-label="Clear user name" data-clear="loginName">&times;</button>
        </div>
        <p class="field-help" id="loginNameHelp">
          Students: enter your registration number. Instructors and administrators: enter your email or NIC.
        </p>
      </div>

      <div class="field-group">
        <div class="field-control">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" required
                 autocomplete="current-password" aria-describedby="passwordHelp">
          <button class="clear-field" type="button" aria-label="Clear password" data-clear="password">&times;</button>
        </div>
        <p class="field-help" id="passwordHelp">Type your password</p>
      </div>

      <button class="btn-login" type="submit">Login</button>
      <a class="forgot-password" href="admin/resetcredentials.php">Forgot your password?</a>
      <p class="mt-3 mb-0 text-center">Don't have an account? <a href="admin/signup.php">Sign up</a></p>
    </form>
  </main>

  <script src="assets/js/common.js" data-page="login.php"></script>
</body>
</html>
