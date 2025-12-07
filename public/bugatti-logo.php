<?php
// Simple image endpoint that serves the bugatti logo with proper headers
$path = __DIR__ . '/assets/bugatti-logo.png';
if (!file_exists($path)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Not found';
    exit;
}
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $path);
finfo_close($finfo);
header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=604800'); // 7 days
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 604800) . ' GMT');
readfile($path);
exit;
