<?php
declare(strict_types=1);

session_start();

function courseUploadEscape($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function courseUploadAllowedMimeTypes(): array
{
  return [
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
}

function courseUploadStoreFile($file, string $folder, bool $optional): array
{
  if ($file === null && $optional) {
    return ['error' => '', 'path' => null, 'absolute_path' => null, 'size' => 0];
  }

  if (!is_array($file) ||
      !isset($file['error'], $file['name'], $file['size'], $file['tmp_name']) ||
      !is_int($file['error']) ||
      !is_string($file['name']) ||
      !is_int($file['size']) ||
      !is_string($file['tmp_name'])) {
    return ['error' => 'Choose a valid file and try again.', 'path' => null, 'absolute_path' => null, 'size' => 0];
  }

  if ($file['error'] === UPLOAD_ERR_NO_FILE && $optional) {
    return ['error' => '', 'path' => null, 'absolute_path' => null, 'size' => 0];
  }

  if ($file['error'] !== UPLOAD_ERR_OK) {
    $message = $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
      ? 'The file is larger than the server allows.'
      : 'The file could not be uploaded. Choose a file and try again.';
    return ['error' => $message, 'path' => null, 'absolute_path' => null, 'size' => 0];
  }

  if ($file['size'] < 1 ||
      $file['size'] > 20 * 1024 * 1024 ||
      !is_uploaded_file($file['tmp_name'])) {
    return ['error' => 'Choose a valid file smaller than 20 MB.', 'path' => null, 'absolute_path' => null, 'size' => 0];
  }

  $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  $allowedMimeTypes = courseUploadAllowedMimeTypes();
  $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);

  if (!isset($allowedMimeTypes[$extension]) ||
      !is_string($mimeType) ||
      !in_array($mimeType, $allowedMimeTypes[$extension], true)) {
    return ['error' => 'Use a PDF, Word, PowerPoint, Excel, text, or ZIP file.', 'path' => null, 'absolute_path' => null, 'size' => 0];
  }

  $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR .
    'uploads' . DIRECTORY_SEPARATOR . $folder;

  if (!is_dir($uploadDirectory) &&
      !mkdir($uploadDirectory, 0755, true) &&
      !is_dir($uploadDirectory)) {
    return ['error' => 'The upload folder could not be created.', 'path' => null, 'absolute_path' => null, 'size' => 0];
  }

  $relativePath = 'uploads/' . $folder . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
  $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR .
    str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

  if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
    return ['error' => 'The file could not be saved on the server.', 'path' => null, 'absolute_path' => null, 'size' => 0];
  }

  return [
    'error' => '',
    'path' => $relativePath,
    'absolute_path' => $absolutePath,
    'size' => $file['size'],
  ];
}

function courseUploadRemoveStoredFile(?string $relativePath, string $folder): bool
{
  if ($relativePath === null || $relativePath === '') {
    return true;
  }

  $pattern = '#^uploads/' . preg_quote($folder, '#') .
    '/[a-f0-9]{32}\.(pdf|doc|docx|ppt|pptx|xls|xlsx|txt|zip)$#D';

  if (preg_match($pattern, $relativePath) !== 1) {
    error_log('Refusing to remove a file with an unexpected stored path: ' . $relativePath);
    return false;
  }

  $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR .
    str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

  if (!is_file($absolutePath)) {
    return true;
  }

  if (!unlink($absolutePath)) {
    error_log('Could not remove stored upload: ' . $absolutePath);
    return false;
  }

  return true;
}

