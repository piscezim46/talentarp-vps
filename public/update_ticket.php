<?php
// Start session if not already active
if (function_exists('session_status')) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
} else {
    @session_start();
}
require_once '../includes/db.php';

if (!isset($_SESSION['user'])) {
    die("Unauthorized access");
}

$user = $_SESSION['user'];

// Only admin or HR can update status
$roleNorm = isset($user['role']) ? strtolower(trim($user['role'])) : '';
if (!in_array($roleNorm, ['admin','master admin','master_admin','master-admin','masteradmin'], true) && !in_array($user['role'], ['hr'])) {
    die("Access denied");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ticket_id = $_POST['ticket_id'];
    $status = $_POST['status'];
    $note = $_POST['note'];

    if ($ticket_id && $status) {
        $stmt = $conn->prepare("UPDATE tickets SET status = ?, note = ? WHERE id = ?");
        $stmt->bind_param("ssi", $status, $note, $ticket_id);
        $stmt->execute();
    }
}

header("Location: admin.php?updated=1");
exit;
?>
