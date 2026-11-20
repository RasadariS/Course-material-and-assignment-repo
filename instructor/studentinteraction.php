<?php
declare(strict_types=1);

session_start();

function studentInteractionEscape($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function studentInteractionFormatDate($value): string
{
  if (!$value) {
    return '—';
  }

  $time = strtotime((string) $value);
  if ($time === false) {
    return (string) $value;
  }

  return date('Y-m-d H:i', $time);
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

$interactions = [];
$errorMessage = '';
$viewCount = 0;
$downloadCount = 0;
$submissionCount = 0;
$earlyCount = 0;
$onTimeCount = 0;
$lateCount = 0;

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
    $activityLookup = $pdo->prepare(
      'SELECT al.action_type, al.timestamp, u.full_name, c.course_name, cm.title AS material_title
       FROM activity_logs al
       INNER JOIN users u ON u.user_id = al.user_id
       INNER JOIN students st ON st.user_id = u.user_id
       INNER JOIN course_materials cm ON cm.material_id = al.material_id
       INNER JOIN modules m ON m.module_id = cm.module_id
       INNER JOIN instructor_modules im
         ON im.module_id = m.module_id AND im.instructor_id = ?
       INNER JOIN semesters s ON s.semester_id = m.semester_id
       INNER JOIN courses c ON c.course_id = s.course_id
       ORDER BY al.timestamp DESC'
    );
    $activityLookup->execute([$instructorId]);

    foreach ($activityLookup->fetchAll() as $activity) {
      $actionType = strtolower(str_replace([' ', '-'], '_', $activity['action_type']));
      $category = '';
      $activityName = '';

      if (strpos($actionType, 'material') !== false && strpos($actionType, 'view') !== false) {
        $category = 'material_view';
        $activityName = 'Viewed material';
      } elseif (strpos($actionType, 'material') !== false && strpos($actionType, 'download') !== false) {
        $category = 'material_download';
        $activityName = 'Downloaded material';
      }

      if ($category !== '') {
        $interactions[] = [
          'sort_time' => strtotime($activity['timestamp']) ?: 0,
          'timestamp' => $activity['timestamp'],
          'student_name' => $activity['full_name'],
          'course_name' => $activity['course_name'],
          'activity' => $activityName,
          'category' => $category,
          'content_title' => $activity['material_title'],
          'submitted' => null,
          'deadline' => null,
          'timing' => 'n/a',
        ];
      }
    }

    $submissionLookup = $pdo->prepare(
      'SELECT sub.submission_timestamp, sub.is_late,
              a.title AS assignment_title, a.deadline,
              u.full_name, c.course_name
       FROM submissions sub
       INNER JOIN students st ON st.student_id = sub.student_id
       INNER JOIN users u ON u.user_id = st.user_id
       INNER JOIN assignments a ON a.assignment_id = sub.assignment_id
       INNER JOIN modules m ON m.module_id = a.module_id
       INNER JOIN instructor_modules im
         ON im.module_id = m.module_id AND im.instructor_id = ?
       INNER JOIN semesters s ON s.semester_id = m.semester_id
       INNER JOIN courses c ON c.course_id = s.course_id
       ORDER BY sub.submission_timestamp DESC'
    );
    $submissionLookup->execute([$instructorId]);

    foreach ($submissionLookup->fetchAll() as $submission) {
      $submittedTime = strtotime($submission['submission_timestamp']);
      $deadlineTime = strtotime($submission['deadline']);

      if ((int) $submission['is_late'] === 1) {
        $timing = 'late';
      } elseif ($submittedTime === false || $deadlineTime === false) {
        $timing = 'n/a';
      } elseif ($submittedTime < $deadlineTime) {
        $timing = 'early';
      } else {
        $timing = 'on-time';
      }

      $interactions[] = [
        'sort_time' => $submittedTime ?: 0,
        'timestamp' => $submission['submission_timestamp'],
        'student_name' => $submission['full_name'],
        'course_name' => $submission['course_name'],
        'activity' => 'Submitted assignment',
        'category' => 'assignment_submit',
        'content_title' => $submission['assignment_title'],
        'submitted' => $submission['submission_timestamp'],
        'deadline' => $submission['deadline'],
        'timing' => $timing,
      ];
    }

    usort($interactions, function ($left, $right) {
      if ($left['sort_time'] === $right['sort_time']) {
        return 0;
      }
      return $left['sort_time'] > $right['sort_time'] ? -1 : 1;
    });

    foreach ($interactions as $interaction) {
      if ($interaction['category'] === 'material_view') {
        $viewCount += 1;
      } elseif ($interaction['category'] === 'material_download') {
        $downloadCount += 1;
      } elseif ($interaction['category'] === 'assignment_submit') {
        $submissionCount += 1;
        if ($interaction['timing'] === 'early') {
          $earlyCount += 1;
        } elseif ($interaction['timing'] === 'on-time') {
          $onTimeCount += 1;
        } elseif ($interaction['timing'] === 'late') {
          $lateCount += 1;
        }
      }
    }
  }
} catch (PDOException $error) {
  error_log('Student interaction report database error: ' . $error->getMessage());
  http_response_code(500);
  $interactions = [];
  $viewCount = 0;
  $downloadCount = 0;
  $submissionCount = 0;
  $earlyCount = 0;
  $onTimeCount = 0;
  $lateCount = 0;
  $errorMessage = 'Student interactions could not be loaded. Check the database setup and PHP error log.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Student Interactions - DTET</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/common.css" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column min-vh-100 page-instructor-studentinteraction-php">
  <?php
  $headerBasePath = '../';
  require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'header.php';
  ?>

  <main class="container-fluid px-4 py-4 flex-fill">
    <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
      <div>
        <h1 class="page-title mb-1">Student Interactions</h1>
        <p class="text-muted mb-0">Learning material engagement and assignment submission timing.</p>
      </div>
      <a class="btn btn-outline-primary mt-2 mt-sm-0" href="../course/courseupload.php">Course Content</a>
    </div>

    <?php if ($errorMessage !== ''): ?>
      <div class="alert alert-danger" role="alert"><?php echo studentInteractionEscape($errorMessage); ?></div>
    <?php endif; ?>

    <section class="row mb-3" aria-label="Interaction summary">
      <div class="summary-column mb-3">
        <div class="card summary-card shadow-sm"><div class="card-body">
          <h2 class="summary-label">Material views</h2><p class="summary-value" id="viewCount"><?php echo $viewCount; ?></p>
        </div></div>
      </div>
      <div class="summary-column mb-3">
        <div class="card summary-card shadow-sm"><div class="card-body">
          <h2 class="summary-label">Material downloads</h2><p class="summary-value" id="downloadCount"><?php echo $downloadCount; ?></p>
        </div></div>
      </div>
      <div class="summary-column mb-3">
        <div class="card summary-card submissions shadow-sm"><div class="card-body">
          <h2 class="summary-label">Total submissions</h2><p class="summary-value" id="submissionCount"><?php echo $submissionCount; ?></p>
        </div></div>
      </div>
      <div class="summary-column mb-3">
        <div class="card summary-card submissions shadow-sm"><div class="card-body">
          <h2 class="summary-label">Early submissions</h2><p class="summary-value" id="earlyCount"><?php echo $earlyCount; ?></p>
        </div></div>
      </div>
      <div class="summary-column mb-3">
        <div class="card summary-card submissions shadow-sm"><div class="card-body">
          <h2 class="summary-label">On-time submissions</h2><p class="summary-value" id="onTimeCount"><?php echo $onTimeCount; ?></p>
        </div></div>
      </div>
      <div class="summary-column mb-3">
        <div class="card summary-card submissions shadow-sm"><div class="card-body">
          <h2 class="summary-label">Late submissions</h2><p class="summary-value" id="lateCount"><?php echo $lateCount; ?></p>
        </div></div>
      </div>
    </section>

    <section class="card shadow-sm" aria-labelledby="interactionHeading">
      <div class="card-header bg-white">
        <h2 class="h5 mb-0" id="interactionHeading">Interaction log</h2>
      </div>
      <div class="card-body border-bottom">
        <div class="form-row align-items-end">
          <div class="form-group col-md-3">
            <label for="searchFilter">Student or course</label>
            <input class="form-control" id="searchFilter" type="search" placeholder="Search names or courses">
          </div>
          <div class="form-group col-md-3">
            <label for="typeFilter">Activity</label>
            <select class="form-control" id="typeFilter">
              <option value="">All activity</option>
              <option value="material_view">Viewed material</option>
              <option value="material_download">Downloaded material</option>
              <option value="assignment_submit">Submitted assignment</option>
            </select>
          </div>
          <div class="form-group col-md-3">
            <label for="timingFilter">Submission timing</label>
            <select class="form-control" id="timingFilter">
              <option value="">All timing</option>
              <option value="early">Early</option>
              <option value="on-time">On time</option>
              <option value="late">Late</option>
            </select>
          </div>
          <div class="form-group col-md-3 filter-actions">
            <button class="btn btn-outline-secondary" type="button" id="clearFilters">Clear filters</button>
          </div>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-hover interaction-table mb-0">
          <thead class="thead-light">
            <tr>
              <th scope="col">Date &amp; time</th>
              <th scope="col">Student</th>
              <th scope="col">Course</th>
              <th scope="col">Activity</th>
              <th scope="col">Material / assignment</th>
              <th scope="col">Submitted</th>
              <th scope="col">Deadline</th>
              <th scope="col">Timing</th>
            </tr>
          </thead>
          <tbody id="interactionRows">
            <?php foreach ($interactions as $interaction): ?>
              <tr data-activity="<?php echo studentInteractionEscape($interaction['category']); ?>"
                  data-timing="<?php echo studentInteractionEscape($interaction['timing']); ?>"
                  data-search="<?php echo studentInteractionEscape($interaction['student_name'] . ' ' . $interaction['course_name']); ?>">
                <td><?php echo studentInteractionEscape(studentInteractionFormatDate($interaction['timestamp'])); ?></td>
                <td><?php echo studentInteractionEscape($interaction['student_name']); ?></td>
                <td><?php echo studentInteractionEscape($interaction['course_name']); ?></td>
                <td><?php echo studentInteractionEscape($interaction['activity']); ?></td>
                <td><?php echo studentInteractionEscape($interaction['content_title']); ?></td>
                <td><?php echo studentInteractionEscape(studentInteractionFormatDate($interaction['submitted'])); ?></td>
                <td><?php echo studentInteractionEscape(studentInteractionFormatDate($interaction['deadline'])); ?></td>
                <td class="timing timing-<?php echo $interaction['timing'] === 'n/a' ? 'na' : studentInteractionEscape($interaction['timing']); ?>">
                  <?php echo $interaction['timing'] === 'n/a' ? '—' : studentInteractionEscape($interaction['timing'] === 'on-time' ? 'On time' : $interaction['timing']); ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <tr id="emptyRow" <?php echo $interactions ? 'hidden' : ''; ?>>
              <td class="empty-state" colspan="8">
                <?php
                if ($errorMessage !== '') {
                  echo studentInteractionEscape('Interaction records are unavailable.');
                } elseif ($interactions) {
                  echo studentInteractionEscape('No interactions match these filters.');
                } else {
                  echo studentInteractionEscape('No student interactions have been recorded.');
                }
                ?>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
  <script>
    var interactionRows = Array.prototype.slice.call(
      document.querySelectorAll('#interactionRows tr[data-activity]')
    );
    var emptyRow = document.getElementById('emptyRow');

    function filterInteractions() {
      var searchText = document.getElementById('searchFilter').value.toLowerCase();
      var selectedType = document.getElementById('typeFilter').value;
      var selectedTiming = document.getElementById('timingFilter').value;
      var counts = {
        material_view: 0,
        material_download: 0,
        assignment_submit: 0,
        early: 0,
        'on-time': 0,
        late: 0
      };
      var visibleCount = 0;

      interactionRows.forEach(function (row) {
        var activity = row.getAttribute('data-activity');
        var timing = row.getAttribute('data-timing');
        var matches = row.getAttribute('data-search').toLowerCase().indexOf(searchText) !== -1 &&
          (!selectedType || activity === selectedType) &&
          (!selectedTiming || timing === selectedTiming);

        row.hidden = !matches;
        if (matches) {
          visibleCount += 1;
          counts[activity] += 1;
          if (timing !== 'n/a') counts[timing] += 1;
        }
      });

      document.getElementById('viewCount').textContent = counts.material_view;
      document.getElementById('downloadCount').textContent = counts.material_download;
      document.getElementById('submissionCount').textContent = counts.assignment_submit;
      document.getElementById('earlyCount').textContent = counts.early;
      document.getElementById('onTimeCount').textContent = counts['on-time'];
      document.getElementById('lateCount').textContent = counts.late;
      emptyRow.hidden = visibleCount > 0;
      if (visibleCount === 0) {
        emptyRow.querySelector('td').textContent = interactionRows.length > 0
          ? 'No interactions match these filters.'
          : 'No student interactions have been recorded.';
      }
    }

    ['searchFilter', 'typeFilter', 'timingFilter'].forEach(function (id) {
      document.getElementById(id).addEventListener('input', filterInteractions);
      document.getElementById(id).addEventListener('change', filterInteractions);
    });
    document.getElementById('clearFilters').addEventListener('click', function () {
      document.getElementById('searchFilter').value = '';
      document.getElementById('typeFilter').value = '';
      document.getElementById('timingFilter').value = '';
      filterInteractions();
    });
  </script>
</body>
</html>
