<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/email_utils.php';

$to = 'icv5ui@gmail.com';
$username = 'testuser';
$password = 'TempPass123!';
$opts = [
    'subject' => 'SMTP Test from Talent ARP',
    'from_email' => defined('SMTP_FROM') ? SMTP_FROM : 'Master@talentarp.com',
    'from_name' => defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Master'
];

echo "Sending test email to: $to\n";
try {
    $res = send_invitation_email($to, $username, $password, $opts);
    echo "send_invitation_email returned: ";
    var_export($res);
    echo "\n";
} catch (Throwable $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}

$logFile = __DIR__ . '/logs/email.log';
if (file_exists($logFile)) {
    echo "\nLast 80 lines of logs/email.log:\n";
    $lines = explode("\n", trim(file_get_contents($logFile)));
    $last = array_slice($lines, -80);
    echo implode("\n", $last);
    echo "\n";
} else {
    echo "\nNo log file found at logs/email.log\n";
}

?>