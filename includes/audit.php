<?php
// Simple audit logging helper for the application.
// Usage: require_once __DIR__ . '/audit.php'; then call audit_log(...)

if (!function_exists('audit_log')) {
    function audit_log($actor_id, $action_type, $entity_type, $entity_id, $old_values = null, $new_values = null, $note = null, $ip_address = null) {
        // Use existing $conn if available, otherwise include db connection
        $conn = null;
        if (isset($GLOBALS['conn']) && $GLOBALS['conn']) $conn = $GLOBALS['conn'];
        if (!$conn) {
            // Try to include db.php (safe-guard: avoid recursion)
            if (file_exists(__DIR__ . '/db.php')) {
                require_once __DIR__ . '/db.php';
                if (isset($conn)) {
                    // ok
                } else if (isset($GLOBALS['conn'])) {
                    $conn = $GLOBALS['conn'];
                }
            }
        }
        if (!$conn) return false;

        // Normalize values to JSON or NULL
        $old_json = null;
        $new_json = null;
        try {
            if ($old_values !== null) $old_json = is_string($old_values) ? $old_values : json_encode($old_values, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $_) { $old_json = null; }
        try {
            if ($new_values !== null) $new_json = is_string($new_values) ? $new_values : json_encode($new_values, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $_) { $new_json = null; }

        // IP fallback
        if (empty($ip_address)) {
            $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        }

        $stmt = $conn->prepare("INSERT INTO audit_logs (actor_id, action_type, entity_type, entity_id, old_values, new_values, note, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) return false;
        // Bind types: i - actor_id, s - action_type, s - entity_type, i - entity_id, s - old_values, s - new_values, s - note, s - ip
        $aid = (int)$actor_id;
        $eid = is_null($entity_id) ? 0 : (int)$entity_id;
        $old_json = $old_json === null ? null : $old_json;
        $new_json = $new_json === null ? null : $new_json;
        $note = $note === null ? null : (string)$note;
        $ip_address = $ip_address === null ? null : (string)$ip_address;
        $stmt->bind_param('ississss', $aid, $action_type, $entity_type, $eid, $old_json, $new_json, $note, $ip_address);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('audit_log_auto')) {
    // Convenience wrapper that uses the current session user and server IP
    function audit_log_auto($action_type, $entity_type, $entity_id = 0, $old_values = null, $new_values = null, $note = null) {
        $actor = $_SESSION['user']['id'] ?? ($_SESSION['user']['user_id'] ?? null);
        if (!$actor) $actor = 0;
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        return audit_log($actor, $action_type, $entity_type, $entity_id, $old_values, $new_values, $note, $ip);
    }
}

?>
