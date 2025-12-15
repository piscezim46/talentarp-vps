<?php
// Ensure session is started if not already active
if (function_exists('session_status')) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
} else {
    @session_start();
}
require_once '../includes/db.php';

if (!isset($_SESSION['user'])) {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    // If AJAX/JSON client, return 403 with JSON; otherwise show simple text
    if (strpos($accept, 'application/json') !== false || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'unauthorized', 'message' => 'Access denied']);
        exit;
    }
    die("Unauthorized");
}

// small debug logger to uploads/ (helpful on production)
function upload_debug_log($msg) {
    $dir = __DIR__ . '/uploads/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . 'upload-debug.log';
    $entry = "[" . date('c') . "] " . $msg . PHP_EOL;
    @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $position_id = intval($_POST['position_id'] ?? 0);
    $user_id = $_SESSION['user']['id']; // uploader

    // Accept either 'resumes' or legacy 'pdfs' as the file input name
    $filesKey = null;
    if (isset($_FILES['resumes'])) $filesKey = 'resumes';
    elseif (isset($_FILES['pdfs'])) $filesKey = 'pdfs';

    $uploadDir = __DIR__ . "/uploads/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $results = [];
    $createdCount = 0;

    if ($filesKey) {
        foreach ($_FILES[$filesKey]['tmp_name'] as $index => $tmpName) {
            $fileName = basename($_FILES[$filesKey]['name'][$index] ?? '');
            $fileError = $_FILES[$filesKey]['error'][$index] ?? UPLOAD_ERR_NO_FILE;
            $fileSize = $_FILES[$filesKey]['size'][$index] ?? 0;
            $targetFile = $uploadDir . time() . "_" . $fileName;

            // Basic validation
            $fileType = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if ($fileType !== "pdf") {
                $results[] = ['file' => $fileName, 'status' => 'skipped - not a PDF', 'ticket_id' => null];
                upload_debug_log("SKIP non-pdf: {$fileName} ext={$fileType} size={$fileSize}");
                continue;
            }

            // Check PHP upload error code first
            if ($fileError !== UPLOAD_ERR_OK) {
                $results[] = ['file' => $fileName, 'status' => 'upload_error_code_' . intval($fileError), 'ticket_id' => null];
                upload_debug_log("UPLOAD_ERR for {$fileName}: code={$fileError} size={$fileSize}");
                continue;
            }

            // Ensure the tmp file exists and is a valid uploaded file
            if (!is_uploaded_file($tmpName)) {
                $results[] = ['file' => $fileName, 'status' => 'tmp_missing_or_invalid', 'ticket_id' => null];
                upload_debug_log("INVALID_TMP for {$fileName}: tmpName={$tmpName} exists=" . (file_exists($tmpName)?'1':'0'));
                // also log PHP temp dir and upload_tmp_dir setting
                upload_debug_log('upload_tmp_dir=' . ini_get('upload_tmp_dir') . ' sys_get_temp_dir=' . sys_get_temp_dir());
                continue;
            }

            // Ensure upload dir exists and is writable
            if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true)) {
                $results[] = ['file' => $fileName, 'status' => 'server_error_upload_dir', 'ticket_id' => null];
                upload_debug_log("FAILED_MKDIR uploadDir={$uploadDir} for {$fileName}; is_dir=" . (is_dir($uploadDir)?'1':'0'));
                continue;
            }
            if (!is_writable($uploadDir)) {
                $results[] = ['file' => $fileName, 'status' => 'upload_dir_not_writable', 'ticket_id' => null];
                upload_debug_log("UPLOAD_DIR_NOT_WRITABLE {$uploadDir} perms=" . substr(sprintf('%o', fileperms($uploadDir)), -4) . " for {$fileName}");
                continue;
            }

            // Attempt move and log failures with diagnostics
            if (move_uploaded_file($tmpName, $targetFile)) {
                // Save applicant
                $stmt = $conn->prepare("INSERT INTO applicants (resume_file) VALUES (?)");
                $stmt->bind_param("s", $targetFile);
                $stmt->execute();
                $applicant_id = $stmt->insert_id;

                // Create ticket
                $status = "Submitted";
                $stmt2 = $conn->prepare("INSERT INTO tickets (applicant_id, user_id, position_id, status, resume_path) VALUES (?, ?, ?, ?, ?)");
                $stmt2->bind_param("iiiss", $applicant_id, $user_id, $position_id, $status, $targetFile);
                $stmt2->execute();
                $ticket_id = $stmt2->insert_id ?? null;

                $results[] = ['file' => $fileName, 'status' => 'created', 'ticket_id' => $ticket_id];
                $createdCount++;
            } else {
                $results[] = ['file' => $fileName, 'status' => 'error uploading', 'ticket_id' => null];
                // Log detailed diagnostics to help debugging on prod
                $diag = [
                    'file' => $fileName,
                    'tmpName' => $tmpName,
                    'target' => $targetFile,
                    'tmp_exists' => file_exists($tmpName) ? 1 : 0,
                    'upload_dir_exists' => is_dir($uploadDir) ? 1 : 0,
                    'upload_dir_perms' => substr(sprintf('%o', fileperms($uploadDir)), -4),
                    'php_upload_tmp_dir' => ini_get('upload_tmp_dir'),
                    'sys_temp_dir' => sys_get_temp_dir(),
                    'file_error_code' => $fileError,
                    'file_size' => $fileSize,
                ];
                upload_debug_log('MOVE_FAILED: ' . json_encode($diag));
            }
        }
    }

    // If the client expects JSON (AJAX fetch), return JSON
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (strpos($accept, 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($results);
        exit;
    }

    // Non-AJAX: show simple HTML and a toast (load notify.js if needed)
    foreach ($results as $r) {
        echo htmlspecialchars($r['file']) . ' — ' . htmlspecialchars($r['status']) . ($r['ticket_id'] ? (' (ticket ' . intval($r['ticket_id']) . ')') : '') . "<br>";
    }
    echo "<a href='applicants.php'>Back to Applicants</a>";

    // Print inline toast script to inform user how many applicants were created
    $msg = 'Uploaded ' . intval($createdCount) . ' applicants';
    echo "\n<link rel=\"stylesheet\" href=\"assets/css/notify.css\">\n";
    echo "<script>var s=document.createElement('script');s.src='assets/js/notify.js';s.onload=function(){try{if(window.Notify&&Notify.push){Notify.push({from:'Applicants',message:" . json_encode($msg) . ",color:'#10b981',duration:8000});} }catch(e){console.warn(e);}};document.head.appendChild(s);</script>";
}
