<?php 
// public/flows_create.php - Accepts JSON to create a new status and transitions 
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
 
// --- VALIDATION WRAPPER ---
try { 
    $vals = validate_flow_input($conn, $data, 'positions'); 
} catch(Exception $e) {
    // Don't access arbitrary properties on Exception; use getCode()
    $code = (int)$e->getCode();
    if ($code < 100 || $code > 599) {
        $code = 400;
    }

    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
    exit;
}
 
// Extract validated values
$name        = $vals['status_name']; 
$color       = $vals['status_color']; 
$pool_id     = $vals['pool_id']; 
$sort_order  = $vals['sort_order']; 
$active      = $vals['active']; 
$transitions = $vals['transitions']; 
 
try { 
    $conn->begin_transaction(); 
 
    // Insert new status
    $ins = $conn->prepare(
        'INSERT INTO positions_status (status_name, status_color, pool_id, active, sort_order)
         VALUES (?, ?, ?, ?, ?)'
    ); 
    if (!$ins) throw new Exception('Prepare failed'); 
 
    $ins->bind_param('ssiii', $name, $color, $pool_id, $active, $sort_order); 
    if (!$ins->execute()) throw new Exception('Insert failed: ' . $ins->error); 
 
    $new_id = $conn->insert_id; 
    $ins->close(); 
 
    // Insert transitions
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0; 
 
    $tstmt = $conn->prepare(
        'INSERT INTO positions_status_transitions
         (from_status_id, to_status_id, active, updated_by, updated_at)
         VALUES (?, ?, 1, ?, NOW())'
    ); 
    if (!$tstmt) throw new Exception('Prepare transitions failed'); 
 
    foreach ($transitions as $to) { 
        $to_id = (int)$to; 
        $tstmt->bind_param('iii', $new_id, $to_id, $userId); 
        if (!$tstmt->execute()) { 
            throw new Exception('Transition insert failed: ' . $tstmt->error); 
        } 
    } 
    $tstmt->close(); 
 
    $conn->commit(); 
 
    $row = [ 
        'status_id'    => $new_id, 
        'status_name'  => $name, 
        'status_color' => $color, 
        'pool_id'      => $pool_id, 
        'active'       => $active, 
        'sort_order'   => $sort_order 
    ]; 
 
    echo json_encode([
        'success' => true,
        'id'      => $new_id,
        'status'  => $row
    ]); 
    exit; 
 
} catch (Exception $e) { 
    $conn->rollback(); 
    http_response_code(500); 
    error_log('flows_create.php error: ' . $e->getMessage()); 
    echo json_encode(['success' => false, 'error' => 'Server error']); 
    exit; 
} 
 
?>
