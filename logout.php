<?php
declare(strict_types=1);

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Allow: POST');
  http_response_code(405);
  exit('Use the logout form to sign out.');
}

$submittedToken = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['logout_csrf'] ?? '';
if (empty($_SESSION['user_id']) || !is_string($submittedToken) ||
    !is_string($sessionToken) || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
  http_response_code(400);
  exit('The logout request is invalid or has expired. Reload the page and try again.');
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
  $cookie = session_get_cookie_params();
  setcookie(
    session_name(),
    '',
    time() - 42000,
    $cookie['path'],
    $cookie['domain'],
    $cookie['secure'],
    $cookie['httponly']
  );
}
if (!session_destroy()) {
  http_response_code(500);
  exit('The session could not be closed. Try again.');
}

header('Location: login.php');
exit;
