<?php
// Start session only if not already active
if (function_exists('session_status')) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
} else {
    @session_start();
}
require_once '../includes/db.php';

$pageTitle = 'Register';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    $role = $_POST['role'] ?? 'hr';

    if ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (!empty($name) && !empty($email) && !empty($password)) {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $name, $email, $hashed, $role);
        $stmt->execute();
        header("Location: index.php");
        exit;
    } else {
        $error = "Please fill in all fields.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($pageTitle ?? 'App') ?></title>
    <link rel="stylesheet" href="styles/login.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>

<div class="container">
    <h1>Create Account</h1>

    <form method="POST">
        <?php if (!empty($error)) echo "<p style='color:red;'>$error</p>"; ?>

        <input type="text" name="name" placeholder="Full Name" required><br>
        <input type="email" name="email" placeholder="Email" required><br>

        <div style="position: relative;">
            <input type="password" name="password" id="password" placeholder="Password" required>
            <i class="bi bi-eye-slash" id="togglePass" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); cursor:pointer; color:#007bff;"></i>
        </div>

        <div style="position: relative;">
            <input type="password" name="confirm" id="confirm" placeholder="Confirm Password" required>
            <i class="bi bi-eye-slash" id="toggleConfirm" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); cursor:pointer; color:#007bff;"></i>
        </div>

        <select name="role" required style="margin-top: 10px; padding: 10px; width: 100%; border-radius: 8px; background-color: #2b2d31; color: #e4e4e4; border: none;">
            <option value="hr">HR</option>
            <option value="mana
