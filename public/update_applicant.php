<?php
// public/update_applicant.php
// start session only if not already active
if (function_exists('session_status')) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
} else {
    @session_start();
}
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied']);
    exit;
}

$allowed = [
     'full_name','phone','email','linkedin','gender','nationality',
     'age','degree','years_experience','experience_level','description','education_level','skills'
 ];


$in = $_POST;
// We allow status updates through this endpoint if provided, but validate transitions server-side.
// Extract status update fields (if any) and leave the rest to the generic updater below.
$status_update = null;
if (isset($in['status_id'])) {
    $status_update = [ 'to_status' => intval($in['status_id']), 'reason' => (isset($in['status_reason']) ? (string)$in['status_reason'] : '') ];
}
// Explicitly ignore position-related keys — position changes are handled elsewhere
foreach (['position_id','position'] as $k) { if (isset($in[$k])) unset($in[$k]); }
$applicant_id = isset($in['applicant_id']) ? intval($in['applicant_id']) : 0;
if ($applicant_id <= 0) { echo json_encode(['ok'=>false,'error'=>'Missing applicant_id']); exit; }

// fetch current row so we only update changed fields (prevents unnecessary trigger actions)
$cur = null;
$selSql = "SELECT a.* FROM applicants a LEFT JOIN positions p ON a.position_id = p.id WHERE a.applicant_id = ? LIMIT 1";
$q = $conn->prepare($selSql);
if ($q) {
    $q->bind_param('i', $applicant_id);
    $q->execute();
    $resq = $q->get_result();
    $cur = $resq ? $resq->fetch_assoc() : null;
    $q->close();
}

// If applicant not found, abort early
if (!$cur) {
    echo json_encode(['ok' => false, 'error' => 'Applicant not found']);
    exit;
}

$sets = [];
$vals = [];
$types = '';
$statusChanged = false;
foreach ($allowed as $field) {
    if (!array_key_exists($field, $in)) continue;
    $inVal = (string)($in[$field] ?? '');
    // Normalize skills: accept pasted comma- or newline-separated lists, trim and dedupe
    if ($field === 'skills') {
        $parts = preg_split('/[\r\n,]+/', $inVal);
        $clean = [];
        foreach ($parts as $p) {
            $t = trim($p);
            if ($t === '') continue;
            $lower = mb_strtolower($t);
            $exists = false;
            foreach ($clean as $c) { if (mb_strtolower($c) === $lower) { $exists = true; break; } }
            if (!$exists) $clean[] = $t;
        }
        $inVal = implode(', ', $clean);
    }
    $curVal = $cur && array_key_exists($field, $cur) && $cur[$field] !== null ? (string)$cur[$field] : '';
    // trim for comparison
    if (trim($inVal) === trim($curVal)) continue; // no change
    $sets[] = "$field = ?";
    if (in_array($field, ['age','years_experience'])) {
        $vals[] = (int)$in[$field];
        $types .= 'i';
    } else {
        $vals[] = $in[$field];
        $types .= 's';
    }
}

