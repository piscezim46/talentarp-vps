<?php
// Guard session start to prevent notice when included after a session exists
if (function_exists('session_status')) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
} else {
    @session_start();
}
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/access.php';

// authorization
if (!isset($_SESSION['user']) || !_has_access('users_view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$id = isset($input['id']) ? (int)$input['id'] : 0;
if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid id']);
    exit;
}

// generate a reasonably strong password (12 chars alphanumeric + symbols)
function gen_password($len = 12){
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*()-_=+';
    $out = '';
    $max = strlen($chars) - 1;
    for ($i=0;$i<$len;$i++){ $out .= $chars[random_int(0,$max)]; }
    return $out;
}

$newPassword = gen_password(12);
$hash = password_hash($newPassword, PASSWORD_DEFAULT);

// update DB
// Update password and set force flag. We'll set password_changed_at via helper to centralize behavior.
$stmt = $conn->prepare('UPDATE users SET password = ?, force_password_reset = 1 WHERE id = ?');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Prepare failed']);
    exit;
}
$stmt->bind_param('si', $hash, $id);
$ok = $stmt->execute();
$stmt->close();
if ($ok) {
    // set password_changed_at centrally (non-fatal)
    try {
        if (file_exists(__DIR__ . '/../includes/user_utils.php')) require_once __DIR__ . '/../includes/user_utils.php';
        if (function_exists('set_password_changed_at_now')) set_password_changed_at_now($conn, $id);
        else {
            $up = $conn->prepare('UPDATE users SET password_changed_at = NOW() WHERE id = ?');
            if ($up) { $up->bind_param('i', $id); $up->execute(); $up->close(); }
        }
    } catch (Throwable $e) { /* non-fatal */ }

    // Log the force reset action (single)
    try {
        $performedBy = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
        $note = 'Admin reset via UI';
        $lin = $conn->prepare('INSERT INTO force_reset_logs (target_user_id, action_type, performed_by_user_id, note) VALUES (?, ?, ?, ?)');
        if ($lin) {
            $atype = 'single';
            $lin->bind_param('isis', $id, $atype, $performedBy, $note);
            $lin->execute();
            $lin->close();
        }
    } catch (Throwable $e) { /* non-fatal */ }

    echo json_encode(['success' => true, 'id' => $id, 'password' => $newPassword]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Update failed']);
}
exit;
