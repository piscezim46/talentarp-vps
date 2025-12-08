<?php
// Force all PHP errors to display
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database credentials
$host = "localhost";
$user = "root";
$pass = "password"; // replace with your actual MySQL root password
$dbname = "ticketing_db";

// Try connecting
$conn = @new mysqli($host, $user, $pass, $dbname);

// Check connection
if ($conn->connect_error) {
    die("DB Connection Error: " . $conn->connect_error);
}

// Confirm current DB
$result = $conn->query("SELECT DATABASE() AS dbname");
$row = $result->fetch_assoc();
echo "Database connection successful!<br>";
echo "Currently connected to: " . $row['dbname'];
?>
