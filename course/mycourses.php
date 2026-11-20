<?php
session_start();

function myCoursesEscape($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, [
  'options' => ['min_range' => 1],
]);

if ($userId === false) {
  header('Location: ../login.php');
  exit;
}

$courses = [];
$errorMessage = '';
$userRole = strtolower((string) ($_SESSION['role'] ?? ''));

if ($userRole !== 'student') {
  http_response_code(403);
  $errorMessage = 'This page is available to student accounts only.';
} else {
  try {
    $pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
    $courseLookup = $pdo->prepare(
      'SELECT c.course_id, c.course_code, c.course_name,
              s.semester_number, m.module_code, m.module_name
       FROM students st
       INNER JOIN student_modules sm ON sm.student_id = st.student_id
       INNER JOIN modules m ON m.module_id = sm.module_id
       INNER JOIN semesters s ON s.semester_id = m.semester_id
       INNER JOIN courses c ON c.course_id = s.course_id
       WHERE st.user_id = ?
       ORDER BY c.course_name, s.semester_number, m.module_name'
    );
    $courseLookup->execute([$userId]);

    foreach ($courseLookup->fetchAll() as $row) {
      $courseId = (int) $row['course_id'];

      if (!isset($courses[$courseId])) {
        $courses[$courseId] = [
          'course_code' => $row['course_code'],
          'course_name' => $row['course_name'],
          'modules' => [],
        ];
      }

      $courses[$courseId]['modules'][] = [
        'semester_number' => $row['semester_number'],
        'module_code' => $row['module_code'],
        'module_name' => $row['module_name'],
      ];
    }
  } catch (PDOException $error) {
    error_log('My courses database error: ' . $error->getMessage());
    http_response_code(500);
    $errorMessage = 'Your courses could not be loaded. Check the database setup and PHP error log.';
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My Courses - DTET</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/common.css" rel="stylesheet">
</head>
<body class="bg-white d-flex flex-column min-vh-100 page-course-mycourses-php">
  <?php
  $headerBasePath = '../';
  require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'header.php';
  ?>

  <main class="container-fluid px-5 py-4 flex-fill">
    <h1 class="page-title">My Courses</h1>
    <p class="page-subtitle">Course Overview</p>

    <?php if ($errorMessage !== ''): ?>
      <div class="alert alert-danger" role="alert"><?php echo myCoursesEscape($errorMessage); ?></div>
    <?php elseif (!$courses): ?>
      <p class="text-muted" role="status">You are not enrolled in any modules yet.</p>
    <?php else: ?>
      <div class="course-row" aria-label="My courses">
        <?php foreach ($courses as $courseId => $course): ?>
          <div class="col">
            <a href="course.php?course_id=<?php echo (int) $courseId; ?>" class="card border-success mb-3 course-card">
              <img src="../assets/images/hat.jpg" class="card-img-top" alt="">
              <div class="card-body">
                <h2 class="card-title"><?php echo myCoursesEscape($course['course_name']); ?></h2>
                <p class="course-code"><?php echo myCoursesEscape($course['course_code']); ?></p>
                <ul class="list-unstyled mb-0">
                  <?php foreach ($course['modules'] as $module): ?>
                    <li>
                      Semester <?php echo myCoursesEscape($module['semester_number']); ?>:
                      <?php echo myCoursesEscape($module['module_code']); ?> -
                      <?php echo myCoursesEscape($module['module_name']); ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>

  <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
</body>
</html>
