<?php
session_start();
require_once '../includes/db.php';

// debug helpers (temporary)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (isset($_GET['debug'])) {
    header('Content-Type: application/json');

    $files_info = [];
    foreach ($_FILES as $field => $f) {
        if (is_array($f['name'])) {
            $files_info[$field] = [];
            for ($i = 0; $i < count($f['name']); $i++) {
                $tmp = $f['tmp_name'][$i] ?? '';
                $files_info[$field][] = [
                    'name' => $f['name'][$i] ?? null,
                    'error' => $f['error'][$i] ?? null,
                    'size' => $f['size'][$i] ?? null,
                    'tmp_name' => $tmp,
                    'is_uploaded' => $tmp ? is_uploaded_file($tmp) : false,
                ];
            }
        } else {
            $tmp = $f['tmp_name'] ?? '';
            $files_info[$field] = [
                'name' => $f['name'] ?? null,
                'error' => $f['error'] ?? null,
                'size' => $f['size'] ?? null,
                'tmp_name' => $tmp,
                'is_uploaded' => $tmp ? is_uploaded_file($tmp) : false,
            ];
        }
    }

    echo json_encode([
        'files' => $files_info,
        'post_max_size' => ini_get('post_max_size'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'max_file_uploads' => ini_get('max_file_uploads'),
        'php_sapi' => php_sapi_name(),
    ], JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// find an uploaded file (accept 'resume', 'uploads[]' or any file input) and use the first valid one
$uploadFile = null;
$uploadField = null;

if (!empty($_FILES['resume']) && is_array($_FILES['resume']) && ($_FILES['resume']['error'] === UPLOAD_ERR_OK)) {
    $uploadFile = $_FILES['resume'];
    $uploadField = 'resume';
} elseif (!empty($_FILES['uploads'])) {
    // uploads[] may be a multi-file input
    if (is_array($_FILES['uploads']['name'])) {
        for ($i = 0; $i < count($_FILES['uploads']['name']); $i++) {
            if ($_FILES['uploads']['error'][$i] === UPLOAD_ERR_OK) {
                $uploadFile = [
                    'name' => $_FILES['uploads']['name'][$i],
                    'tmp_name' => $_FILES['uploads']['tmp_name'][$i],
                    'type' => $_FILES['uploads']['type'][$i],
                    'error' => $_FILES['uploads']['error'][$i],
                    'size' => $_FILES['uploads']['size'][$i],
                ];
                $uploadField = 'uploads';
                break;
            }
        }
    } elseif ($_FILES['uploads']['error'] === UPLOAD_ERR_OK) {
        $uploadFile = $_FILES['uploads'];
        $uploadField = 'uploads';
    }
} else {
    // fallback: pick first successful file from any input name
    foreach ($_FILES as $k => $f) {
        if (is_array($f['name'])) {
            for ($i = 0; $i < count($f['name']); $i++) {
                if ($f['error'][$i] === UPLOAD_ERR_OK) {
                    $uploadFile = [
                        'name' => $f['name'][$i],
                        'tmp_name' => $f['tmp_name'][$i],
                        'type' => $f['type'][$i],
                        'error' => $f['error'][$i],
                        'size' => $f['size'][$i],
                    ];
                    $uploadField = $k;
                    break 2; // exits both the for and the foreach
                }
            }
        } else {
            if ($f['error'] === UPLOAD_ERR_OK) {
                $uploadFile = $f;
                $uploadField = $k;
                break; // only need to exit the foreach here
            }
        }
    }
}

if (!$uploadFile) {
    http_response_code(400);
    echo json_encode(['error' => 'Resume file is required']);
    exit;
}

// save the chosen uploaded file
$uploadsDir = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);

$origName = basename($uploadFile['name']);
$target = $uploadsDir . DIRECTORY_SEPARATOR . time() . '_' . preg_replace('/[^A-Za-z0-9\-\._]/', '_', $origName);
if (!move_uploaded_file($uploadFile['tmp_name'], $target)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save uploaded resume']);
    exit;
}
$resume_path_for_db = 'uploads/' . basename($target);

// Optional: create a ticket record (if you still use tickets)
$role_applied = trim($_POST['role_applied'] ?? '');
$department   = trim($_POST['department'] ?? '');
$note         = trim($_POST['note'] ?? '');
$userId = (int)($_SESSION['user']['id'] ?? 0);
$ticket_id = null;

if ($role_applied !== '' || $department !== '' || $note !== '') {
    $stmtT = $conn->prepare("INSERT INTO tickets (role_applied, department, note, resume_path, status, created_at, user_id) VALUES (?, ?, ?, ?, 'Submitted', NOW(), ?)");
    if ($stmtT) {
        $stmtT->bind_param('ssssi', $role_applied, $department, $note, $resume_path_for_db, $userId);
        $stmtT->execute();
        $ticket_id = $stmtT->insert_id;
        $stmtT->close();
    }
}

// Insert into applicants table as a queued job (uses applicants as queue)
$attempts = 0;

$ins = $conn->prepare("INSERT INTO applicants (resume_file, ai_result, attempts, last_error, created_at) VALUES (?, ?, ?, ?, NOW())");
if (!$ins) {
    http_response_code(500);
    echo json_encode(['error' => 'DB prepare failed: ' . $conn->error]);
    exit;
}
$ai_result_json = null;
$last_err = null;
$ins->bind_param('ssis', $resume_path_for_db, $ai_result_json, $attempts, $last_err);
if (!$ins->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'DB insert failed: ' . $ins->error]);
    $ins->close();
    exit;
}
$applicant_id = $ins->insert_id;
$ins->close();

// NOTE: AI enqueuing disabled temporarily. We will not create ai_jobs rows here.
// This prevents automatic parsing until a new AI integration is ready.
$job_id = null;
// If you later want to resume enqueuing, re-enable the ai_jobs insertion logic above.

// return JSON only (no inline script). front-end must read this JSON and start polling.
echo json_encode(['success' => true, 'applicant_id' => $applicant_id, 'ticket_id' => $ticket_id ?? null, 'job_id' => $job_id]);
exit;
?>