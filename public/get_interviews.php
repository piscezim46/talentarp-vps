<?php
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json; charset=utf-8');
// Start session only if not already active. This file is often requested
// via AJAX while the main page already started a session, which causes
// a PHP notice: "session_start(): Ignoring session_start() because a session is already active".
if (function_exists('session_status')) {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
} else {
  @session_start();
}

$applicant_id = isset($_GET['applicant_id']) ? (int)$_GET['applicant_id'] : 0;
if (!$applicant_id) { echo json_encode(['ok'=>false,'message'=>'Missing applicant_id']); exit; }

$sql = "SELECT i.id, i.applicant_id, i.position_id, i.interview_datetime, i.status_id, COALESCE(s.name,'') AS status_name, COALESCE(s.status_color,'') AS status_color, i.location, i.result, i.comments, i.created_at, i.updated_at, i.created_by, COALESCE(u.name, '') AS created_by_name
  FROM interviews i
  LEFT JOIN interview_statuses s ON i.status_id = s.id
  LEFT JOIN users u ON i.created_by = u.id
  WHERE i.applicant_id = ?
  ORDER BY i.interview_datetime DESC, i.created_at DESC";

if ($stmt = $conn->prepare($sql)) {
  $stmt->bind_param('i', $applicant_id);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];
  while ($r = $res->fetch_assoc()) $rows[] = $r;
  $stmt->close();
  echo json_encode(['ok'=>true,'interviews'=>$rows]);
  exit;
}

echo json_encode(['ok'=>false,'message'=>'DB error: '.$conn->error]);
exit;
?>