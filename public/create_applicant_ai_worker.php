<?php
// CLI worker — run manually or schedule:
// php c:\xampp\htdocs\website\ticketing-system\public\create_applicant_ai_worker.php
require_once __DIR__ . '/../includes/db.php';

$CHATPDF_KEY = getenv('CHATPDF_API_KEY') ?: null;
$LOCAL_EXTRACTOR_URL = getenv('LOCAL_EXTRACTOR_URL') ?: 'http://127.0.0.1:8080/api/extract-cv-details';
$LOCAL_EXTRACTOR_KEY = getenv('LOCAL_EXTRACTOR_API_KEY') ?: null;
set_time_limit(120);

// Helper: mark job failed
function mark_job_failed($conn, $job_id, $applicant_id, $err) {
    $e = $conn->real_escape_string(substr($err,0,2000));
    if ($job_id) $conn->query("UPDATE ai_jobs SET status='failed', last_error='{$e}', updated_at=NOW() WHERE job_id=" . intval($job_id));
    if ($applicant_id) $conn->query("UPDATE applicants SET last_error='{$e}', updated_at=NOW() WHERE applicant_id=" . intval($applicant_id));
}

// pick one ai_jobs queued row
$res = $conn->query("SELECT * FROM ai_jobs WHERE status='queued' ORDER BY created_at LIMIT 1 FOR UPDATE");
$job = $res ? $res->fetch_assoc() : null;

$use_from_applicants = false;
if (!$job) {
    // fallback: pick one queued applicant (legacy flow)
    $r = $conn->query("SELECT applicant_id, resume_file, attempts FROM applicants WHERE (ai_result IS NULL OR ai_result = '') ORDER BY created_at LIMIT 1 FOR UPDATE")->fetch_assoc();
    if (!$r) { echo "No queued jobs\n"; exit(0); }
    $job = [
        'job_id' => null,
        'applicant_id' => $r['applicant_id'],
        'ticket_id' => null,
        'resume_path' => $r['resume_file'],
        'status' => 'queued',
        'attempts' => $r['attempts']
    ];
    $use_from_applicants = true;
}

$job_id = $job['job_id'] ? intval($job['job_id']) : null;
$applicant_id = intval($job['applicant_id']);
$resume_rel = $job['resume_path'];
$resume_path = realpath(__DIR__ . '/../' . $resume_rel);

if (!$resume_path || !file_exists($resume_path)) {
    mark_job_failed($conn, $job_id, $applicant_id, 'resume_missing');
    echo "resume missing for applicant {$applicant_id}\n";
    exit(1);
}

// mark processing
if ($job_id) {
    $conn->query("UPDATE ai_jobs SET status='processing', attempts=attempts+1, updated_at=NOW() WHERE job_id=" . $job_id);
}
    $conn->query("UPDATE applicants SET attempts=attempts+1, updated_at=NOW() WHERE applicant_id=" . $applicant_id);

// Extraction: try ChatPDF if key present, else call local extractor service
$parsed = null;
$error = null;

