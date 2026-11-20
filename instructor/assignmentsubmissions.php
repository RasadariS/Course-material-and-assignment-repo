<?php
declare(strict_types=1);

session_start();

function assignmentSubmissionsEscape($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, [
  'options' => ['min_range' => 1],
]);
$userRole = strtolower((string) ($_SESSION['role'] ?? ''));

if ($userId === false) {
  header('Location: ../login.php');
  exit;
}

if ($userRole !== 'instructor') {
  http_response_code(403);
  exit('This page is available to instructor accounts only.');
}

if (empty($_SESSION['instructor_grade_submission_csrf'])) {
  $_SESSION['instructor_grade_submission_csrf'] = bin2hex(random_bytes(32));
}

$submissions = [];
$formValues = [];
$errorMessage = '';
$successMessage = isset($_GET['saved']) && $_GET['saved'] === '1'
  ? 'Grade and feedback saved.'
  : '';

try {
  $pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

  $instructorLookup = $pdo->prepare(
    'SELECT instructor_id
     FROM instructors
     WHERE user_id = ?
     LIMIT 1'
  );
  $instructorLookup->execute([$userId]);
  $instructorId = $instructorLookup->fetchColumn();

  if ($instructorId === false) {
    http_response_code(404);
    $errorMessage = 'No instructor profile was found for this account.';
  } else {
    $instructorId = (int) $instructorId;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      $submittedToken = $_POST['csrf_token'] ?? '';

      if (!is_string($submittedToken) ||
          !hash_equals($_SESSION['instructor_grade_submission_csrf'], $submittedToken)) {
        http_response_code(400);
        $errorMessage = 'Your form expired. Reload the page and try again.';
      } elseif (($_POST['action'] ?? '') !== 'grade_submission') {
        http_response_code(400);
        $errorMessage = 'Choose a submission to grade and try again.';
      } else {
        $submissionId = filter_var(
          $_POST['submission_id'] ?? null,
          FILTER_VALIDATE_INT,
          ['options' => ['min_range' => 1]]
        );
        $marksText = is_string($_POST['marks_obtained'] ?? null)
          ? trim($_POST['marks_obtained'])
          : '';
        $feedback = is_string($_POST['feedback'] ?? null)
          ? trim($_POST['feedback'])
          : '';

        if ($submissionId !== false) {
          $formValues[$submissionId] = [
            'marks_obtained' => $marksText,
            'feedback' => $feedback,
          ];
        }

        if ($submissionId === false ||
            !preg_match('/^\d+(\.\d{1,2})?$/D', $marksText) ||
            strlen($feedback) > 65535) {
          http_response_code(400);
            $errorMessage = 'Enter a valid mark and feedback no longer than 65,535 bytes.';
        } else {
          $submissionLookup = $pdo->prepare(
            'SELECT a.max_marks
             FROM submissions sub
             INNER JOIN assignments a ON a.assignment_id = sub.assignment_id
             INNER JOIN instructor_modules im
               ON im.module_id = a.module_id AND im.instructor_id = ?
             WHERE sub.submission_id = ?
             LIMIT 1'
          );
          $submissionLookup->execute([$instructorId, $submissionId]);
          $assignment = $submissionLookup->fetch();

          if (!$assignment) {
            http_response_code(404);
            $errorMessage = 'This submission was not found in one of your assigned modules.';
          } elseif ((float) $marksText > (float) $assignment['max_marks']) {
            http_response_code(400);
            $errorMessage = 'The mark cannot be greater than the assignment maximum.';
          } else {
            $saveGrade = $pdo->prepare(
              'UPDATE submissions
               SET marks_obtained = ?, feedback = ?, graded_by_instructor_id = ?
               WHERE submission_id = ?'
            );
            $saveGrade->execute([
              $marksText,
              $feedback === '' ? null : $feedback,
              $instructorId,
              $submissionId,
            ]);

            header('Location: assignmentsubmissions.php?saved=1');
            exit;
          }
        }
      }
    }

    $submissionList = $pdo->prepare(
      'SELECT sub.submission_id, sub.file_path, sub.file_size_mb,
              sub.submission_timestamp, sub.is_late, sub.marks_obtained, sub.feedback,
              a.title AS assignment_title, a.deadline, a.max_marks,
              u.full_name AS student_name,
              m.module_code, m.module_name,
              c.course_code, c.course_name
       FROM submissions sub
       INNER JOIN students st ON st.student_id = sub.student_id
       INNER JOIN users u ON u.user_id = st.user_id
       INNER JOIN assignments a ON a.assignment_id = sub.assignment_id
       INNER JOIN modules m ON m.module_id = a.module_id
       INNER JOIN instructor_modules im
         ON im.module_id = m.module_id AND im.instructor_id = ?
       INNER JOIN semesters s ON s.semester_id = m.semester_id
       INNER JOIN courses c ON c.course_id = s.course_id
       ORDER BY sub.submission_timestamp DESC, sub.submission_id DESC'
    );
    $submissionList->execute([$instructorId]);
    $submissions = $submissionList->fetchAll();
  }
} catch (PDOException $error) {
  error_log('Instructor assignment grading database error: ' . $error->getMessage());
  http_response_code(500);
  $errorMessage = 'Submissions could not be loaded or graded. Check the database setup and PHP error log.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Assignment Submissions - DTET</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/common.css" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column min-vh-100">
  <?php
  $headerBasePath = '../';
  require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'header.php';
  ?>

  <main class="container-fluid px-4 py-4 flex-fill">
    <h1 class="page-title mb-3">Assignment Submissions and Grading</h1>

    <?php if ($errorMessage !== ''): ?>
      <div class="alert alert-danger" role="alert"><?php echo assignmentSubmissionsEscape($errorMessage); ?></div>
    <?php elseif ($successMessage !== ''): ?>
      <div class="alert alert-success" role="status"><?php echo assignmentSubmissionsEscape($successMessage); ?></div>
    <?php endif; ?>

    <section class="card shadow-sm" aria-labelledby="submissionsHeading">
      <div class="card-header bg-white">
        <h2 class="h5 mb-0" id="submissionsHeading">Student submissions</h2>
      </div>
      <?php if (!$submissions): ?>
        <div class="card-body">
          <p class="text-muted mb-0">No submissions have been received for your assigned modules.</p>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="thead-light">
              <tr>
                <th scope="col">Student</th>
                <th scope="col">Course and module</th>
                <th scope="col">Assignment</th>
                <th scope="col">Submitted</th>
                <th scope="col">Status</th>
                <th scope="col">File</th>
                <th scope="col">Grade and feedback</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($submissions as $submission): ?>
                <?php
                $submissionId = (int) $submission['submission_id'];
                $submissionPath = (string) $submission['file_path'];
                $validSubmissionPath = preg_match(
                  '#^uploads/submissions/[a-f0-9]{32}\.(pdf|doc|docx|ppt|pptx|xls|xlsx|txt|zip)$#D',
                  $submissionPath
                ) === 1;
                $submissionFile = dirname(__DIR__) . DIRECTORY_SEPARATOR .
                  str_replace('/', DIRECTORY_SEPARATOR, $submissionPath);
                $gradeValues = $formValues[$submissionId] ?? [
                  'marks_obtained' => $submission['marks_obtained'] === null
                    ? ''
                    : (string) $submission['marks_obtained'],
                  'feedback' => (string) ($submission['feedback'] ?? ''),
                ];
                ?>
                <tr>
                  <td><?php echo assignmentSubmissionsEscape($submission['student_name']); ?></td>
                  <td>
                    <?php echo assignmentSubmissionsEscape($submission['course_code'] . ' - ' . $submission['module_code']); ?>
                    <div class="text-muted"><?php echo assignmentSubmissionsEscape($submission['module_name']); ?></div>
                  </td>
                  <td>
                    <?php echo assignmentSubmissionsEscape($submission['assignment_title']); ?>
                    <div class="text-muted">
                      Maximum: <?php echo assignmentSubmissionsEscape($submission['max_marks']); ?> marks
                    </div>
                  </td>
                  <td>
                    <?php echo assignmentSubmissionsEscape($submission['submission_timestamp']); ?>
                    <div class="text-muted">Deadline: <?php echo assignmentSubmissionsEscape($submission['deadline']); ?></div>
                  </td>
                  <td><?php echo (int) $submission['is_late'] === 1 ? 'Late' : 'On time'; ?></td>
                  <td>
                    <?php if ($validSubmissionPath && is_file($submissionFile)): ?>
                      <a href="../<?php echo assignmentSubmissionsEscape($submissionPath); ?>">Download</a>
                    <?php else: ?>
                      <span class="text-muted">File unavailable</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <form method="post" action="assignmentsubmissions.php">
                      <input type="hidden" name="csrf_token"
                             value="<?php echo assignmentSubmissionsEscape($_SESSION['instructor_grade_submission_csrf']); ?>">
                      <input type="hidden" name="action" value="grade_submission">
                      <input type="hidden" name="submission_id" value="<?php echo $submissionId; ?>">
                      <div class="form-group">
                        <label for="marks<?php echo $submissionId; ?>">Marks</label>
                        <input class="form-control" id="marks<?php echo $submissionId; ?>"
                               name="marks_obtained" type="number" min="0"
                               max="<?php echo assignmentSubmissionsEscape($submission['max_marks']); ?>"
                               step="0.01" value="<?php echo assignmentSubmissionsEscape($gradeValues['marks_obtained']); ?>"
                               required>
                      </div>
                      <div class="form-group">
                        <label for="feedback<?php echo $submissionId; ?>">Feedback</label>
                        <textarea class="form-control" id="feedback<?php echo $submissionId; ?>"
                                  name="feedback" rows="2" maxlength="65535"><?php echo assignmentSubmissionsEscape($gradeValues['feedback']); ?></textarea>
                      </div>
                      <button class="btn btn-primary btn-sm" type="submit">Save Grade</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </main>

  <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
</body>
</html>
