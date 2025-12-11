<?php
// Start session only if not already active
if (function_exists('session_status')) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
} else {
    @session_start();
}
require_once '../includes/db.php';

$pageTitle = 'Parse Resume(s)';

if (!isset($_SESSION['user'])) {
    die("Unauthorized access");
}

// ✅ ChatPDF API Key
$API_KEY = "YOUR_CHATPDF_API_KEY";

// ✅ Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['resumes'])) {
    $files = $_FILES['resumes'];
    $count = count($files['name']);

    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;

        $filename = basename($files['name'][$i]);
        $target = "../uploads/" . time() . "_" . $filename;

        if (!move_uploaded_file($files['tmp_name'][$i], $target)) {
            echo "Failed to upload $filename<br>";
            continue;
        }

        // --------------------------
        // 1. Upload resume to ChatPDF
        // --------------------------
        $ch = curl_init("https://api.chatpdf.com/v1/sources/add-file");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $API_KEY"
        ]);
        $mime = mime_content_type($target);
        $data = ["file" => new CURLFile($target, $mime, $filename)];
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $resp = curl_exec($ch);
        curl_close($ch);

        $sourceData = json_decode($resp, true);
        if (!isset($sourceData['sourceId'])) {
            echo "Error uploading $filename<br>";
            continue;
        }
        $sourceId = $sourceData['sourceId'];

        // --------------------------
        // 2. Ask questions to parse
        // --------------------------
        $questions = [
            "Full name of the candidate",
            "Email address",
            "Phone number",
            "LinkedIn profile URL",
            "Highest degree or certificate",
            "Age (if mentioned)",
            "Gender (if mentioned)",
            "Nationality",
            "Total years of professional experience",
            "List of all skills mentioned"
        ];

        $parsed = [];
        foreach ($questions as $q) {
            $ch2 = curl_init("https://api.chatpdf.com/v1/chats/message");
            curl_setopt($ch2, CURLOPT_POST, true);
            curl_setopt($ch2, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer $API_KEY",
                "Content-Type: application/json"
            ]);
            $body = json_encode([
                "sourceId" => $sourceId,
                "messages" => [
                    ["role" => "user", "content" => $q]
                ]
            ]);
            curl_setopt($ch2, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            $resp2 = curl_exec($ch2);
            curl_close($ch2);
            $data2 = json_decode($resp2, true);
            $parsed[$q] = $data2['content'] ?? null;
        }

        // --------------------------
        // 3. Insert into applicants table
        // --------------------------
        $stmt = $conn->prepare("
            INSERT INTO applicants
            (full_name, email, phone, linkedin, degree, age, gender, nationality,
             years_experience, skills, resume_file, ai_result)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "ssssisssisss",
            $parsed["Full name of the candidate"],
            $parsed["Email address"],
            $parsed["Phone number"],
            $parsed["LinkedIn profile URL"],
            $parsed["Highest degree or certificate"],
            $parsed["Age (if mentioned)"],
            $parsed["Gender (if mentioned)"],
            $parsed["Nationality"],
            $parsed["Total years of professional experience"],
            $parsed["List of all skills mentioned"],
            $target,
            json_encode($parsed)
        );

        if ($stmt->execute()) {
            echo "✅ Applicant added for $filename<br>";
        } else {
            echo "❌ DB error: " . $stmt->error . "<br>";
        }
    }
} else {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title><?= htmlspecialchars($pageTitle ?? 'App') ?></title>
    </head>
    <body>
        <h2>Upload Resumes to Parse</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="file" name="resumes[]" multiple required>
            <button type="submit">Upload & Parse</button>
        </form>
    </body>
    </html>
    <?php
}
