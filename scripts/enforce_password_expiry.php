<?php
// scripts/enforce_password_expiry.php
// CLI helper to mark users whose password_changed_at is older than N days

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/user_utils.php';

// Accept days as first argument, default 30
$days = isset($argv[1]) ? (int)$argv[1] : 30;
if ($days <= 0) $days = 30;

try {
    if (!function_exists('enforce_password_expiry')) {
        fwrite(STDERR, "enforce_password_expiry() not available. Did you include includes/user_utils.php?\n");
        exit(2);
    }

    $affected = enforce_password_expiry($conn, $days);
    if ($affected === false) {
        fwrite(STDERR, "Failed to enforce password expiry (DB error). Check logs and DB connection.\n");
        error_log('enforce_password_expiry.php: enforce_password_expiry returned false');
        exit(3);
    }

    $msg = sprintf("Enforce password expiry: marked %d user(s) as requiring password reset (older than %d days).\n", $affected, $days);
    echo $msg;
    error_log('enforce_password_expiry.php: ' . trim($msg));
    exit(0);
} catch (Throwable $e) {
    $err = 'Exception: ' . $e->getMessage();
    fwrite(STDERR, $err . "\n");
    error_log('enforce_password_expiry.php exception: ' . $e->getMessage());
    exit(4);
}
?>