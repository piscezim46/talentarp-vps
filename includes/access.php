<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
// Load DB connection when needed
if (!function_exists('_has_access')) {
    require_once __DIR__ . '/db.php';

    // Load access keys for current user's role_id into session (if missing)
    function _load_access_keys_for_session() {
        if (!isset($_SESSION['user'])) return;
        if (!empty($_SESSION['user']['access_keys']) && is_array($_SESSION['user']['access_keys'])) return;
        // Prefer role_id (normalized). If present, fetch access keys for that role_id.
        if (!empty($_SESSION['user']['role_id'])) {
            $rid = (int)$_SESSION['user']['role_id'];
            global $conn;
            if (isset($conn)) {
                $stmt = $conn->prepare("SELECT ar.access_key FROM role_access_rights rar JOIN access_rights ar ON rar.access_id = ar.access_id WHERE rar.role_id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $rid);
                    if ($stmt->execute()) {
                        $res = $stmt->get_result();
                        $keys = [];
                        while ($r = $res->fetch_assoc()) $keys[] = $r['access_key'];
                        $_SESSION['user']['access_keys'] = $keys;
                    }
                    $stmt->close();
                }
            }
        }
    }

    /**
     * Check access for a given access_key.
     * Optionally provide an array of legacy role names allowed when access_keys are missing.
     */
    function _has_access($key, $legacyRoles = []) {
        if (!isset($_SESSION['user'])) return false;
        // ensure access keys loaded when possible (role_id -> access rights)
        _load_access_keys_for_session();
        $ak = $_SESSION['user']['access_keys'] ?? null;
        if (is_array($ak) && in_array($key, $ak)) return true;
        // legacy fallback: if access_keys missing, allow admin or other legacy role names
        if (empty($ak)) {
            // treat Master Admin and common variants as admin as well
            $r = isset($_SESSION['user']['role']) ? strtolower(trim($_SESSION['user']['role'])) : '';
            $adminNames = ['admin','master admin','master_admin','master-admin','masteradmin'];
            if (in_array($r, $adminNames, true)) return true;
            if (!empty($legacyRoles) && isset($_SESSION['user']['role']) && in_array($_SESSION['user']['role'], $legacyRoles, true)) return true;
        }
        return false;
    }
}
