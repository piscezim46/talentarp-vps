<?php
$host = "localhost";
$user = "root";
$pass = "Hayder.2085.root";
$db   = "talentarp_db";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
  die("DB connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");
?>
