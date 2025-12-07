<?php
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json; charset=utf-8');
// Guard session start when included into pages that already started one
if (function_exists('session_status')) {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
} else {
  @session_start();
}

if (!isset($_SESSION['user'])) { echo json_encode(['ok'=>false,'message'=>'Not authenticated']); exit; }

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if (!$id) { echo json_encode(['ok'=>false,'message'=>'Missing id']); exit; }

$fields = [];
$params = [];
$types = '';

if (isset($_POST['status_id'])) { $fields[] = 'status_id = ?'; $params[] = (int)$_POST['status_id']; $types .= 'i'; }
if (isset($_POST['interview_datetime'])) { $fields[] = 'interview_datetime = ?'; $dt = str_replace('T',' ',trim($_POST['interview_datetime'])); $params[] = $dt; $types .= 's'; }
if (isset($_POST['location'])) { $fields[] = 'location = ?'; $params[] = trim($_POST['location']); $types .= 's'; }
if (isset($_POST['result'])) { $fields[] = 'result = ?'; $params[] = trim($_POST['result']); $types .= 's'; }
if (isset($_POST['comments'])) { $fields[] = 'comments = ?'; $params[] = trim($_POST['comments']); $types .= 's'; }

if (!count($fields)) { echo json_encode(['ok'=>false,'message'=>'No fields to update']); exit; }

$sql = 'UPDATE interviews SET ' . implode(', ', $fields) . ' WHERE id = ? LIMIT 1';
$params[] = $id; $types .= 'i';

// Apply department restriction for non-admins: ensure the interview's position belongs to user's department
// no department restriction: update interviews without department constraints

if ($stmt = $conn->prepare($sql)) {
  $bind_names[] = $types;
  for ($i=0;$i<count($params);$i++) { $bind_names[] = &$params[$i]; }
  call_user_func_array(array($stmt,'bind_param'), $bind_names);
  if ($stmt->execute()) {
    $stmt->close();
    // Return the updated row — apply same department restriction so non-admins cannot read others
    $selSql = "SELECT i.id, i.applicant_id, i.position_id, i.interview_datetime, i.status_id, COALESCE(s.name,'') AS status_name, COALESCE(s.status_color,'') AS status_color, i.location, i.result, i.comments, i.created_at, i.updated_at, i.created_by, COALESCE(u.name, '') AS created_by_name, COALESCE(d.department_name, '') AS created_by_department FROM interviews i LEFT JOIN interview_statuses s ON i.status_id = s.id LEFT JOIN users u ON i.created_by = u.id LEFT JOIN departments d ON u.department_id = d.department_id LEFT JOIN positions p ON p.id = i.position_id WHERE i.id = ?";
    $selSql .= ' LIMIT 1';
    $sel = $conn->prepare($selSql);
    if ($sel) {
      $sel->bind_param('i', $id);
      $sel->execute();
      $res = $sel->get_result();
      $row = $res->fetch_assoc();
      $sel->close();
      echo json_encode(['ok'=>true,'interview'=>$row]); exit;
    }
  }
  $stmt->close();
  echo json_encode(['ok'=>false,'message'=>'Update failed: '.$conn->error]); exit;
}

echo json_encode(['ok'=>false,'message'=>'DB prepare failed: '.$conn->error]);
exit;
?>