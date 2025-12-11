<?php
// public/create_applicant.php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user'])) {
    http_response_code(403);
    echo "Access denied";
    exit;
}

$uploadDir = __DIR__ . '/uploads/applicants/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// Simple upload debug logger. Appends to uploads/applicants/upload-debug.log
function upload_debug_log($msg){
    $logDir = __DIR__ . '/uploads/applicants/';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $file = $logDir . 'upload-debug.log';
    $entry = "[".date('c')."] " . $msg . "\n";
    @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
}

// Collect form fields (shared metadata)
$full_name = trim($_POST['full_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$position_id = isset($_POST['position_id']) ? intval($_POST['position_id']) : 0;
$department_id = isset($_POST['department_id']) ? intval($_POST['department_id']) : 0;
$team_id = isset($_POST['team_id']) ? intval($_POST['team_id']) : 0;
$manager_name = trim($_POST['manager_name'] ?? '');
$created_by = (int)($_SESSION['user']['id'] ?? 0);

// record that the endpoint was invoked (helps diagnose missing upload-debug.log)
upload_debug_log('ENTRY: create_applicant invoked; method=' . ($_SERVER['REQUEST_METHOD'] ?? 'NULL') . '; position_id=' . intval($position_id) . '; _FILES_keys=' . implode(',', array_keys($_FILES ?? [])) . '; CONTENT_LENGTH=' . ($_SERVER['CONTENT_LENGTH'] ?? 'NULL') . '; HTTP_REFERER=' . ($_SERVER['HTTP_REFERER'] ?? 'NULL'));

if (!isset($_FILES['uploads']) || !is_array($_FILES['uploads']['name']) || $position_id <= 0) {
    $msg = 'Missing files or position - position_id=' . intval($position_id) . ' - files_present=' . (isset($_FILES['uploads'])? '1':'0');
    upload_debug_log($msg . ' - $_FILES keys: ' . implode(',', array_keys($_FILES)) );
    http_response_code(400);
    echo "Missing files or position";
    exit;
}

$files = $_FILES['uploads'];
$processed = 0;
$errors = [];

for ($i=0; $i < count($files['name']); $i++) {
    if ($files['error'][$i] !== UPLOAD_ERR_OK) { $errors[] = "Upload error for {$files['name'][$i]}"; continue; }
    $orig = basename($files['name'][$i]);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') { $errors[] = "Skipped non-pdf: {$orig}"; continue; }

    $safe = time() . '_' . bin2hex(random_bytes(6)) . '_' . preg_replace('/[^A-Za-z0-9._-]/','_', $orig);
    $target = $uploadDir . $safe;
    if (!move_uploaded_file($files['tmp_name'][$i], $target)) { $errors[] = "Failed move: {$orig}"; continue; }

    // set resume path
    $resume_path = 'uploads/applicants/' . $safe;

    // decide default status values
    $default_status_text = 'open';
    $default_status_id = 1;

    // detect which status column exists in applicants table
    $has_status_col = false;
    $has_status_id_col = false;
    $colRes = $conn->query("SHOW COLUMNS FROM applicants LIKE 'status'");
    if ($colRes && $colRes->num_rows > 0) $has_status_col = true;
    $colRes && $colRes->free();

    $colRes = $conn->query("SHOW COLUMNS FROM applicants LIKE 'status_id'");
    if ($colRes && $colRes->num_rows > 0) $has_status_id_col = true;
    $colRes && $colRes->free();

    if ($has_status_col) {
        // insert using textual status column (parsing_status removed)
        $stmt = $conn->prepare(
            "INSERT INTO applicants
              (full_name, email, phone, resume_file, position_id, `status`, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );
        if (!$stmt) { @unlink($target); $errors[] = "DB prepare failed: " . $conn->error; continue; }
        $stmt->bind_param('sssiss', $full_name, $email, $phone, $resume_path, $position_id, $default_status_text);
    } elseif ($has_status_id_col) {
        // insert using numeric status_id column (parsing_status removed)
        $stmt = $conn->prepare(
            "INSERT INTO applicants
              (full_name, email, phone, resume_file, position_id, status_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );
        if (!$stmt) { @unlink($target); $errors[] = "DB prepare failed: " . $conn->error; continue; }
        $stmt->bind_param('ssssis', $full_name, $email, $phone, $resume_path, $position_id, $default_status_id);
    } else {
        // fallback: insert without any status column (parsing_status removed)
        $stmt = $conn->prepare(
            "INSERT INTO applicants
              (full_name, email, phone, resume_file, position_id, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        if (!$stmt) { @unlink($target); $errors[] = "DB prepare failed: " . $conn->error; continue; }
        $stmt->bind_param('ssssi', $full_name, $email, $phone, $resume_path, $position_id);
    }

    if (!$stmt->execute()) {
        error_log("create_applicant execute failed: " . $stmt->error);
        @unlink($target);
        $stmt->close();
        $errors[] = "DB insert failed for {$orig}: " . $stmt->error;
        continue;
    }

    $stmt->close();
    $processed++;
}

if ($processed === 0) {
    // log diagnostics for failed upload attempts
    $dump = "No files processed. processed={$processed}; errors=" . implode('; ', $errors);
    $dump .= "; \\_FILES= " . json_encode(array_map(function($f){ return ['name'=>$f['name'] ?? null,'error'=>$f['error'] ?? null,'size'=>$f['size'] ?? null]; }, $_FILES));
    upload_debug_log($dump);
    http_response_code(500);
    echo "No files processed. " . implode('; ', $errors);
    exit;
}

// After creating applicants, if they were created for a specific position, attempt to
// update the position status to "Applicants Active" when the current status is
// "Hiring Active". Catch and record any DB errors but don't block applicant creation.
if ($position_id > 0) {
    // fetch current status name for the position
    $pstmt = $conn->prepare("SELECT p.status_id, COALESCE(s.status_name,'') AS status_name FROM positions p LEFT JOIN positions_status s ON p.status_id = s.status_id WHERE p.id = ? LIMIT 1");
    if ($pstmt) {
        $pstmt->bind_param('i', $position_id);
        if ($pstmt->execute()) {
            $pres = $pstmt->get_result();
            $prow = $pres ? $pres->fetch_assoc() : null;
            if ($prow) {
                $curStatus = trim((string)($prow['status_name'] ?? ''));
                if (strcasecmp($curStatus, 'Hiring Active') === 0) {
                    // find the status_id for 'Applicants Active'
                    $lookup = $conn->prepare("SELECT status_id FROM positions_status WHERE LOWER(status_name) = ? LIMIT 1");
                    if ($lookup) {
                        $needle = 'applicants active';
                        $lookup->bind_param('s', $needle);
                        if ($lookup->execute()) {
                            $lres = $lookup->get_result();
                            $lr = $lres ? $lres->fetch_assoc() : null;
                            if ($lr && isset($lr['status_id'])) {
                                $targetStatusId = (int)$lr['status_id'];
                                $up = $conn->prepare("UPDATE positions SET status_id = ? WHERE id = ?");
                                if ($up) {
                                    $up->bind_param('ii', $targetStatusId, $position_id);
                                    if (!$up->execute()) {
                                        $errors[] = 'Position status update failed: ' . $up->error;
                                    }
                                    else {
                                        // record status change in positions_status_history for audit
                                        $hist = $conn->prepare("INSERT INTO positions_status_history (position_id, status_id, updated_by, updated_at, reason) VALUES (?, ?, ?, NOW(), ?)");
                                        if ($hist) {
                                            $reason = 'Auto-updated to Applicants Active after applicant upload';
                                            $hist->bind_param('iiis', $position_id, $targetStatusId, $created_by, $reason);
                                            if (!$hist->execute()) {
                                                $errors[] = 'Failed to write positions_status_history: ' . $hist->error;
                                            }
                                            $hist->close();
                                        } else {
                                            $errors[] = 'Failed to prepare positions_status_history insert: ' . $conn->error;
                                        }
                                    }
                                    $up->close();
                                } else {
                                    $errors[] = 'Position status update prepare failed: ' . $conn->error;
                                }
                            } else {
                                $errors[] = 'Target status "Applicants Active" not found in positions_status table.';
                            }
                        } else {
                            $errors[] = 'Failed to lookup Applicants Active status: ' . $lookup->error;
                        }
                        $lookup->close();
                    } else {
                        $errors[] = 'Failed to prepare status lookup: ' . $conn->error;
                    }
                }
            }
        } else {
            $errors[] = 'Failed to read position status: ' . $pstmt->error;
        }
        $pstmt->close();
    } else {
        $errors[] = 'Failed to prepare position status read: ' . $conn->error;
    }
}

// success: redirect back to the applicants dashboard page (keep user on applicants.php)
// If there were DB errors when updating the position status, include them in the query string
if (!empty($errors)) {
    $err = urlencode(implode('; ', $errors));
    // log errors for visibility
    upload_debug_log('Completed with errors: ' . implode(' | ', $errors));
    header('Location: applicants.php?created=' . $processed . '&status_error=' . $err);
} else {
    upload_debug_log('Upload processed successfully: count=' . intval($processed));
    header('Location: applicants.php?created=' . $processed);
}
exit;
?>
