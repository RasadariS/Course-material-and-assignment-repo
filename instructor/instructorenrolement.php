<?php
session_start();

function instructorEnrollmentEscape($value): string
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

if (!in_array($userRole, ['admin', 'administrator', 'system administrator'], true)) {
  http_response_code(403);
  exit('This page is available to administrators only.');
}

if (empty($_SESSION['instructor_enrollment_csrf'])) {
  $_SESSION['instructor_enrollment_csrf'] = bin2hex(random_bytes(32));
}

$instructors = [];
$courseGroups = [];
$assignedModuleCount = 0;
$selectedInstructorId = 0;
$editMode = false;
$errorMessage = '';
$successMessage = '';
if (($_GET['saved'] ?? '') === '1') {
  $successMessage = 'Module assignments saved.';
} elseif (($_GET['removed'] ?? '') === '1') {
  $successMessage = 'Module assignment removed.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $instructorInput = $_POST['instructor_id'] ?? '';
} else {
  $instructorInput = $_GET['instructor_id'] ?? '';
}

$selectedInstructorId = 0;
if (is_string($instructorInput) || is_int($instructorInput)) {
  $instructorId = filter_var($instructorInput, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
  ]);
  if ($instructorId !== false) {
    $selectedInstructorId = $instructorId;
  }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedInstructorId === 0) {
  $errorMessage = 'Select an instructor profile from the list.';
}

