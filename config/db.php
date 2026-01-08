<?php
// config/db.php
declare(strict_types=1);

/**
 * DB config
 * Update these values to match your MySQL credentials
 */
$DB_HOST = "localhost";
$DB_NAME = "appdocs";
$DB_USER = "root";
$DB_PASS = "";

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";

try {
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo "DB connection failed";
  exit;
}
