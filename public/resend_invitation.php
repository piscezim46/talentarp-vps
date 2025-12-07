<?php
// Guarded session_start to avoid "session already active" notice
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

// fetch user
$s = $conn->prepare('SELECT id, email, user_name, name FROM users WHERE id = ? LIMIT 1');
if (!$s) { http_response_code(500); echo json_encode(['success'=>false,'error'=>'Prepare failed']); exit; }
$s->bind_param('i', $id); $s->execute(); $res = $s->get_result(); $user = $res ? $res->fetch_assoc() : null; $s->close();
if (!$user) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'User not found']); exit; }

// generate password
function gen_password_local($len = 12){
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*()-_=+';
    $out = '';
    $max = strlen($chars) - 1;
    for ($i=0;$i<$len;$i++){ $out .= $chars[random_int(0,$max)]; }
    return $out;
}

$newPassword = gen_password_local(12);
$hash = password_hash($newPassword, PASSWORD_DEFAULT);

// update DB: password + force_password_reset + invitation_sent
$u = $conn->prepare('UPDATE users SET password = ?, force_password_reset = 1, invitation_sent = 1 WHERE id = ?');
if (!$u) { http_response_code(500); echo json_encode(['success'=>false,'error'=>'Prepare failed']); exit; }
$u->bind_param('si', $hash, $id);
$ok = $u->execute();
$u->close();

if ($ok) {
    // set password_changed_at
    try {
        if (file_exists(__DIR__ . '/../includes/user_utils.php')) require_once __DIR__ . '/../includes/user_utils.php';
        if (function_exists('set_password_changed_at_now')) set_password_changed_at_now($conn, $id);
        else { $up = $conn->prepare('UPDATE users SET password_changed_at = NOW() WHERE id = ?'); if ($up) { $up->bind_param('i',$id); $up->execute(); $up->close(); } }
    } catch (Throwable $e) { /* non-fatal */ }

    // try to send invitation email
    $emailSent = false; $emailError = null;
    try {
        if (file_exists(__DIR__ . '/../includes/email_utils.php')) {
            require_once __DIR__ . '/../includes/email_utils.php';
            $ident = !empty($user['user_name']) ? $user['user_name'] : $user['email'];
            $opts = ['from_email' => 'Master@talentarp.com', 'from_name' => 'Master', 'subject' => 'Your Talent ARP account'];
            $res = send_invitation_email($user['email'], $ident, $newPassword, $opts);
            if (is_array($res)) {
                $emailSent = !empty($res['success']);
                $emailError = $res['error'] ?? null;
            } else {
                $emailSent = (bool)$res;
                $emailError = null;
            }
        }
    } catch (Throwable $e) {
        $emailSent = false; $emailError = $e->getMessage();
    }

    // log the resend action (optional)
    try {
        $performedBy = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
        $note = 'Resend invitation via UI';
        $lin = $conn->prepare('INSERT INTO force_reset_logs (target_user_id, action_type, performed_by_user_id, note) VALUES (?, ?, ?, ?)');
        if ($lin) {
            $atype = 'resend_invite';
            $lin->bind_param('isis', $id, $atype, $performedBy, $note);
            $lin->execute();
            $lin->close();
        }
    } catch (Throwable $e) { /* non-fatal */ }

    echo json_encode(['success' => true, 'id' => $id, 'password' => $newPassword, 'email_sent' => $emailSent, 'email_error' => $emailError]);
    exit;
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Update failed']);
    exit;
}

?>