// If a status update was requested, validate transition and prepare to apply it
if ($status_update !== null) {
    // current status from DB
    $current_status = $cur && isset($cur['status_id']) ? intval($cur['status_id']) : 0;
    $desired = intval($status_update['to_status']);
    $statusChanged = false;
    if ($desired <= 0) {
        echo json_encode(['ok'=>false,'error'=>'Invalid status_id']); exit;
    }
    // helper: check transition allowed by a transitions table. Prefer newly-added
    // `interviews_status_transitions` if present, otherwise fall back to
    // `applicants_status_transitions`. Try common column name pairs.
    $transition_allowed = false;
    $matched_transition = null;
    $tablesToTry = ['interviews_status_transitions', 'applicants_status_transitions'];
    $tryCols = [ ['from_status_id','to_status_id'], ['from_status','to_status'], ['from_id','to_id'] ];
    foreach ($tablesToTry as $tbl) {
        foreach ($tryCols as $cols) {
            $fromCol = $cols[0];
            $toCol = $cols[1];
            // Verify the candidate columns actually exist in this table to avoid fatal SQL errors
            try {
                $colExistsFrom = false; $colExistsTo = false;
                $chkFrom = $conn->query("SHOW COLUMNS FROM `" . $conn->real_escape_string($tbl) . "` LIKE '" . $conn->real_escape_string($fromCol) . "'");
                if ($chkFrom && $chkFrom->num_rows > 0) $colExistsFrom = true;
                $chkTo = $conn->query("SHOW COLUMNS FROM `" . $conn->real_escape_string($tbl) . "` LIKE '" . $conn->real_escape_string($toCol) . "'");
                if ($chkTo && $chkTo->num_rows > 0) $colExistsTo = true;
            } catch (Throwable $t) {
                // if SHOW COLUMNS fails for any reason, skip this candidate
                continue;
            }
            if (!$colExistsFrom || !$colExistsTo) continue;

            // Determine bind values/types. Support name-based columns by resolving ids to names.
            $bindA = $current_status;
            $bindB = $desired;
            $bindTypes = 'ii';
            if (stripos($fromCol, 'name') !== false) {
                try {
                    $s = $conn->prepare('SELECT status_name FROM applicants_status WHERE status_id = ? LIMIT 1');
                    if ($s) {
                        $s->bind_param('i', $current_status);
                        $s->execute();
                        $sr = $s->get_result();
                        $srow = $sr ? $sr->fetch_assoc() : null;
                        $s->close();
                        if ($srow && isset($srow['status_name'])) { $bindA = $srow['status_name']; $bindTypes = 'si'; }
                        else continue;
                    } else continue;
                } catch (Throwable $t) { continue; }
            }
            if (stripos($toCol, 'name') !== false) {
                try {
                    $s2 = $conn->prepare('SELECT status_name FROM applicants_status WHERE status_id = ? LIMIT 1');
                    if ($s2) {
                        $s2->bind_param('i', $desired);
                        $s2->execute();
                        $sr2 = $s2->get_result();
                        $srow2 = $sr2 ? $sr2->fetch_assoc() : null;
                        $s2->close();
                        if ($srow2 && isset($srow2['status_name'])) {
                            $bindB = $srow2['status_name'];
                            $bindTypes = (stripos($fromCol,'name')!==false) ? 'ss' : 'is';
                        } else continue;
                    } else continue;
                } catch (Throwable $t) { continue; }
            }

            $sqlChk = sprintf('SELECT 1 FROM %s WHERE %s = ? AND %s = ? LIMIT 1', $tbl, $fromCol, $toCol);
            $chk = $conn->prepare($sqlChk);
            if (!$chk) continue;
            try {
                if ($bindTypes === 'ii') $chk->bind_param('ii', $bindA, $bindB);
                else if ($bindTypes === 'is') $chk->bind_param('is', $bindA, $bindB);
                else if ($bindTypes === 'si') $chk->bind_param('si', $bindA, $bindB);
                else if ($bindTypes === 'ss') $chk->bind_param('ss', $bindA, $bindB);
                else $chk->bind_param('ii', $bindA, $bindB);
                $chk->execute();
                $cres = $chk->get_result();
                if ($cres && $cres->fetch_assoc()) { $transition_allowed = true; $matched_transition = ['table'=>$tbl,'from'=>$fromCol,'to'=>$toCol]; }
                $chk->close();
                if ($transition_allowed) break 2;
            } catch (Throwable $t) { if ($chk) { try{ @$chk->close(); }catch(Throwable $_){} } continue; }
        }
    }
    // Log which table/cols matched (if any) to help debugging illegal transitions
    try { error_log('[update_applicant] transition check: allowed=' . ($transition_allowed ? '1' : '0') . ' matched=' . json_encode($matched_transition)); } catch (Throwable $_) { }
    // allow staying in same status (no-op)
    // Special rule: if the ticket is already in a 'Closed' status, disallow attempting to move to Closed again
    if ($current_status === $desired) {
        // Check status name for the current status id (if available)
        try {
            $cn = $conn->prepare('SELECT status_name FROM applicants_status WHERE status_id = ? LIMIT 1');
            if ($cn) {
                $cn->bind_param('i', $current_status);
                $cn->execute();
                $cres = $cn->get_result();
                $srow = $cres ? $cres->fetch_assoc() : null;
                $cn->close();
                $curName = $srow && isset($srow['status_name']) ? strtolower(trim($srow['status_name'])) : '';
                if ($curName === 'closed') {
                    echo json_encode(['ok' => false, 'error' => 'Ticket is already closed']); exit;
                }
            }
        } catch (Throwable $_) {
            // ignore lookup errors and fall back to allowing no-op
        }
        $transition_allowed = true;
    }
    if (!$transition_allowed) { echo json_encode(['ok'=>false,'error'=>'Transition not allowed']); exit; }

    // If allowed, add to update sets
    if (!in_array('status_id', $sets)) {
        $sets[] = 'status_id = ?'; $vals[] = $desired; $types .= 'i';
        if ($desired !== $current_status) $statusChanged = true;
    }
    // If a reason was provided and the applicants table contains a status_reason column,
    // attempt to update it. We'll try to add the column update but if it fails silently
    // the main status update will still proceed.
    if (strlen(trim($status_update['reason'])) > 0) {
        // Check if applicants table actually has a `status_reason` column before including it
        try {
            $colChk = $conn->prepare("SHOW COLUMNS FROM applicants LIKE 'status_reason'");
            if ($colChk) {
                $colChk->execute();
                $cres = $colChk->get_result();
                $has = ($cres && $cres->fetch_assoc());
                $colChk->close();
            } else {
                $has = false;
            }
        } catch (Throwable $t) { $has = false; }
        if ($has) {
            $sets[] = 'status_reason = ?'; $vals[] = $status_update['reason']; $types .= 's';
        }
    }
}

