<?php
// Guarded session start
if (function_exists('session_status')) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
} else {
    @session_start();
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/access.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || !_has_access('departments_view')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied']);
    exit;
}

$dept_id = isset($_POST['department_id']) ? intval($_POST['department_id']) : 0;
if ($dept_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid department_id']);
    exit;
}

$stmt = $conn->prepare("UPDATE departments SET active = IF(active=1,0,1) WHERE department_id = ?");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $conn->error]);
    exit;
}
$stmt->bind_param('i', $dept_id);
$ok = $stmt->execute();
if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $stmt->error]);
    exit;
}

$r = $conn->query("SELECT active FROM departments WHERE department_id = " . intval($dept_id));
$active = null;
if ($r) { $row = $r->fetch_assoc(); $active = isset($row['active']) ? (int)$row['active'] : null; $r->free(); }

echo json_encode(['ok' => true, 'department_id' => $dept_id, 'active' => $active]);

?>