try {
  $pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

  $instructorLookup = $pdo->query(
    'SELECT i.instructor_id, i.employee_code, i.department, u.full_name
     FROM instructors i
     INNER JOIN users u ON u.user_id = i.user_id
     ORDER BY u.full_name'
  );
  $instructors = $instructorLookup->fetchAll();

  $instructorIds = [];
  foreach ($instructors as $instructor) {
    $instructorIds[] = (int) $instructor['instructor_id'];
  }

  if ($selectedInstructorId > 0 && !in_array($selectedInstructorId, $instructorIds, true)) {
    $errorMessage = 'Select an instructor profile from the list.';
    $selectedInstructorId = 0;
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage === '') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) ||
        !hash_equals($_SESSION['instructor_enrollment_csrf'], $submittedToken)) {
      http_response_code(400);
      $errorMessage = 'Your form expired. Reload the page and try again.';
    } else {
      $action = isset($_POST['remove_module_id'])
        ? 'remove'
        : ($_POST['action'] ?? '');

      if (!is_string($action)) {
        $errorMessage = 'Choose a valid assignment action.';
      } elseif ($action === 'edit_assignments') {
        $editMode = true;
      } elseif ($action === 'remove') {
        $moduleIdInput = $_POST['remove_module_id'] ?? '';
        if (!is_string($moduleIdInput) && !is_int($moduleIdInput)) {
          $errorMessage = 'The selected module is not valid.';
        } else {
          $moduleId = filter_var($moduleIdInput, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
          ]);
          if ($moduleId === false) {
            $errorMessage = 'The selected module is not valid.';
          } else {
            $assignmentLookup = $pdo->prepare(
              'SELECT module_id
               FROM instructor_modules
               WHERE instructor_id = ? AND module_id = ?
               LIMIT 1'
            );
            $assignmentLookup->execute([$selectedInstructorId, $moduleId]);

            if ($assignmentLookup->fetchColumn() === false) {
              $errorMessage = 'This module is not assigned to the selected instructor.';
            } else {
              $removeAssignment = $pdo->prepare(
                'DELETE FROM instructor_modules
                 WHERE instructor_id = ? AND module_id = ?'
              );
              $removeAssignment->execute([$selectedInstructorId, $moduleId]);
              header('Location: instructorenrolement.php?instructor_id=' .
                $selectedInstructorId . '&removed=1');
              exit;
            }
          }
        }
      } elseif ($action === 'add_assignments' || $action === 'update_assignments') {
        if ($action === 'update_assignments') {
          $editMode = true;
        }
        $submittedModuleIds = $_POST['module_ids'] ?? [];
        if (!is_array($submittedModuleIds)) {
          $errorMessage = 'The selected modules are not valid.';
        } else {
          $selectedModuleIds = [];
          foreach ($submittedModuleIds as $moduleIdInput) {
            if (!is_string($moduleIdInput) && !is_int($moduleIdInput)) {
              $errorMessage = 'The selected modules are not valid.';
              break;
            }
            $moduleId = filter_var($moduleIdInput, FILTER_VALIDATE_INT, [
              'options' => ['min_range' => 1],
            ]);
            if ($moduleId === false) {
              $errorMessage = 'The selected modules are not valid.';
              break;
            }
            $selectedModuleIds[$moduleId] = $moduleId;
          }

          if ($errorMessage === '') {
            $validModuleLookup = $pdo->query(
              'SELECT m.module_id
               FROM modules m
               INNER JOIN semesters s ON s.semester_id = m.semester_id'
            );
            $validModuleIds = [];
            foreach ($validModuleLookup->fetchAll() as $module) {
              $validModuleIds[] = (int) $module['module_id'];
            }

            foreach ($selectedModuleIds as $moduleId) {
              if (!in_array($moduleId, $validModuleIds, true)) {
                $errorMessage = 'One or more selected modules could not be found.';
                break;
              }
            }
          }

          if ($errorMessage === '') {
            try {
              $pdo->beginTransaction();

              $currentLookup = $pdo->prepare(
                'SELECT module_id
                 FROM instructor_modules
                 WHERE instructor_id = ?'
              );
              $currentLookup->execute([$selectedInstructorId]);
              $currentModuleIds = [];
              foreach ($currentLookup->fetchAll() as $module) {
                $currentModuleIds[] = (int) $module['module_id'];
              }

              if ($action === 'update_assignments') {
                $deleteAssignment = $pdo->prepare(
                  'DELETE FROM instructor_modules
                   WHERE instructor_id = ? AND module_id = ?'
                );
                foreach ($currentModuleIds as $moduleId) {
                  if (!isset($selectedModuleIds[$moduleId])) {
                    $deleteAssignment->execute([$selectedInstructorId, $moduleId]);
                  }
                }
              }

              $addAssignment = $pdo->prepare(
                'INSERT INTO instructor_modules (instructor_id, module_id, assigned_date)
                 VALUES (?, ?, CURDATE())'
              );
              foreach ($selectedModuleIds as $moduleId) {
                if (!in_array($moduleId, $currentModuleIds, true)) {
                  $addAssignment->execute([$selectedInstructorId, $moduleId]);
                }
              }

              $pdo->commit();
              header('Location: instructorenrolement.php?instructor_id=' .
                $selectedInstructorId . '&saved=1');
              exit;
            } catch (PDOException $error) {
              if ($pdo->inTransaction()) {
                $pdo->rollBack();
              }
              throw $error;
            }
          }
        }
      } else {
        $errorMessage = 'Choose a valid assignment action.';
      }
    }
  }

  if ($selectedInstructorId > 0) {
    $moduleLookup = $pdo->prepare(
      'SELECT m.module_id, m.module_code, m.module_name, m.description,
              s.semester_id, s.semester_number, s.academic_year,
              c.course_id, c.course_code, c.course_name,
              im.assigned_date
       FROM modules m
       INNER JOIN semesters s ON s.semester_id = m.semester_id
       INNER JOIN courses c ON c.course_id = s.course_id
       LEFT JOIN instructor_modules im
         ON im.module_id = m.module_id AND im.instructor_id = ?
       ORDER BY c.course_name, s.semester_number, m.module_name'
    );
    $moduleLookup->execute([$selectedInstructorId]);

    foreach ($moduleLookup->fetchAll() as $module) {
      $courseId = (int) $module['course_id'];
      $semesterId = (int) $module['semester_id'];

      if (!isset($courseGroups[$courseId])) {
        $courseGroups[$courseId] = [
          'course_code' => $module['course_code'],
          'course_name' => $module['course_name'],
          'semesters' => [],
        ];
      }
      if (!isset($courseGroups[$courseId]['semesters'][$semesterId])) {
        $courseGroups[$courseId]['semesters'][$semesterId] = [
          'semester_number' => $module['semester_number'],
          'academic_year' => $module['academic_year'],
          'modules' => [],
        ];
      }

      if ($module['assigned_date'] !== null) {
        $assignedModuleCount += 1;
      }
      $courseGroups[$courseId]['semesters'][$semesterId]['modules'][] = $module;
    }
  }
} catch (PDOException $error) {
  error_log('Instructor module assignment database error: ' . $error->getMessage());
  http_response_code(500);
  $errorMessage = 'Instructor module assignments could not be loaded or saved. Check the database setup and PHP error log.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Instructor Module Assignment - DTET</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/common.css" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column min-vh-100 page-instructor-instructorenrolement-php">
  <?php
  $headerBasePath = '../';
  require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'header.php';
  ?>

  <main class="container-fluid px-4 py-4 flex-fill">
    <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
      <div>
        <h1 class="page-title mb-1">Instructor Module Assignment</h1>
        <p class="text-muted mb-0">Assign instructors to modules within each course and semester.</p>
      </div>
    </div>

    <?php if ($errorMessage !== ''): ?>
      <div class="alert alert-danger" role="alert"><?php echo instructorEnrollmentEscape($errorMessage); ?></div>
    <?php endif; ?>
    <?php if ($successMessage !== ''): ?>
      <div class="alert alert-success" role="status"><?php echo instructorEnrollmentEscape($successMessage); ?></div>
    <?php endif; ?>

    <section class="card shadow-sm mb-3 workflow" aria-labelledby="instructorHeading">
      <div class="card-body">
        <h2 class="h5 mb-3" id="instructorHeading">1. Select instructor profile</h2>
        <form action="instructorenrolement.php" method="get" class="form-row align-items-end">
          <div class="form-group col-md-6 mb-md-0">
            <label for="instructorSelect">Instructor</label>
            <select class="form-control" id="instructorSelect" name="instructor_id" required>
              <option value="">Select an instructor profile</option>
              <?php foreach ($instructors as $instructor): ?>
                <option value="<?php echo (int) $instructor['instructor_id']; ?>"
                  <?php echo $selectedInstructorId === (int) $instructor['instructor_id'] ? 'selected' : ''; ?>>
                  <?php echo instructorEnrollmentEscape($instructor['employee_code'] . ' - ' . $instructor['full_name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6 mt-2 mt-md-0">
            <button class="btn btn-outline-primary" type="submit">Load modules</button>
          </div>
        </form>
        <?php if (!$instructors): ?>
          <p class="text-muted mt-3 mb-0">Create an instructor profile before assigning modules.</p>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($selectedInstructorId > 0): ?>
      <section class="card shadow-sm" aria-labelledby="modulesHeading">
        <div class="card-body border-bottom">
          <h2 class="h5 mb-1" id="modulesHeading">2. Select modules</h2>
          <p class="text-muted small mb-3">
            Select modules to assign them. Use Edit assignments to change the instructor’s current module list.
          </p>

          <?php if ($courseGroups): ?>
            <div class="form-row align-items-end">
              <div class="form-group col-md-6">
                <label for="courseFilter">Course</label>
                <select class="form-control" id="courseFilter">
                  <option value="">All courses</option>
                  <?php foreach ($courseGroups as $courseId => $course): ?>
                    <option value="<?php echo (int) $courseId; ?>">
                      <?php echo instructorEnrollmentEscape($course['course_code'] . ' - ' . $course['course_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group col-md-6 mb-md-0">
                <label for="semesterFilter">Semester</label>
                <select class="form-control" id="semesterFilter">
                  <option value="">All semesters</option>
                  <?php foreach ($courseGroups as $courseId => $course): ?>
                    <?php foreach ($course['semesters'] as $semesterId => $semester): ?>
                      <option value="<?php echo (int) $semesterId; ?>">
                        <?php echo instructorEnrollmentEscape(
                          $course['course_code'] . ' - Semester ' . $semester['semester_number']
                        ); ?>
                      </option>
                    <?php endforeach; ?>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          <?php endif; ?>

          <div class="toolbar">
            <button class="btn btn-outline-secondary btn-sm" type="button" id="selectVisible">Select visible</button>
            <button class="btn btn-outline-secondary btn-sm" type="button" id="clearSelection">Clear selection</button>
          </div>
        </div>

        <form action="instructorenrolement.php" method="post">
          <input type="hidden" name="csrf_token" value="<?php echo instructorEnrollmentEscape($_SESSION['instructor_enrollment_csrf']); ?>">
          <input type="hidden" name="instructor_id" value="<?php echo (int) $selectedInstructorId; ?>">
          <div class="module-list" id="moduleList" aria-live="polite">
            <?php if (!$courseGroups): ?>
              <div class="empty-state">No course modules are available yet. Add semesters and modules in Course Management first.</div>
            <?php else: ?>
              <?php foreach ($courseGroups as $courseId => $course): ?>
                <section class="course-group" data-course-id="<?php echo (int) $courseId; ?>">
                  <h3 class="course-heading mb-0">
                    <?php echo instructorEnrollmentEscape($course['course_code'] . ' - ' . $course['course_name']); ?>
                  </h3>
                  <?php foreach ($course['semesters'] as $semesterId => $semester): ?>
                    <div class="semester-group" data-semester-id="<?php echo (int) $semesterId; ?>">
                      <h4 class="semester-heading mb-2">
                        Semester <?php echo instructorEnrollmentEscape($semester['semester_number']); ?>
                        <?php if ($semester['academic_year'] !== ''): ?>
                          (<?php echo instructorEnrollmentEscape($semester['academic_year']); ?>)
                        <?php endif; ?>
                      </h4>
                      <?php foreach ($semester['modules'] as $module): ?>
                        <div class="module-row">
                          <input type="checkbox"
                                 class="<?php echo $module['assigned_date'] !== null ? 'assigned-module-checkbox' : ''; ?>"
                                 id="module-<?php echo (int) $module['module_id']; ?>"
                                 name="module_ids[]" value="<?php echo (int) $module['module_id']; ?>"
                                 <?php if ($module['assigned_date'] !== null): ?>checked<?php echo $editMode ? '' : ' disabled'; ?><?php endif; ?>>
                          <label for="module-<?php echo (int) $module['module_id']; ?>">
                            <?php echo instructorEnrollmentEscape($module['module_code'] . ' - ' . $module['module_name']); ?>
                            <?php if ($module['description'] !== null && $module['description'] !== ''): ?>
                              <span class="module-description"><?php echo instructorEnrollmentEscape($module['description']); ?></span>
                            <?php endif; ?>
                          </label>
                          <?php if ($module['assigned_date'] !== null): ?>
                            <span class="module-state">Assigned <?php echo instructorEnrollmentEscape($module['assigned_date']); ?></span>
                            <button class="btn btn-sm btn-outline-danger" type="submit"
                                    name="remove_module_id" value="<?php echo (int) $module['module_id']; ?>"
                                    onclick="return confirm('Remove this module assignment?');">
                              Remove
                            </button>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endforeach; ?>
                </section>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap">
            <span class="small text-muted" id="selectionCount">0 modules selected</span>
            <div class="toolbar mt-2 mt-sm-0">
              <?php if ($editMode): ?>
                <button class="btn btn-success" type="submit" name="action" value="update_assignments">
                  Update assignments
                </button>
              <?php else: ?>
                <?php if ($assignedModuleCount > 0): ?>
                  <button class="btn btn-outline-primary" type="submit" name="action" value="edit_assignments">
                    Edit assignments
                  </button>
                <?php endif; ?>
                <button class="btn btn-primary" type="submit" name="action" value="add_assignments">
                  Assign selected modules
                </button>
              <?php endif; ?>
            </div>
          </div>
        </form>
      </section>
    <?php endif; ?>
  </main>

  <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
  <script src="../assets/js/common.js" data-page="instructor/instructorenrolement.php"></script>
</body>
</html>
