<?php
declare(strict_types=1);

session_start();

function resetCredentialsEscape($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function findResetAccount(PDO $pdo, string $token): ?array
{
  if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    return null;
  }

  $tokenHash = hash('sha256', $token);
  $lookup = $pdo->prepare(
    'SELECT u.user_id, u.email
     FROM password_reset_tokens t
     INNER JOIN users u ON u.user_id = t.user_id
     WHERE t.token_hash = ? AND t.expires_at > CURRENT_TIMESTAMP
     LIMIT 1'
  );
  $lookup->execute([$tokenHash]);
  $account = $lookup->fetch();

  return $account ?: null;
}

if (empty($_SESSION['password_reset_csrf'])) {
  $_SESSION['password_reset_csrf'] = bin2hex(random_bytes(32));
}

$errorMessage = '';
$successMessage = '';
$identifier = '';
$token = '';
$resetAccount = null;
$passwordResetComplete = false;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['token']) && is_string($_GET['token'])) {
  $token = $_GET['token'];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token']) && is_string($_POST['token'])) {
  $token = $_POST['token'];
}

try {
  $pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
  $pdo->query('SELECT user_id FROM password_reset_tokens LIMIT 0');

  if ($token !== '') {
    $resetAccount = findResetAccount($pdo, $token);
    if (!$resetAccount) {
      $errorMessage = 'This password reset link is invalid or has expired. Request a new link.';
    }
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) ||
        !hash_equals($_SESSION['password_reset_csrf'], $submittedToken)) {
      http_response_code(400);
      $errorMessage = 'Your form expired. Reload the page and try again.';
    } elseif ($token !== '') {
      if ($resetAccount) {
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!is_string($password) || !is_string($confirmPassword) ||
            strlen($password) < 8 || strlen($password) > 72) {
          $errorMessage = 'Choose a password between 8 and 72 characters.';
        } elseif (!hash_equals($password, $confirmPassword)) {
          $errorMessage = 'The passwords do not match.';
        } else {
          $pdo->beginTransaction();
          $updatePassword = $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
          $updatePassword->execute([
            password_hash($password, PASSWORD_DEFAULT),
            $resetAccount['user_id'],
          ]);

          $deleteToken = $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?');
          $deleteToken->execute([$resetAccount['user_id']]);
          $pdo->commit();
          $passwordResetComplete = true;
        }
      }
    } else {
      $identifierInput = $_POST['identifier'] ?? '';
      if (!is_string($identifierInput)) {
        $errorMessage = 'Enter a valid user name, email, or NIC.';
      } else {
        $identifier = trim($identifierInput);
        if ($identifier === '' || strlen($identifier) > 150) {
          $errorMessage = 'Enter a valid user name, email, or NIC.';
        } else {
          $fromEmail = getenv('PASSWORD_RESET_FROM_EMAIL');
          $baseUrl = getenv('APP_BASE_URL');
          $baseUrlParts = is_string($baseUrl) ? parse_url($baseUrl) : false;

          if (!is_string($fromEmail) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL) ||
              !is_array($baseUrlParts) ||
              !in_array($baseUrlParts['scheme'] ?? '', ['http', 'https'], true) ||
              empty($baseUrlParts['host'])) {
            $errorMessage = 'Password reset email is not configured. Set PASSWORD_RESET_FROM_EMAIL and APP_BASE_URL.';
          } else {
            $lookup = $pdo->prepare(
              'SELECT u.user_id, u.email AS reset_email, u.role
               FROM users u
               LEFT JOIN students s ON s.user_id = u.user_id
               LEFT JOIN instructors i ON i.user_id = u.user_id
               WHERE (LOWER(u.role) = \'student\' AND s.registration_number = ?)
                  OR (LOWER(u.role) = \'instructor\' AND i.user_id IS NOT NULL
                      AND (u.email = ? OR u.nic_number = ?))
                  OR (LOWER(u.role) NOT IN (\'student\', \'instructor\')
                      AND (u.email = ? OR u.nic_number = ?))
               LIMIT 2'
            );
            $lookup->execute([$identifier, $identifier, $identifier, $identifier, $identifier]);
            $accounts = $lookup->fetchAll();

            if (count($accounts) === 1) {
              $account = $accounts[0];
              $resetEmail = $account['reset_email'];

              if (!is_string($resetEmail) || !filter_var($resetEmail, FILTER_VALIDATE_EMAIL)) {
                error_log('No valid reset email is saved for user ID ' . $account['user_id']);
              } else {
                $plainToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $plainToken);

                $saveToken = $pdo->prepare(
                  'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
                   VALUES (?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 HOUR))
                   ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash),
                     expires_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 HOUR)'
                );
                $saveToken->execute([$account['user_id'], $tokenHash]);

                $resetLink = rtrim($baseUrl, '/') . '/admin/resetcredentials.php?token=' .
                  urlencode($plainToken);
                $subject = 'Reset your DTET account password';
                $message = "We received a request to reset your password.\n\n" .
                  "Open this link within one hour to choose a new password:\n" .
                  $resetLink . "\n\nIf you did not request this, you can ignore this email.";
                $headers = "From: " . $fromEmail . "\r\n" .
                  "Content-Type: text/plain; charset=UTF-8";

                if (!mail($resetEmail, $subject, $message, $headers)) {
                  error_log('Unable to send password reset email for user ID ' . $account['user_id']);
                  $removeToken = $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?');
                  $removeToken->execute([$account['user_id']]);
                }
              }
            } elseif (count($accounts) > 1) {
              error_log('Password reset request matched multiple user accounts.');
            }

            if ($errorMessage === '') {
              $successMessage = 'If the details match an account, a reset link will be sent to its registered email address. If you do not receive it, contact an administrator.';
            }
          }
        }
      }
    }
  }
} catch (PDOException $error) {
  if (isset($pdo) && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log('Password reset database error: ' . $error->getMessage());
  http_response_code(500);
  $errorMessage = 'Password reset is unavailable. Check the database setup and PHP error log.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reset Password - DTET</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/common.css" rel="stylesheet">
</head>
<body class="page-admin-resetcredentials-php">
  <main class="reset-card" aria-labelledby="resetTitle">
    <img class="reset-logo" src="../assets/images/DTET logo.jpg" alt="DTET Sri Lanka logo">
    <h1 class="h4 text-center mb-3" id="resetTitle">Reset your password</h1>

    <?php if ($errorMessage !== ''): ?>
      <div class="alert alert-danger" role="alert"><?php echo resetCredentialsEscape($errorMessage); ?></div>
    <?php endif; ?>
    <?php if ($successMessage !== ''): ?>
      <div class="alert alert-info" role="status"><?php echo resetCredentialsEscape($successMessage); ?></div>
    <?php endif; ?>

    <?php if ($passwordResetComplete): ?>
      <div class="alert alert-success" role="status">Your password has been changed. You can now sign in.</div>
    <?php elseif ($resetAccount): ?>
      <p class="text-muted">Choose a new password for your account.</p>
      <form action="resetcredentials.php" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo resetCredentialsEscape($_SESSION['password_reset_csrf']); ?>">
        <input type="hidden" name="token" value="<?php echo resetCredentialsEscape($token); ?>">
        <div class="form-group">
          <label for="password">New Password</label>
          <input class="form-control" id="password" name="password" type="password"
                 minlength="8" maxlength="72" autocomplete="new-password" required>
        </div>
        <div class="form-group">
          <label for="confirmPassword">Confirm New Password</label>
          <input class="form-control" id="confirmPassword" name="confirm_password" type="password"
                 minlength="8" maxlength="72" autocomplete="new-password" required>
        </div>
        <button class="btn btn-primary" type="submit">Change Password</button>
      </form>
    <?php else: ?>
      <p class="text-muted">
        Students: enter your registration number. Instructors and administrators: enter your email or NIC.
        A reset link will be sent to the email address saved with your account.
      </p>
      <form action="resetcredentials.php" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo resetCredentialsEscape($_SESSION['password_reset_csrf']); ?>">
        <div class="form-group">
          <label for="identifier">User Name, Email, or NIC</label>
          <input class="form-control" id="identifier" name="identifier" type="text" maxlength="150"
                 value="<?php echo resetCredentialsEscape($identifier); ?>" autocomplete="username" required>
        </div>
        <button class="btn btn-primary" type="submit">Request Reset Link</button>
      </form>
    <?php endif; ?>

    <p class="text-center mt-3 mb-0">
      <a href="../login.php">Back to login</a>
    </p>
  </main>
</body>
</html>
