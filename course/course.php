<?php
declare(strict_types=1);

session_start();

function coursePageEscape($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$userRole = strtolower((string) ($_SESSION['role'] ?? ''));
if ($userRole === 'student' && empty($_SESSION['course_submission_csrf'])) {
  $_SESSION['course_submission_csrf'] = bin2hex(random_bytes(32));
}

$course = null;
$courseModules = [];
$assignments = [];
$courseMaterials = [];
$studentId = null;
$enrolledModuleIds = [];
$submissionsByAssignment = [];
$errorMessage = '';
$successMessage = '';

if ($userRole === 'student' && $userId !== false && ($_GET['submitted'] ?? '') === '1') {
  $successMessage = 'Your assignment was submitted successfully.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($userRole !== 'student' || $userId === false)) {
  http_response_code(403);
  $errorMessage = 'Only students can submit assignments.';
}

$courseIdInput = is_string($_GET['course_id'] ?? null) ? trim($_GET['course_id']) : '';
$courseCode = is_string($_GET['course_code'] ?? null) ? trim($_GET['course_code']) : '';

try {
  $pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

  if ($courseIdInput !== '') {
    $courseId = filter_var($courseIdInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($courseId !== false) {
      $courseLookup = $pdo->prepare(
        'SELECT course_id, course_name
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
        'SELECT course_id, course_name
         FROM courses
         WHERE course_code = ?
         LIMIT 1'
      );
      $courseLookup->execute([$courseCode]);
      $course = $courseLookup->fetch();
    }
  } else {
    $courseLookup = $pdo->query(
      'SELECT course_id, course_name
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
      'SELECT m.module_code, m.module_name
       FROM modules m
       INNER JOIN semesters s ON s.semester_id = m.semester_id
       WHERE s.course_id = ?
       ORDER BY m.module_code'
    );
    $moduleLookup->execute([$course['course_id']]);
    $courseModules = $moduleLookup->fetchAll();

    $assignmentLookup = $pdo->prepare(
      'SELECT a.assignment_id, a.module_id, a.title, a.instructions, a.week_number,
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

    $materialLookup = $pdo->prepare(
      'SELECT cm.title, cm.week_number, cm.file_path
       FROM course_materials cm
       INNER JOIN modules m ON m.module_id = cm.module_id
       INNER JOIN semesters s ON s.semester_id = m.semester_id
       WHERE s.course_id = ?
       ORDER BY s.semester_number, cm.week_number, m.module_code, cm.title'
    );
    $materialLookup->execute([$course['course_id']]);
    $courseMaterials = $materialLookup->fetchAll();

    if ($userRole === 'student' && $userId !== false) {
      $studentLookup = $pdo->prepare('SELECT student_id FROM students WHERE user_id = ? LIMIT 1');
      $studentLookup->execute([$userId]);
      $studentIdValue = $studentLookup->fetchColumn();

      if ($studentIdValue === false) {
        http_response_code(404);
        $errorMessage = 'No student profile was found for this account.';
      } else {
        $studentId = (int) $studentIdValue;

        $enrollmentLookup = $pdo->prepare('SELECT module_id FROM student_modules WHERE student_id = ?');
        $enrollmentLookup->execute([$studentId]);
        foreach ($enrollmentLookup->fetchAll(PDO::FETCH_COLUMN) as $moduleId) {
          $enrolledModuleIds[] = (int) $moduleId;
        }
      }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userRole === 'student' &&
        $studentId !== null && $errorMessage === '') {
      $submittedToken = $_POST['csrf_token'] ?? '';
      if (!is_string($submittedToken) ||
          !hash_equals($_SESSION['course_submission_csrf'], $submittedToken)) {
        http_response_code(400);
        $errorMessage = 'Your submission form expired. Reload the page and try again.';
      } else {
        $assignmentId = filter_var(
          $_POST['assignment_id'] ?? null,
          FILTER_VALIDATE_INT,
          ['options' => ['min_range' => 1]]
        );
        $selectedAssignment = null;

        if ($assignmentId !== false) {
          foreach ($assignments as $assignment) {
            if ((int) $assignment['assignment_id'] === $assignmentId) {
              $selectedAssignment = $assignment;
              break;
            }
          }
        }

        if (!$selectedAssignment) {
          http_response_code(404);
          $errorMessage = 'Choose a valid assignment for this course.';
        } elseif (!in_array((int) $selectedAssignment['module_id'], $enrolledModuleIds, true)) {
          http_response_code(403);
          $errorMessage = 'You must be enrolled in this module to submit the assignment.';
        } else {
          $file = $_FILES['submission_file'] ?? null;

          if (!is_array($file) ||
              !isset($file['error'], $file['name'], $file['size'], $file['tmp_name']) ||
              !is_int($file['error']) ||
              !is_string($file['name']) ||
              !is_int($file['size']) ||
              !is_string($file['tmp_name'])) {
            $errorMessage = 'Choose a file to submit.';
          } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMessage = 'The submission file could not be uploaded. Choose a file and try again.';
          } else {
            $fileSizeLimitMb = $selectedAssignment['file_size_limit_mb'] === null
              ? 20
              : (float) $selectedAssignment['file_size_limit_mb'];
            $fileSizeLimitBytes = (int) ($fileSizeLimitMb * 1024 * 1024);

            if ($fileSizeLimitMb <= 0 ||
                $file['size'] < 1 ||
                $file['size'] > $fileSizeLimitBytes ||
                !is_uploaded_file($file['tmp_name'])) {
              $errorMessage = 'Choose a valid file within the assignment file size limit.';
            } else {
              $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
              $allowedMimeTypes = [
                'pdf' => ['application/pdf'],
                'doc' => ['application/msword', 'application/x-ole-storage'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
                'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage'],
                'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
                'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage'],
                'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
                'txt' => ['text/plain'],
                'zip' => ['application/zip', 'application/x-zip-compressed'],
              ];
              $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);

              if (!isset($allowedMimeTypes[$extension]) ||
                  !is_string($mimeType) ||
                  !in_array($mimeType, $allowedMimeTypes[$extension], true)) {
                $errorMessage = 'Use a PDF, Word, PowerPoint, Excel, text, or ZIP file.';
              } else {
                $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR .
                  'uploads' . DIRECTORY_SEPARATOR . 'submissions';
                if (!is_dir($uploadDirectory) &&
                    !mkdir($uploadDirectory, 0755, true) &&
                    !is_dir($uploadDirectory)) {
                  $errorMessage = 'The submissions folder could not be created.';
                } else {
                  $relativeFilePath = 'uploads/submissions/' . bin2hex(random_bytes(16)) . '.' . $extension;
                  $absoluteFilePath = dirname(__DIR__) . DIRECTORY_SEPARATOR .
                    str_replace('/', DIRECTORY_SEPARATOR, $relativeFilePath);

                  if (!move_uploaded_file($file['tmp_name'], $absoluteFilePath)) {
                    $errorMessage = 'The submission file could not be saved.';
                  } else {
                    try {
                      $lateCheck = $pdo->prepare(
                        'SELECT deadline < CURRENT_TIMESTAMP
                         FROM assignments
                         WHERE assignment_id = ?'
                      );
                      $lateCheck->execute([$assignmentId]);
                      $isLate = (int) $lateCheck->fetchColumn();

                      $saveSubmission = $pdo->prepare(
                        'INSERT INTO submissions
                          (assignment_id, student_id, file_path, file_size_mb, is_late)
                         VALUES (?, ?, ?, ?, ?)'
                      );
                      $saveSubmission->execute([
                        $assignmentId,
                        $studentId,
                        $relativeFilePath,
                        round($file['size'] / (1024 * 1024), 2),
                        $isLate,
                      ]);
                    } catch (PDOException $error) {
                      if (is_file($absoluteFilePath) && !unlink($absoluteFilePath)) {
                        error_log('Unable to remove submission file after a database error: ' . $absoluteFilePath);
                      }
                      throw $error;
                    }

                    header('Location: course.php?course_id=' . (int) $course['course_id'] . '&submitted=1');
                    exit;
                  }
                }
              }
            }
          }
        }
      }
    }

    if ($studentId !== null) {
      $submissionLookup = $pdo->prepare(
        'SELECT sub.submission_id, sub.assignment_id, sub.file_path, sub.file_size_mb,
                sub.submission_timestamp, sub.marks_obtained, sub.feedback, sub.is_late
         FROM submissions sub
         INNER JOIN assignments a ON a.assignment_id = sub.assignment_id
         INNER JOIN modules m ON m.module_id = a.module_id
         INNER JOIN semesters s ON s.semester_id = m.semester_id
         WHERE sub.student_id = ? AND s.course_id = ?
         ORDER BY sub.submission_timestamp DESC, sub.submission_id DESC'
      );
      $submissionLookup->execute([$studentId, $course['course_id']]);

      foreach ($submissionLookup->fetchAll() as $submission) {
        $assignmentId = (int) $submission['assignment_id'];
        if (!isset($submissionsByAssignment[$assignmentId])) {
          $submissionsByAssignment[$assignmentId] = $submission;
        }
      }
    }
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
    <?php if ($successMessage !== ''): ?>
      <div class="alert alert-success" role="status"><?php echo coursePageEscape($successMessage); ?></div>
    <?php endif; ?>

    <?php if ($course): ?>
      <section class="course-details mb-4" aria-label="Course modules">
        <?php if (!$courseModules): ?>
          <p class="schema-empty-state mb-0" role="status">No modules are available for this course yet.</p>
        <?php else: ?>
          <?php foreach ($courseModules as $module): ?>
            <h1 class="course-title mb-3">
              <?php echo coursePageEscape($module['module_code'] . ' - ' . $module['module_name']); ?>
            </h1>
          <?php endforeach; ?>
        <?php endif; ?>
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
          <section class="week-list p-3 mt-4" aria-labelledby="courseMaterialsTitle">
            <h2 class="h5 mb-2" id="courseMaterialsTitle">Uploaded Course Materials</h2>
            <?php if (!$courseMaterials): ?>
              <p class="schema-empty-state mb-0" role="status">No course materials have been uploaded for this course yet.</p>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm mb-0">
                  <thead>
                    <tr>
                      <th scope="col">Week Number</th>
                      <th scope="col">Material</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($courseMaterials as $material): ?>
                      <?php
                      $materialPath = (string) $material['file_path'];
                      $validMaterialPath = preg_match(
                        '#^uploads/course_materials/[a-f0-9]{32}\.(pdf|doc|docx|ppt|pptx|xls|xlsx|txt|zip)$#D',
                        $materialPath
                      ) === 1;
                      $materialFile = dirname(__DIR__) . DIRECTORY_SEPARATOR .
                        str_replace('/', DIRECTORY_SEPARATOR, $materialPath);
                      ?>
                      <tr>
                        <td><?php echo coursePageEscape($material['week_number']); ?></td>
                        <td>
                          <?php if ($validMaterialPath && is_file($materialFile)): ?>
                            <a href="../<?php echo coursePageEscape($materialPath); ?>">
                              <?php echo coursePageEscape($material['title']); ?>
                            </a>
                          <?php else: ?>
                            <?php echo coursePageEscape($material['title']); ?>
                            <span class="text-muted">(file unavailable)</span>
                          <?php endif; ?>
                        </td>
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
                      <dd class="col-sm-8">
                        <?php
                        $assignmentFilePath = (string) ($assignment['file_path'] ?? '');
                        $validAssignmentPath = preg_match(
                          '#^uploads/assignments/[a-f0-9]{32}\.(pdf|doc|docx|ppt|pptx|xls|xlsx|txt|zip)$#D',
                          $assignmentFilePath
                        ) === 1;
                        $assignmentFile = dirname(__DIR__) . DIRECTORY_SEPARATOR .
                          str_replace('/', DIRECTORY_SEPARATOR, $assignmentFilePath);
                        ?>
                        <?php if ($assignmentFilePath === ''): ?>
                          Not provided
                        <?php elseif ($validAssignmentPath && is_file($assignmentFile)): ?>
                          <a href="../<?php echo coursePageEscape($assignmentFilePath); ?>">Download assignment file</a>
                        <?php else: ?>
                          <span class="text-muted">File unavailable</span>
                        <?php endif; ?>
                      </dd>

                      <dt class="col-sm-4">File Size Limit (MB)</dt>
                      <dd class="col-sm-8"><?php echo coursePageEscape($assignment['file_size_limit_mb'] ?: 'Not provided'); ?></dd>

                      <dt class="col-sm-4">Deadline</dt>
                      <dd class="col-sm-8"><?php echo coursePageEscape($assignment['deadline']); ?></dd>

                      <dt class="col-sm-4">Maximum Marks</dt>
                      <dd class="col-sm-8"><?php echo coursePageEscape($assignment['max_marks']); ?></dd>
                    </dl>

                    <?php if ($userRole === 'student'): ?>
                      <hr>
                      <?php if ($studentId !== null &&
                          in_array((int) $assignment['module_id'], $enrolledModuleIds, true)): ?>
                        <?php $latestSubmission = $submissionsByAssignment[(int) $assignment['assignment_id']] ?? null; ?>
                        <h4 class="h6">Your Submission</h4>

                        <?php if ($latestSubmission): ?>
                          <?php
                          $submissionPath = (string) $latestSubmission['file_path'];
                          $validSubmissionPath = preg_match(
                            '#^uploads/submissions/[a-f0-9]{32}\.(pdf|doc|docx|ppt|pptx|xls|xlsx|txt|zip)$#D',
                            $submissionPath
                          ) === 1;
                          $submissionFile = dirname(__DIR__) . DIRECTORY_SEPARATOR .
                            str_replace('/', DIRECTORY_SEPARATOR, $submissionPath);
                          ?>
                          <p class="mb-1">
                            <strong>Latest file:</strong>
                            <?php if ($validSubmissionPath && is_file($submissionFile)): ?>
                              <a href="../<?php echo coursePageEscape($submissionPath); ?>">Download your submission</a>
                            <?php else: ?>
                              <span class="text-muted">File unavailable</span>
                            <?php endif; ?>
                          </p>
                          <p class="mb-1">
                            <strong>Submitted:</strong>
                            <?php echo coursePageEscape($latestSubmission['submission_timestamp']); ?>
                          </p>
                          <p class="mb-1">
                            <strong>Submission status:</strong>
                            <?php echo (int) $latestSubmission['is_late'] === 1 ? 'Late' : 'On time'; ?>
                          </p>
                          <p class="mb-1">
                            <strong>Marks:</strong>
                            <?php echo $latestSubmission['marks_obtained'] === null
                              ? 'Not graded yet'
                              : coursePageEscape($latestSubmission['marks_obtained']); ?>
                          </p>
                          <?php if (is_string($latestSubmission['feedback']) && $latestSubmission['feedback'] !== ''): ?>
                            <p><strong>Feedback:</strong> <?php echo nl2br(coursePageEscape($latestSubmission['feedback'])); ?></p>
                          <?php endif; ?>
                        <?php else: ?>
                          <p class="schema-empty-state" role="status">You have not submitted this assignment yet.</p>
                        <?php endif; ?>

                        <form action="course.php?course_id=<?php echo (int) $course['course_id']; ?>"
                              method="post" enctype="multipart/form-data">
                          <input type="hidden" name="csrf_token"
                                 value="<?php echo coursePageEscape($_SESSION['course_submission_csrf']); ?>">
                          <input type="hidden" name="assignment_id"
                                 value="<?php echo (int) $assignment['assignment_id']; ?>">
                          <div class="form-group">
                            <label for="submissionFile<?php echo (int) $assignment['assignment_id']; ?>">Submission File</label>
                            <input class="form-control-file"
                                   id="submissionFile<?php echo (int) $assignment['assignment_id']; ?>"
                                   name="submission_file" type="file"
                                   accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip" required>
                            <small class="form-text text-muted">
                              Maximum file size:
                              <?php echo $assignment['file_size_limit_mb'] === null
                                ? '20'
                                : coursePageEscape($assignment['file_size_limit_mb']); ?> MB.
                            </small>
                          </div>
                          <button type="submit" class="btn btn-sm btn-primary">
                            <?php echo $latestSubmission ? 'Resubmit Assignment' : 'Submit Assignment'; ?>
                          </button>
                        </form>
                      <?php else: ?>
                        <p class="schema-empty-state" role="status">
                          You must be enrolled in this module to submit the assignment.
                        </p>
                      <?php endif; ?>
                    <?php endif; ?>

                  </section>
                <?php endforeach; ?>
              <?php endif; ?>
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
