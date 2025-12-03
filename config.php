<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'password');
define('DB_NAME', 'ticketing_db');

// SMTP configuration placeholders
// Fill these in with your Hostinger SMTP settings or leave blank to use PHP mail() fallback.
// Example Hostinger SMTP values (do NOT commit real passwords into repository):
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'Master@talentarp.com');
define('SMTP_PASS', 'your_smtp_password_here');
define('SMTP_SECURE', 'tls'); // or 'ssl'
define('SMTP_FROM', 'Master@talentarp.com');
define('SMTP_FROM_NAME', 'Master');
?>