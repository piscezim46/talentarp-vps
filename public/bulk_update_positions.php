<?php
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

// basic auth/permission check (role_id-aware)
session_start();
require_once __DIR__ . '/../includes/access.php';
if (!isset($_SESSION['user']) || !_has_access('positions_view', ['admin','hr','manager'])) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$body = file_get_contents('php://input');
if (!$body) {
    echo json_encode(['error' => 'no payload']);
    exit;
}
$data = json_decode($body, true);
if (!is_array($data) || empty($data['ids']) || !isset($data['status_id'])) {
    echo json_encode(['error' => 'invalid payload']);
    exit;
}

$ids = array_filter(array_map('intval', $data['ids']));
$status_id = intval($data['status_id']);
if (!$ids) { echo json_encode(['error'=>'no ids']); exit; }

// prepare placeholders
 $placeholders = implode(',', array_fill(0, count($ids), '?'));
 $sql = "UPDATE positions SET status_id = ?, updated_at = NOW() WHERE id IN ($placeholders)";
 $stmt = $conn->prepare($sql);
if (!$stmt) { echo json_encode(['error'=>'prepare_failed','details'=>$conn->error]); exit; }

$types = str_repeat('i', 1 + count($ids));
$params = array_merge([$status_id], $ids);
$stmt->bind_param($types, ...$params);
$ok = $stmt->execute();
if (!$ok) {
    echo json_encode(['error' => 'execute_failed', 'details' => $stmt->error]);
    $stmt->close();
    exit;
}
$affected = $stmt->affected_rows;
$stmt->close();

echo json_encode(['success' => true, 'affected' => $affected]);
exit;
?>