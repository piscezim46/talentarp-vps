<?php
// Guard session start to prevent "session already active" notice
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

if (!isset($_SESSION['user']) || !_has_access('roles_edit')) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Access denied']);
  exit;
}

$role_id = isset($_POST['role_id']) ? intval($_POST['role_id']) : 0;
if ($role_id <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Invalid role_id']);
  exit;
}

$stmt = $conn->prepare("UPDATE roles SET active = IF(active=1,0,1) WHERE role_id = ?");
if (!$stmt) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $conn->error]);
  exit;
}
$stmt->bind_param('i', $role_id);
$ok = $stmt->execute();
if (!$ok) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $stmt->error]);
  exit;
}

// fetch new active state
$r = $conn->query("SELECT active FROM roles WHERE role_id = " . intval($role_id));
$active = null;
if ($r) { $row = $r->fetch_assoc(); $active = isset($row['active']) ? (int)$row['active'] : null; $r->free(); }

echo json_encode(['ok' => true, 'role_id' => $role_id, 'active' => $active]);

?>
