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
$password_expiration_days = isset($input['password_expiration_days']) ? intval($input['password_expiration_days']) : null;
$scope = isset($input['scope']) ? trim($input['scope']) : null;

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
// update users.role_id instead of users.role; include user_name and optional password_expiration_days and scope
$stmt = $conn->prepare("UPDATE users SET name = ?, user_name = ?, email = ?, role_id = ?, department_id = ?, team_id = ?, password_expiration_days = ?, scope = ? WHERE id = ?");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Prepare failed']);
    exit;
}
// bind: name(s), email(s), role_id(i), department_id(i), team_id(i), id(i)
$role_id_param = $role_id === null ? null : $role_id;
$pwdExpParam = $password_expiration_days;
// validate scope: allow only 'local' or 'global'; preserve existing when not provided
$scopeParam = null;
if ($scope !== null) {
    $s = strtolower($scope);
    if ($s === 'local' || $s === 'global') $scopeParam = $s; else $scopeParam = 'local';
}
if ($pwdExpParam === null) {
    // preserve existing value when not provided
    $g = $conn->prepare("SELECT password_expiration_days FROM users WHERE id = ? LIMIT 1");
    if ($g) {
        $g->bind_param('i', $id);
        $g->execute();
        $gres = $g->get_result();
        if ($gres && ($grow = $gres->fetch_assoc())) {
            $pwdExpParam = intval($grow['password_expiration_days']);
        } else {
            $pwdExpParam = 90;
        }
        $g->close();
    } else {
        $pwdExpParam = 90;
    }
}
$scopePreserve = $scopeParam;
if ($scopePreserve === null) {
    // fetch existing scope to preserve
    $g2 = $conn->prepare("SELECT scope FROM users WHERE id = ? LIMIT 1");
    if ($g2) {
        $g2->bind_param('i', $id);
        $g2->execute();
        $gres2 = $g2->get_result();
        if ($gres2 && ($grow2 = $gres2->fetch_assoc())) {
            $scopePreserve = $grow2['scope'] ?? 'local';
        } else {
            $scopePreserve = 'local';
        }
        $g2->close();
    } else {
        $scopePreserve = 'local';
    }
}
$stmt->bind_param('sssiiiisi', $name, $user_name, $email, $role_id_param, $department_id, $team_id, $pwdExpParam, $scopePreserve, $id);
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

echo json_encode(['success' => true, 'id' => $id, 'user_name' => $user_name, 'role_id' => $role_id_param, 'role' => $resp_role_name, 'scope' => $scopePreserve]);
// If the current session user updated their own scope/department, refresh session values
if (isset($_SESSION['user']) && isset($_SESSION['user']['id']) && intval($_SESSION['user']['id']) === intval($id)) {
    try {
        $_SESSION['user']['scope'] = $scopePreserve;
        $_SESSION['user']['department_id'] = $department_id;
        // keep top-level department name in sync for helpers
        if (!empty($department_id)) {
            $dstmt = $conn->prepare('SELECT department_name FROM departments WHERE department_id = ? LIMIT 1');
            if ($dstmt) {
                $dstmt->bind_param('i', $department_id);
                $dstmt->execute();
                $dres = $dstmt->get_result();
                if ($dres && ($drow = $dres->fetch_assoc())) $_SESSION['user']['department_name'] = $drow['department_name'];
                $dstmt->close();
            }
        }
    } catch (Throwable $_) { /* ignore session refresh failures */ }
}
exit;
?>