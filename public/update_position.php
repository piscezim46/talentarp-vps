<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Only start a session if one isn't active already (prevents "session_start() ignoring" notices)
if (function_exists('session_status')) {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
} else {
  @session_start();
}
require_once __DIR__ . '/../includes/db.php';

function out(array $p, int $code=200){ http_response_code($code); echo json_encode($p); exit; }

try {
  if (!$conn instanceof mysqli) throw new RuntimeException('DB down');

  $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  if ($id <= 0) out(['ok'=>false,'error'=>'missing id'], 400);

  $selSql = "SELECT p.*, COALESCE(s.status_name,'') AS status_name, COALESCE(s.status_color,'') AS status_color FROM positions p LEFT JOIN positions_status s ON p.status_id=s.status_id WHERE p.id=? LIMIT 1";
  // load current position (no department restriction)
  $q = $conn->prepare($selSql);
  if (!$q) throw new RuntimeException('prepare fail: '.$conn->error);
  $q->bind_param('i', $id);
  $q->execute();
  $cur = $q->get_result()->fetch_assoc();
  $q->close();
  if (!$cur) out(['ok'=>false,'error'=>'not_found'], 404);

  // Determine current user id from session. Prefer structured session `$_SESSION['user']['id']`.
  $currentUserId = 0;
  if (isset($_SESSION['user']) && is_array($_SESSION['user']) && isset($_SESSION['user']['id'])) {
    $currentUserId = (int)$_SESSION['user']['id'];
  } elseif (isset($_SESSION['user_id'])) {
    $currentUserId = (int)$_SESSION['user_id'];
  } elseif (isset($_SESSION['id'])) {
    $currentUserId = (int)$_SESSION['id'];
  }

  // determine current user's access keys (for permission checks)
  $currentUserAccess = [];
  if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    $ak = $_SESSION['user']['access_keys'] ?? $_SESSION['user']['access'] ?? [];
    if (is_array($ak)) $currentUserAccess = $ak;
  }
  $canApprove = is_array($currentUserAccess) && in_array('positions_approve', $currentUserAccess, true);

  // allowed non-status fields (department/team/manager/director excluded)
  $allowed = [
    'title','requirements','description',
    // newly added longtext fields
    'role_responsibilities','role_expectations',
    'experience_level','education_level','employment_type',
    'openings','hiring_deadline','salary','work_location',
    'reason_for_opening','working_hours','min_age','gender','nationality_requirement'
  ];

  $input = $_POST;
  $sets = [];
  $params = [];
  $types = '';

  // track fields that were submitted but matched existing values
  $unchanged = [];
  // track submitted keys that are ignored (not allowed and not control fields)
  $ignored = [];

  // collect non-status changes
  $nonStatusChanged = false;
  foreach ($allowed as $f) {
    if (!array_key_exists($f, $input)) continue;
    $new = $input[$f];
    $old = $cur[$f] ?? null;
    $new_s = is_array($new) ? json_encode($new) : trim((string)$new);
    $old_s = is_array($old) ? json_encode($old) : trim((string)$old);
    if ($new_s === $old_s) {
      $unchanged[] = $f;
      continue;
    }

    $nonStatusChanged = true;
    $isInt = in_array($f, ['openings','salary','min_age'], true);
    $types .= $isInt ? 'i' : 's';
    $params[] = $isInt ? (int)$new : (string)$new;
    $sets[] = "$f = ?";
  }

  // optional status change (validated)
  $statusChanged = false;
  if (isset($input['status_id']) && $input['status_id'] !== '') {
    $toStatus = (int)$input['status_id'];
    $fromStatus = (int)$cur['status_id'];

    if ($toStatus !== $fromStatus) {
      // Ensure the transition exists and is active, and that the target status is active
      $chk = $conn->prepare("SELECT 1 FROM positions_status_transitions t LEFT JOIN positions_status s ON t.to_status_id = s.status_id WHERE t.from_status_id=? AND t.to_status_id=? AND t.active = 1 AND COALESCE(s.active,0) = 1 LIMIT 1");
      if (!$chk) throw new RuntimeException('prepare fail: '.$conn->error);
      $chk->bind_param('ii', $fromStatus, $toStatus);
      $chk->execute();
      $ok = $chk->get_result()->num_rows > 0;
      $chk->close();
      if (!$ok) out(['ok'=>false,'error'=>'transition_not_allowed','message'=>'Status transition not allowed'], 400);

      // Permission check: require positions_approve to change status
      if (!$canApprove) {
        out(['ok'=>false,'error'=>'access_denied','message'=>'Insufficient permission to change status'], 403);
      }

      $sets[] = "status_id = ?";
      $types .= 'i';
      $params[] = $toStatus;
      $statusChanged = true;
    }
    else {
      // submitted status equals current status
      $unchanged[] = 'status_id';
    }
  }

  // detect ignored keys (submitted but not processed)
  foreach (array_keys($input) as $k) {
    if ($k === 'id' || $k === 'status_id' || $k === 'reason') continue;
    if (in_array($k, $allowed, true)) continue;
    // if it's a control or already accounted for, skip
    $ignored[] = $k;
  }

  // permission: any non-status field update requires Open + creator
  if ($nonStatusChanged) {
    if ((int)$cur['status_id'] !== 1 || !$currentUserId || (int)$cur['created_by'] !== $currentUserId) {
      out(['ok'=>false,'error'=>'access_denied','message'=>'Access limited to creator'], 403);
    }
  }

  if (empty($sets)) {
    $msg = 'No changes to update.';
    // provide a clearer reason when possible
    if (count($unchanged) > 0) {
      $msg = 'Submitted values match existing values for: ' . implode(', ', $unchanged) . '.';
    } elseif (count($ignored) > 0) {
      $msg = 'No allowed fields were changed. Ignored fields: ' . implode(', ', $ignored) . '.';
    }
    out(['ok'=>false,'error'=>'no_changes','message'=>$msg,'unchangedFields'=>$unchanged,'ignoredFields'=>$ignored], 200);
  }

  $sql = "UPDATE positions SET ".implode(', ', $sets)." WHERE id = ? LIMIT 1";
  $types .= 'i';
  $params[] = $id;

  $u = $conn->prepare($sql);
  if (!$u) throw new RuntimeException('prepare fail: '.$conn->error);
  // bind params safely: mysqli_stmt::bind_param requires references
  if (strlen($types) > 0) {
    $bindParams = [];
    $bindParams[] = $types;
    // ensure values are strings/ints as needed and create references
    foreach ($params as $k => $v) {
      // normalize booleans/nulls
      if (is_bool($v)) $params[$k] = $v ? '1' : '0';
      if (is_null($v)) $params[$k] = '';
      $bindParams[] = &$params[$k];
    }
    // call bind_param with references
    if (!call_user_func_array([$u, 'bind_param'], $bindParams)) {
      throw new RuntimeException('bind_param failed');
    }
  }

  // execute inside a transaction so subsequent select sees committed changes
  $affected = 0;
  $changed_fields = [];
  try {
    // Inform any DB trigger whether this update is intended to change status.
    // The `validate_position_transition` trigger should check this session variable
    // to avoid blocking non-status updates. If the trigger isn't updated yet,
    // this has no effect.
    try {
      if (!empty($statusChanged)) {
        $conn->query("SET @pos_check_status_change = 1");
      } else {
        $conn->query("SET @pos_check_status_change = 0");
      }
    } catch (Throwable $_) { /* ignore if session variable cannot be set */ }

    $conn->begin_transaction();
    // execute and capture for debug; if a DB error occurs, surface it to the client
    try {
      $u->execute();
    } catch (mysqli_sql_exception $e) {
      // rollback and return DB error (do not swallow)
      try { $conn->rollback(); } catch (Throwable $_) {}
      // include SQL error message in response so client sees reason like "Position is already in this status"
      $msg = $e->getMessage();
      // log server-side
      error_log('update_position db error: ' . $msg . ' sql=' . $sql . ' params=' . json_encode($params));
      out(['ok' => false, 'error' => 'db_error', 'message' => $msg, 'debug' => ['sql' => $sql, 'params' => $params, 'errno' => $e->getCode()]], 400);
    }
    $affected = $u->affected_rows;
    // capture executed SQL + params for debugging (avoid exposing in production)
    $executed_sql = $sql;
    $executed_params = $params;
    // derive changed field names from $sets entries ("field = ?")
    foreach ($sets as $entry) {
      $parts = explode('=', $entry);
      $changed_fields[] = trim($parts[0]);
    }
    $u->close();
    $conn->commit();
  } catch (Throwable $e) {
    // rollback on failure and rethrow
    try { $conn->rollback(); } catch (Throwable $_) {}
    if (isset($u) && $u) try { $u->close(); } catch (Throwable $_) {}
    throw $e;
  }

  // return updated row
  $s = $conn->prepare("SELECT p.*, COALESCE(s.status_name,'') AS status_name, COALESCE(s.status_color,'') AS status_color FROM positions p LEFT JOIN positions_status s ON p.status_id=s.status_id WHERE p.id=? LIMIT 1");
  $s->bind_param('i', $id);
  $s->execute();
  $row = $s->get_result()->fetch_assoc();
  $s->close();

  // Determine which requested changes were actually applied by comparing
  // the freshly selected row with the submitted input values. This avoids
  // relying solely on mysqli->affected_rows which can be -1 in some drivers
  // or contexts. Build `appliedFields` and a human message when nothing
  // took effect.
  $applied_fields = [];
  foreach ($changed_fields as $cf) {
    $newVal = $input[$cf] ?? null;
    $rowVal = $row[$cf] ?? null;
    $new_s = is_array($newVal) ? json_encode($newVal) : trim((string)$newVal);
    $row_s = is_array($rowVal) ? json_encode($rowVal) : trim((string)$rowVal);
    if ($new_s === $row_s) {
      $applied_fields[] = $cf;
    }
  }
  // include status if requested and matches
  if ($statusChanged && isset($toStatus) && isset($row['status_id']) && (int)$row['status_id'] === (int)$toStatus) {
    if (!in_array('status_id', $applied_fields, true)) $applied_fields[] = 'status_id';
  }

  $applied_count = count($applied_fields);
  // If nothing was applied, return a clear response explaining what happened
  if ($applied_count === 0) {
    // concise short message for the client
    $short = 'No changes applied';
    if (count($changed_fields) === 1) {
      $short = 'No changes applied for: ' . $changed_fields[0];
    }
    // include full details in a debug object (client will show only short)
    // collect mysqli warnings and errors for diagnostics
    $mysqli_warnings = [];
    try {
      $warnRes = $conn->query('SHOW WARNINGS');
      if ($warnRes && $warnRes instanceof mysqli_result) {
        while ($wr = $warnRes->fetch_assoc()) $mysqli_warnings[] = $wr;
        $warnRes->free();
      }
    } catch (Throwable $_) { /* ignore */ }

    $stmt_error = '';
    $stmt_errno = 0;
    try { if (isset($u) && $u) { $stmt_error = $u->error; $stmt_errno = $u->errno; } } catch (Throwable $_) {}

    $debug = [
      'requestedChangedFields' => $changed_fields,
      'appliedFields' => $applied_fields,
      'unchangedFields' => $unchanged,
      'ignoredFields' => $ignored,
      'position' => $row,
      'sql' => $executed_sql ?? $sql,
      'params' => $executed_params ?? $params,
      'types' => $types,
      'stmt_error' => $stmt_error,
      'stmt_errno' => $stmt_errno,
      'mysqli_errno' => $conn->errno ?? 0,
      'mysqli_error' => $conn->error ?? '',
      'warnings' => $mysqli_warnings
    ];
    // log debug server-side as well
    error_log('update_position debug no-op: sql=' . ($executed_sql ?? $sql) . ' params=' . json_encode($executed_params ?? $params) . ' stmt_error=' . $stmt_error . ' conn_error=' . ($conn->error ?? '') );
    out([
      'ok' => false,
      'error' => 'no_rows_affected',
      'message' => $short,
      'affected' => 0,
      'debug' => $debug
    ], 200);
  }

  // If at least one field was applied, treat as success but report appliedFields
  $affected = $applied_count;

  // If status changed, record it in positions_status_history table for audit
  if ($statusChanged) {
    try {
      $updated_by = ($_SESSION['user']['name'] ?? $_SESSION['user']['username'] ?? ($_SESSION['user']['id'] ?? 'system'));
      $reason = isset($input['reason']) ? trim($input['reason']) : null;
      $h = $conn->prepare("INSERT INTO positions_status_history (position_id, status_id, updated_by, updated_at, reason) VALUES (?, ?, ?, NOW(), ?)");
      if ($h) {
        // prefer $toStatus which was set earlier when validating the transition
        $status_for_history = isset($toStatus) ? (int)$toStatus : ((int)$cur['status_id'] ?: 0);
        $h->bind_param('iiss', $id, $status_for_history, $updated_by, $reason);
        $h->execute();
        $h->close();
      }
    } catch (Throwable $e) {
      // don't fail the whole request if history insert fails; log for later
      error_log('Failed to write status history: ' . $e->getMessage());
    }
  }

  out(['ok'=>true,'position'=>$row,'statusChanged'=>$statusChanged,'affected'=>$affected,'changedFields'=>$changed_fields,'appliedFields'=>$applied_fields]);

} catch (Throwable $e) {
  // Log full error server-side for diagnostics
  error_log('update_position fatal: '.$e->getMessage());
  // Return the error message to the client to help debugging (trimmed)
  $msg = $e->getMessage();
  if (is_string($msg) && strlen($msg) > 1024) $msg = substr($msg, 0, 1024) . '...';
  out(['ok'=>false,'error'=>'server_error','message'=>$msg], 500);
}