<?php
// Start session only if not active
if (function_exists('session_status')) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
} else {
    @session_start();
}
require_once '../includes/db.php';

if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}

$user = $_SESSION['user'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role_applied = $_POST['role_applied'] ?? '';
    $file = $_FILES['resume'];

    if ($file['error'] === 0 && $role_applied) {
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $newName = uniqid('resume_', true) . '.' . $ext;
        $target = '../uploads/' . $newName;

        if (move_uploaded_file($file['tmp_name'], $target)) {
            $stmt = $conn->prepare("INSERT INTO tickets (user_id, role_applied, resume_path) VALUES (?, ?, ?)");
            $stmt->bind_param('iss', $user['id'], $role_applied, $newName);
            $stmt->execute();

            $success = "Resume uploaded and ticket created!";
        } else {
            $error = "Upload failed.";
        }
    } else {
        $error = "Please fill all fields and select a valid file.";
    }
}
?>

<h2>Upload Resume</h2>
<?php if (isset($error)) echo "<p style='color:red;'>$error</p>"; ?>
<?php if (isset($success)) echo "<p style='color:green;'>$success</p>"; ?>

<form method="POST" enctype="multipart/form-data">
    <input name="role_applied" placeholder="Role you're applying for" required><br><br>
    <input type="file" name="resume" accept=".pdf,.doc,.docx" required><br><br>
    <button type="submit">Upload</button>
</form>

<a href="dashboard.php">← Back to Dashboard</a>
