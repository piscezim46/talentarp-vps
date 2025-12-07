<?php
// Ensure session started only when necessary
if (function_exists('session_status')) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
} else {
    @session_start();
}
require_once '../includes/db.php';
require_once __DIR__ . '/../includes/access.php';
header('Content-Type: application/json');

// authorization: prefer access_keys (role_id-aware)
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

$id = isset($input['id']) ? intval($input['id']) : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user id']);
    exit;
}

// Allowed fields: name, email, role, department_id, team_id
$name = trim($input['name'] ?? '');
$user_name = trim($input['user_name'] ?? '');
$email = trim($input['email'] ?? '');
$role = trim($input['role'] ?? '');
$role_id = isset($input['role_id']) && $input['role_id'] !== '' ? intval($input['role_id']) : null;
$department_id = isset($input['department_id']) ? intval($input['department_id']) : 0;
$team_id = isset($input['team_id']) ? intval($input['team_id']) : 0;
$manager_name = trim($input['manager_name'] ?? ''); // ignored if present
$director_name = trim($input['director_name'] ?? ''); // ignored if present

if ($name === '' || $email === '' || ($role_id === null && $role === '')) {
    http_response_code(400);
    echo json_encode(['error' => 'Name, email and role are required']);
    exit;
}

// resolve role name to role_id when necessary
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

$chk = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1");
$chk->bind_param('si', $email, $id);
$chk->execute();
$chk->store_result();
if ($chk->num_rows > 0) {
    $chk->close();
    http_response_code(409);
    echo json_encode(['error' => 'Email already in use']);
    exit;
}
$chk->close();

// Check name not used by another user (case-insensitive)
$chk_name = $conn->prepare("SELECT id FROM users WHERE LOWER(name) = LOWER(?) AND id <> ? LIMIT 1");
$chk_name->bind_param('si', $name, $id);
$chk_name->execute();
$chk_name->store_result();
if ($chk_name->num_rows > 0) {
    $chk_name->close();
    http_response_code(409);
    echo json_encode(['error' => 'Name already in use']);
    exit;
}
$chk_name->close();

// Check username not used by another user (if provided)
if ($user_name !== '') {
    $chk_un = $conn->prepare("SELECT id FROM users WHERE LOWER(user_name) = LOWER(?) AND id <> ? LIMIT 1");
    $chk_un->bind_param('si', $user_name, $id);
    $chk_un->execute();
    $chk_un->store_result();
    if ($chk_un->num_rows > 0) {
        $chk_un->close();
        http_response_code(409);
        echo json_encode(['error' => 'Username already in use']);
        exit;
    }
    $chk_un->close();
}

// Some schemas keep manager/director names on departments/teams. Update only user fields present in users table.
// update users.role_id instead of users.role; include user_name
$stmt = $conn->prepare("UPDATE users SET name = ?, user_name = ?, email = ?, role_id = ?, department_id = ?, team_id = ? WHERE id = ?");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Prepare failed']);
    exit;
}
// bind: name(s), email(s), role_id(i), department_id(i), team_id(i), id(i)
$role_id_param = $role_id === null ? null : $role_id;
$stmt->bind_param('sssiiii', $name, $user_name, $email, $role_id_param, $department_id, $team_id, $id);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Update failed: ' . $stmt->error]);
    $stmt->close();
    exit;
}
$stmt->close();
// Try to resolve the role name for response
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

echo json_encode(['success' => true, 'id' => $id, 'user_name' => $user_name, 'role_id' => $role_id_param, 'role' => $resp_role_name]);
exit;
?>