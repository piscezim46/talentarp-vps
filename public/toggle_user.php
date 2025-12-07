<?php
// Guard session start to avoid duplicate session warnings
if (function_exists('session_status')) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
} else {
    @session_start();
}
require_once '../includes/db.php';
require_once __DIR__ . '/../includes/access.php';
header('Content-Type: application/json');

// authorization
if (!isset($_SESSION['user']) || !_has_access('users_view')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$id = isset($input['id']) ? intval($input['id']) : 0;
$active = isset($input['active']) ? (int)$input['active'] : null;

if ($id <= 0 || ($active !== 0 && $active !== 1)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

// Optional: prevent admin from deactivating themselves
if ($id === (int)$_SESSION['user']['id']) {
    http_response_code(400);
    echo json_encode(['error' => 'You cannot change your own active state']);
    exit;
}

// Update
$stmt = $conn->prepare("UPDATE users SET active = ? WHERE id = ?");
$stmt->bind_param('ii', $active, $id);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Update failed']);
    $stmt->close();
    exit;
}
$stmt->close();

echo json_encode(['success' => true, 'id' => $id, 'active' => $active]);
exit;
?>