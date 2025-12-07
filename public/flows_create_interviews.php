<?php
// public/flows_create_interviews.php - Accepts JSON to create a new interview status and transitions
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/flows_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user']) || !_has_access('flows_view', ['admin','hr'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

try {
    $vals = validate_flow_input($conn, $data, 'interviews');
} catch(Exception $e) {
    // Use getCode() only to avoid accessing undefined properties on Exception
    $code = (int)$e->getCode();
    if ($code < 100 || $code > 599) {
        $code = 400;
    }
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

$name = $vals['status_name'];
$color = $vals['status_color'];
$pool_id = $vals['pool_id'];
$sort_order = $vals['sort_order'];
$active = $vals['active'];
$transitions = $vals['transitions'];

try {
    $conn->begin_transaction();

    $ins = $conn->prepare('INSERT INTO interview_statuses (name, status_color, pool_id, active, sort_order) VALUES (?, ?, ?, ?, ?)');
    if (!$ins) throw new Exception('Prepare failed: ' . $conn->error);
    $ins->bind_param('ssiii', $name, $color, $pool_id, $active, $sort_order);
    if (!$ins->execute()) {
        // MySQL error 1364 = Field doesn't have a default value (strict mode)
        $errno = $ins->errno ?: $conn->errno;
        $err = $ins->error ?: $conn->error;
        if ($errno == 1364) {
            // Provide a helpful message and suggested SQL to fix the schema
            $suggest = "ALTER TABLE `interview_statuses` MODIFY COLUMN `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY;";
            error_log('flows_create_interviews.php insert failed (no default id): ' . $err);
            echo json_encode(['success' => false, 'error' => "Field 'id' has no default value on table `interview_statuses`. This usually means the `id` column is not AUTO_INCREMENT. Suggested fix: $suggest", 'sql_suggestion' => $suggest]);
            exit;
        }
        throw new Exception('Insert failed: ' . $err);
    }
    $new_id = $conn->insert_id;
    $ins->close();

    $updated_by = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
    if (is_array($transitions) && count($transitions)) {
        $tstmt = $conn->prepare('INSERT INTO interviews_status_transitions (from_status_id, to_status_id, active, updated_by, created_at) VALUES (?, ?, 1, ?, NOW())');
        if (!$tstmt) throw new Exception('Prepare transitions failed');
        foreach ($transitions as $to) {
            $to_id = (int)$to;
            $tstmt->bind_param('iii', $new_id, $to_id, $updated_by);
            if (!$tstmt->execute()) throw new Exception('Transition insert failed: ' . $tstmt->error);
        }
        $tstmt->close();
    }

    $conn->commit();

    $row = [
        'id' => $new_id,
        'name' => $name,
        'status_color' => $color,
        'pool_id' => $pool_id,
        'active' => $active,
        'sort_order' => $sort_order,
    ];

    echo json_encode(['success' => true, 'id' => $new_id, 'status' => $row]);
    exit;

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    // Log the full error for server-side debugging
    error_log('flows_create_interviews.php error: ' . $e->getMessage());
    // Return the exception message in the response to aid client-side debugging
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

?>