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
        // legacy fallback: if access_keys missing, allow roles explicitly passed in $legacyRoles
        if (empty($ak) && !empty($legacyRoles)) {
            if (isset($_SESSION['user']['role']) && in_array($_SESSION['user']['role'], $legacyRoles, true)) return true;
        }
        return false;
    }
}

// --- Scope helpers ---------------------------------------------------------
/**
 * Return current user's scope: 'global' or 'local'. Falls back to 'local'.
 */
function _user_scope() {
    if (!isset($_SESSION['user'])) return 'local';
    return (!empty($_SESSION['user']['scope']) && $_SESSION['user']['scope'] === 'global') ? 'global' : 'local';
}

/**
 * Return current user's department_id (int) or null.
 */
function _user_department_id() {
    if (!isset($_SESSION['user'])) return null;
    if (!empty($_SESSION['user']['department_id'])) return (int)$_SESSION['user']['department_id'];
    return null;
}

/**
 * Return current user's department name (string) or empty string. Caches in session to avoid repeated queries.
 */
function _user_department_name() {
    if (!isset($_SESSION['user'])) return '';
    if (!empty($_SESSION['user']['department_name'])) return $_SESSION['user']['department_name'];
    $deptId = _user_department_id();
    if (!$deptId) return '';
    global $conn;
    if (!isset($conn)) return '';
    $stmt = $conn->prepare('SELECT department_name FROM departments WHERE department_id = ? LIMIT 1');
    if (!$stmt) return '';
    $stmt->bind_param('i', $deptId);
    if (!$stmt->execute()) { $stmt->close(); return ''; }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $name = $row['department_name'] ?? '';
    // cache into session for this request
    $_SESSION['user']['department_name'] = $name;
    return $name;
}

/**
 * Return a SQL clause restricting data to the user's department when scope is local.
 * If $useWhere is true the clause starts with ' WHERE', otherwise it starts with ' AND'.
 * Supported $table values: 'departments','teams','users','positions','applicants'.
 */
function _scope_clause($table, $alias = '', $useWhere = false) {
    $scope = _user_scope();
    if ($scope === 'global') return '';
    $prefix = $useWhere ? ' WHERE ' : ' AND ';
    $a = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    $deptId = _user_department_id();
    if (!$deptId) return '';
    switch ($table) {
        case 'departments':
            return $prefix . $a . "department_id = " . (int)$deptId;
        case 'teams':
            return $prefix . $a . "department_id = " . (int)$deptId;
        case 'users':
            return $prefix . $a . "department_id = " . (int)$deptId;
        case 'positions':
            // positions table stores department as text in many places; compare with user's department name
            $dname = _user_department_name();
            if ($dname === '') return '';
            // escape single quotes in department name
            $dnameEsc = str_replace("'","\\'", $dname);
            return $prefix . $a . "department = '" . $dnameEsc . "'";
        case 'applicants':
            // Applicants are associated to departments via their linked position (position_id).
            // Some queries already JOIN positions as alias 'p' and could reference p.department,
            // but many queries do not. To avoid unknown-column errors we'll scope via a
            // subquery against the positions table using the applicants alias/column.
            $dname2 = _user_department_name();
            if ($dname2 === '') return '';
            $dnameEsc2 = str_replace("'","\\'", $dname2);
            // Use applicants alias (if provided) to reference position_id, otherwise reference position_id directly.
            // $a variable already includes trailing dot when alias provided.
            return $prefix . "(EXISTS(SELECT 1 FROM positions p WHERE p.id = " . $a . "position_id AND p.department = '" . $dnameEsc2 . "'))";
        default:
            return '';
    }
}
