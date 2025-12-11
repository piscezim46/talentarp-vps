<?php
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json; charset=utf-8');
// Start session only if not already active to avoid PHP notices when this
// endpoint is called from a page that already started the session.
if (function_exists('session_status')) {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
} else {
  @session_start();
}

if (!isset($_SESSION['user'])) { echo json_encode(['ok'=>false,'message'=>'Not authenticated']); exit; }

$applicant_id = isset($_POST['applicant_id']) ? (int)$_POST['applicant_id'] : 0;
$position_id = isset($_POST['position_id']) ? (int)$_POST['position_id'] : 0;
$interview_dt = isset($_POST['interview_datetime']) ? trim($_POST['interview_datetime']) : '';
$status_id = isset($_POST['status_id']) ? (int)$_POST['status_id'] : 1;
$location = isset($_POST['location']) ? trim($_POST['location']) : null;
$result = isset($_POST['result']) ? trim($_POST['result']) : null;
$comments = isset($_POST['comments']) ? trim($_POST['comments']) : null;

if (!$applicant_id || !$interview_dt) { echo json_encode(['ok'=>false,'message'=>'Missing required fields']); exit; }
// If a position_id was provided, ensure it exists to avoid FK constraint errors
if ($position_id > 0) {
  $pstmt = $conn->prepare('SELECT id FROM positions WHERE id = ? LIMIT 1');
  if ($pstmt) {
    $pstmt->bind_param('i', $position_id);
    $pstmt->execute();
    $pres = $pstmt->get_result();
    if (!$pres || $pres->num_rows === 0) { echo json_encode(['ok'=>false,'message'=>'Invalid position_id']); exit; }
    $pstmt->close();
  }
}
// normalize datetime-local format by replacing T with space
$interview_dt = str_replace('T',' ',$interview_dt);

// Ensure we store the numeric user id in created_by (required by DB)
$created_by = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
if (!$created_by) { echo json_encode(['ok'=>false,'message'=>'Missing user id in session']); exit; }

// Conflict check: ensure the user doesn't already have an interview that overlaps
// with the requested time. We assume an interview may take 20 minutes and treat
// any overlapping 20-minute window as a conflict.
try {
  $chkSql = "SELECT id, interview_datetime FROM interviews WHERE created_by = ? AND (interview_datetime < DATE_ADD(?, INTERVAL 20 MINUTE) AND DATE_ADD(interview_datetime, INTERVAL 20 MINUTE) > ?) LIMIT 1";
  $chk = $conn->prepare($chkSql);
  if ($chk) {
    // bind params: created_by (int), interview_dt (string) twice
    $chk->bind_param('iss', $created_by, $interview_dt, $interview_dt);
    $chk->execute();
    $cres = $chk->get_result();
    $crow = $cres ? $cres->fetch_assoc() : null;
    $chk->close();
    if ($crow) {
      // return a helpful message and the conflicting interview row
      echo json_encode(['ok' => false, 'message' => 'Time slot conflicts with another event (creator)', 'conflict' => $crow]);
      exit;
    }
  }
} catch (Throwable $_) { /* swallow DB errors here to avoid blocking scheduling if DB check fails unexpectedly */ }

// Applicant-level conflict check: ensure the applicant doesn't already have another
// interview overlapping the requested time window (20 minutes). This prevents
// scheduling two interviews for the same applicant at effectively the same time.
try {
  if ($applicant_id > 0) {
    $appChkSql = "SELECT id, interview_datetime, created_by FROM interviews WHERE applicant_id = ? AND (interview_datetime < DATE_ADD(?, INTERVAL 20 MINUTE) AND DATE_ADD(interview_datetime, INTERVAL 20 MINUTE) > ?) LIMIT 1";
    $appChk = $conn->prepare($appChkSql);
    if ($appChk) {
      $appChk->bind_param('iss', $applicant_id, $interview_dt, $interview_dt);
      $appChk->execute();
      $aRes = $appChk->get_result();
      $aRow = $aRes ? $aRes->fetch_assoc() : null;
      $appChk->close();
      if ($aRow) {
        echo json_encode(['ok' => false, 'message' => 'Time slot conflicts with another interview for this applicant', 'conflict' => $aRow]);
        exit;
      }
    }
  }
} catch (Throwable $_) { /* ignore applicant-level conflict check failures and proceed */ }

