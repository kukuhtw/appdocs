<?php
// lib/helpers.php
declare(strict_types=1);

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, "UTF-8");
}

function redirect(string $url): void {
  header("Location: {$url}");
  exit;
}

function post_str(string $key): string {
  return isset($_POST[$key]) ? trim((string)$_POST[$key]) : "";
}

function get_int(string $key): int {
  return isset($_GET[$key]) ? (int)$_GET[$key] : 0;
}

function flash_set(string $msg): void {
  $_SESSION["flash"] = $msg;
}

function flash_get(): string {
  $m = $_SESSION["flash"] ?? "";
  unset($_SESSION["flash"]);
  return $m;
}
