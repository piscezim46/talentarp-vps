<?php
$host = "localhost";
$user = "ticketing_user";
$pass = "strongpassword";
$db   = "ticketing_db";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
  die("DB connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");
// Load department access helpers so pages can easily apply department filters.
// This file is included widely, so requiring the helpers here makes them
// available to most public scripts without adding extra includes everywhere.
if (file_exists(__DIR__ . '/department_access.php')) {
    require_once __DIR__ . '/department_access.php';
}
?>