if (empty($sets)) {
    // nothing changed — return current applicant row so client can update UI without re-running triggers
    $rSql = "SELECT a.*, COALESCE(s.status_name,'') AS status_name FROM applicants a LEFT JOIN applicants_status s ON a.status_id = s.status_id LEFT JOIN positions p ON a.position_id = p.id WHERE a.applicant_id = ?";
    $rSql .= ' LIMIT 1';
    $r = $conn->prepare($rSql);
    if ($r) {
        $r->bind_param('i', $applicant_id);
        $r->execute();
        $res = $r->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $r->close();
        echo json_encode(['ok' => true, 'applicant' => $row, 'warning' => 'No changes', 'db_message' => 'No changes']);
        exit;
    }
    echo json_encode(['ok'=>false,'error'=>'No fields to update']); exit;
}

$sql = "UPDATE applicants SET " . implode(',', $sets) . " WHERE applicant_id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    $err = $conn->error;
    error_log('[update_applicant] prepare failed: ' . $err);
    echo json_encode(['ok'=>false,'error'=>'Prepare failed: '.$err, 'db_message' => $err]);
    exit;
}
// bind params: append applicant id then department param if present
$types .= 'i';
$vals[] = $applicant_id;
// mysqli bind_param requires references
$params = array_merge([$types], $vals);
$tmp = [];
foreach ($params as $key => $value) $tmp[$key] = &$params[$key];
// Debug: log SQL and params to error log to help trace trigger-caused exceptions
try { error_log('[update_applicant] SQL: ' . $sql . ' | types: ' . $types . ' | vals: ' . json_encode($vals)); } catch (Throwable $t) { error_log('[update_applicant] debug log failed: ' . $t->getMessage()); }
// track if a trigger-specific exception was ignored so client can be informed
$trigger_ignored = null;
try {
    call_user_func_array([$stmt, 'bind_param'], $tmp);
    if (!$stmt->execute()) {
        // execution failed without throwing — return JSON error
        $err = $stmt->error ?: 'Unknown execute error';
        error_log('[update_applicant] execute failed: ' . $err);
        if ($stmt) { try { @$stmt->close(); } catch (Throwable $_) {} $stmt = null; }
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Execute failed: ' . $err, 'db_message' => $err]);
        exit;
    }
    // record affected rows for debugging (0 means nothing changed or update was a no-op)
    $affected_rows = $stmt->affected_rows;
} catch (mysqli_sql_exception $ex) {
    // Handle DB exceptions. For the specific benign trigger error
    // "Applicant is already in this status" we will silently ignore it
    // (like update_position.php) and continue to return the current row.
    $msg = $ex->getMessage();
    error_log('[update_applicant] mysqli_sql_exception: ' . $msg);
    if ($stmt) { try { @$stmt->close(); } catch (Throwable $_) {} $stmt = null; }
    if (stripos($msg, 'already in this status') !== false) {
        // log SQL+params for debugging, note that trigger prevented update
        try { error_log('[update_applicant] ignored exception SQL: ' . $sql . ' | vals: ' . json_encode($vals)); } catch (Throwable $t) { error_log('[update_applicant] logging failed: ' . $t->getMessage()); }
        // remember the trigger message so we can inform the client
        $trigger_ignored = $msg;
        // fall through to selecting current row and returning ok:true
    } else {
        // Other DB errors: return detailed JSON for debugging.
        try { error_log('[update_applicant] exception SQL: ' . $sql . ' | vals: ' . json_encode($vals)); } catch (Throwable $t) { error_log('[update_applicant] logging failed: ' . $t->getMessage()); }
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=> $msg, 'neutral' => false, 'db_message' => $msg, 'sql' => $sql, 'params' => $vals]);
        exit;
    }
} catch (Throwable $t) {
    if ($stmt) { try { @$stmt->close(); } catch (Throwable $_) {} $stmt = null; }
    http_response_code(500);
    error_log('[update_applicant] unexpected throwable: ' . $t->getMessage());
    echo json_encode(['ok'=>false,'error'=>'Unexpected error', 'db_message' => $t->getMessage()]);
    exit;
}

    if ($stmt) { try { @$stmt->close(); } catch (Throwable $_) {} $stmt = null; }

