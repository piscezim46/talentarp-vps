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
    die("Unauthorized");
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
            $fileName = basename($_FILES[$filesKey]['name'][$index]);
            $targetFile = $uploadDir . time() . "_" . $fileName;

            // Validate file
            $fileType = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if ($fileType !== "pdf") {
                $results[] = ['file' => $fileName, 'status' => 'skipped - not a PDF', 'ticket_id' => null];
                continue;
            }

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
