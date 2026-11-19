<?php
declare(strict_types=1);

session_start();

function courseUploadEscape($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$userRole = strtolower((string) ($_SESSION['role'] ?? ''));

if ($userId === false) {
  header('Location: ../login.php');
  exit;
}

if ($userRole !== 'instructor') {
  http_response_code(403);
  exit('This page is available to instructor accounts only.');
}

if (empty($_SESSION['course_material_upload_csrf'])) {
  $_SESSION['course_material_upload_csrf'] = bin2hex(random_bytes(32));
}

$errorMessage = '';
$successMessage = isset($_GET['uploaded']) && $_GET['uploaded'] === '1' ? 'Course material uploaded successfully.' : '';
$instructorId = null;
$modules = [];
$materialTitle = '';
$weekNumber = '';
$selectedModuleId = '';

try {
  $pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

  $instructorLookup = $pdo->prepare('SELECT instructor_id FROM instructors WHERE user_id = ? LIMIT 1');
  $instructorLookup->execute([$userId]);
  $instructorId = $instructorLookup->fetchColumn();

  if ($instructorId === false) {
    $errorMessage = 'Your instructor profile could not be found.';
    $instructorId = null;
  } else {
    $instructorId = (int) $instructorId;
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && $instructorId !== null) {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if ($action !== 'upload_material') {
      $errorMessage = 'Assignment creation is not connected to the database yet.';
    } else {
      $submittedToken = $_POST['csrf_token'] ?? '';

      if (!is_string($submittedToken) ||
          !hash_equals($_SESSION['course_material_upload_csrf'], $submittedToken)) {
        $errorMessage = 'Your form expired. Reload the page and try again.';
      } else {
        $selectedModuleId = is_string($_POST['module_id'] ?? null) ? trim($_POST['module_id']) : '';
        $materialTitle = is_string($_POST['title'] ?? null) ? trim($_POST['title']) : '';
        $weekNumber = is_string($_POST['week_number'] ?? null) ? trim($_POST['week_number']) : '';
        $moduleId = filter_var($selectedModuleId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $week = filter_var($weekNumber, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($moduleId === false || $materialTitle === '' || strlen($materialTitle) > 150 || $week === false) {
          $errorMessage = 'Choose a module, enter a title, and enter a week number greater than zero.';
        } else {
          $assignedModule = $pdo->prepare(
            'SELECT module_id
             FROM instructor_modules
             WHERE instructor_id = ? AND module_id = ?
             LIMIT 1'
          );
          $assignedModule->execute([$instructorId, $moduleId]);

          if ($assignedModule->fetchColumn() === false) {
            $errorMessage = 'Choose a module assigned to your instructor account.';
          } else {
            $file = $_FILES['material_file'] ?? null;

            if (!is_array($file) ||
                !isset($file['error'], $file['name'], $file['size'], $file['tmp_name']) ||
                !is_int($file['error']) ||
                !is_string($file['name']) ||
                !is_int($file['size']) ||
                !is_string($file['tmp_name'])) {
              $errorMessage = 'Choose a course material file and try again.';
            } elseif ($file['error'] !== UPLOAD_ERR_OK) {
              $errorMessage = $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
                ? 'The file is larger than the server allows.'
                : 'The file could not be uploaded. Choose a file and try again.';
            } elseif ($file['size'] < 1 || $file['size'] > 20 * 1024 * 1024 ||
                      !is_uploaded_file($file['tmp_name'])) {
              $errorMessage = 'Choose a valid file smaller than 20 MB.';
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
                $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'course_materials';

                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                  $errorMessage = 'The course material folder could not be created.';
                } else {
                  $relativeFilePath = 'uploads/course_materials/' . bin2hex(random_bytes(16)) . '.' . $extension;
                  $absoluteFilePath = dirname(__DIR__) . DIRECTORY_SEPARATOR .
                    str_replace('/', DIRECTORY_SEPARATOR, $relativeFilePath);

                  if (!move_uploaded_file($file['tmp_name'], $absoluteFilePath)) {
                    $errorMessage = 'The course material could not be saved on the server.';
                  } else {
                    try {
                      $saveMaterial = $pdo->prepare(
                        'INSERT INTO course_materials
                          (module_id, uploaded_by_instructor_id, title, week_number, file_path, file_size_mb)
                         VALUES (?, ?, ?, ?, ?, ?)'
                      );
                      $saveMaterial->execute([
                        $moduleId,
                        $instructorId,
                        $materialTitle,
                        $week,
                        $relativeFilePath,
                        round($file['size'] / (1024 * 1024), 2),
                      ]);
                    } catch (PDOException $error) {
                      if (is_file($absoluteFilePath) && !unlink($absoluteFilePath)) {
                        error_log('Could not remove course material after a database error: ' . $absoluteFilePath);
                      }
                      throw $error;
                    }

                    header('Location: courseupload.php?uploaded=1');
                    exit;
                  }
                }
              }
            }
          }
        }
      }
    }
  }

  if ($instructorId !== null) {
    $moduleLookup = $pdo->prepare(
      'SELECT m.module_id, m.module_code, m.module_name, s.semester_number, c.course_code
       FROM instructor_modules im
       INNER JOIN modules m ON m.module_id = im.module_id
       INNER JOIN semesters s ON s.semester_id = m.semester_id
       INNER JOIN courses c ON c.course_id = s.course_id
       WHERE im.instructor_id = ?
       ORDER BY c.course_code, s.semester_number, m.module_code'
    );
    $moduleLookup->execute([$instructorId]);
    $modules = $moduleLookup->fetchAll();
  }
} catch (PDOException $error) {
  error_log('Course material upload database error: ' . $error->getMessage());
  http_response_code(500);
  $errorMessage = 'Course materials could not be loaded or saved. Check the database setup and PHP error log.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Instructor Course Content - DTET</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/common.css" rel="stylesheet">
</head>
<body class="bg-white page-course-courseupload-php">
  <?php
  $headerBasePath = '../';
  require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'header.php';
  ?>

  <main class="container-fluid px-4 py-4">
    <h1 class="course-title">Course Content &amp; Assignments</h1>

    <?php if ($errorMessage !== ''): ?>
      <div class="alert alert-danger" role="alert"><?php echo courseUploadEscape($errorMessage); ?></div>
    <?php elseif ($successMessage !== ''): ?>
      <div class="alert alert-success" role="status"><?php echo courseUploadEscape($successMessage); ?></div>
    <?php endif; ?>

    <div class="row">
      <section class="col-md-6 panel-column" aria-labelledby="course-heading">
        <h2 class="panel-tab active" id="course-heading">Course Materials</h2>
        <form class="week-body" id="materialForm" method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?php echo courseUploadEscape($_SESSION['course_material_upload_csrf']); ?>">
          <input type="hidden" name="action" value="upload_material">
          <div class="form-group">
            <label for="materialModule">Module</label>
            <select class="form-control" id="materialModule" name="module_id" required <?php echo !$modules ? 'disabled' : ''; ?>>
              <option value="">Select an assigned module</option>
              <?php foreach ($modules as $module): ?>
                <option value="<?php echo (int) $module['module_id']; ?>" <?php echo $selectedModuleId === (string) $module['module_id'] ? 'selected' : ''; ?>>
                  <?php echo courseUploadEscape($module['module_code'] . ' - ' . $module['module_name'] . ' (' . $module['course_code'] . ', Semester ' . $module['semester_number'] . ')'); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (!$modules): ?>
              <small class="form-text text-muted">You need an assigned module before uploading course materials.</small>
            <?php endif; ?>
          </div>
          <div class="form-group">
            <label for="materialTitle">Material Title</label>
            <input class="form-control" id="materialTitle" name="title" type="text" maxlength="150" required value="<?php echo courseUploadEscape($materialTitle); ?>">
          </div>
          <div class="form-group">
            <label for="materialWeek">Week Number</label>
            <input class="form-control" id="materialWeek" name="week_number" type="number" min="1" required value="<?php echo courseUploadEscape($weekNumber); ?>">
          </div>
          <div class="form-group">
            <label for="materialFile">Course Material File</label>
            <input class="form-control-file" id="materialFile" name="material_file" type="file" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip" required>
            <p class="file-status mb-0" id="materialFileStatus" aria-live="polite"></p>
          </div>
          <button class="btn btn-primary btn-sm" type="submit" <?php echo !$modules ? 'disabled' : ''; ?>>Upload Material</button>
          <small class="form-text text-muted">PDF, Office, text, and ZIP files up to 20 MB are accepted.</small>
        </form>
      </section>

      <section class="col-md-6 panel-column assignment-column" aria-labelledby="assignment-heading">
        <h2 class="panel-tab" id="assignment-heading">Create Assignment</h2>
        <div class="assignment-panel">
          <form class="assignment-details" id="assignmentForm" action="#" method="post" enctype="multipart/form-data">
            <div class="form-group">
              <label for="assignmentModule">Module</label>
              <select class="form-control" id="assignmentModule" name="module_id" required disabled>
                <option value="" selected>Assignment form is not connected yet</option>
              </select>
              <small class="form-text text-muted">Assignment creation is not connected to the database yet.</small>
            </div>
            <div class="form-group">
              <label for="assignmentTitle">Title</label>
              <input class="form-control" id="assignmentTitle" name="title" type="text" required>
            </div>
            <div class="form-group">
              <label for="assignmentInstructions">Instructions</label>
              <textarea class="form-control" id="assignmentInstructions" name="instructions" rows="3" required></textarea>
            </div>
            <div class="form-row">
              <div class="form-group col-6">
                <label for="assignmentWeek">Week Number</label>
                <input class="form-control" id="assignmentWeek" name="week_number" type="number" min="1" required>
              </div>
              <div class="form-group col-6">
                <label for="assignmentDeadline">Deadline</label>
                <input class="form-control" id="assignmentDeadline" name="deadline" type="datetime-local" required>
              </div>
              <div class="form-group col-6">
                <label for="fileSizeLimit">File Size Limit (MB)</label>
                <input class="form-control" id="fileSizeLimit" name="file_size_limit_mb" type="number" min="0.01" step="0.01" required>
              </div>
              <div class="form-group col-6">
                <label for="maximumMarks">Maximum Marks</label>
                <input class="form-control" id="maximumMarks" name="max_marks" type="number" min="0" step="0.01" required>
              </div>
            </div>
            <div class="form-group">
              <label for="assignmentFile">Assignment File (optional)</label>
              <input class="form-control-file" id="assignmentFile" name="assignment_file" type="file">
              <p class="file-status mb-0" id="assignmentFileStatus" aria-live="polite"></p>
            </div>
            <button class="btn btn-primary btn-sm" type="submit" disabled>Create Assignment</button>
          </form>
        </div>
      </section>
    </div>
  </main>

  <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
  <script src="../assets/js/common.js" data-page="course/courseupload.php"></script>
</body>
</html>
