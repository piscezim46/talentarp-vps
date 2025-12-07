<?php
// Start session only if not active
if (function_exists('session_status')) {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
} else {
  @session_start();
}
// suppress display of PHP warnings/notices to keep JSON responses clean
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/access.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user']) || !_has_access('roles_edit')) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Access denied']);
  exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data || !isset($data['role_id'])) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
  exit;
}

$role_id = intval($data['role_id']);
$role_name = isset($data['role_name']) ? trim($data['role_name']) : '';
$department_id = (array_key_exists('department_id', $data) && $data['department_id'] !== '') ? (int)$data['department_id'] : null;
$description = isset($data['description']) ? trim($data['description']) : '';
$access_ids = isset($data['access_ids']) && is_array($data['access_ids']) ? array_map('intval', $data['access_ids']) : [];

// validate role exists
$res = $conn->query("SELECT role_id FROM roles WHERE role_id = " . $role_id);
if (!$res || $res->num_rows === 0) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Role not found']);
  exit;
}

// update roles table with safe handling of NULL department
if ($department_id === null) {
  $stmt = $conn->prepare("UPDATE roles SET role_name = ?, department_id = NULL, description = ? WHERE role_id = ?");
  if (!$stmt) { echo json_encode(['ok'=>false,'error'=>$conn->error]); exit; }
  $stmt->bind_param('ssi', $role_name, $description, $role_id);
} else {
  $stmt = $conn->prepare("UPDATE roles SET role_name = ?, department_id = ?, description = ? WHERE role_id = ?");
  if (!$stmt) { echo json_encode(['ok'=>false,'error'=>$conn->error]); exit; }
  $stmt->bind_param('sisi', $role_name, $department_id, $description, $role_id);
}
$ok = $stmt->execute();
if ($stmt->errno) { echo json_encode(['ok'=>false,'error'=>$stmt->error]); exit; }
if (isset($stmt) && $stmt) $stmt->close();

// replace role_access_rights
// replace role_access_rights
$del = $conn->prepare('DELETE FROM role_access_rights WHERE role_id = ?');
if ($del) { $del->bind_param('i', $role_id); $del->execute(); $del->close(); }
if (!empty($access_ids)) {
  $ins = $conn->prepare('INSERT INTO role_access_rights (role_id, access_id) VALUES (?, ?)');
  if ($ins) {
    foreach ($access_ids as $aid) { $aid_i = (int)$aid; $ins->bind_param('ii', $role_id, $aid_i); $ins->execute(); }
    $ins->close();
  }
}

// fetch updated access keys and active state
$r = $conn->query("SELECT r.role_id, r.role_name, r.department_id, r.description, r.active, GROUP_CONCAT(DISTINCT ar.access_key ORDER BY ar.access_key SEPARATOR ', ') AS access_keys FROM roles r LEFT JOIN role_access_rights rar ON rar.role_id = r.role_id LEFT JOIN access_rights ar ON ar.access_id = rar.access_id WHERE r.role_id = " . intval($role_id) . " GROUP BY r.role_id");
$row = $r ? $r->fetch_assoc() : null;

echo json_encode(['ok' => true, 'role' => $row]);

?>
