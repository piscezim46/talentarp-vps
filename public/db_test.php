<?php
$host = "localhost";
$user = "root";
$pass = "password"; // use your actual password
$dbname = "ticketing_db";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
echo "Database connection successful!";
?>