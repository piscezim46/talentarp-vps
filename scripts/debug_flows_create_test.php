<?php
// Debug script to exercise validate_flow_input and basic flows_create logic
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/flows_helpers.php';

// emulate session user
    // Start session only if not already active
    if (function_exists('session_status')) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    } else {
        @session_start();
    }
$_SESSION['user'] = ['id' => 1, 'role' => 'admin', 'access_keys' => ['flows_view']];

header('Content-Type: application/json; charset=utf-8');

$tests = [];

$sample_positions = [
    'status_name' => 'Debug Test',
    'status_color' => '#123456',
    'pool_id' => 0,
    'sort_order' => 100,
    'active' => 1,
    'transitions' => []
];

$sample_applicants = $sample_positions; // same structure

try {
    $vals = validate_flow_input($conn, $sample_positions, 'positions');
    $tests['positions'] = ['ok' => true, 'validated' => $vals];
} catch (Exception $e) {
    // $tests['positions'] = ['ok' => false, 'error' => $e->getMessage(), 'code' => $e->getCode(), 'http_code' => (isset($e->http_code) ? $e->http_code : null)];
}

try {
    $vals = validate_flow_input($conn, $sample_applicants, 'applicants');
    $tests['applicants'] = ['ok' => true, 'validated' => $vals];
} catch (Exception $e) {
    // $tests['applicants'] = ['ok' => false, 'error' => $e->getMessage(), 'code' => $e->getCode(), 'http_code' => (isset($e->http_code) ? $e->http_code : null)];
}

echo json_encode($tests, JSON_PRETTY_PRINT);
