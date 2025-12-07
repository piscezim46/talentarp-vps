<?php
// Guarded session start to prevent notices when called after a session exists
if (function_exists('session_status')) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
} else {
    @session_start();
}
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user']) || empty($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$password = isset($input['password']) ? trim($input['password']) : '';
if (strlen($password) < 4) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Password must be at least 4 characters']);
    exit;
}

$uid = intval($_SESSION['user']['id']);
// Fetch current password hash to prevent re-using the same password
$stmtCur = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
if (!$stmtCur) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
    exit;
}
$stmtCur->bind_param('i', $uid);
$stmtCur->execute();
$stmtCur->bind_result($current_hash);
$got = $stmtCur->fetch();
$stmtCur->close();
if ($got && !empty($current_hash)) {
    if (password_verify($password, $current_hash)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'New password cannot be the same as your current password']);
        exit;
    }
}

$hash = password_hash($password, PASSWORD_DEFAULT);

// Update password and clear force flag, then set password_changed_at via helper to centralize behavior
$stmt = $conn->prepare('UPDATE users SET password = ?, force_password_reset = 0 WHERE id = ?');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Prepare failed']);
    exit;
}
$stmt->bind_param('si', $hash, $uid);
$ok = $stmt->execute();
if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Update failed']);
    $stmt->close();
    exit;
}
$stmt->close();
// set the password_changed_at timestamp centrally
try {
    if (file_exists(__DIR__ . '/../includes/user_utils.php')) require_once __DIR__ . '/../includes/user_utils.php';
    if (function_exists('set_password_changed_at_now')) set_password_changed_at_now($conn, $uid);
} catch (Throwable $e) { /* non-fatal */ }

// clear session force flag so user can proceed
if (isset($_SESSION['user']['force_password_reset'])) unset($_SESSION['user']['force_password_reset']);

// reload access keys for session (optional) - let includes/access.php handle it on next page load

echo json_encode(['success' => true]);
exit;
?>