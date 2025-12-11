<?php
// Endpoint to receive client-side audit events (clicks, etc.)
if (function_exists('session_status') && session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
// include audit helper if available
if (file_exists(__DIR__ . '/../includes/audit.php')) require_once __DIR__ . '/../includes/audit.php';

// Only accept POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
if (!$raw) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Empty body']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Determine actor from session when possible
$actor = $_SESSION['user']['id'] ?? ($_SESSION['user']['user_id'] ?? 0);

$action_type = isset($data['action_type']) ? substr((string)$data['action_type'],0,50) : 'click';
$entity_type = isset($data['entity_type']) ? substr((string)$data['entity_type'],0,50) : ($data['entity'] ?? 'page');
$entity_id = isset($data['entity_id']) ? intval($data['entity_id']) : 0;

// Build new_values payload: include element summary and page info
$new_values = [];
foreach (['element','page','timestamp','meta'] as $k) {
    if (isset($data[$k])) $new_values[$k] = $data[$k];
}

// Ensure we don't record sensitive values
if (isset($new_values['element']['dataset']) && is_array($new_values['element']['dataset'])) {
    foreach ($new_values['element']['dataset'] as $dk => $dv) {
        $lk = strtolower($dk);
        if (strpos($lk, 'password') !== false || strpos($lk, 'token') !== false || strpos($lk, 'secret') !== false) {
            $new_values['element']['dataset'][$dk] = '[REDACTED]';
        }
    }
}

// Call audit helper
$ok = false;
try {
    if (function_exists('audit_log')) {
        // Use audit_log with explicit actor when available
        $ok = audit_log($actor, $action_type, $entity_type, $entity_id, null, $new_values, $data['note'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null);
    } elseif (function_exists('audit_log_auto')) {
        $ok = audit_log_auto($action_type, $entity_type, $entity_id, null, $new_values);
    }
} catch (Throwable $_) { $ok = false; }

if ($ok) echo json_encode(['success' => true]); else echo json_encode(['success' => false, 'error' => 'write_failed']);
exit;

?>
