<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$headerBasePath = isset($headerBasePath) ? $headerBasePath : '../';
$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$userRole = strtolower((string) ($_SESSION['role'] ?? ''));
$profilePath = $headerBasePath . 'login.php';
if ($userId !== false) {
  if (empty($_SESSION['logout_csrf'])) {
    $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
  }
  if ($userRole === 'student') {
    $profilePath = $headerBasePath . 'admin/studentprofile.php';
  } elseif ($userRole === 'instructor') {
    $profilePath = $headerBasePath . 'admin/instructorprofile.php';
  } elseif (in_array($userRole, ['admin', 'administrator', 'system administrator'], true)) {
    $profilePath = $headerBasePath . 'admin/adminprofile.php';
  }
}
$headerEscape = function ($value) {
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<header class="site-header">
  <nav class="navbar navbar-expand py-2" aria-label="Main">
    <div class="container-fluid px-4">

      <!-- Shared site navigation -->
      <a class="navbar-brand d-flex align-items-center" href="#">
        <img src="<?php echo $headerEscape($headerBasePath . 'assets/images/DTET logo.jpg'); ?>" alt="DTET" height="50" class="d-inline-block align-text-top">
      </a>

      <!-- Right side: Home, My Courses, bell, profile -->
      <ul class="navbar-nav align-items-center ms-auto gap-1">
        <li class="nav-item"><a class="nav-link px-3" aria-current="page" href="<?php echo $headerEscape($headerBasePath . 'index.php'); ?>">Home</a></li>
        <?php if ($userId !== false && in_array($userRole, ['admin', 'administrator', 'system administrator'], true)): ?>
          <li class="nav-item">
            <a class="nav-link px-3" href="<?php echo $headerEscape($headerBasePath . 'admin/userprevilage.php'); ?>">
              User Privileges
            </a>
          </li>
        <?php endif; ?>
        <li class="nav-item"><a class="nav-link px-3" href="<?php echo $headerEscape($headerBasePath . 'course/mycourses.html'); ?>">My Courses</a></li>
        <li class="nav-item"><a class="nav-link px-3" href="<?php echo $headerEscape($headerBasePath . 'student/trackstudentactivity.html'); ?>">My Activity</a></li>

        <li class="nav-item mx-2">
          <img src="<?php echo $headerEscape($headerBasePath . 'assets/images/bell-fill.svg'); ?>" alt="Notifications" id="bellBtn"
               class="common-inline-layouts-header-html-1">
        </li>

        <li class="nav-item">
          <a class="user-box" href="<?php echo $headerEscape($profilePath); ?>" aria-label="<?php echo $userId !== false ? 'User profile' : 'Sign in'; ?>">
            <img src="<?php echo $headerEscape($headerBasePath . 'assets/images/person-fill.svg'); ?>" alt="User profile" class="common-inline-layouts-header-html-2">
          </a>
        </li>
        <?php if ($userId !== false): ?>
          <li class="nav-item">
            <form action="<?php echo $headerEscape($headerBasePath . 'logout.php'); ?>" method="post" class="mb-0">
              <input type="hidden" name="csrf_token" value="<?php echo $headerEscape($_SESSION['logout_csrf']); ?>">
              <button class="nav-link btn btn-link px-3" type="submit">Logout</button>
            </form>
          </li>
        <?php endif; ?>
      </ul>

    </div>
  </nav>
</header>