// Determine canonical status for newly created interviews. We always override
// the incoming `status_id` for new records to ensure the DB's 'Created'
// status is used as the initial state. If a 'Created' status isn't present,
// fall back to the first row in `interview_statuses`.
$created_status_id = 0;
try {
  $s = $conn->prepare('SELECT id FROM interview_statuses WHERE LOWER(name) = ? LIMIT 1');
  if ($s) {
    $nm = 'created';
    $s->bind_param('s', $nm);
    $s->execute();
    $r = $s->get_result();
    $row = $r ? $r->fetch_assoc() : null;
    $s->close();
    if ($row && isset($row['id'])) $created_status_id = (int)$row['id'];
  }
} catch (Throwable $_) { /* ignore */ }
if (!$created_status_id) {
  try {
    $f = $conn->query('SELECT id FROM interview_statuses ORDER BY id LIMIT 1');
    if ($f && $f->num_rows > 0) { $fr = $f->fetch_assoc(); if (isset($fr['id'])) $created_status_id = (int)$fr['id']; }
  } catch (Throwable $_) { /* ignore */ }
}
// If we still couldn't find any status, leave the provided status_id but
// prefer a sane numeric fallback of 1.
if ($created_status_id > 0) { $status_id = $created_status_id; } else { $status_id = max(1, (int)$status_id); }

// Build INSERT dynamically: omit position_id when not provided (avoids FK error with 0)
$cols = ['applicant_id'];
$placeholders = ['?'];
$types = 'i';
$values = [$applicant_id];
if ($position_id > 0) { $cols[] = 'position_id'; $placeholders[] = '?'; $types .= 'i'; $values[] = $position_id; }
$cols[] = 'interview_datetime'; $placeholders[] = '?'; $types .= 's'; $values[] = $interview_dt;
$cols[] = 'created_by'; $placeholders[] = '?'; $types .= 'i'; $values[] = $created_by;
$cols[] = 'status_id'; $placeholders[] = '?'; $types .= 'i'; $values[] = $status_id;
$cols[] = 'location'; $placeholders[] = '?'; $types .= 's'; $values[] = ($location === null ? '' : $location);
$cols[] = 'result'; $placeholders[] = '?'; $types .= 's'; $values[] = ($result === null ? '' : $result);
$cols[] = 'comments'; $placeholders[] = '?'; $types .= 's'; $values[] = ($comments === null ? '' : $comments);

$sql = "INSERT INTO interviews (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
if ($stmt = $conn->prepare($sql)) {
  // bind params dynamically (call_user_func_array requires references)
  $bind_names[] = $types;
  for ($i = 0; $i < count($values); $i++) { $bind_name = 'var' . $i; $$bind_name = $values[$i]; $bind_names[] = &$$bind_name; }
  call_user_func_array([$stmt, 'bind_param'], $bind_names);
  if ($stmt->execute()) {
    $insert_id = $stmt->insert_id;
    $stmt->close();
    // return inserted row with status info
    $sel = $conn->prepare("SELECT i.id, i.applicant_id, i.position_id, i.interview_datetime, i.status_id, COALESCE(s.name,'') AS status_name, COALESCE(s.status_color,'') AS status_color, i.location, i.result, i.comments, i.created_at, i.updated_at, i.created_by, COALESCE(u.name, '') AS created_by_name FROM interviews i LEFT JOIN interview_statuses s ON i.status_id = s.id LEFT JOIN users u ON i.created_by = u.id WHERE i.id = ? LIMIT 1");
    if ($sel) {
      $sel->bind_param('i', $insert_id);
      $sel->execute();
      $res = $sel->get_result();
      $row = $res->fetch_assoc();
      $sel->close();
      echo json_encode(['ok'=>true,'interview'=>$row]);
      exit;
    }
    echo json_encode(['ok'=>true,'interview_id'=>$insert_id]); exit;
  }
  $stmt->close();
  echo json_encode(['ok'=>false,'message'=>'Insert failed: '.$conn->error]); exit;
}

echo json_encode(['ok'=>false,'message'=>'DB prepare failed: '.$conn->error]);
exit;
?>