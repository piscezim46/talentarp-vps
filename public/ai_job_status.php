<?php
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json');
$job = intval($_GET['job_id'] ?? 0);
if ($job) {
    $r = $conn->query("SELECT job_id, applicant_id, ticket_id, status, result, last_error, attempts, created_at, updated_at FROM ai_jobs WHERE job_id = " . $job)->fetch_assoc();
    if (!$r) { echo json_encode(['error'=>'not found']); exit; }
    echo json_encode($r);
    exit;
}
// fallback: query applicant parsing_status
$app = intval($_GET['applicant_id'] ?? 0);
if ($app) {
    $r = $conn->query("SELECT applicant_id, ai_result, last_error, attempts, updated_at FROM applicants WHERE applicant_id = " . $app)->fetch_assoc();
    if (!$r) { echo json_encode(['error'=>'not found']); exit; }
    echo json_encode($r);
    exit;
}
echo json_encode(['error'=>'missing job_id or applicant_id']);
?>