if ($CHATPDF_KEY) {
    // minimal ChatPDF flow: upload then ask a set of Qs (same as earlier)
    $ch = curl_init("https://api.chatpdf.com/v1/sources/add-file");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$CHATPDF_KEY}"]);
    $cfile = new CURLFile($resume_path, mime_content_type($resume_path) ?: 'application/pdf', basename($resume_path));
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => $cfile]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    if ($resp === false) { $error = curl_error($ch); curl_close($ch); mark_job_failed($conn,$job_id,$applicant_id,$error); exit(1); }
    curl_close($ch);
    $j = json_decode($resp, true);
    if (empty($j['sourceId'])) { $error = 'chatpdf_upload_failed'; mark_job_failed($conn,$job_id,$applicant_id,$error); exit(1); }
    $sourceId = $j['sourceId'];

    $questions = [
        'Full name of the candidate',
        'Email address',
        'Phone number',
        'LinkedIn profile URL',
        'Highest degree or certificate',
        'Age (if mentioned)',
        'Gender (if mentioned)',
        'Nationality',
        'Total years of professional experience',
        'List of all skills mentioned (comma separated)',
        'One paragraph summary of professional profile'
    ];
    $answers = [];
    foreach ($questions as $q) {
        $ch2 = curl_init("https://api.chatpdf.com/v1/chats/message");
        curl_setopt($ch2, CURLOPT_POST, true);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$CHATPDF_KEY}", "Content-Type: application/json"]);
        curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode(["sourceId"=>$sourceId,"messages"=>[["role"=>"user","content"=>$q]]]));
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        $resp2 = curl_exec($ch2);
        if ($resp2 === false) { $error = curl_error($ch2); curl_close($ch2); mark_job_failed($conn,$job_id,$applicant_id,$error); exit(1); }
        curl_close($ch2);
        $d2 = json_decode($resp2, true);
        $ans = $d2['content'] ?? ($d2['message'] ?? ($resp2 ?: null));
        $answers[$q] = $ans;
    }
    $parsed = $answers;
} else {
    // call local extractor service
    $ch = curl_init($LOCAL_EXTRACTOR_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $headers = [];
    if ($LOCAL_EXTRACTOR_KEY) $headers[] = "X-API-Key: {$LOCAL_EXTRACTOR_KEY}";
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $cfile = new CURLFile($resume_path, mime_content_type($resume_path) ?: 'application/pdf', basename($resume_path));
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => $cfile]);
    $resp = curl_exec($ch);
    if ($resp === false) { $error = curl_error($ch); mark_job_failed($conn,$job_id,$applicant_id,$error); exit(1); }
    $json = json_decode($resp, true);
    if (!$json) { $error = 'local_extractor_invalid_json'; mark_job_failed($conn,$job_id,$applicant_id,$error); exit(1); }
    $parsed = $json;
}

// map parsed into applicant columns (best-effort)
$full_name = $parsed['Full name of the candidate'] ?? ($parsed['full_name'] ?? ($parsed['name'] ?? null));
$email = $parsed['Email address'] ?? ($parsed['email'] ?? null);
$phone = $parsed['Phone number'] ?? ($parsed['phone'] ?? null);
$linkedin = $parsed['LinkedIn profile URL'] ?? ($parsed['linkedin'] ?? null);
$degree = $parsed['Highest degree or certificate'] ?? ($parsed['degree'] ?? null);
$age = isset($parsed['Age (if mentioned)']) ? intval($parsed['Age (if mentioned)']) : (isset($parsed['age']) ? intval($parsed['age']) : null);
$gender = $parsed['Gender (if mentioned)'] ?? ($parsed['gender'] ?? null);
$nationality = $parsed['Nationality'] ?? ($parsed['nationality'] ?? null);
$years_experience = isset($parsed['Total years of professional experience']) ? intval($parsed['Total years of professional experience']) : (isset($parsed['years_experience']) ? intval($parsed['years_experience']) : null);
$skills = $parsed['List of all skills mentioned (comma separated)'] ?? (is_array($parsed['skills'] ?? null) ? implode(', ', $parsed['skills']) : ($parsed['skills'] ?? null));
$ai_result_json = json_encode($parsed);

// update applicants row (ai_summary and parsing_status removed)
$upd = $conn->prepare("
    UPDATE applicants SET
      full_name = COALESCE(?, full_name),
      email = COALESCE(?, email),
      phone = COALESCE(?, phone),
      linkedin = COALESCE(?, linkedin),
      degree = COALESCE(?, degree),
      age = COALESCE(?, age),
      gender = COALESCE(?, gender),
      nationality = COALESCE(?, nationality),
      years_experience = COALESCE(?, years_experience),
      skills = COALESCE(?, skills),
      ai_result = ?,
      updated_at = NOW()
    WHERE applicant_id = ?
");
if (!$upd) { mark_job_failed($conn,$job_id,$applicant_id,"prepare_failed: ".$conn->error); exit(1); }

$upd->bind_param(
    'sssssississi',
    $full_name,
    $email,
    $phone,
    $linkedin,
    $degree,
    $age,
    $gender,
    $nationality,
    $years_experience,
    $skills,
    $ai_result_json,
    $applicant_id
);
if (!$upd->execute()) {
    mark_job_failed($conn,$job_id,$applicant_id, "update_failed: ".$upd->error);
    $upd->close();
    exit(1);
}
$upd->close();

// update ai_jobs row if used
if ($job_id) {
    $safe_result = $conn->real_escape_string($ai_result_json);
    $conn->query("UPDATE ai_jobs SET status='done', result = '{$safe_result}', updated_at=NOW() WHERE job_id=" . intval($job_id));
}

echo "Applicant {$applicant_id} parsed\n";
exit(0);
?>