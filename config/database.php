<?php
declare(strict_types=1);

$host = getenv('DB_HOST') ?: 'localhost';
$database = getenv('DB_NAME') ?: 'course_repository_db';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD');
if ($password === false) {
  $password = '';
}

return new PDO(
  'mysql:host=' . $host . ';dbname=' . $database . ';charset=utf8mb4',
  $username,
  $password,
  [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]
);