function courseUploadStoredFileExists(?string $relativePath, string $folder): bool
{
  if ($relativePath === null) {
    return false;
  }

  $pattern = '#^uploads/' . preg_quote($folder, '#') .
    '/[a-f0-9]{32}\.(pdf|doc|docx|ppt|pptx|xls|xlsx|txt|zip)$#D';

  if (preg_match($pattern, $relativePath) !== 1) {
    return false;
  }

  $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR .
    str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
  return is_file($absolutePath);
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
$successMessage = '';
if (isset($_GET['uploaded']) && $_GET['uploaded'] === '1') {
  $successMessage = 'Course material uploaded successfully.';
} elseif (isset($_GET['assignment_created']) && $_GET['assignment_created'] === '1') {
  $successMessage = 'Assignment created successfully.';
} elseif (isset($_GET['material_saved']) && $_GET['material_saved'] === '1') {
  $successMessage = 'Course material updated successfully.';
} elseif (isset($_GET['assignment_saved']) && $_GET['assignment_saved'] === '1') {
  $successMessage = 'Assignment updated successfully.';
} elseif (isset($_GET['material_deleted']) && $_GET['material_deleted'] === '1') {
  $successMessage = 'Course material deleted successfully.';
} elseif (isset($_GET['assignment_deleted']) && $_GET['assignment_deleted'] === '1') {
  $successMessage = 'Assignment deleted successfully.';
}
if (isset($_GET['file_cleanup_failed']) && $_GET['file_cleanup_failed'] === '1') {
  $successMessage .= ' The database record changed, but an old file could not be removed.';
}
$instructorId = null;
$modules = [];
$courseMaterials = [];
$assignments = [];
$materialTitle = '';
$weekNumber = '';
$selectedModuleId = '';
$editingMaterialId = 0;
$materialCurrentFilePath = null;
$assignmentModuleId = '';
$assignmentTitle = '';
$assignmentInstructions = '';
$assignmentWeekNumber = '';
$assignmentDeadline = '';
$assignmentFileSizeLimit = '';
$assignmentMaxMarks = '';
$editingAssignmentId = 0;
$assignmentCurrentFilePath = null;

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

    if ($action === 'create_assignment') {
      $submittedToken = $_POST['csrf_token'] ?? '';

      if (!is_string($submittedToken) ||
          !hash_equals($_SESSION['course_material_upload_csrf'], $submittedToken)) {
        $errorMessage = 'Your form expired. Reload the page and try again.';
      } else {
        $assignmentModuleId = is_string($_POST['module_id'] ?? null) ? trim($_POST['module_id']) : '';
        $assignmentTitle = is_string($_POST['title'] ?? null) ? trim($_POST['title']) : '';
        $assignmentInstructions = is_string($_POST['instructions'] ?? null) ? trim($_POST['instructions']) : '';
        $assignmentWeekNumber = is_string($_POST['week_number'] ?? null) ? trim($_POST['week_number']) : '';
        $assignmentDeadline = is_string($_POST['deadline'] ?? null) ? trim($_POST['deadline']) : '';
        $assignmentFileSizeLimit = is_string($_POST['file_size_limit_mb'] ?? null)
          ? trim($_POST['file_size_limit_mb'])
          : '';
        $assignmentMaxMarks = is_string($_POST['max_marks'] ?? null) ? trim($_POST['max_marks']) : '';

        $assignmentModuleIdValue = filter_var(
          $assignmentModuleId,
          FILTER_VALIDATE_INT,
          ['options' => ['min_range' => 1]]
        );
        $assignmentWeek = filter_var(
          $assignmentWeekNumber,
          FILTER_VALIDATE_INT,
          ['options' => ['min_range' => 1]]
        );
        $fileSizeLimit = filter_var($assignmentFileSizeLimit, FILTER_VALIDATE_FLOAT);
        $maximumMarks = filter_var($assignmentMaxMarks, FILTER_VALIDATE_FLOAT);
        $deadlineDate = DateTime::createFromFormat('Y-m-d\TH:i', $assignmentDeadline);

        if ($assignmentModuleIdValue === false ||
            $assignmentTitle === '' ||
            strlen($assignmentTitle) > 150 ||
            $assignmentInstructions === '' ||
            strlen($assignmentInstructions) > 65535 ||
            $assignmentWeek === false ||
            $deadlineDate === false ||
            $deadlineDate->format('Y-m-d\TH:i') !== $assignmentDeadline ||
            $fileSizeLimit === false ||
            !preg_match('/^\d+(\.\d{1,2})?$/D', $assignmentFileSizeLimit) ||
            $fileSizeLimit <= 0 ||
            $fileSizeLimit > 9999.99 ||
            $maximumMarks === false ||
            !preg_match('/^\d+(\.\d{1,2})?$/D', $assignmentMaxMarks) ||
            $maximumMarks < 0 ||
            $maximumMarks > 999.99) {
          $errorMessage = 'Enter a module, title, instructions, valid week, deadline, file size limit, and maximum marks.';
        } else {
          $assignedModule = $pdo->prepare(
            'SELECT module_id
             FROM instructor_modules
             WHERE instructor_id = ? AND module_id = ?
             LIMIT 1'
          );
          $assignedModule->execute([$instructorId, $assignmentModuleIdValue]);

          if ($assignedModule->fetchColumn() === false) {
            $errorMessage = 'Choose a module assigned to your instructor account.';
          } else {
            $uploadedFile = courseUploadStoreFile($_FILES['assignment_file'] ?? null, 'assignments', true);
            $errorMessage = $uploadedFile['error'];
            $assignmentFilePath = $uploadedFile['path'];
            $assignmentAbsolutePath = $uploadedFile['absolute_path'];

            if ($errorMessage === '') {
              try {
                $saveAssignment = $pdo->prepare(
                  'INSERT INTO assignments
                    (module_id, created_by_instructor_id, title, instructions, week_number,
                     file_path, file_size_limit_mb, deadline, max_marks)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $saveAssignment->execute([
                  $assignmentModuleIdValue,
                  $instructorId,
                  $assignmentTitle,
                  $assignmentInstructions,
                  $assignmentWeek,
                  $assignmentFilePath,
                  $fileSizeLimit,
                  $deadlineDate->format('Y-m-d H:i:s'),
                  $maximumMarks,
                ]);
              } catch (PDOException $error) {
                courseUploadRemoveStoredFile($assignmentFilePath, 'assignments');
                throw $error;
              }

              header('Location: courseupload.php?assignment_created=1');
              exit;
            }
          }
        }
      }
    } elseif ($action === 'update_material') {
      $submittedToken = $_POST['csrf_token'] ?? '';

      if (!is_string($submittedToken) ||
          !hash_equals($_SESSION['course_material_upload_csrf'], $submittedToken)) {
        $errorMessage = 'Your form expired. Reload the page and try again.';
      } else {
        $materialId = filter_var(
          $_POST['material_id'] ?? null,
          FILTER_VALIDATE_INT,
          ['options' => ['min_range' => 1]]
        );
        $editingMaterialId = $materialId === false ? 0 : $materialId;
        $selectedModuleId = is_string($_POST['module_id'] ?? null) ? trim($_POST['module_id']) : '';
        $materialTitle = is_string($_POST['title'] ?? null) ? trim($_POST['title']) : '';
        $weekNumber = is_string($_POST['week_number'] ?? null) ? trim($_POST['week_number']) : '';
        $moduleId = filter_var($selectedModuleId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $week = filter_var($weekNumber, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($moduleId === false ||
            $materialTitle === '' ||
            strlen($materialTitle) > 150 ||
            $week === false ||
            $materialId === false) {
          $errorMessage = 'Choose a module, enter a title, and enter a valid week number.';
        } else {
          $existingMaterial = $pdo->prepare(
            'SELECT cm.file_path, cm.file_size_mb
             FROM course_materials cm
             INNER JOIN instructor_modules im ON im.module_id = cm.module_id
             WHERE cm.material_id = ? AND cm.uploaded_by_instructor_id = ?
               AND im.instructor_id = ?
             LIMIT 1'
          );
          $existingMaterial->execute([$materialId, $instructorId, $instructorId]);
          $oldMaterial = $existingMaterial->fetch();

          $assignedModule = $pdo->prepare(
            'SELECT module_id
             FROM instructor_modules
             WHERE instructor_id = ? AND module_id = ?
             LIMIT 1'
          );
          $assignedModule->execute([$instructorId, $moduleId]);

          if (!$oldMaterial) {
            http_response_code(404);
            $errorMessage = 'Course material was not found in your assigned modules.';
          } elseif ($assignedModule->fetchColumn() === false) {
            $errorMessage = 'Choose a module assigned to your instructor account.';
          } else {
            $uploadedFile = courseUploadStoreFile($_FILES['material_file'] ?? null, 'course_materials', true);
            $errorMessage = $uploadedFile['error'];
            $materialCurrentFilePath = $oldMaterial['file_path'];

            if ($errorMessage === '') {
              $newFilePath = $uploadedFile['path'] ?? $oldMaterial['file_path'];
              $newFileSize = $uploadedFile['path'] === null
                ? $oldMaterial['file_size_mb']
                : round($uploadedFile['size'] / (1024 * 1024), 2);

              try {
                $updateMaterial = $pdo->prepare(
                  'UPDATE course_materials
                   SET module_id = ?, title = ?, week_number = ?, file_path = ?, file_size_mb = ?
                   WHERE material_id = ? AND uploaded_by_instructor_id = ?'
                );
                $updateMaterial->execute([
                  $moduleId,
                  $materialTitle,
                  $week,
                  $newFilePath,
                  $newFileSize,
                  $materialId,
                  $instructorId,
                ]);
              } catch (PDOException $error) {
                courseUploadRemoveStoredFile($uploadedFile['path'], 'course_materials');
                throw $error;
              }

              $fileCleanupFailed = $uploadedFile['path'] !== null &&
                !courseUploadRemoveStoredFile($oldMaterial['file_path'], 'course_materials');
              header('Location: courseupload.php?material_saved=1' .
                ($fileCleanupFailed ? '&file_cleanup_failed=1' : ''));
              exit;
            }
          }
        }
      }
    } elseif ($action === 'delete_material') {
      $submittedToken = $_POST['csrf_token'] ?? '';

      if (!is_string($submittedToken) ||
          !hash_equals($_SESSION['course_material_upload_csrf'], $submittedToken)) {
        $errorMessage = 'Your form expired. Reload the page and try again.';
      } else {
        $materialId = filter_var(
          $_POST['material_id'] ?? null,
          FILTER_VALIDATE_INT,
          ['options' => ['min_range' => 1]]
        );

        if ($materialId === false) {
          $errorMessage = 'Choose a valid course material to delete.';
        } else {
          $existingMaterial = $pdo->prepare(
            'SELECT cm.file_path
             FROM course_materials cm
             INNER JOIN instructor_modules im ON im.module_id = cm.module_id
             WHERE cm.material_id = ? AND cm.uploaded_by_instructor_id = ?
               AND im.instructor_id = ?
             LIMIT 1'
          );
          $existingMaterial->execute([$materialId, $instructorId, $instructorId]);
          $oldMaterial = $existingMaterial->fetch();

          if (!$oldMaterial) {
            http_response_code(404);
            $errorMessage = 'Course material was not found in your assigned modules.';
          } else {
            $deleteMaterial = $pdo->prepare(
              'DELETE FROM course_materials
               WHERE material_id = ? AND uploaded_by_instructor_id = ?'
            );
            $deleteMaterial->execute([$materialId, $instructorId]);
            $fileCleanupFailed = !courseUploadRemoveStoredFile($oldMaterial['file_path'], 'course_materials');
            header('Location: courseupload.php?material_deleted=1' .
              ($fileCleanupFailed ? '&file_cleanup_failed=1' : ''));
            exit;
          }
        }
      }
    } elseif ($action === 'update_assignment') {
      $submittedToken = $_POST['csrf_token'] ?? '';

      if (!is_string($submittedToken) ||
          !hash_equals($_SESSION['course_material_upload_csrf'], $submittedToken)) {
        $errorMessage = 'Your form expired. Reload the page and try again.';
      } else {
        $assignmentId = filter_var(
          $_POST['assignment_id'] ?? null,
          FILTER_VALIDATE_INT,
          ['options' => ['min_range' => 1]]
        );
        $editingAssignmentId = $assignmentId === false ? 0 : $assignmentId;
        $assignmentModuleId = is_string($_POST['module_id'] ?? null) ? trim($_POST['module_id']) : '';
        $assignmentTitle = is_string($_POST['title'] ?? null) ? trim($_POST['title']) : '';
        $assignmentInstructions = is_string($_POST['instructions'] ?? null) ? trim($_POST['instructions']) : '';
        $assignmentWeekNumber = is_string($_POST['week_number'] ?? null) ? trim($_POST['week_number']) : '';
        $assignmentDeadline = is_string($_POST['deadline'] ?? null) ? trim($_POST['deadline']) : '';
        $assignmentFileSizeLimit = is_string($_POST['file_size_limit_mb'] ?? null)
          ? trim($_POST['file_size_limit_mb'])
          : '';
        $assignmentMaxMarks = is_string($_POST['max_marks'] ?? null) ? trim($_POST['max_marks']) : '';

        $moduleId = filter_var($assignmentModuleId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $week = filter_var($assignmentWeekNumber, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $fileSizeLimit = filter_var($assignmentFileSizeLimit, FILTER_VALIDATE_FLOAT);
        $maximumMarks = filter_var($assignmentMaxMarks, FILTER_VALIDATE_FLOAT);
        $deadlineDate = DateTime::createFromFormat('Y-m-d\TH:i', $assignmentDeadline);

        if ($assignmentId === false ||
            $moduleId === false ||
            $assignmentTitle === '' ||
            strlen($assignmentTitle) > 150 ||
            $assignmentInstructions === '' ||
            strlen($assignmentInstructions) > 65535 ||
            $week === false ||
            $deadlineDate === false ||
            $deadlineDate->format('Y-m-d\TH:i') !== $assignmentDeadline ||
            $fileSizeLimit === false ||
            !preg_match('/^\d+(\.\d{1,2})?$/D', $assignmentFileSizeLimit) ||
            $fileSizeLimit <= 0 ||
            $fileSizeLimit > 9999.99 ||
            $maximumMarks === false ||
            !preg_match('/^\d+(\.\d{1,2})?$/D', $assignmentMaxMarks) ||
            $maximumMarks < 0 ||
            $maximumMarks > 999.99) {
          $errorMessage = 'Enter a module, title, instructions, valid week, deadline, file size limit, and maximum marks.';
        } else {
          $existingAssignment = $pdo->prepare(
            'SELECT a.file_path,
                    (SELECT COUNT(*) FROM submissions sub WHERE sub.assignment_id = a.assignment_id) AS submission_count
             FROM assignments a
             INNER JOIN instructor_modules im ON im.module_id = a.module_id
             WHERE a.assignment_id = ? AND a.created_by_instructor_id = ?
               AND im.instructor_id = ?
             LIMIT 1'
          );
          $existingAssignment->execute([$assignmentId, $instructorId, $instructorId]);
          $oldAssignment = $existingAssignment->fetch();

          $assignedModule = $pdo->prepare(
            'SELECT module_id
             FROM instructor_modules
             WHERE instructor_id = ? AND module_id = ?
             LIMIT 1'
          );
          $assignedModule->execute([$instructorId, $moduleId]);

          if (!$oldAssignment) {
            http_response_code(404);
            $errorMessage = 'Assignment was not found in your assigned modules.';
          } elseif ((int) $oldAssignment['submission_count'] > 0) {
            http_response_code(409);
            $errorMessage = 'Assignments can only be edited before a student submits.';
          } elseif ($assignedModule->fetchColumn() === false) {
            $errorMessage = 'Choose a module assigned to your instructor account.';
          } else {
            $uploadedFile = courseUploadStoreFile($_FILES['assignment_file'] ?? null, 'assignments', true);
            $errorMessage = $uploadedFile['error'];
            $assignmentCurrentFilePath = $oldAssignment['file_path'];

            if ($errorMessage === '') {
              $newFilePath = $uploadedFile['path'] ?? $oldAssignment['file_path'];

              try {
                $updateAssignment = $pdo->prepare(
                  'UPDATE assignments
                   SET module_id = ?, title = ?, instructions = ?, week_number = ?,
                       file_path = ?, file_size_limit_mb = ?, deadline = ?, max_marks = ?
                   WHERE assignment_id = ? AND created_by_instructor_id = ?'
                );
                $updateAssignment->execute([
                  $moduleId,
                  $assignmentTitle,
                  $assignmentInstructions,
                  $week,
                  $newFilePath,
                  $fileSizeLimit,
                  $deadlineDate->format('Y-m-d H:i:s'),
                  $maximumMarks,
                  $assignmentId,
                  $instructorId,
                ]);
              } catch (PDOException $error) {
                courseUploadRemoveStoredFile($uploadedFile['path'], 'assignments');
                throw $error;
              }

              $fileCleanupFailed = $uploadedFile['path'] !== null &&
                !courseUploadRemoveStoredFile($oldAssignment['file_path'], 'assignments');
              header('Location: courseupload.php?assignment_saved=1' .
                ($fileCleanupFailed ? '&file_cleanup_failed=1' : ''));
              exit;
            }
          }
        }
      }
    } elseif ($action === 'delete_assignment') {
      $submittedToken = $_POST['csrf_token'] ?? '';

      if (!is_string($submittedToken) ||
          !hash_equals($_SESSION['course_material_upload_csrf'], $submittedToken)) {
        $errorMessage = 'Your form expired. Reload the page and try again.';
      } else {
        $assignmentId = filter_var(
          $_POST['assignment_id'] ?? null,
          FILTER_VALIDATE_INT,
          ['options' => ['min_range' => 1]]
        );

        if ($assignmentId === false) {
          $errorMessage = 'Choose a valid assignment to delete.';
        } else {
          $existingAssignment = $pdo->prepare(
            'SELECT a.file_path,
                    (SELECT COUNT(*) FROM submissions sub WHERE sub.assignment_id = a.assignment_id) AS submission_count
             FROM assignments a
             INNER JOIN instructor_modules im ON im.module_id = a.module_id
             WHERE a.assignment_id = ? AND a.created_by_instructor_id = ?
               AND im.instructor_id = ?
             LIMIT 1'
          );
          $existingAssignment->execute([$assignmentId, $instructorId, $instructorId]);
          $oldAssignment = $existingAssignment->fetch();

          if (!$oldAssignment) {
            http_response_code(404);
            $errorMessage = 'Assignment was not found in your assigned modules.';
          } elseif ((int) $oldAssignment['submission_count'] > 0) {
            http_response_code(409);
            $errorMessage = 'Assignments can only be deleted before a student submits.';
          } else {
            $deleteAssignment = $pdo->prepare(
              'DELETE FROM assignments
               WHERE assignment_id = ? AND created_by_instructor_id = ?'
            );
            $deleteAssignment->execute([$assignmentId, $instructorId]);
            $fileCleanupFailed = !courseUploadRemoveStoredFile($oldAssignment['file_path'], 'assignments');
            header('Location: courseupload.php?assignment_deleted=1' .
              ($fileCleanupFailed ? '&file_cleanup_failed=1' : ''));
            exit;
          }
        }
      }
    } elseif ($action !== 'upload_material') {
      $errorMessage = 'Choose a valid form action and try again.';
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
            $uploadedFile = courseUploadStoreFile($_FILES['material_file'] ?? null, 'course_materials', false);
            $errorMessage = $uploadedFile['error'];

            if ($errorMessage === '') {
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
                  $uploadedFile['path'],
                  round($uploadedFile['size'] / (1024 * 1024), 2),
                ]);
              } catch (PDOException $error) {
                courseUploadRemoveStoredFile($uploadedFile['path'], 'course_materials');
                throw $error;
              }

              header('Location: courseupload.php?uploaded=1');
              exit;
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

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit_material'])) {
      $materialId = filter_var(
        $_GET['edit_material'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
      );

      if ($materialId === false) {
        $errorMessage = 'Choose a valid course material to edit.';
      } else {
        $editMaterial = $pdo->prepare(
          'SELECT cm.material_id, cm.module_id, cm.title, cm.week_number, cm.file_path
           FROM course_materials cm
           INNER JOIN instructor_modules im ON im.module_id = cm.module_id
           WHERE cm.material_id = ? AND cm.uploaded_by_instructor_id = ?
             AND im.instructor_id = ?
           LIMIT 1'
        );
        $editMaterial->execute([$materialId, $instructorId, $instructorId]);
        $material = $editMaterial->fetch();

        if (!$material) {
          http_response_code(404);
          $errorMessage = 'Course material was not found in your assigned modules.';
        } else {
          $editingMaterialId = (int) $material['material_id'];
          $selectedModuleId = (string) $material['module_id'];
          $materialTitle = (string) $material['title'];
          $weekNumber = (string) $material['week_number'];
          $materialCurrentFilePath = $material['file_path'];
        }
      }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit_assignment'])) {
      $assignmentId = filter_var(
        $_GET['edit_assignment'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
      );

      if ($assignmentId === false) {
        $errorMessage = 'Choose a valid assignment to edit.';
      } else {
        $editAssignment = $pdo->prepare(
          'SELECT a.assignment_id, a.module_id, a.title, a.instructions, a.week_number,
                  a.file_path, a.file_size_limit_mb, a.deadline, a.max_marks,
                  (SELECT COUNT(*) FROM submissions sub WHERE sub.assignment_id = a.assignment_id) AS submission_count
           FROM assignments a
           INNER JOIN instructor_modules im ON im.module_id = a.module_id
           WHERE a.assignment_id = ? AND a.created_by_instructor_id = ?
             AND im.instructor_id = ?
           LIMIT 1'
        );
        $editAssignment->execute([$assignmentId, $instructorId, $instructorId]);
        $assignment = $editAssignment->fetch();

        if (!$assignment) {
          http_response_code(404);
          $errorMessage = 'Assignment was not found in your assigned modules.';
        } elseif ((int) $assignment['submission_count'] > 0) {
          http_response_code(409);
          $errorMessage = 'Assignments can only be edited before a student submits.';
        } else {
          $editingAssignmentId = (int) $assignment['assignment_id'];
          $assignmentModuleId = (string) $assignment['module_id'];
          $assignmentTitle = (string) $assignment['title'];
          $assignmentInstructions = (string) ($assignment['instructions'] ?? '');
          $assignmentWeekNumber = (string) $assignment['week_number'];
          $assignmentDeadline = str_replace(' ', 'T', substr((string) $assignment['deadline'], 0, 16));
          $assignmentFileSizeLimit = $assignment['file_size_limit_mb'] === null
            ? '20'
            : (string) $assignment['file_size_limit_mb'];
          $assignmentMaxMarks = (string) $assignment['max_marks'];
          $assignmentCurrentFilePath = $assignment['file_path'];
        }
      }
    }

    $materialList = $pdo->prepare(
      'SELECT cm.material_id, cm.module_id, cm.title, cm.week_number, cm.file_path,
              m.module_code, m.module_name
       FROM course_materials cm
       INNER JOIN modules m ON m.module_id = cm.module_id
       INNER JOIN instructor_modules im ON im.module_id = cm.module_id
       WHERE cm.uploaded_by_instructor_id = ? AND im.instructor_id = ?
       ORDER BY m.module_code, cm.week_number, cm.title'
    );
    $materialList->execute([$instructorId, $instructorId]);
    $courseMaterials = $materialList->fetchAll();

    $assignmentList = $pdo->prepare(
      'SELECT a.assignment_id, a.module_id, a.title, a.week_number, a.deadline,
              m.module_code, m.module_name,
              (SELECT COUNT(*) FROM submissions sub WHERE sub.assignment_id = a.assignment_id) AS submission_count
       FROM assignments a
       INNER JOIN modules m ON m.module_id = a.module_id
       INNER JOIN instructor_modules im ON im.module_id = a.module_id
       WHERE a.created_by_instructor_id = ? AND im.instructor_id = ?
       ORDER BY m.module_code, a.week_number, a.deadline'
    );
    $assignmentList->execute([$instructorId, $instructorId]);
    $assignments = $assignmentList->fetchAll();
  }
} catch (PDOException $error) {
  error_log('Course upload database error: ' . $error->getMessage());
  http_response_code(500);
  $errorMessage = 'Course materials or assignments could not be loaded or saved. Check the database setup and PHP error log.';
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
          <input type="hidden" name="action" value="<?php echo $editingMaterialId > 0 ? 'update_material' : 'upload_material'; ?>">
          <?php if ($editingMaterialId > 0): ?>
            <input type="hidden" name="material_id" value="<?php echo $editingMaterialId; ?>">
          <?php endif; ?>
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
            <label for="materialFile"><?php echo $editingMaterialId > 0 ? 'Replace Course Material File (optional)' : 'Course Material File'; ?></label>
            <input class="form-control-file" id="materialFile" name="material_file" type="file" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip" <?php echo $editingMaterialId === 0 ? 'required' : ''; ?>>
            <p class="file-status mb-0" id="materialFileStatus" aria-live="polite"></p>
            <?php if ($editingMaterialId > 0 && courseUploadStoredFileExists($materialCurrentFilePath, 'course_materials')): ?>
              <small class="form-text text-muted">
                Current file:
                <a href="../<?php echo courseUploadEscape($materialCurrentFilePath); ?>">Download current file</a>
              </small>
            <?php endif; ?>
          </div>
          <button class="btn btn-primary btn-sm" type="submit" <?php echo !$modules ? 'disabled' : ''; ?>>
            <?php echo $editingMaterialId > 0 ? 'Update Material' : 'Upload Material'; ?>
          </button>
          <?php if ($editingMaterialId > 0): ?>
            <a class="btn btn-outline-secondary btn-sm" href="courseupload.php#materialForm">Cancel Edit</a>
          <?php endif; ?>
          <small class="form-text text-muted">PDF, Office, text, and ZIP files up to 20 MB are accepted.</small>
        </form>

        <section class="week-body" id="materialList" aria-labelledby="materialListHeading">
          <h3 class="h5" id="materialListHeading">Manage Course Materials</h3>
          <?php if (!$courseMaterials): ?>
            <p class="text-muted mb-0">No course materials are available to manage.</p>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm">
                <thead>
                  <tr>
                    <th scope="col">Module</th>
                    <th scope="col">Week</th>
                    <th scope="col">Title</th>
                    <th scope="col">File</th>
                    <th scope="col">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($courseMaterials as $material): ?>
                    <tr>
                      <td><?php echo courseUploadEscape($material['module_code']); ?></td>
                      <td><?php echo (int) $material['week_number']; ?></td>
                      <td><?php echo courseUploadEscape($material['title']); ?></td>
                      <td>
                        <?php if (courseUploadStoredFileExists($material['file_path'], 'course_materials')): ?>
                          <a href="../<?php echo courseUploadEscape($material['file_path']); ?>">Download</a>
                        <?php else: ?>
                          <span class="text-muted">File unavailable</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <a class="btn btn-outline-primary btn-sm"
                           href="courseupload.php?edit_material=<?php echo (int) $material['material_id']; ?>#materialForm">
                          Edit
                        </a>
                        <form class="d-inline" method="post"
                              onsubmit="return confirm('Delete this course material? Existing activity history will no longer link to it.');">
                          <input type="hidden" name="csrf_token" value="<?php echo courseUploadEscape($_SESSION['course_material_upload_csrf']); ?>">
                          <input type="hidden" name="action" value="delete_material">
                          <input type="hidden" name="material_id" value="<?php echo (int) $material['material_id']; ?>">
                          <button class="btn btn-outline-danger btn-sm" type="submit">Delete</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      </section>

      <section class="col-md-6 panel-column assignment-column" aria-labelledby="assignment-heading">
        <h2 class="panel-tab" id="assignment-heading">
          <?php echo $editingAssignmentId > 0 ? 'Edit Assignment' : 'Create Assignment'; ?>
        </h2>
        <div class="assignment-panel">
          <form class="assignment-details" id="assignmentForm" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo courseUploadEscape($_SESSION['course_material_upload_csrf']); ?>">
            <input type="hidden" name="action" value="<?php echo $editingAssignmentId > 0 ? 'update_assignment' : 'create_assignment'; ?>">
            <?php if ($editingAssignmentId > 0): ?>
              <input type="hidden" name="assignment_id" value="<?php echo $editingAssignmentId; ?>">
            <?php endif; ?>
            <div class="form-group">
              <label for="assignmentModule">Module</label>
              <select class="form-control" id="assignmentModule" name="module_id" required <?php echo !$modules ? 'disabled' : ''; ?>>
                <option value="">Select an assigned module</option>
                <?php foreach ($modules as $module): ?>
                  <option value="<?php echo (int) $module['module_id']; ?>" <?php echo $assignmentModuleId === (string) $module['module_id'] ? 'selected' : ''; ?>>
                    <?php echo courseUploadEscape($module['module_code'] . ' - ' . $module['module_name'] . ' (' . $module['course_code'] . ', Semester ' . $module['semester_number'] . ')'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <?php if (!$modules): ?>
                <small class="form-text text-muted">You need an assigned module before creating an assignment.</small>
              <?php endif; ?>
            </div>
            <div class="form-group">
              <label for="assignmentTitle">Title</label>
              <input class="form-control" id="assignmentTitle" name="title" type="text" maxlength="150" required value="<?php echo courseUploadEscape($assignmentTitle); ?>">
            </div>
            <div class="form-group">
              <label for="assignmentInstructions">Instructions</label>
              <textarea class="form-control" id="assignmentInstructions" name="instructions" rows="3" maxlength="65535" required><?php echo courseUploadEscape($assignmentInstructions); ?></textarea>
            </div>
            <div class="form-row">
              <div class="form-group col-6">
                <label for="assignmentWeek">Week Number</label>
                <input class="form-control" id="assignmentWeek" name="week_number" type="number" min="1" required value="<?php echo courseUploadEscape($assignmentWeekNumber); ?>">
              </div>
              <div class="form-group col-6">
                <label for="assignmentDeadline">Deadline</label>
                <input class="form-control" id="assignmentDeadline" name="deadline" type="datetime-local" required value="<?php echo courseUploadEscape($assignmentDeadline); ?>">
              </div>
              <div class="form-group col-6">
                <label for="fileSizeLimit">File Size Limit (MB)</label>
                <input class="form-control" id="fileSizeLimit" name="file_size_limit_mb" type="number" min="0.01" max="9999.99" step="0.01" required value="<?php echo courseUploadEscape($assignmentFileSizeLimit); ?>">
              </div>
              <div class="form-group col-6">
                <label for="maximumMarks">Maximum Marks</label>
                <input class="form-control" id="maximumMarks" name="max_marks" type="number" min="0" max="999.99" step="0.01" required value="<?php echo courseUploadEscape($assignmentMaxMarks); ?>">
              </div>
            </div>
            <div class="form-group">
              <label for="assignmentFile"><?php echo $editingAssignmentId > 0 ? 'Replace Assignment File (optional)' : 'Assignment File (optional)'; ?></label>
              <input class="form-control-file" id="assignmentFile" name="assignment_file" type="file" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip">
              <p class="file-status mb-0" id="assignmentFileStatus" aria-live="polite"></p>
              <?php if ($editingAssignmentId > 0 && courseUploadStoredFileExists($assignmentCurrentFilePath, 'assignments')): ?>
                <small class="form-text text-muted">
                  Current file:
                  <a href="../<?php echo courseUploadEscape($assignmentCurrentFilePath); ?>">Download current file</a>
                </small>
              <?php endif; ?>
            </div>
            <button class="btn btn-primary btn-sm" type="submit" <?php echo !$modules ? 'disabled' : ''; ?>>
              <?php echo $editingAssignmentId > 0 ? 'Update Assignment' : 'Create Assignment'; ?>
            </button>
            <?php if ($editingAssignmentId > 0): ?>
              <a class="btn btn-outline-secondary btn-sm" href="courseupload.php#assignmentForm">Cancel Edit</a>
            <?php endif; ?>
            <small class="form-text text-muted">Assignment files can be PDF, Office, text, or ZIP files up to 20 MB.</small>
          </form>

          <section class="mt-4" id="assignmentList" aria-labelledby="assignmentListHeading">
            <h3 class="h5" id="assignmentListHeading">Manage Assignments</h3>
            <?php if (!$assignments): ?>
              <p class="text-muted mb-0">No assignments are available to manage.</p>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm">
                  <thead>
                    <tr>
                      <th scope="col">Module</th>
                      <th scope="col">Week</th>
                      <th scope="col">Assignment</th>
                      <th scope="col">Deadline</th>
                      <th scope="col">Submissions</th>
                      <th scope="col">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($assignments as $assignment): ?>
                      <tr>
                        <td><?php echo courseUploadEscape($assignment['module_code']); ?></td>
                        <td><?php echo (int) $assignment['week_number']; ?></td>
                        <td><?php echo courseUploadEscape($assignment['title']); ?></td>
                        <td><?php echo courseUploadEscape($assignment['deadline']); ?></td>
                        <td><?php echo (int) $assignment['submission_count']; ?></td>
                        <td>
                          <?php if ((int) $assignment['submission_count'] === 0): ?>
                            <a class="btn btn-outline-primary btn-sm"
                               href="courseupload.php?edit_assignment=<?php echo (int) $assignment['assignment_id']; ?>#assignmentForm">
                              Edit
                            </a>
                            <form class="d-inline" method="post"
                                  onsubmit="return confirm('Delete this assignment? This cannot be undone.');">
                              <input type="hidden" name="csrf_token" value="<?php echo courseUploadEscape($_SESSION['course_material_upload_csrf']); ?>">
                              <input type="hidden" name="action" value="delete_assignment">
                              <input type="hidden" name="assignment_id" value="<?php echo (int) $assignment['assignment_id']; ?>">
                              <button class="btn btn-outline-danger btn-sm" type="submit">Delete</button>
                            </form>
                          <?php else: ?>
                            <span class="text-muted">Locked after first submission</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>
        </div>
      </section>
    </div>
  </main>

  <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
  <script src="../assets/js/common.js" data-page="course/courseupload.php"></script>
</body>
</html>
