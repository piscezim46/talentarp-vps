<?php
session_start();
require_once '../includes/db.php';
require_once __DIR__ . '/../includes/access.php';
header('Content-Type: application/json');

// authorization
if (!isset($_SESSION['user']) || !_has_access('users_view')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$name = trim($input['name'] ?? '');
$user_name = trim($input['user_name'] ?? '');
$email = trim($input['email'] ?? '');
$password = $input['password'] ?? '';
$send_invite = isset($input['send_invite']) ? intval($input['send_invite']) : 1; // default: send invitation
$role = trim($input['role'] ?? '');
$role_id = isset($input['role_id']) && $input['role_id'] !== '' ? intval($input['role_id']) : null;
$manager_name = trim($input['manager_name'] ?? '');
$department_id = isset($input['department_id']) ? intval($input['department_id']) : 0;
$team_id = isset($input['team_id']) ? intval($input['team_id']) : 0;
$password_expiration_days = isset($input['password_expiration_days']) ? intval($input['password_expiration_days']) : 90;
$scope = isset($input['scope']) && in_array($input['scope'], ['local','global'], true) ? $input['scope'] : 'local';

// require role_id or role name
if ($name === '' || $email === '' || $password === '' || ($role_id === null && $role === '')) {
    http_response_code(400);
    echo json_encode(['error' => 'Name, email, password and role are required']);
    exit;
}

// If role_id not provided but role name is, try to resolve to role_id
if ($role_id === null && $role !== '') {
    $rstmt = $conn->prepare("SELECT role_id FROM roles WHERE role_name = ? LIMIT 1");
    if ($rstmt) {
        $rstmt->bind_param('s', $role);
        $rstmt->execute();
        $rres = $rstmt->get_result();
        if ($rres && ($rrow = $rres->fetch_assoc())) {
            $role_id = (int)$rrow['role_id'];
        }
        $rstmt->close();
    }
}

// check duplicate name (case-insensitive)
$chk = $conn->prepare("SELECT id FROM users WHERE LOWER(name) = LOWER(?) LIMIT 1");
$chk->bind_param('s', $name);
$chk->execute();
$chk->store_result();
if ($chk->num_rows > 0) {
    $chk->close();
    http_response_code(409);
    echo json_encode(['error' => 'Name already exists']);
    exit;
}
$chk->close();

// check duplicate username if provided (case-insensitive)
if ($user_name !== '') {
    $chk2 = $conn->prepare("SELECT id FROM users WHERE LOWER(user_name) = LOWER(?) LIMIT 1");
    $chk2->bind_param('s', $user_name);
    $chk2->execute();
    $chk2->store_result();
    if ($chk2->num_rows > 0) {
        $chk2->close();
        http_response_code(409);
        echo json_encode(['error' => 'Username already exists']);
        exit;
    }
    $chk2->close();
}

// check duplicate email (case-insensitive)
$chk3 = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
$chk3->bind_param('s', $email);
$chk3->execute();
$chk3->store_result();
if ($chk3->num_rows > 0) {
    $chk3->close();
    http_response_code(409);
    echo json_encode(['error' => 'Email already exists']);
    exit;
}
$chk3->close();

// hash password
$hash = password_hash($password, PASSWORD_DEFAULT);
$active = 1;

// insert user (if department/team not provided, use 0)
// insert using role_id (nullable) — include optional user_name and password_expiration_days
$stmt = $conn->prepare("INSERT INTO users (name, user_name, email, password, role_id, manager_name, active, department_id, team_id, password_expiration_days, scope, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Prepare failed']);
    exit;
}
// bind role_id as integer (may be null)
$role_id_param = $role_id === null ? null : $role_id;
$stmt->bind_param('ssssisiiiss', $name, $user_name, $email, $hash, $role_id_param, $manager_name, $active, $department_id, $team_id, $password_expiration_days, $scope);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Insert failed: ' . $stmt->error]);
    $stmt->close();
    exit;
}

    $newId = $stmt->insert_id;
    $stmt->close();

    $invite_sent_ok = false;
    // If client asked to send invitation, attempt to send email now (use raw password or a temporary link)
    if ($send_invite) {
        try {
            if (file_exists(__DIR__ . '/../includes/email_utils.php')) {
                require_once __DIR__ . '/../includes/email_utils.php';
                // prefer username if provided, otherwise email as identifier
                $loginIdent = $user_name !== '' ? $user_name : $email;
                // call helper to send email (uses PHPMailer SMTP). Ensure composer/install and config.php SMTP are set.
                $opts = [
                    'from_email' => 'Master@talentarp.com',
                    'from_name' => 'Master',
                    'subject' => 'Your Talent ARP account',
                    // 'use_temporary_link' => true, 'temp_link' => $tempLink
                ];
                $sendRes = send_invitation_email($email, $loginIdent, $password, $opts);
                if (is_array($sendRes)) {
                    $invite_sent_ok = !empty($sendRes['success']);
                    $invite_error = $sendRes['error'] ?? null;
                } else {
                    // backward compatibility: boolean
                    $invite_sent_ok = (bool)$sendRes;
                    $invite_error = null;
                }
                if ($invite_sent_ok) {
                    // mark invitation_sent = 1
                    $u = $conn->prepare('UPDATE users SET invitation_sent = 1 WHERE id = ?');
                    if ($u) { $u->bind_param('i', $newId); $u->execute(); $u->close(); }
                }
            }
        } catch (Throwable $e) {
            // non-fatal, send failure logged by helper
            $invite_sent_ok = false;
        }
    }

    // set password_changed_at to now for newly created account (use helper)
    try {
        if (file_exists(__DIR__ . '/../includes/user_utils.php')) require_once __DIR__ . '/../includes/user_utils.php';
        if (function_exists('set_password_changed_at_now')) {
            set_password_changed_at_now($conn, $newId);
        } else {
            // fallback to prepared statement
            $up = $conn->prepare('UPDATE users SET password_changed_at = NOW() WHERE id = ?');
            if ($up) { $up->bind_param('i', $newId); $up->execute(); $up->close(); }
        }
    } catch (Throwable $e) {
        // non-fatal
    }

    // try to resolve role_name for response
    $resp_role_name = $role;
    if (($role_id_param !== null) && ($resp_role_name === '' || $resp_role_name === null)) {
        $r = $conn->prepare("SELECT role_name FROM roles WHERE role_id = ? LIMIT 1");
        if ($r) {
            $r->bind_param('i', $role_id_param);
            $r->execute();
            $res = $r->get_result();
            if ($res && ($rr = $res->fetch_assoc())) $resp_role_name = $rr['role_name'];
            $r->close();
        }
    }

    $out = [
        'success' => true,
        'user' => [
            'id' => (int)$newId,
            'name' => $name,
            'user_name' => $user_name,
            'email' => $email,
            'role_id' => $role_id_param,
            'role' => $resp_role_name,
            'manager_name' => $manager_name,
            'department_id' => $department_id,
            'team_id' => $team_id,
            'scope' => $scope
        ]
    ];

    // include invite send details for client visibility
    $out['invite_sent'] = !empty($invite_sent_ok);
    if (!empty($invite_error)) $out['invite_error'] = $invite_error;

    echo json_encode($out);
exit;
?>