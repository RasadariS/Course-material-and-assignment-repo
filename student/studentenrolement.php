<?php
session_start();

function studentEnrollmentEscape($value): string
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

if (empty($_SESSION['student_enrollment_csrf'])) {
  $_SESSION['student_enrollment_csrf'] = bin2hex(random_bytes(32));
}

$students = [];
$courseGroups = [];
$selectedStudentId = 0;
$selectedStudent = null;
$editMode = false;
$enrolledModuleCount = 0;
$errorMessage = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $studentInput = $_POST['student_id'] ?? '';
} else {
  $studentInput = $_GET['student_id'] ?? '';
}

if (is_string($studentInput) || is_int($studentInput)) {
  $studentId = filter_var($studentInput, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
  ]);
  if ($studentId !== false) {
    $selectedStudentId = $studentId;
  }
}

try {
  $pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

  $studentLookup = $pdo->query(
    'SELECT s.student_id, s.registration_number, u.full_name
     FROM students s
     INNER JOIN users u ON u.user_id = s.user_id
     ORDER BY u.full_name'
  );
  $students = $studentLookup->fetchAll();

  foreach ($students as $student) {
    if ((int) $student['student_id'] === $selectedStudentId) {
      $selectedStudent = $student;
      break;
    }
  }

  if ($selectedStudentId > 0 && !$selectedStudent) {
    $errorMessage = 'Select a student profile from the list.';
    $selectedStudentId = 0;
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedStudent) {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) ||
        !hash_equals($_SESSION['student_enrollment_csrf'], $submittedToken)) {
      http_response_code(400);
      $errorMessage = 'Your form expired. Reload the page and try again.';
    } else {
      $action = isset($_POST['remove_module_id'])
        ? 'remove'
        : ($_POST['action'] ?? 'add');

      if (!is_string($action)) {
        $errorMessage = 'Choose a valid enrollment action.';
      } elseif ($action === 'edit') {
        $editMode = true;
      } elseif ($action === 'remove') {
        $moduleInput = $_POST['remove_module_id'] ?? '';
        if (!is_string($moduleInput) && !is_int($moduleInput)) {
          $errorMessage = 'The selected module is not valid.';
        } else {
          $moduleId = filter_var($moduleInput, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
          ]);
          if ($moduleId === false) {
            $errorMessage = 'The selected module is not valid.';
          } else {
            $checkEnrollment = $pdo->prepare(
              'SELECT module_id
               FROM student_modules
               WHERE student_id = ? AND module_id = ?
               LIMIT 1'
            );
            $checkEnrollment->execute([$selectedStudentId, $moduleId]);

            if ($checkEnrollment->fetchColumn() === false) {
              $errorMessage = 'This student is not enrolled in the selected module.';
            } else {
              $removeEnrollment = $pdo->prepare(
                'DELETE FROM student_modules
                 WHERE student_id = ? AND module_id = ?'
              );
              $removeEnrollment->execute([$selectedStudentId, $moduleId]);
              header('Location: studentenrolement.php?student_id=' .
                $selectedStudentId . '&removed=1');
              exit;
            }
          }
        }
      } elseif ($action === 'add' || $action === 'update') {
        if ($action === 'update') {
          $editMode = true;
        }

        $moduleInputs = $_POST['module_ids'] ?? [];
        if (!is_array($moduleInputs)) {
          $errorMessage = 'The selected modules are not valid.';
        } else {
          $moduleIds = [];
          foreach ($moduleInputs as $moduleInput) {
            if (!is_string($moduleInput) && !is_int($moduleInput)) {
              $errorMessage = 'The selected modules are not valid.';
              break;
            }

            $moduleId = filter_var($moduleInput, FILTER_VALIDATE_INT, [
              'options' => ['min_range' => 1],
            ]);
            if ($moduleId === false) {
              $errorMessage = 'The selected modules are not valid.';
              break;
            }
            $moduleIds[$moduleId] = $moduleId;
          }

          if ($errorMessage === '' && $action === 'add' && !$moduleIds) {
            $errorMessage = 'Select at least one available module.';
          }

          if ($errorMessage === '') {
            try {
              $pdo->beginTransaction();

              $checkModule = $pdo->prepare(
                'SELECT module_id
                 FROM modules
                 WHERE module_id = ?
                 LIMIT 1'
              );
              foreach ($moduleIds as $moduleId) {
                $checkModule->execute([$moduleId]);
                if ($checkModule->fetchColumn() === false) {
                  throw new InvalidArgumentException('One or more selected modules could not be found.');
                }
              }

              $currentLookup = $pdo->prepare(
                'SELECT module_id
                 FROM student_modules
                 WHERE student_id = ?'
              );
              $currentLookup->execute([$selectedStudentId]);
              $currentModuleIds = [];
              foreach ($currentLookup->fetchAll() as $enrollment) {
                $currentModuleIds[] = (int) $enrollment['module_id'];
              }

              if ($action === 'update') {
                $removeEnrollment = $pdo->prepare(
                  'DELETE FROM student_modules
                   WHERE student_id = ? AND module_id = ?'
                );
                foreach ($currentModuleIds as $moduleId) {
                  if (!isset($moduleIds[$moduleId])) {
                    $removeEnrollment->execute([$selectedStudentId, $moduleId]);
                  }
                }
              }

              $addEnrollment = $pdo->prepare(
                'INSERT INTO student_modules (student_id, module_id, enrollment_date)
                 VALUES (?, ?, CURDATE())'
              );
              foreach ($moduleIds as $moduleId) {
                if (!in_array($moduleId, $currentModuleIds, true)) {
                  $addEnrollment->execute([$selectedStudentId, $moduleId]);
                }
              }

              $pdo->commit();
              header('Location: studentenrolement.php?student_id=' .
                $selectedStudentId . '&saved=1');
              exit;
            } catch (InvalidArgumentException $error) {
              if ($pdo->inTransaction()) {
                $pdo->rollBack();
              }
              $errorMessage = $error->getMessage();
            } catch (PDOException $error) {
              if ($pdo->inTransaction()) {
                $pdo->rollBack();
              }
              throw $error;
            }
          }
        }
      } else {
        $errorMessage = 'Choose a valid enrollment action.';
      }
    }
  }

  if (($_GET['saved'] ?? '') === '1' && $selectedStudent) {
    $successMessage = 'Student module enrollment saved.';
  } elseif (($_GET['removed'] ?? '') === '1' && $selectedStudent) {
    $successMessage = 'Module enrollment removed.';
  }

  if ($selectedStudent) {
    $moduleLookup = $pdo->prepare(
      'SELECT m.module_id, m.module_code, m.module_name, m.description,
              s.semester_id, s.semester_number, s.academic_year,
              c.course_id, c.course_code, c.course_name,
              sm.enrollment_date
       FROM modules m
       INNER JOIN semesters s ON s.semester_id = m.semester_id
       INNER JOIN courses c ON c.course_id = s.course_id
       LEFT JOIN student_modules sm
         ON sm.module_id = m.module_id AND sm.student_id = ?
       ORDER BY c.course_name, s.semester_number, m.module_name'
    );
    $moduleLookup->execute([$selectedStudentId]);

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

      if ($module['enrollment_date'] !== null) {
        $enrolledModuleCount += 1;
      }
      $courseGroups[$courseId]['semesters'][$semesterId]['modules'][] = $module;
    }
  }
} catch (PDOException $error) {
  error_log('Student module enrollment database error: ' . $error->getMessage());
  http_response_code(500);
  $errorMessage = 'Student enrollments could not be loaded or saved. Check the database setup and PHP error log.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Student Module Enrollment - DTET</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/common.css" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column min-vh-100 page-student-studentenrolement-php">
  <?php
  $headerBasePath = '../';
  require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'header.php';
  ?>

  <main class="container-fluid px-4 py-4 flex-fill">
    <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
      <div>
        <h1 class="page-title mb-1">Student Module Enrollment</h1>
        <p class="text-muted mb-0">Enroll each student in modules within their course and semester.</p>
      </div>
    </div>

    <div class="alert alert-info" role="note">
      Choose a student, then select modules from the course and semester list.
    </div>

    <?php if ($errorMessage !== ''): ?>
      <div class="alert alert-danger" role="alert"><?php echo studentEnrollmentEscape($errorMessage); ?></div>
    <?php endif; ?>
    <?php if ($successMessage !== ''): ?>
      <div class="alert alert-success" role="status"><?php echo studentEnrollmentEscape($successMessage); ?></div>
    <?php endif; ?>

    <section class="card shadow-sm mb-3 workflow" aria-labelledby="studentHeading">
      <div class="card-body">
        <h2 class="h5 mb-3" id="studentHeading">1. Select student</h2>
        <form action="studentenrolement.php" method="get" class="form-row align-items-end">
          <div class="form-group col-md-6 mb-md-0">
            <label for="studentSelect">Student profile</label>
            <select class="form-control" id="studentSelect" name="student_id" required>
              <option value="">Select a student profile</option>
              <?php foreach ($students as $student): ?>
                <option value="<?php echo (int) $student['student_id']; ?>"
                  <?php echo $selectedStudentId === (int) $student['student_id'] ? 'selected' : ''; ?>>
                  <?php echo studentEnrollmentEscape($student['registration_number'] . ' - ' . $student['full_name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6 mt-2 mt-md-0">
            <button class="btn btn-outline-primary" type="submit">Load modules</button>
          </div>
        </form>
        <?php if ($selectedStudent): ?>
          <p class="small text-muted mt-3 mb-0">
            Registration: <?php echo studentEnrollmentEscape($selectedStudent['registration_number']); ?>
          </p>
        <?php elseif (!$students): ?>
          <p class="small text-muted mt-3 mb-0">Create student profiles before enrolling them.</p>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($selectedStudent): ?>
      <section class="card shadow-sm" aria-labelledby="modulesHeading">
        <div class="card-body border-bottom">
          <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
            <div>
              <h2 class="h5 mb-1" id="modulesHeading">2. Select modules</h2>
              <p class="text-muted small mb-0">
                <?php if ($editMode): ?>
                  Select the modules this student should stay enrolled in, then update the list.
                <?php else: ?>
                  Already enrolled modules are checked. Select available modules to add them.
                <?php endif; ?>
              </p>
            </div>
            <div class="toolbar mt-2 mt-md-0">
              <button class="btn btn-outline-secondary btn-sm" type="button" id="selectVisible">Select available</button>
              <button class="btn btn-outline-secondary btn-sm" type="button" id="clearSelection">Clear selection</button>
            </div>
          </div>

          <div class="form-row align-items-end">
            <div class="form-group col-md-6">
              <label for="courseFilter">Course</label>
              <select class="form-control" id="courseFilter">
                <option value="">All courses</option>
                <?php foreach ($courseGroups as $courseId => $course): ?>
                  <option value="<?php echo (int) $courseId; ?>">
                    <?php echo studentEnrollmentEscape($course['course_code'] . ' - ' . $course['course_name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-md-6 mb-md-0">
              <label for="semesterFilter">Semester</label>
              <select class="form-control" id="semesterFilter">
                <option value="">All semesters</option>
                <?php foreach ($courseGroups as $course): ?>
                  <?php foreach ($course['semesters'] as $semesterId => $semester): ?>
                    <option value="<?php echo (int) $semesterId; ?>">
                      <?php echo studentEnrollmentEscape(
                        $course['course_code'] . ' - Semester ' . $semester['semester_number']
                      ); ?>
                    </option>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>

        <form action="studentenrolement.php" method="post">
          <input type="hidden" name="csrf_token" value="<?php echo studentEnrollmentEscape($_SESSION['student_enrollment_csrf']); ?>">
          <input type="hidden" name="student_id" value="<?php echo (int) $selectedStudentId; ?>">
          <div class="module-list" id="moduleList" aria-live="polite">
            <?php if (!$courseGroups): ?>
              <div class="empty-state">No course modules are available yet.</div>
            <?php else: ?>
              <?php foreach ($courseGroups as $courseId => $course): ?>
                <section class="course-group" data-course-id="<?php echo (int) $courseId; ?>">
                  <h3 class="course-heading mb-0">
                    <?php echo studentEnrollmentEscape($course['course_code'] . ' - ' . $course['course_name']); ?>
                  </h3>
                  <?php foreach ($course['semesters'] as $semesterId => $semester): ?>
                    <div class="semester-group" data-semester-id="<?php echo (int) $semesterId; ?>">
                      <h4 class="semester-heading mb-2">
                        Semester <?php echo studentEnrollmentEscape($semester['semester_number']); ?>
                        <?php if ($semester['academic_year'] !== ''): ?>
                          (<?php echo studentEnrollmentEscape($semester['academic_year']); ?>)
                        <?php endif; ?>
                      </h4>
                      <?php foreach ($semester['modules'] as $module): ?>
                        <div class="module-row">
                          <input type="checkbox"
                                 id="module-<?php echo (int) $module['module_id']; ?>"
                                 name="module_ids[]"
                                 value="<?php echo (int) $module['module_id']; ?>"
                                 <?php if ($module['enrollment_date'] !== null): ?>
                                   checked<?php echo $editMode ? '' : ' disabled'; ?>
                                 <?php endif; ?>>
                          <label for="module-<?php echo (int) $module['module_id']; ?>">
                            <?php echo studentEnrollmentEscape($module['module_code'] . ' - ' . $module['module_name']); ?>
                            <?php if ($module['description'] !== null && $module['description'] !== ''): ?>
                              <span class="module-description"><?php echo studentEnrollmentEscape($module['description']); ?></span>
                            <?php endif; ?>
                          </label>
                          <?php if ($module['enrollment_date'] !== null): ?>
                            <span class="module-state">Enrolled <?php echo studentEnrollmentEscape($module['enrollment_date']); ?></span>
                            <button class="btn btn-sm btn-outline-danger" type="submit"
                                    name="remove_module_id" value="<?php echo (int) $module['module_id']; ?>"
                                    onclick="return confirm('Remove this module enrollment?');">
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
                <button class="btn btn-success" type="submit" name="action" value="update">
                  Update enrollments
                </button>
              <?php else: ?>
                <?php if ($enrolledModuleCount > 0): ?>
                  <button class="btn btn-outline-primary" type="submit" name="action" value="edit">
                    Edit enrollments
                  </button>
                <?php endif; ?>
                <button class="btn btn-primary" type="submit" name="action" value="add" id="enrollButton">
                  Enroll selected modules
                </button>
              <?php endif; ?>
            </div>
          </div>
        </form>
      </section>
    <?php endif; ?>
  </main>

  <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
  <script>
    var moduleList = document.getElementById('moduleList');

    if (moduleList) {
      var courseFilter = document.getElementById('courseFilter');
      var semesterFilter = document.getElementById('semesterFilter');
      var selectionCount = document.getElementById('selectionCount');
      var enrollButton = document.getElementById('enrollButton');

      function updateSelectionCount() {
        var count = moduleList.querySelectorAll('input[type="checkbox"]:checked:not(:disabled)').length;
        selectionCount.textContent = count + (count === 1 ? ' module selected' : ' modules selected');
        if (enrollButton) {
          enrollButton.disabled = count === 0;
        }
      }

      function filterModules() {
        var selectedCourse = courseFilter.value;
        var selectedSemester = semesterFilter.value;

        moduleList.querySelectorAll('.course-group').forEach(function (courseGroup) {
          var courseMatches = !selectedCourse ||
            courseGroup.getAttribute('data-course-id') === selectedCourse;
          var visibleSemesters = 0;

          courseGroup.querySelectorAll('.semester-group').forEach(function (semesterGroup) {
            var semesterMatches = !selectedSemester ||
              semesterGroup.getAttribute('data-semester-id') === selectedSemester;
            semesterGroup.hidden = !courseMatches || !semesterMatches;
            if (!semesterGroup.hidden) visibleSemesters += 1;
          });

          courseGroup.hidden = visibleSemesters === 0;
        });
      }

      courseFilter.addEventListener('change', filterModules);
      semesterFilter.addEventListener('change', filterModules);
      moduleList.addEventListener('change', updateSelectionCount);

      document.getElementById('selectVisible').addEventListener('click', function () {
        moduleList.querySelectorAll('.semester-group:not([hidden]) input[type="checkbox"]:not(:disabled)')
          .forEach(function (checkbox) {
            checkbox.checked = true;
          });
        updateSelectionCount();
      });

      document.getElementById('clearSelection').addEventListener('click', function () {
        moduleList.querySelectorAll('input[type="checkbox"]:not(:disabled)').forEach(function (checkbox) {
          checkbox.checked = false;
        });
        updateSelectionCount();
      });

      updateSelectionCount();
    }
  </script>
</body>
</html>
