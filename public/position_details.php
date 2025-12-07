<?php
// Guard session start to avoid redundant start notices
if (function_exists('session_status')) {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
} else {
  @session_start();
}
require_once '../includes/db.php';
header('Content-Type: application/json');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) { http_response_code(400); echo json_encode(['error'=>'Invalid id']); exit; }

$sql = "
  SELECT p.id, p.title, p.experience_level, p.education_level, p.employment_type, p.openings, p.salary, p.hiring_deadline,
         p.department, p.team, p.manager_name, p.description, p.requirements, p.status, p.created_at,
         COALESCE(u.name,'') AS created_by_name
  FROM positions p
  LEFT JOIN users u ON p.created_by = u.id
  WHERE p.id = ?";
$sql .= ' LIMIT 1';

$stmt = $conn->prepare($sql);
if (!$stmt) { http_response_code(500); echo json_encode(['error'=>$conn->error]); exit; }
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$row) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }
echo json_encode($row);
exit;
?>