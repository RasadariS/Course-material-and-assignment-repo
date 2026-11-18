<?php
declare(strict_types=1);

session_start();

function instructorProfileRespond(int $status, array $payload): void
{
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_SLASHES);
  exit;
}

function instructorProfilePhotoUrl(?string $path): ?string
{
  if (!$path || !preg_match('#^uploads/instructors/[a-f0-9]{32}\.(jpg|png|gif|webp)$#D', $path)) {
    return null;
  }

  return '../' . $path;
}

function removeInstructorProfilePhoto(?string $path): bool
{
  if (!$path || !preg_match('#^uploads/instructors/[a-f0-9]{32}\.(jpg|png|gif|webp)$#D', $path)) {
    return true;
  }

  $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR .
    'instructors' . DIRECTORY_SEPARATOR . basename($path);
  return !is_file($absolutePath) || unlink($absolutePath);
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($userId === false || strtolower((string) ($_SESSION['role'] ?? '')) !== 'instructor') {
  instructorProfileRespond(403, ['error' => 'Sign in as an instructor to manage this profile.']);
}

if (empty($_SESSION['instructor_profile_csrf'])) {
  $_SESSION['instructor_profile_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Allow: GET, POST');
  instructorProfileRespond(405, ['error' => 'This request method is not supported.']);
}

try {
  $pdo = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

  if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $lookup = $pdo->prepare(
      'SELECT u.nic_number, u.full_name, u.contact_number, u.email,
              i.employee_code, i.department, i.profile_photo
       FROM users u
       INNER JOIN instructors i ON i.user_id = u.user_id
       WHERE u.user_id = ? AND LOWER(u.role) = \'instructor\'
       LIMIT 1'
    );
    $lookup->execute([$userId]);
    $profile = $lookup->fetch();
    if (!$profile) {
      instructorProfileRespond(404, ['error' => 'No instructor profile was found for this account.']);
    }

    $profile['role'] = 'instructor';
    $profile['photo_url'] = instructorProfilePhotoUrl($profile['profile_photo']);
    unset($profile['profile_photo']);
    instructorProfileRespond(200, ['profile' => $profile, 'csrf_token' => $_SESSION['instructor_profile_csrf']]);
  }

  $submittedToken = $_POST['csrf_token'] ?? '';
  if (!is_string($submittedToken) || !hash_equals($_SESSION['instructor_profile_csrf'], $submittedToken)) {
    instructorProfileRespond(400, ['error' => 'Your profile form expired. Reload the page and try again.']);
  }

  $fields = ['nic_number', 'full_name', 'contact_number', 'email', 'employee_code', 'department'];
  $profile = [];
  foreach ($fields as $field) {
    $value = $_POST[$field] ?? '';
    if (!is_string($value)) {
      instructorProfileRespond(400, ['error' => 'Enter valid instructor profile details.']);
    }
    $profile[$field] = trim($value);
  }

  if ($profile['nic_number'] === '' || strlen($profile['nic_number']) > 20 ||
      $profile['full_name'] === '' || strlen($profile['full_name']) > 150 ||
      $profile['contact_number'] === '' || strlen($profile['contact_number']) > 20 ||
      $profile['email'] === '' || strlen($profile['email']) > 150 ||
      !filter_var($profile['email'], FILTER_VALIDATE_EMAIL) ||
      $profile['employee_code'] === '' || strlen($profile['employee_code']) > 50 ||
      $profile['department'] === '' || strlen($profile['department']) > 100) {
    instructorProfileRespond(400, ['error' => 'Check the required fields and their maximum lengths, and enter a valid email address.']);
  }

  $profileLookup = $pdo->prepare('SELECT instructor_id, profile_photo FROM instructors WHERE user_id = ? LIMIT 1');
  $profileLookup->execute([$userId]);
  $existingProfile = $profileLookup->fetch();
  if (!$existingProfile) {
    instructorProfileRespond(404, ['error' => 'No instructor profile was found for this account.']);
  }

  $duplicateUser = $pdo->prepare(
    'SELECT user_id FROM users
     WHERE (nic_number = ? OR email = ?) AND user_id <> ?
     LIMIT 1'
  );
  $duplicateUser->execute([$profile['nic_number'], $profile['email'], $userId]);
  if ($duplicateUser->fetch()) {
    instructorProfileRespond(409, ['error' => 'Another account already uses that NIC or email address.']);
  }

  $duplicateEmployeeCode = $pdo->prepare(
    'SELECT instructor_id FROM instructors WHERE employee_code = ? AND instructor_id <> ? LIMIT 1'
  );
  $duplicateEmployeeCode->execute([$profile['employee_code'], $existingProfile['instructor_id']]);
  if ($duplicateEmployeeCode->fetch()) {
    instructorProfileRespond(409, ['error' => 'That employee code is already assigned to another instructor.']);
  }

  $newPhotoPath = null;
  if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK || $_FILES['photo']['size'] > 2 * 1024 * 1024 ||
        !is_uploaded_file($_FILES['photo']['tmp_name'])) {
      instructorProfileRespond(400, ['error' => 'Choose an image smaller than 2 MB.']);
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['photo']['tmp_name']);
    $imageExtensions = [
      'image/jpeg' => '.jpg',
      'image/png' => '.png',
      'image/gif' => '.gif',
      'image/webp' => '.webp',
    ];
    if (!isset($imageExtensions[$mimeType])) {
      instructorProfileRespond(400, ['error' => 'Choose a JPEG, PNG, GIF, or WebP profile photo.']);
    }

    $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'instructors';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
      instructorProfileRespond(500, ['error' => 'The profile photo upload directory is unavailable.']);
    }

    $newPhotoPath = 'uploads/instructors/' . bin2hex(random_bytes(16)) . $imageExtensions[$mimeType];
    $absolutePhotoPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $newPhotoPath);
    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $absolutePhotoPath)) {
      instructorProfileRespond(500, ['error' => 'The profile photo could not be saved.']);
    }
  }

  try {
    $pdo->beginTransaction();
    $updateUser = $pdo->prepare(
      'UPDATE users SET nic_number = ?, full_name = ?, email = ?, contact_number = ? WHERE user_id = ?'
    );
    $updateUser->execute([
      $profile['nic_number'],
      $profile['full_name'],
      $profile['email'],
      $profile['contact_number'],
      $userId,
    ]);

    $photoPath = $newPhotoPath !== null ? $newPhotoPath : $existingProfile['profile_photo'];
    $updateInstructor = $pdo->prepare(
      'UPDATE instructors SET employee_code = ?, department = ?, profile_photo = ? WHERE user_id = ?'
    );
    $updateInstructor->execute([
      $profile['employee_code'],
      $profile['department'],
      $photoPath,
      $userId,
    ]);
    $pdo->commit();
  } catch (Throwable $error) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    if ($newPhotoPath !== null && !removeInstructorProfilePhoto($newPhotoPath)) {
      error_log('Unable to remove instructor profile photo after update rollback: ' . $newPhotoPath);
    }
    throw $error;
  }

  if ($newPhotoPath !== null && !removeInstructorProfilePhoto($existingProfile['profile_photo'])) {
    error_log('Unable to remove replaced instructor profile photo: ' . $existingProfile['profile_photo']);
  }
  $_SESSION['full_name'] = $profile['full_name'];
  $profile['role'] = 'instructor';
  $profile['photo_url'] = instructorProfilePhotoUrl($photoPath);
  instructorProfileRespond(200, ['profile' => $profile, 'message' => 'Instructor profile updated successfully.']);
} catch (PDOException $error) {
  error_log('Instructor profile database error: ' . $error->getMessage());
  instructorProfileRespond(500, ['error' => 'The instructor profile could not be saved. Check the database configuration and PHP error log.']);
} catch (Throwable $error) {
  error_log('Instructor profile update error: ' . $error->getMessage());
  instructorProfileRespond(500, ['error' => 'The instructor profile could not be saved. Check the PHP error log.']);
}
