<?php
declare(strict_types=1);

session_start();

function courseManagementEscape($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function courseManagementInput(string $name): string
{
  $value = $_POST[$name] ?? '';
  return is_string($value) ? trim($value) : '';
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$userRole = strtolower((string) ($_SESSION['role'] ?? ''));

if ($userId === false) {
  header('Location: ../login.php');
  exit;
}

if (!in_array($userRole, ['admin', 'administrator', 'system administrator'], true)) {
  http_response_code(403);
  exit('This page is available to administrators only.');
}

if (empty($_SESSION['course_management_csrf'])) {
  $_SESSION['course_management_csrf'] = bin2hex(random_bytes(32));
}

$pageError = '';
$pageSuccess = isset($_GET['saved']) && $_GET['saved'] === '1' ? 'Changes saved.' : '';
$courseFormId = 0;
$semesterFormId = 0;
$moduleFormId = 0;
$courseForm = [
  'course_code' => '',
  'course_name' => '',
  'duration_months' => '',
  'total_semesters' => '',
];
$semesterForm = [
  'course_id' => '',
  'semester_number' => '',
  'academic_year' => '',
];
$moduleForm = [
  'semester_id' => '',
  'module_code' => '',
  'module_name' => '',
  'description' => '',
];
$courses = [];
$semesters = [];
$modules = [];

try {
  $pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($submittedToken) ||
        !hash_equals($_SESSION['course_management_csrf'], $submittedToken)) {
      $pageError = 'Your form expired. Reload the page and try again.';
    } else {
      $action = courseManagementInput('action');
      $recordIdInput = courseManagementInput('record_id');
      $recordId = filter_var($recordIdInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
      if ($recordId === false && $recordIdInput !== '0') {
        $action = 'invalid_record';
      }

      try {
        if ($action === 'save_course') {
          $courseFormId = $recordId === false ? 0 : $recordId;
          $courseForm['course_code'] = courseManagementInput('course_code');
          $courseForm['course_name'] = courseManagementInput('course_name');
          $courseForm['duration_months'] = courseManagementInput('duration_months');
          $courseForm['total_semesters'] = courseManagementInput('total_semesters');

          $duration = filter_var($courseForm['duration_months'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
          $totalSemesters = filter_var($courseForm['total_semesters'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

          if ($courseForm['course_code'] === '' || strlen($courseForm['course_code']) > 50 ||
              $courseForm['course_name'] === '' || strlen($courseForm['course_name']) > 150 ||
              $duration === false || $totalSemesters === false) {
            $pageError = 'Enter a course code, course name, positive duration, and positive semester count.';
          } else {
            if ($courseFormId > 0) {
              $highestSemester = $pdo->prepare(
                'SELECT MAX(semester_number) FROM semesters WHERE course_id = ?'
              );
              $highestSemester->execute([$courseFormId]);
              $existingHighestSemester = $highestSemester->fetchColumn();

              if ($existingHighestSemester !== false &&
                  $existingHighestSemester !== null &&
                  (int) $existingHighestSemester > $totalSemesters) {
                $pageError = 'The semester count cannot be lower than a semester already saved for this course.';
              }
            }

            if ($pageError === '') {
              if ($courseFormId > 0) {
                $saveCourse = $pdo->prepare(
                  'UPDATE courses
                   SET course_code = ?, course_name = ?, duration_months = ?, total_semesters = ?
                   WHERE course_id = ?'
                );
                $saveCourse->execute([
                  $courseForm['course_code'],
                  $courseForm['course_name'],
                  $duration,
                  $totalSemesters,
                  $courseFormId,
                ]);
              } else {
                $saveCourse = $pdo->prepare(
                  'INSERT INTO courses (course_code, course_name, duration_months, total_semesters)
                   VALUES (?, ?, ?, ?)'
                );
                $saveCourse->execute([
                  $courseForm['course_code'],
                  $courseForm['course_name'],
                  $duration,
                  $totalSemesters,
                ]);
              }

              header('Location: coursemanagement.php?saved=1#courseForm');
              exit;
            }
          }
        } elseif ($action === 'save_semester') {
          $semesterFormId = $recordId === false ? 0 : $recordId;
          $semesterForm['course_id'] = courseManagementInput('course_id');
          $semesterForm['semester_number'] = courseManagementInput('semester_number');
          $semesterForm['academic_year'] = courseManagementInput('academic_year');

          $courseId = filter_var($semesterForm['course_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
          $semesterNumber = filter_var($semesterForm['semester_number'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

          if ($courseId === false || $semesterNumber === false ||
              $semesterForm['academic_year'] === '' || strlen($semesterForm['academic_year']) > 20) {
            $pageError = 'Select a course, enter a valid semester number, and enter an academic year (up to 20 characters).';
          } else {
            $courseLookup = $pdo->prepare('SELECT total_semesters FROM courses WHERE course_id = ? LIMIT 1');
            $courseLookup->execute([$courseId]);
            $courseTotalSemesters = $courseLookup->fetchColumn();

            if ($courseTotalSemesters === false || $semesterNumber > (int) $courseTotalSemesters) {
              $pageError = 'Select an existing course and a semester number within that course total.';
            } else {
              $duplicateSemester = $pdo->prepare(
                'SELECT semester_id
                 FROM semesters
                 WHERE course_id = ? AND semester_number = ? AND academic_year = ? AND semester_id <> ?
                 LIMIT 1'
              );
              $duplicateSemester->execute([
                $courseId,
                $semesterNumber,
                $semesterForm['academic_year'],
                $semesterFormId,
              ]);

              if ($duplicateSemester->fetch()) {
                $pageError = 'That semester is already listed for this course and academic year.';
              } elseif ($semesterFormId > 0) {
                $saveSemester = $pdo->prepare(
                  'UPDATE semesters
                   SET course_id = ?, semester_number = ?, academic_year = ?
                   WHERE semester_id = ?'
                );
                $saveSemester->execute([
                  $courseId,
                  $semesterNumber,
                  $semesterForm['academic_year'],
                  $semesterFormId,
                ]);
                header('Location: coursemanagement.php?saved=1#semesterForm');
                exit;
              } else {
                $saveSemester = $pdo->prepare(
                  'INSERT INTO semesters (course_id, semester_number, academic_year)
                   VALUES (?, ?, ?)'
                );
                $saveSemester->execute([$courseId, $semesterNumber, $semesterForm['academic_year']]);
                header('Location: coursemanagement.php?saved=1#semesterForm');
                exit;
              }
            }
          }
        } elseif ($action === 'save_module') {
          $moduleFormId = $recordId === false ? 0 : $recordId;
          $moduleForm['semester_id'] = courseManagementInput('semester_id');
          $moduleForm['module_code'] = courseManagementInput('module_code');
          $moduleForm['module_name'] = courseManagementInput('module_name');
          $moduleForm['description'] = courseManagementInput('description');

          $semesterId = filter_var($moduleForm['semester_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

          if ($semesterId === false ||
              $moduleForm['module_code'] === '' || strlen($moduleForm['module_code']) > 50 ||
              $moduleForm['module_name'] === '' || strlen($moduleForm['module_name']) > 150) {
            $pageError = 'Select a semester and enter a module code and name.';
          } else {
            $semesterLookup = $pdo->prepare('SELECT semester_id FROM semesters WHERE semester_id = ? LIMIT 1');
            $semesterLookup->execute([$semesterId]);

            if ($semesterLookup->fetchColumn() === false) {
              $pageError = 'Select an existing semester.';
            } else {
              $duplicateModule = $pdo->prepare(
                'SELECT module_id FROM modules WHERE module_code = ? AND module_id <> ? LIMIT 1'
              );
              $duplicateModule->execute([$moduleForm['module_code'], $moduleFormId]);

              if ($duplicateModule->fetch()) {
                $pageError = 'A module with this code already exists.';
              } elseif ($moduleFormId > 0) {
                $saveModule = $pdo->prepare(
                  'UPDATE modules
                   SET semester_id = ?, module_code = ?, module_name = ?, description = ?
                   WHERE module_id = ?'
                );
                $saveModule->execute([
                  $semesterId,
                  $moduleForm['module_code'],
                  $moduleForm['module_name'],
                  $moduleForm['description'] === '' ? null : $moduleForm['description'],
                  $moduleFormId,
                ]);
                header('Location: coursemanagement.php?saved=1#moduleForm');
                exit;
              } else {
                $saveModule = $pdo->prepare(
                  'INSERT INTO modules (semester_id, module_code, module_name, description)
                   VALUES (?, ?, ?, ?)'
                );
                $saveModule->execute([
                  $semesterId,
                  $moduleForm['module_code'],
                  $moduleForm['module_name'],
                  $moduleForm['description'] === '' ? null : $moduleForm['description'],
                ]);
                header('Location: coursemanagement.php?saved=1#moduleForm');
                exit;
              }
            }
          }
        } elseif (in_array($action, ['delete_course', 'delete_semester', 'delete_module'], true)) {
          if ($recordId === false) {
            $pageError = 'The selected record could not be found.';
          } elseif ($action === 'delete_course') {
            $relatedRows = $pdo->prepare('SELECT COUNT(*) FROM semesters WHERE course_id = ?');
            $relatedRows->execute([$recordId]);

            if ((int) $relatedRows->fetchColumn() > 0) {
              $pageError = 'Delete this course’s semesters and modules before deleting the course.';
            } else {
              $deleteCourse = $pdo->prepare('DELETE FROM courses WHERE course_id = ?');
              $deleteCourse->execute([$recordId]);
              header('Location: coursemanagement.php?saved=1#courseForm');
              exit;
            }
          } elseif ($action === 'delete_semester') {
            $relatedRows = $pdo->prepare('SELECT COUNT(*) FROM modules WHERE semester_id = ?');
            $relatedRows->execute([$recordId]);

            if ((int) $relatedRows->fetchColumn() > 0) {
              $pageError = 'Delete this semester’s modules before deleting the semester.';
            } else {
              $deleteSemester = $pdo->prepare('DELETE FROM semesters WHERE semester_id = ?');
              $deleteSemester->execute([$recordId]);
              header('Location: coursemanagement.php?saved=1#semesterForm');
              exit;
            }
          } else {
            $relatedRows = $pdo->prepare(
              'SELECT
                 (SELECT COUNT(*) FROM course_materials WHERE module_id = ?) +
                 (SELECT COUNT(*) FROM assignments WHERE module_id = ?)'
            );
            $relatedRows->execute([$recordId, $recordId]);

            if ((int) $relatedRows->fetchColumn() > 0) {
              $pageError = 'This module has course materials or assignments. Delete those records before deleting the module.';
            } else {
              $deleteModule = $pdo->prepare('DELETE FROM modules WHERE module_id = ?');
              $deleteModule->execute([$recordId]);
              header('Location: coursemanagement.php?saved=1#moduleForm');
              exit;
            }
          }
        } else {
          $pageError = 'Choose a valid form action and try again.';
        }
      } catch (PDOException $error) {
        if ((string) $error->getCode() === '23000' &&
            isset($error->errorInfo[1]) &&
            (int) $error->errorInfo[1] === 1062) {
          $pageError = 'A course or module with that code already exists.';
        } else {
          throw $error;
        }
      }
    }
  } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $editCourseId = filter_var($_GET['edit_course'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $editSemesterId = filter_var($_GET['edit_semester'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $editModuleId = filter_var($_GET['edit_module'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($editCourseId !== false) {
      $editCourse = $pdo->prepare('SELECT * FROM courses WHERE course_id = ? LIMIT 1');
      $editCourse->execute([$editCourseId]);
      $record = $editCourse->fetch();
      if ($record) {
        $courseFormId = (int) $record['course_id'];
        $courseForm = $record;
      }
    } elseif ($editSemesterId !== false) {
      $editSemester = $pdo->prepare('SELECT * FROM semesters WHERE semester_id = ? LIMIT 1');
      $editSemester->execute([$editSemesterId]);
      $record = $editSemester->fetch();
      if ($record) {
        $semesterFormId = (int) $record['semester_id'];
        $semesterForm = $record;
      }
    } elseif ($editModuleId !== false) {
      $editModule = $pdo->prepare('SELECT * FROM modules WHERE module_id = ? LIMIT 1');
      $editModule->execute([$editModuleId]);
      $record = $editModule->fetch();
      if ($record) {
        $moduleFormId = (int) $record['module_id'];
        $moduleForm = $record;
      }
    }
  }

  $courseList = $pdo->query(
    'SELECT course_id, course_code, course_name, duration_months, total_semesters
     FROM courses
     ORDER BY course_name'
  );
  $courses = $courseList->fetchAll();

  $semesterList = $pdo->query(
    'SELECT s.semester_id, s.course_id, s.semester_number, s.academic_year,
            c.course_code, c.course_name
     FROM semesters s
     INNER JOIN courses c ON c.course_id = s.course_id
     ORDER BY c.course_name, s.semester_number, s.academic_year'
  );
  $semesters = $semesterList->fetchAll();

  $moduleList = $pdo->query(
    'SELECT m.module_id, m.semester_id, m.module_code, m.module_name, m.description,
            s.semester_number, s.academic_year, c.course_code, c.course_name
     FROM modules m
     INNER JOIN semesters s ON s.semester_id = m.semester_id
     INNER JOIN courses c ON c.course_id = s.course_id
     ORDER BY c.course_name, s.semester_number, m.module_name'
  );
  $modules = $moduleList->fetchAll();
} catch (PDOException $error) {
  error_log('Course management database error: ' . $error->getMessage());
  http_response_code(500);
  $pageError = 'Course information could not be loaded or saved. Check the database setup and PHP error log.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Course Management - DTET</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/common.css" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column min-vh-100 page-course-coursemanagement-php">
  <?php
  $headerBasePath = '../';
  require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'header.php';
  ?>

  <main class="container-fluid px-4 py-4 flex-fill">
    <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
      <div>
        <h1 class="page-title mb-1">Course Management</h1>
        <p class="text-muted mb-0">Manage courses, semesters, and modules.</p>
      </div>
      <span class="badge badge-primary">Admin only</span>
    </div>

    <?php if ($pageError !== ''): ?>
      <div class="alert alert-danger" role="alert"><?php echo courseManagementEscape($pageError); ?></div>
    <?php elseif ($pageSuccess !== ''): ?>
      <div class="alert alert-success" role="status"><?php echo courseManagementEscape($pageSuccess); ?></div>
    <?php endif; ?>

    <div class="row">
      <section class="col-lg-6 mb-3" aria-labelledby="coursesHeading">
        <div class="card management-section shadow-sm">
          <div class="card-header">
            <h2 class="mb-0" id="coursesHeading">Courses</h2>
          </div>
          <div class="card-body">
            <form id="courseForm" method="post">
              <input type="hidden" name="csrf_token" value="<?php echo courseManagementEscape($_SESSION['course_management_csrf']); ?>">
              <input type="hidden" name="action" value="save_course">
              <input type="hidden" name="record_id" value="<?php echo $courseFormId; ?>">
              <div class="form-group">
                <label for="courseCode">Course Code</label>
                <input class="form-control" id="courseCode" name="course_code" type="text" maxlength="50" required value="<?php echo courseManagementEscape($courseForm['course_code']); ?>">
              </div>
              <div class="form-group">
                <label for="courseName">Course Name</label>
                <input class="form-control" id="courseName" name="course_name" type="text" maxlength="150" required value="<?php echo courseManagementEscape($courseForm['course_name']); ?>">
              </div>
              <div class="form-row">
                <div class="form-group col-md-6">
                  <label for="courseDuration">Duration (months)</label>
                  <input class="form-control" id="courseDuration" name="duration_months" type="number" min="1" required value="<?php echo courseManagementEscape($courseForm['duration_months']); ?>">
                </div>
                <div class="form-group col-md-6">
                  <label for="courseTotalSemesters">Total Semesters</label>
                  <input class="form-control" id="courseTotalSemesters" name="total_semesters" type="number" min="1" required value="<?php echo courseManagementEscape($courseForm['total_semesters']); ?>">
                </div>
              </div>
              <button class="btn btn-primary" type="submit"><?php echo $courseFormId > 0 ? 'Save Course' : 'Add Course'; ?></button>
              <?php if ($courseFormId > 0): ?>
                <a class="btn btn-outline-secondary" href="coursemanagement.php#courseForm">Cancel</a>
              <?php endif; ?>
            </form>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
              <thead class="thead-light">
                <tr><th scope="col">Course</th><th scope="col">Duration</th><th scope="col">Semesters</th><th scope="col">Actions</th></tr>
              </thead>
              <tbody>
                <?php if (!$courses): ?>
                  <tr><td class="empty-state py-3" colspan="4">No courses added yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($courses as $course): ?>
                  <tr>
                    <td><strong><?php echo courseManagementEscape($course['course_code']); ?></strong><br><?php echo courseManagementEscape($course['course_name']); ?></td>
                    <td><?php echo courseManagementEscape($course['duration_months']); ?> months</td>
                    <td><?php echo courseManagementEscape($course['total_semesters']); ?></td>
                    <td class="record-actions">
                      <a class="btn btn-sm btn-outline-primary" href="coursemanagement.php?edit_course=<?php echo (int) $course['course_id']; ?>#courseForm">Edit</a>
                      <form class="d-inline" method="post" onsubmit="return confirm('Delete this course? Courses with semesters cannot be deleted.');">
                        <input type="hidden" name="csrf_token" value="<?php echo courseManagementEscape($_SESSION['course_management_csrf']); ?>">
                        <input type="hidden" name="action" value="delete_course">
                        <input type="hidden" name="record_id" value="<?php echo (int) $course['course_id']; ?>">
                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <section class="col-lg-6 mb-3" aria-labelledby="semestersHeading">
        <div class="card management-section shadow-sm">
          <div class="card-header">
            <h2 class="mb-0" id="semestersHeading">Semesters</h2>
          </div>
          <div class="card-body">
            <form id="semesterForm" method="post">
              <input type="hidden" name="csrf_token" value="<?php echo courseManagementEscape($_SESSION['course_management_csrf']); ?>">
              <input type="hidden" name="action" value="save_semester">
              <input type="hidden" name="record_id" value="<?php echo $semesterFormId; ?>">
              <div class="form-group">
                <label for="semesterCourse">Course</label>
                <select class="form-control" id="semesterCourse" name="course_id" required>
                  <option value="">Select course</option>
                  <?php foreach ($courses as $course): ?>
                    <option value="<?php echo (int) $course['course_id']; ?>" <?php echo (string) $semesterForm['course_id'] === (string) $course['course_id'] ? 'selected' : ''; ?>>
                      <?php echo courseManagementEscape($course['course_code'] . ' - ' . $course['course_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-row">
                <div class="form-group col-md-6">
                  <label for="semesterNumber">Semester Number</label>
                  <input class="form-control" id="semesterNumber" name="semester_number" type="number" min="1" required value="<?php echo courseManagementEscape($semesterForm['semester_number']); ?>">
                </div>
                <div class="form-group col-md-6">
                  <label for="academicYear">Academic Year</label>
                  <input class="form-control" id="academicYear" name="academic_year" type="text" maxlength="20" placeholder="For example, 2026/2027" required value="<?php echo courseManagementEscape($semesterForm['academic_year']); ?>">
                </div>
              </div>
              <button class="btn btn-primary" type="submit"><?php echo $semesterFormId > 0 ? 'Save Semester' : 'Add Semester'; ?></button>
              <?php if ($semesterFormId > 0): ?>
                <a class="btn btn-outline-secondary" href="coursemanagement.php#semesterForm">Cancel</a>
              <?php endif; ?>
            </form>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
              <thead class="thead-light">
                <tr><th scope="col">Semester</th><th scope="col">Academic Year</th><th scope="col">Course</th><th scope="col">Actions</th></tr>
              </thead>
              <tbody>
                <?php if (!$semesters): ?>
                  <tr><td class="empty-state py-3" colspan="4">No semesters added yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($semesters as $semester): ?>
                  <tr>
                    <td>Semester <?php echo courseManagementEscape($semester['semester_number']); ?></td>
                    <td><?php echo courseManagementEscape($semester['academic_year']); ?></td>
                    <td><?php echo courseManagementEscape($semester['course_code'] . ' - ' . $semester['course_name']); ?></td>
                    <td class="record-actions">
                      <a class="btn btn-sm btn-outline-primary" href="coursemanagement.php?edit_semester=<?php echo (int) $semester['semester_id']; ?>#semesterForm">Edit</a>
                      <form class="d-inline" method="post" onsubmit="return confirm('Delete this semester? Semesters with modules cannot be deleted.');">
                        <input type="hidden" name="csrf_token" value="<?php echo courseManagementEscape($_SESSION['course_management_csrf']); ?>">
                        <input type="hidden" name="action" value="delete_semester">
                        <input type="hidden" name="record_id" value="<?php echo (int) $semester['semester_id']; ?>">
                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <section class="col-lg-6 mb-3" aria-labelledby="modulesHeading">
        <div class="card management-section shadow-sm">
          <div class="card-header">
            <h2 class="mb-0" id="modulesHeading">Modules</h2>
          </div>
          <div class="card-body">
            <form id="moduleForm" method="post">
              <input type="hidden" name="csrf_token" value="<?php echo courseManagementEscape($_SESSION['course_management_csrf']); ?>">
              <input type="hidden" name="action" value="save_module">
              <input type="hidden" name="record_id" value="<?php echo $moduleFormId; ?>">
              <div class="form-group">
                <label for="moduleSemester">Semester</label>
                <select class="form-control" id="moduleSemester" name="semester_id" required>
                  <option value="">Select semester</option>
                  <?php foreach ($semesters as $semester): ?>
                    <option value="<?php echo (int) $semester['semester_id']; ?>" <?php echo (string) $moduleForm['semester_id'] === (string) $semester['semester_id'] ? 'selected' : ''; ?>>
                      <?php echo courseManagementEscape('Semester ' . $semester['semester_number'] . ' (' . $semester['academic_year'] . ') - ' . $semester['course_code']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label for="moduleCode">Module Code</label>
                <input class="form-control" id="moduleCode" name="module_code" type="text" maxlength="50" required value="<?php echo courseManagementEscape($moduleForm['module_code']); ?>">
              </div>
              <div class="form-group">
                <label for="moduleName">Module Name</label>
                <input class="form-control" id="moduleName" name="module_name" type="text" maxlength="150" required value="<?php echo courseManagementEscape($moduleForm['module_name']); ?>">
              </div>
              <div class="form-group">
                <label for="moduleDescription">Description</label>
                <textarea class="form-control" id="moduleDescription" name="description" rows="2"><?php echo courseManagementEscape($moduleForm['description']); ?></textarea>
              </div>
              <button class="btn btn-primary" type="submit"><?php echo $moduleFormId > 0 ? 'Save Module' : 'Add Module'; ?></button>
              <?php if ($moduleFormId > 0): ?>
                <a class="btn btn-outline-secondary" href="coursemanagement.php#moduleForm">Cancel</a>
              <?php endif; ?>
            </form>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
              <thead class="thead-light">
                <tr><th scope="col">Module</th><th scope="col">Description</th><th scope="col">Semester</th><th scope="col">Actions</th></tr>
              </thead>
              <tbody>
                <?php if (!$modules): ?>
                  <tr><td class="empty-state py-3" colspan="4">No modules added yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($modules as $module): ?>
                  <tr>
                    <td><strong><?php echo courseManagementEscape($module['module_code']); ?></strong><br><?php echo courseManagementEscape($module['module_name']); ?></td>
                    <td><?php echo courseManagementEscape($module['description'] ?: '—'); ?></td>
                    <td><?php echo courseManagementEscape('Semester ' . $module['semester_number'] . ' (' . $module['academic_year'] . ') - ' . $module['course_code']); ?></td>
                    <td class="record-actions">
                      <a class="btn btn-sm btn-outline-primary" href="coursemanagement.php?edit_module=<?php echo (int) $module['module_id']; ?>#moduleForm">Edit</a>
                      <form class="d-inline" method="post" onsubmit="return confirm('Delete this module? Modules with course materials or assignments cannot be deleted.');">
                        <input type="hidden" name="csrf_token" value="<?php echo courseManagementEscape($_SESSION['course_management_csrf']); ?>">
                        <input type="hidden" name="action" value="delete_module">
                        <input type="hidden" name="record_id" value="<?php echo (int) $module['module_id']; ?>">
                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </div>
  </main>

  <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
</body>
</html>
