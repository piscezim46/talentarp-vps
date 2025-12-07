<?php
if (function_exists('session_status')) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
} else { @session_start(); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/access.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || !(_has_access('users_bulk_force_reset') || _has_access('users_edit'))) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}
$ids = isset($input['ids']) && is_array($input['ids']) ? array_map('intval', $input['ids']) : [];
$days = isset($input['days']) ? intval($input['days']) : 0;
if (empty($ids) || $days <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid ids or days']);
    exit;
}

// sanitize ids and build IN list
$ids = array_values(array_unique($ids));
$in = implode(',', array_map('intval', $ids));

$stmt = $conn->prepare("UPDATE users SET password_expiration_days = ? WHERE id IN ($in)");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
    exit;
}
$stmt->bind_param('i', $days);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Update failed: ' . $stmt->error]);
    $stmt->close();
    exit;
}
$affected = $stmt->affected_rows;
$stmt->close();

echo json_encode(['success' => true, 'updated' => $affected, 'updated_ids' => $ids]);
exit;
?>
