<?php
declare(strict_types=1);

function coursePageEscape($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$course = null;
$courseModules = [];
$assignments = [];
$errorMessage = '';

$courseIdInput = is_string($_GET['course_id'] ?? null) ? trim($_GET['course_id']) : '';
$courseCode = is_string($_GET['course_code'] ?? null) ? trim($_GET['course_code']) : '';

try {
  $pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

  if ($courseIdInput !== '') {
    $courseId = filter_var($courseIdInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($courseId !== false) {
      $courseLookup = $pdo->prepare(
        'SELECT course_id, course_code, course_name, duration_months, total_semesters
         FROM courses
         WHERE course_id = ?
         LIMIT 1'
      );
      $courseLookup->execute([$courseId]);
      $course = $courseLookup->fetch();
    }
  } elseif ($courseCode !== '') {
    if (strlen($courseCode) <= 50) {
      $courseLookup = $pdo->prepare(
        'SELECT course_id, course_code, course_name, duration_months, total_semesters
         FROM courses
         WHERE course_code = ?
         LIMIT 1'
      );
      $courseLookup->execute([$courseCode]);
      $course = $courseLookup->fetch();
    }
  } else {
    $courseLookup = $pdo->query(
      'SELECT course_id, course_code, course_name, duration_months, total_semesters
       FROM courses
       ORDER BY course_id
       LIMIT 1'
    );
    $course = $courseLookup->fetch();
  }

  if (!$course) {
    http_response_code(404);
    $errorMessage = 'Course not found. Open a course from the course list.';
  } else {
    $moduleLookup = $pdo->prepare(
      'SELECT s.semester_number, s.academic_year, m.module_code, m.module_name
       FROM semesters s
       LEFT JOIN modules m ON m.semester_id = s.semester_id
       WHERE s.course_id = ?
       ORDER BY s.semester_number, m.module_code'
    );
    $moduleLookup->execute([$course['course_id']]);
    $courseModules = $moduleLookup->fetchAll();

    $assignmentLookup = $pdo->prepare(
      'SELECT a.assignment_id, a.title, a.instructions, a.week_number,
              a.file_path, a.file_size_limit_mb, a.deadline, a.max_marks,
              m.module_code, m.module_name, s.semester_number
       FROM assignments a
       INNER JOIN modules m ON m.module_id = a.module_id
       INNER JOIN semesters s ON s.semester_id = m.semester_id
       WHERE s.course_id = ?
       ORDER BY s.semester_number, a.week_number, a.deadline'
    );
    $assignmentLookup->execute([$course['course_id']]);
    $assignments = $assignmentLookup->fetchAll();
  }
} catch (PDOException $error) {
  error_log('Course page database error: ' . $error->getMessage());
  http_response_code(500);
  $errorMessage = 'Course information could not be loaded. Check the database setup and PHP error log.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=1024">
  <title><?php echo $course ? coursePageEscape($course['course_name']) : 'Course'; ?> - DTET</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/common.css" rel="stylesheet">
</head>
<body class="bg-white page-course-course-html">
  <?php
  $headerBasePath = '../';
  require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'header.php';
  ?>

  <main class="container-fluid px-5 py-4">
    <?php if ($errorMessage !== ''): ?>
      <div class="alert alert-danger" role="alert"><?php echo coursePageEscape($errorMessage); ?></div>
    <?php endif; ?>

    <?php if ($course): ?>
      <section class="course-details mb-4" aria-labelledby="courseTitle">
        <h1 class="course-title mb-3" id="courseTitle"><?php echo coursePageEscape($course['course_name']); ?></h1>
        <dl class="row mb-0">
          <dt class="col-sm-4">Course Code</dt>
          <dd class="col-sm-8"><?php echo coursePageEscape($course['course_code']); ?></dd>

          <dt class="col-sm-4">Duration (months)</dt>
          <dd class="col-sm-8"><?php echo coursePageEscape($course['duration_months']); ?></dd>

          <dt class="col-sm-4">Total Semesters</dt>
          <dd class="col-sm-8"><?php echo coursePageEscape($course['total_semesters']); ?></dd>
        </dl>
      </section>

      <ul class="nav nav-tabs" id="courseTabs" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="course-tab" data-bs-toggle="tab" data-bs-target="#coursePane"
                  type="button" role="tab" aria-controls="coursePane" aria-selected="true">Course</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="assignment-tab" data-bs-toggle="tab" data-bs-target="#assignmentPane"
                  type="button" role="tab" aria-controls="assignmentPane" aria-selected="false">Assignment</button>
        </li>
      </ul>

      <div class="tab-content pt-3">
        <section class="tab-pane fade show active" id="coursePane" role="tabpanel" aria-labelledby="course-tab" tabindex="0">
          <section class="week-list p-3" aria-labelledby="courseContentTitle">
            <h2 class="h5 mb-2" id="courseContentTitle">Semesters and Modules</h2>
            <?php if (!$courseModules): ?>
              <p class="schema-empty-state mb-0" role="status">No semesters or modules are available for this course yet.</p>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm mb-0">
                  <thead>
                    <tr>
                      <th scope="col">Semester</th>
                      <th scope="col">Academic Year</th>
                      <th scope="col">Module Code</th>
                      <th scope="col">Module Name</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($courseModules as $module): ?>
                      <tr>
                        <td><?php echo coursePageEscape($module['semester_number']); ?></td>
                        <td><?php echo coursePageEscape($module['academic_year']); ?></td>
                        <td><?php echo coursePageEscape($module['module_code'] ?: 'Not added'); ?></td>
                        <td><?php echo coursePageEscape($module['module_name'] ?: 'Not added'); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>
        </section>

        <section class="tab-pane fade" id="assignmentPane" role="tabpanel" aria-labelledby="assignment-tab" tabindex="0">
          <div class="assignment-panel">
            <section aria-labelledby="assignmentDetailsTitle">
              <h2 class="h6 mb-3" id="assignmentDetailsTitle">Assignment Details</h2>
              <?php if (!$assignments): ?>
                <p class="schema-empty-state" role="status">No assignments are available for this course yet.</p>
              <?php else: ?>
                <?php foreach ($assignments as $assignment): ?>
                  <section class="card mb-3 p-3" aria-label="Assignment details">
                    <h3 class="h6"><?php echo coursePageEscape($assignment['title']); ?></h3>
                    <dl class="row assignment-info mb-0">
                      <dt class="col-sm-4">Module</dt>
                      <dd class="col-sm-8">
                        <?php echo coursePageEscape($assignment['module_code'] . ' - ' . $assignment['module_name']); ?>
                      </dd>

                      <dt class="col-sm-4">Semester</dt>
                      <dd class="col-sm-8"><?php echo coursePageEscape($assignment['semester_number']); ?></dd>

                      <dt class="col-sm-4">Week Number</dt>
                      <dd class="col-sm-8"><?php echo coursePageEscape($assignment['week_number']); ?></dd>

                      <dt class="col-sm-4">Instructions</dt>
                      <dd class="col-sm-8"><?php echo nl2br(coursePageEscape($assignment['instructions'] ?: 'Not provided')); ?></dd>

                      <dt class="col-sm-4">Assignment File</dt>
                      <dd class="col-sm-8"><?php echo coursePageEscape($assignment['file_path'] ?: 'Not provided'); ?></dd>

                      <dt class="col-sm-4">File Size Limit (MB)</dt>
                      <dd class="col-sm-8"><?php echo coursePageEscape($assignment['file_size_limit_mb'] ?: 'Not provided'); ?></dd>

                      <dt class="col-sm-4">Deadline</dt>
                      <dd class="col-sm-8"><?php echo coursePageEscape($assignment['deadline']); ?></dd>

                      <dt class="col-sm-4">Maximum Marks</dt>
                      <dd class="col-sm-8"><?php echo coursePageEscape($assignment['max_marks']); ?></dd>
                    </dl>
                  </section>
                <?php endforeach; ?>
              <?php endif; ?>
            </section>

            <hr>

            <section aria-labelledby="submissionTitle">
              <h2 class="h6 mb-3" id="submissionTitle">Your Submission</h2>
              <p class="schema-empty-state" id="submissionStatus" role="status">
                Assignment submission is not connected to the database yet.
              </p>
              <form action="#" method="post" enctype="multipart/form-data">
                <div class="form-group">
                  <label for="submissionFile">Submission File</label>
                  <input class="form-control-file" id="submissionFile" name="submission_file"
                         type="file" disabled aria-describedby="submissionHelp">
                  <small class="form-text text-muted" id="submissionHelp">
                    Assignment submissions are not available yet.
                  </small>
                </div>
                <button type="submit" class="btn btn-sm btn-primary" disabled>Submit Assignment</button>
              </form>
            </section>
          </div>
        </section>
      </div>
    <?php endif; ?>
  </main>

  <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
  <script src="../assets/js/common.js" data-page="course/course.php"></script>
</body>
</html>
