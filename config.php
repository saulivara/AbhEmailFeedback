<?php
// config.php
// 1) Create a MySQL user with INSERT permissions on alanwalker_feedback
// 2) Update the credentials below

$DB_HOST = 'localhost';
$DB_NAME = 'u690158837_email_feedback';
$DB_USER = 'u690158837_Alan_Walker';
$DB_PASS = 'ZarazaiPanevezys2025!';

$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES => false,
];

try {
  $pdo = new PDO(
    "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
    $DB_USER,
    $DB_PASS,
    $options
  );
} catch (Throwable $e) {
  http_response_code(500);
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'error' => 'DB connection failed']);
  exit;
}