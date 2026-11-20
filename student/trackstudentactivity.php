<?php
session_start();

function activityPageEscape($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function activityPageDateIsValid(string $date): bool
{
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    return false;
  }

  $year = (int) substr($date, 0, 4);
  $month = (int) substr($date, 5, 2);
  $day = (int) substr($date, 8, 2);

  return checkdate($month, $day, $year);
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, [
  'options' => ['min_range' => 1],
]);
$userRole = strtolower((string) ($_SESSION['role'] ?? ''));

if ($userId === false) {
  header('Location: ../login.php');
  exit;
}

if ($userRole !== 'student') {
  http_response_code(403);
  exit('This page is available to student accounts only.');
}

$activities = [];
$actions = [];
$errorMessage = '';
$actionFilter = is_string($_GET['action'] ?? null) ? trim($_GET['action']) : '';
$fromDate = is_string($_GET['fromDate'] ?? null) ? trim($_GET['fromDate']) : '';
$toDate = is_string($_GET['toDate'] ?? null) ? trim($_GET['toDate']) : '';

$totalCount = 0;
$materialCount = 0;
$assignmentCount = 0;

if ($fromDate !== '' && !activityPageDateIsValid($fromDate)) {
  $errorMessage = 'Enter a valid start date.';
  $fromDate = '';
}
if ($toDate !== '' && !activityPageDateIsValid($toDate)) {
  $errorMessage = 'Enter a valid end date.';
  $toDate = '';
}
if ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate) {
  $errorMessage = 'The start date must be on or before the end date.';
  $fromDate = '';
  $toDate = '';
}

try {
  $pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

  $actionLookup = $pdo->prepare(
    'SELECT DISTINCT action_type
     FROM activity_logs
     WHERE user_id = ?
     ORDER BY action_type'
  );
  $actionLookup->execute([$userId]);
  $actions = $actionLookup->fetchAll(PDO::FETCH_COLUMN);

  if ($actionFilter !== '' && !in_array($actionFilter, $actions, true)) {
    $errorMessage = 'Choose an action from the list.';
    $actionFilter = '';
  }

  $sql = 'SELECT log_id, material_id, assignment_id, action_type, timestamp, ip_address
          FROM activity_logs
          WHERE user_id = ?';
  $parameters = [$userId];

  if ($actionFilter !== '') {
    $sql .= ' AND action_type = ?';
    $parameters[] = $actionFilter;
  }
  if ($fromDate !== '') {
    $sql .= ' AND timestamp >= ?';
    $parameters[] = $fromDate . ' 00:00:00';
  }
  if ($toDate !== '') {
    $sql .= ' AND timestamp <= ?';
    $parameters[] = $toDate . ' 23:59:59';
  }

  $sql .= ' ORDER BY timestamp DESC';
  $activityLookup = $pdo->prepare($sql);
  $activityLookup->execute($parameters);
  $activities = $activityLookup->fetchAll();

  foreach ($activities as $activity) {
    $totalCount += 1;
    if ($activity['material_id'] !== null) {
      $materialCount += 1;
    }
    if ($activity['assignment_id'] !== null) {
      $assignmentCount += 1;
    }
  }
} catch (PDOException $error) {
  error_log('Student activity database error: ' . $error->getMessage());
  http_response_code(500);
  $activities = [];
  $errorMessage = 'Your activity could not be loaded. Check the database setup and PHP error log.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My Learning Activity - DTET</title>
  <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/common.css" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column min-vh-100 page-student-trackstudentactivity-php">
  <?php
  $headerBasePath = '../';
  require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'header.php';
  ?>

  <main class="container-fluid px-4 py-4 flex-fill">
    <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
      <div>
        <h1 class="page-title mb-1">My Learning Activity</h1>
        <p class="text-muted mb-0">Review your course material and assignment activity.</p>
      </div>
      <a class="btn btn-outline-primary mt-2 mt-sm-0" href="../course/mycourses.php">My Courses</a>
    </div>

    <?php if ($errorMessage !== ''): ?>
      <div class="alert alert-danger" role="alert"><?php echo activityPageEscape($errorMessage); ?></div>
    <?php endif; ?>

    <section class="row mb-3" aria-label="Activity summary">
      <div class="col-md-4 mb-3">
        <div class="card activity-card shadow-sm">
          <div class="card-body">
            <h2 class="h6 text-muted">Matching activity</h2>
            <p class="activity-count mb-0"><?php echo $totalCount; ?></p>
          </div>
        </div>
      </div>
      <div class="col-md-4 mb-3">
        <div class="card activity-card shadow-sm">
          <div class="card-body">
            <h2 class="h6 text-muted">Material activity</h2>
            <p class="activity-count mb-0"><?php echo $materialCount; ?></p>
          </div>
        </div>
      </div>
      <div class="col-md-4 mb-3">
        <div class="card activity-card shadow-sm">
          <div class="card-body">
            <h2 class="h6 text-muted">Assignment activity</h2>
            <p class="activity-count mb-0"><?php echo $assignmentCount; ?></p>
          </div>
        </div>
      </div>
    </section>

    <section class="card shadow-sm" aria-labelledby="activityHeading">
      <div class="card-header bg-white">
        <h2 class="h5 mb-0" id="activityHeading">Activity log</h2>
      </div>
      <div class="card-body border-bottom">
        <form action="trackstudentactivity.php" method="get" class="form-row align-items-end">
          <div class="form-group col-md-4">
            <label for="actionFilter">Action</label>
            <select class="form-control" id="actionFilter" name="action">
              <option value="">All actions</option>
              <?php foreach ($actions as $action): ?>
                <option value="<?php echo activityPageEscape($action); ?>"
                  <?php echo $actionFilter === $action ? 'selected' : ''; ?>>
                  <?php echo activityPageEscape($action); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group col-md-3">
            <label for="fromDate">From</label>
            <input class="form-control" id="fromDate" name="fromDate" type="date"
                   value="<?php echo activityPageEscape($fromDate); ?>">
          </div>
          <div class="form-group col-md-3">
            <label for="toDate">To</label>
            <input class="form-control" id="toDate" name="toDate" type="date"
                   value="<?php echo activityPageEscape($toDate); ?>">
          </div>
          <div class="form-group col-md-2 filter-actions">
            <button class="btn btn-primary" type="submit">Filter</button>
            <a class="btn btn-outline-secondary" href="trackstudentactivity.php">Clear</a>
          </div>
        </form>
      </div>

      <div class="table-responsive">
        <table class="table table-hover activity-table mb-0">
          <thead class="thead-light">
            <tr>
              <th scope="col">Date &amp; time</th>
              <th scope="col">Action</th>
              <th scope="col">Material ID</th>
              <th scope="col">Assignment ID</th>
              <th scope="col">IP address</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$activities): ?>
              <tr>
                <td class="empty-state" colspan="5">
                  <?php echo $errorMessage !== '' ? 'Activity is unavailable.' : 'No activity matches these filters.'; ?>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($activities as $activity): ?>
                <tr>
                  <td><?php echo activityPageEscape($activity['timestamp']); ?></td>
                  <td><?php echo activityPageEscape($activity['action_type']); ?></td>
                  <td><?php echo activityPageEscape($activity['material_id'] ?? '—'); ?></td>
                  <td><?php echo activityPageEscape($activity['assignment_id'] ?? '—'); ?></td>
                  <td><?php echo activityPageEscape($activity['ip_address'] ?? '—'); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'footer.php'; ?>
</body>
</html>
