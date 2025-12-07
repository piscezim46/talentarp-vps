<?php
/**
 * Department-based data access helpers
 *
 * Requirements implemented:
 *  - `getDepartmentFilter($tableAlias = "")` returns "" for admin users or
 *    "WHERE {alias}department = ?" for non-admins (caller appends to SQL).
 *  - `getDepartmentParams()` returns [] for admin users or [$_SESSION['department']] for others.
 *
 * Notes:
 *  - This file assumes sessions are available. `includes/access.php` already calls session_start();
 *    if you call these functions from a script without sessions started, call session_start() first.
 *  - These helpers only return SQL fragments and parameter values. Always use prepared statements
 *    (mysqli or PDO) and bind parameters returned by `getDepartmentParams()`.
 *  - The simple `getDepartmentFilter()` returns a `WHERE` clause per the spec. If you need to
 *    append the department constraint to an existing WHERE, replace the leading `WHERE` with `AND`.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/**
 * Return the department WHERE fragment or empty string when role is admin.
 * Example return values:
 *  - admin: ""
 *  - non-admin: "WHERE t.department = ?"
 *
 * @param string $tableAlias Optional table alias (e.g. 't' or 'tickets t'). If provided, a trailing dot
 *                          will be added automatically (so pass 't' and function will emit 't.department').
 * @return string SQL fragment (empty or with leading WHERE)
 */
function getDepartmentFilter($tableAlias = ""){
    // Normalize role check to lowercase for robustness
    $role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : null;
    $adminNames = ['admin','master admin','master_admin','master-admin','masteradmin'];
    if (in_array($role, $adminNames, true)) return '';

    $alias = '';
    if (is_string($tableAlias) && strlen(trim($tableAlias))>0) {
        $a = trim($tableAlias);
        // If the caller passed something like "t.", avoid double dot
        $a = rtrim($a, '.');
        $alias = $a . '.';
    }
    // If department is not set or empty, do not apply a department filter (avoid WHERE p.department = '')
    $dept = isset($_SESSION['department']) ? trim((string)$_SESSION['department']) : '';
    if ($dept === '') return '';
    return "WHERE " . $alias . "department = ?";
}

/**
 * Return parameter values corresponding to getDepartmentFilter(). For admin users this is []
 * otherwise returns [ $_SESSION['department'] ] (string).
 *
 * @return array
 */
function getDepartmentParams(){
    $role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : null;
    if ($role === 'admin') return [];
    // Guarantee that department is set; if missing treat as empty string so queries don't error.
    $dept = isset($_SESSION['department']) ? trim((string)$_SESSION['department']) : '';
    if ($dept === '') return [];
    return [ $dept ];
}

/**
 * Small helper to bind parameters to a mysqli_stmt using call_user_func_array.
 * Accepts the mysqli_stmt, a types string (e.g. 'si'), and an array of values.
 * This helper is optional — examples below show explicit binding for single-parameter cases.
 */
function mysqli_stmt_bind_params_dynamic($stmt, $types, $values){
    if (empty($values)) return true;
    // mysqli_bind_param requires references
    $refs = [];
    $refs[] = $types;
    foreach ($values as $k => $v){
        // create a variable variable so we can pass by reference
        ${"_p".$k} = $v;
        $refs[] = &${"_p".$k};
    }
    return call_user_func_array([$stmt, 'bind_param'], $refs);
}

/**
 * USAGE EXAMPLES (mysqli, using prepared statements)
 *
 * Note: these are illustrative snippets — adapt variable names / table names to your app.
 */

/* -----------------------
 * Example: SELECT (get all tickets)
 * -----------------------
 *
 * // in your script
 * require_once __DIR__ . '/db.php';
 * require_once __DIR__ . '/department_access.php';
 *
 * $filter = getDepartmentFilter('t');
 * $params = getDepartmentParams();
 * $sql = "SELECT t.* FROM tickets t " . $filter . " ORDER BY t.created_at DESC";
 *
 * $stmt = $conn->prepare($sql);
 * if (!$stmt) { error_log('prepare failed: ' . $conn->error); // handle error }
 * if (!empty($params)) {
 *     // single string department value: bind as 's'
 *     $stmt->bind_param('s', $params[0]);
 * }
 * $stmt->execute();
 * $res = $stmt->get_result();
 * $rows = $res->fetch_all(MYSQLI_ASSOC);
 * $stmt->close();
 */

/* -----------------------
 * Example: UPDATE (update a ticket)
 * Ensure department filtering is applied in the WHERE clause so non-admins cannot update other departments.
 * -----------------------
 *
 * // in your script
 * require_once __DIR__ . '/db.php';
 * require_once __DIR__ . '/department_access.php';
 *
 * $ticketId = (int)($_POST['id'] ?? 0);
 * $status = $_POST['status'] ?? '';
 *
 * // Build base SQL and params
 * $filter = getDepartmentFilter('t');
 * $deptParams = getDepartmentParams();
 * $sql = "UPDATE tickets t SET status = ? WHERE t.id = ? ";
 * // If filter is non-empty it will start with WHERE per spec; combine safely
 * if ($filter !== '') {
 *     // Replace leading WHERE with AND because we already have a WHERE for id
 *     $sql .= ' AND ' . preg_replace('/^WHERE\\s+/i', '', $filter);
 * }
 *
 * $stmt = $conn->prepare($sql);
 * if (!$stmt) { error_log('prepare failed: ' . $conn->error); // handle error }
 * // Types: 's' for status, 'i' for id, plus department param type(s) if present
 * $types = 'si' . (empty($deptParams) ? '' : str_repeat('s', count($deptParams)));
 * $values = array_merge([$status, $ticketId], $deptParams);
 * mysqli_stmt_bind_params_dynamic($stmt, $types, $values);
 * $stmt->execute();
 * $affected = $stmt->affected_rows;
 * $stmt->close();
 */

/* -----------------------
 * Example: DELETE (delete a ticket)
 * Use department filter in WHERE so non-admins cannot delete across departments.
 * -----------------------
 *
 * // in your script
 * require_once __DIR__ . '/db.php';
 * require_once __DIR__ . '/department_access.php';
 *
 * $ticketId = (int)($_POST['id'] ?? 0);
 * $filter = getDepartmentFilter('t');
 * $deptParams = getDepartmentParams();
 * $sql = "DELETE FROM tickets t WHERE t.id = ? ";
 * if ($filter !== '') {
 *     $sql .= ' AND ' . preg_replace('/^WHERE\\s+/i', '', $filter);
 * }
 * $stmt = $conn->prepare($sql);
 * $types = 'i' . (empty($deptParams) ? '' : str_repeat('s', count($deptParams)));
 * $values = array_merge([$ticketId], $deptParams);
 * mysqli_stmt_bind_params_dynamic($stmt, $types, $values);
 * $stmt->execute();
 * $affected = $stmt->affected_rows;
 * $stmt->close();
 */

/* -----------------------
 * Login snippet: store department and role in session
 * -----------------------
 * After you authenticate the user (verify password), set these session values so the helpers work:
 *
 * $_SESSION['user'] = [ 'id' => $uid, 'username' => $username ];
 * $_SESSION['role'] = $row['role'] ?? $row['role_name'] ?? 'user';
 * $_SESSION['department'] = $row['department'] ?? $row['department_name'] ?? '';
 *
 * Ensure that `role` uses the string 'admin' (lowercase) for admin users so checks match. The helpers
 * normalize role to lowercase before checking.
 */

?>
