<?php
// includes/email_utils.php
// Helper for sending invitation emails. Uses PHPMailer via Composer (SMTP-only).

function send_invitation_email($to, $username, $password_or_link, $opts = []) {
    // Options and defaults
    $from_email = $opts['from_email'] ?? 'Master@talentarp.com';
    $from_name = $opts['from_name'] ?? 'Master';
    $subject = $opts['subject'] ?? 'Your account at Talent ARP';
    $use_link = isset($opts['use_temporary_link']) ? (bool)$opts['use_temporary_link'] : false;
    $link = $opts['temp_link'] ?? '';

    // Build login URL (best-effort)
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $loginUrl = rtrim($opts['login_url'] ?? ($scheme . '://' . $host . '/index.php'), '/');

    // Logo path (public-facing). Adjust if your logo is located elsewhere.
    $logoPath = $opts['logo_url'] ?? ($scheme . '://' . $host . '/assets/images/White-Bugatti-Logo.webp');

    // Prepare message (HTML)
    $credsHtml = '';
    if ($use_link && $link) {
        $credsHtml = '<p style="margin:0 0 12px 0;">Please use the following link to set your password and sign in:</p>';
        $credsHtml .= '<p style="margin:0 0 12px 0;"><a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($link) . '</a></p>';
    } else {
        $credsHtml = '<table cellpadding="0" cellspacing="0" style="margin:0 0 12px 0; font-family:Inter,Segoe UI,Arial,sans-serif; font-size:14px;">'
            . '<tr><td style="padding:6px 10px; background:#f6f6f6; border:1px solid #e6e6e6;"><strong>Username</strong></td><td style="padding:6px 10px; border:1px solid #e6e6e6;">' . htmlspecialchars($username) . '</td></tr>'
            . '<tr><td style="padding:6px 10px; background:#f6f6f6; border:1px solid #e6e6e6;"><strong>Password</strong></td><td style="padding:6px 10px; border:1px solid #e6e6e6;">' . htmlspecialchars($password_or_link) . '</td></tr>'
            . '</table>';
    }

    $buttonUrl = $use_link && $link ? $link : $loginUrl;

    $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<style>body{font-family:Inter,Segoe UI,Arial,sans-serif;color:#111;margin:0;padding:0;background:#ffffff} .card{max-width:600px;margin:24px auto;border:1px solid #e9e9e9;padding:20px;border-radius:6px} .btn{display:inline-block;padding:10px 18px;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none}</style>'
        . '</head><body><div class="card">'
        . '<div style="text-align:center;margin-bottom:18px;"><img src="' . htmlspecialchars($logoPath) . '" alt="Logo" style="max-height:48px;object-fit:contain;"/></div>'
        . '<h2 style="margin:0 0 8px 0;font-size:18px;">Welcome to Talent ARP</h2>'
        . '<p style="margin:0 0 12px 0;">An account has been created for you. Below are the details to sign in:</p>'
        . $credsHtml
        . '<p style="margin:14px 0 18px 0; text-align:center;"><a class="btn" href="' . htmlspecialchars($buttonUrl) . '">Go to Login</a></p>'
        . '<div style="margin-top:18px;border-top:1px solid #f0f0f0;padding-top:12px;font-size:13px;color:#666;">Best Regards | Master</div>'
        . '</div></body></html>';

    // Headers
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-type: text/html; charset=UTF-8';
    $headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
    $headers[] = 'Reply-To: ' . $from_email;
    $headers[] = 'X-Mailer: PHP/' . phpversion();
    $headers_str = implode("\r\n", $headers);

    // Enforce PHPMailer-only sending. Composer's autoload (vendor/autoload.php) must be present.
    $success = false;
    $err = null;
    $usedAutoload = null;

    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        $err = 'PHPMailer unavailable: composer autoload not found at ' . $autoloadPath;
        // Logging (below) will record this and we return failure to caller.
    } else {
        $usedAutoload = $autoloadPath;
        require_once $autoloadPath;
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            $err = 'PHPMailer classes not found after including autoload. Did you run `composer install`?';
        } else {
            // SMTP settings must be provided in config.php
            if (!defined('SMTP_HOST') || !SMTP_HOST) {
                $err = 'SMTP_HOST not defined in config.php';
            } else {
                try {
                    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host = SMTP_HOST;
                    $mail->SMTPAuth = true;
                    $mail->Username = defined('SMTP_USER') ? SMTP_USER : $from_email;
                    $mail->Password = defined('SMTP_PASS') ? SMTP_PASS : '';
                    $mail->SMTPSecure = defined('SMTP_SECURE') ? SMTP_SECURE : 'tls';
                    $mail->Port = defined('SMTP_PORT') ? SMTP_PORT : 587;
                    $mail->CharSet = 'UTF-8';
                    $mail->setFrom(defined('SMTP_FROM') ? SMTP_FROM : $from_email, defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : $from_name);
                    $mail->addAddress($to);
                    $mail->isHTML(true);
                    $mail->Subject = $subject;
                    $mail->Body = $html;
                    $mail->AltBody = strip_tags(str_replace(['<br>','<br/>','</p>'], "\n", $html));
                    $sent = $mail->send();
                    $success = $sent ? true : false;
                    if (!$success) {
                        $err = $mail->ErrorInfo ?? 'Unknown PHPMailer send failure';
                    }
                } catch (Throwable $e) {
                    $success = false;
                    $err = $e->getMessage();
                }
            }
        }
    }

    // Logging
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $logFile = $logDir . '/email.log';
    $now = date('Y-m-d H:i:s');
    $entry = "[{$now}] to={$to} subject={$subject} success=" . ($success ? '1' : '0') . "\n";
    if (isset($err)) $entry .= "error=" . $err . "\n";
    $entry .= "autoload_used=" . ($usedAutoload ? $usedAutoload : 'none') . "\n";
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);

    // Return structured result for callers: success boolean and optional error string
    return ['success' => $success, 'error' => $err ?? null];
}

?>
