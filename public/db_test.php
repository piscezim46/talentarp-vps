<?php
// Show all PHP errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database credentials
$host = "localhost";
$user = "root";
$pass = "password"; // replace with your actual DB password
$dbname = "ticketing_db";

// Connect to DB
$conn = new mysqli($host, $user, $pass, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Confirm which database we are connected to
$result = $conn->query("SELECT DATABASE() AS dbname");
$row = $result->fetch_assoc();
echo "Database connection successful!<br>";
echo "Connected to DB: " . $row['dbname'];
?>