// If the status changed, write to applicants_status_history for audit
try {
    if (!empty($statusChanged) && $statusChanged) {
        $history_pos_id = isset($cur['position_id']) ? (int)$cur['position_id'] : 0;
        $history_status = isset($desired) ? (int)$desired : (isset($status_update['to_status']) ? (int)$status_update['to_status'] : (isset($cur['status_id']) ? (int)$cur['status_id'] : 0));
        $updated_by = ($_SESSION['user']['name'] ?? $_SESSION['user']['username'] ?? ($_SESSION['user']['id'] ?? 'system'));
        $reason_text = (isset($status_update['reason']) ? trim($status_update['reason']) : null);
        $h = $conn->prepare("INSERT INTO applicants_status_history (applicant_id, position_id, status_id, updated_by, updated_at, reason) VALUES (?, ?, ?, ?, NOW(), ?)");
        if ($h) {
            $h->bind_param('iiiss', $applicant_id, $history_pos_id, $history_status, $updated_by, $reason_text);
            $h->execute();
            $h->close();
        }
    }
} catch (Throwable $e) {
    error_log('[update_applicant] history insert failed: ' . $e->getMessage());
}

$rSql = "SELECT a.*, COALESCE(s.status_name,'') AS status_name, COALESCE(s.status_color,'') AS status_color FROM applicants a LEFT JOIN applicants_status s ON a.status_id = s.status_id LEFT JOIN positions p ON a.position_id = p.id WHERE a.applicant_id = ? LIMIT 1";
$r = $conn->prepare($rSql);
$r->bind_param('i', $applicant_id);
$r->execute();
$res = $r->get_result();
$row = $res ? $res->fetch_assoc() : null;
$r->close();

// Compute a readable text color for the status badge based on returned status_color
try {
    if ($row && isset($row['status_color']) && $row['status_color'] !== '') {
        $hex = ltrim($row['status_color'], '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        if (strlen($hex) === 6) {
            $rC = hexdec(substr($hex,0,2));
            $gC = hexdec(substr($hex,2,2));
            $bC = hexdec(substr($hex,4,2));
            $luma = (0.299*$rC + 0.587*$gC + 0.114*$bC);
            $row['status_text_color'] = ($luma > 186) ? '#111111' : '#ffffff';
        } else {
            $row['status_text_color'] = '#ffffff';
        }
    } else {
        $row['status_text_color'] = '#ffffff';
    }
} catch (Throwable $_) {
    $row['status_text_color'] = '#ffffff';
}

// if a trigger prevented the update, make that explicit to the client
$resp = [
    'ok' => true,
    'applicant' => $row,
    'affected_rows' => isset($affected_rows) ? (int)$affected_rows : (is_null($trigger_ignored) ? null : 0),
    'sql' => $sql,
    'params' => $vals
];
if (!is_null($trigger_ignored)) $resp['trigger_message'] = $trigger_ignored;

echo json_encode($resp);
exit;
?>