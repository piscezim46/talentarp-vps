<?php
// Ensure session is started (safe check)
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../includes/db.php';
if (file_exists(__DIR__ . '/../includes/user_utils.php')) require_once __DIR__ . '/../includes/user_utils.php';

// Collect PHP warnings/notices so we can surface them as Notify toasts
$page_errors = [];
$prevErrorHandler = set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$page_errors) {
    // Build a concise message
    $typeMap = [E_NOTICE=>'Notice', E_WARNING=>'Warning', E_USER_WARNING=>'Warning', E_USER_NOTICE=>'Notice'];
    $t = $typeMap[$errno] ?? 'Error';
    $page_errors[] = $t . ': ' . $errstr . ' in ' . $errfile . ' on line ' . $errline;
    // prevent PHP internal handler from printing to output
    return true;
});
register_shutdown_function(function() use (&$page_errors, $prevErrorHandler) {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $page_errors[] = 'Fatal: ' . ($err['message'] ?? '') . ' in ' . ($err['file'] ?? '') . ' on line ' . ($err['line'] ?? '');
    }
    // restore previous handler
    if ($prevErrorHandler) set_error_handler($prevErrorHandler);
});

// (JS closures accidentally inserted here were removed)
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}

$user = $_SESSION['user'];
// authorization: allow if user has users_view access or is admin role (backwards-compatible)
$roleNorm = isset($user['role']) ? strtolower(trim($user['role'])) : '';
$has_users_access = in_array('users_view', $_SESSION['user']['access_keys'] ?? []) || in_array($roleNorm, ['admin','master admin','master_admin','master-admin','masteradmin'], true);
if (!$has_users_access) {
    header("Location: index.php");
    exit;
}
$activePage = 'users';

// Enforce password expiry for all users when admin opens Users page
if (function_exists('enforce_password_expiry')) {
    try { enforce_password_expiry($conn, 30); } catch (Throwable $_) { /* ignore failures */ }
}

// Fetch users with department/team and manager/director names — sort oldest first
$users_q = "
SELECT u.id, u.name, u.user_name, u.email, u.role_id, COALESCE(r.role_name, '') AS role, u.created_at, IFNULL(u.active,0) AS active, IFNULL(u.force_password_reset,0) AS force_password_reset,
       u.password_changed_at, u.last_login,
       d.department_id AS department_id, COALESCE(d.department_name,'') AS department_name,
       COALESCE(d.director_name,'') AS director_name,
       t.team_id AS team_id, COALESCE(t.team_name,'') AS team_name,
       COALESCE(t.manager_name,'') AS manager_name
FROM users u
LEFT JOIN roles r ON u.role_id = r.role_id
LEFT JOIN departments d ON u.department_id = d.department_id
LEFT JOIN teams t ON u.team_id = t.team_id
ORDER BY u.created_at ASC
";
$users = $conn->query($users_q);

// Fetch departments and teams for the Create User modal
$departments = [];
$sql = "SELECT d.department_id, d.department_name, d.director_name, t.team_id, t.team_name, t.manager_name, IFNULL(t.active,0) AS team_active
    FROM departments d
    LEFT JOIN teams t ON t.department_id = d.department_id
    WHERE d.active = 1
    ORDER BY d.department_id ASC, t.team_id ASC";
if ($res = $conn->query($sql)) {
    $seenTeamsByDept = [];
    while ($r = $res->fetch_assoc()) {
        $id = (int)$r['department_id'];
        if (!isset($departments[$id])) {
            $departments[$id] = [
                'department_id' => $id,
                'department_name' => $r['department_name'],
                'director_name' => $r['director_name'],
                'teams' => []
            ];
            $seenTeamsByDept[$id] = [];
        }
        // Only include teams that exist and are active (team_active === 1)
        if (!empty($r['team_id']) && isset($r['team_active']) && (int)$r['team_active'] === 1) {
            $tid = (int)$r['team_id'];
            if (!in_array($tid, $seenTeamsByDept[$id], true)) {
                $seenTeamsByDept[$id][] = $tid;
                $departments[$id]['teams'][] = [
                    'team_id' => $tid,
                    'team_name' => $r['team_name'],
                    'manager_name' => $r['manager_name']
                ];
            }
        }
    }
    $res->free();
}

$departments_json = json_encode($departments, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
// fetch roles for dynamic role select (include department_id to allow filtering)
$roles = [];
// Fetch all roles (used for edit/filters) and separately fetch active roles for the Create User modal
$roles_res = $conn->query("SELECT role_id, role_name, department_id FROM roles ORDER BY role_name");
if ($roles_res) { while ($rr = $roles_res->fetch_assoc()) $roles[] = $rr; $roles_res->free(); }
$roles_json = json_encode($roles, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
// Active roles for selection when creating a new user
$active_roles = [];
$ar_res = $conn->query("SELECT role_id, role_name, department_id FROM roles WHERE COALESCE(active,1) = 1 ORDER BY role_name");
if ($ar_res) { while ($r = $ar_res->fetch_assoc()) $active_roles[] = $r; $ar_res->free(); }
$active_roles_json = json_encode($active_roles, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$pageTitle = 'Users';
if (file_exists(__DIR__ . '/../includes/header.php')) include __DIR__ . '/../includes/header.php';
if (file_exists(__DIR__ . '/../includes/navbar.php')) include __DIR__ . '/../includes/navbar.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<!-- positions.css removed (not present) to avoid 404 -->
<link rel="stylesheet" href="styles/applicants.css">
<link rel="stylesheet" href="styles/users.css">
<link rel="stylesheet" href="styles/roles.css">
<link rel="stylesheet" href="assets/css/notify.css">
<script src="assets/js/notify.js"></script>
<style>
    /* Invite toggle visual: green when checked (send), red when unchecked (don't send) */
    #u_send_invite { width:18px; height:18px; accent-color:#10b981; }
    #u_send_invite.invite-off { accent-color:#ef4444; }
</style>
<script>
// Capture and surface JS errors on this page to help debugging (shows toast and logs to console)
window.addEventListener('error', function(e){
    try{
        if (window.Notify && typeof window.Notify.push === 'function') {
            Notify.push({ from: 'Users JS', message: (e && e.message ? e.message : 'Error') + ' @ ' + (e && e.filename ? e.filename : '') + ':' + (e && e.lineno ? e.lineno : ''), color: '#dc2626', duration: 15000 });
        }
    } catch(_){}
    console.error('Users page error:', e);
});
window.addEventListener('unhandledrejection', function(e){
    try{
        var msg = (e && e.reason && e.reason.message) ? e.reason.message : (e && e.reason ? String(e.reason) : 'Unhandled rejection');
        if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users JS', message: msg, color: '#dc2626', duration: 15000 });
    } catch(_){}
    console.error('Unhandled promise rejection on users page:', e);
});
</script>
<main class="content-area">
    <h2 class="section-title">Users</h2>

    <div class="controls">
        <input type="text" id="filter-name" placeholder="Search name or email">
        <select id="filter-role">
            <option value="">All roles</option>
            <option value="admin">Admin</option>
            <option value="director">Director</option>
            <option value="manager">Manager</option>
            <option value="supervisor">Supervisor</option>
        </select>

        <select id="filter-department">
            <option value="">All departments</option>
        </select>
        <select id="filter-team" disabled>
            <option value="">All teams</option>
        </select>

        <select id="filter-active">
            <option value="all">All Status</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
        </select>

        <select id="filter-force-reset">
            <option value="all">All Force Reset</option>
            <option value="1">Force Reset: Yes</option>
            <option value="0">Force Reset: No</option>
        </select>

        <label style="display:inline-flex;align-items:center;gap:6px;margin-left:6px;">From
          <input type="date" id="filter-date-from" style="padding:6px;border-radius:6px;border:1px solid rgba(15,23,42,0.06);" />
        </label>
        <label style="display:inline-flex;align-items:center;gap:6px;">To
          <input type="date" id="filter-date-to" style="padding:6px;border-radius:6px;border:1px solid rgba(15,23,42,0.06);" />
        </label>

        <button type="button" id="clearFiltersBtn" class="btn">Clear Filters</button>


        <button type="button" id="openCreateBtn" class="btn">Create User</button>

        <?php if (_has_access('users_bulk_force_reset')): ?>
            <button type="button" id="bulkForceResetBtn" class="btn" style="background:#f97316;color:#fff;">Bulk Force Password Reset</button>
        <?php endif; ?>

        <div style="margin-left:auto;" class="small-muted">Showing <span id="visibleCount">0</span> / <span id="totalCount">0</span></div>
    </div>

    <div class="table-wrap">
        <table class="users-table" id="usersTable">
            <thead>
                <tr>
                    <th style="width:3.84%" class="sortable" data-sort="id">ID <span class="sort-indicator" aria-hidden="true"></span></th>
                    <th style="width:14%;" class="sortable" data-sort="name">Name <span class="sort-indicator" aria-hidden="true"></span></th>
                    <th style="width:12%;" class="sortable" data-sort="username">Username <span class="sort-indicator" aria-hidden="true"></span></th>
                    <th style="width:20%;" class="sortable" data-sort="email">Email <span class="sort-indicator" aria-hidden="true"></span></th>
                    <th style="width:14%;" class="sortable" data-sort="department">Department <span class="sort-indicator" aria-hidden="true"></span></th>
                    <th style="width:12%;" class="sortable" data-sort="director">Director <span class="sort-indicator" aria-hidden="true"></span></th>
                    <th style="width:12%;" class="sortable" data-sort="team">Team <span class="sort-indicator" aria-hidden="true"></span></th>
                    <th style="width:12%;" class="sortable" data-sort="manager">Manager <span class="sort-indicator" aria-hidden="true"></span></th>
                        <th style="width:8%" class="sortable" data-sort="role">Role <span class="sort-indicator" aria-hidden="true"></span></th>
                        <th style="width:6%" class="sortable" data-sort="active">Active <span class="sort-indicator" aria-hidden="true"></span></th>
                        <th style="width:8%" class="sortable" data-sort="force_password_reset">Force Reset <span class="sort-indicator" aria-hidden="true"></span></th>
                            <th style="width:12%" class="sortable" data-sort="password_changed_at">Password Expiry <span class="sort-indicator" aria-hidden="true"></span></th>
                            <th style="width:12%" class="sortable" data-sort="last_login">Last Login <span class="sort-indicator" aria-hidden="true"></span></th>
                            <th style="width:12%" class="sortable" data-sort="created_at">Created At <span class="sort-indicator" aria-hidden="true"></span></th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $users->fetch_assoc()): ?>
                        <tr class="user-row" tabindex="0"
                            data-id="<?= htmlspecialchars($row['id']) ?>"
                            data-name="<?= htmlspecialchars($row['name']) ?>"
                            data-username="<?= htmlspecialchars($row['user_name'] ?? '') ?>"
                            data-email="<?= htmlspecialchars($row['email']) ?>"
                            data-role-id="<?= (int)($row['role_id'] ?? 0) ?>"
                            data-role="<?= htmlspecialchars($row['role']) ?>"
                            data-active="<?= (int)$row['active'] ?>"
                            data-force-password-reset="<?= (int)($row['force_password_reset'] ?? 0) ?>"
                            data-department-id="<?= (int)($row['department_id'] ?? 0) ?>"
                            data-department="<?= htmlspecialchars($row['department_name'] ?? '') ?>"
                            data-director="<?= htmlspecialchars($row['director_name'] ?? '') ?>"
                            data-team-id="<?= (int)($row['team_id'] ?? 0) ?>"
                            data-team="<?= htmlspecialchars($row['team_name'] ?? '') ?>"
                            data-manager="<?= htmlspecialchars($row['manager_name'] ?? '') ?>"
                            data-created="<?= htmlspecialchars($row['created_at'] ?? '') ?>"
                            data-password-changed-at="<?= htmlspecialchars($row['password_changed_at'] ?? '') ?>"
                            data-last-login="<?= htmlspecialchars($row['last_login'] ?? '') ?>">
                        <td><?= htmlspecialchars($row['id']) ?></td>
                        <td class="users-col-name"><?= htmlspecialchars($row['name']) ?></td>
                        <td class="users-col-username"><?= htmlspecialchars($row['user_name'] ?? '') ?></td>
                        <td class="users-col-email"><?= htmlspecialchars($row['email']) ?></td>
                        <td class="users-col-dept"><?= htmlspecialchars($row['department_name'] ?? '') ?></td>
                        <td class="users-col-director"><?= htmlspecialchars($row['director_name'] ?? '') ?></td>
                        <td class="users-col-team"><?= htmlspecialchars($row['team_name'] ?? '') ?></td>
                        <td class="users-col-manager"><?= htmlspecialchars($row['manager_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars(ucfirst($row['role'])) ?></td>
                            <td class="users-col-active">
                                <?= (int)$row['active'] === 1
                                    ? '<span class="active-badge">1</span>'
                                    : '<span class="inactive-badge">0</span>' ?>
                            </td>
                                        <td class="users-col-force-reset">
                                            <?= (int)$row['force_password_reset'] === 1
                                                ? '<span class="active-badge">1</span>'
                                                : '<span class="inactive-badge">0</span>' ?>
                                        </td>
                                        <td class="users-col-heat" data-ll="<?= htmlspecialchars($row['last_login'] ?? '') ?>" data-pc="<?= htmlspecialchars($row['password_changed_at'] ?? '') ?>">
                                            <?php
                                                // Show heat-dot for last login and also display password expiry text (pulled from password_changed_at)
                                                // Last login dot
                                                if (empty($row['last_login'])) {
                                                    echo '<span class="heat-dot heat-grey" title="Never logged in">&nbsp;</span>';
                                                } else {
                                                    $ll_ts = strtotime($row['last_login']);
                                                    $days = floor((time() - $ll_ts) / 86400);
                                                    if ($days <= 7) { $cls = 'heat-green'; $label = $days . ' days ago'; }
                                                    elseif ($days <= 30) { $cls = 'heat-yellow'; $label = $days . ' days ago'; }
                                                    else { $cls = 'heat-red'; $label = $days . ' days ago'; }
                                                    $title = htmlspecialchars(($row['last_login'] ?? '') . ' (' . $label . ')');
                                                    echo '<span class="heat-dot ' . $cls . '" title="' . $title . '"></span>';
                                                }

                                                // Password expiry text (based on password_changed_at)
                                                if (!empty($row['password_changed_at'])) {
                                                    $ts = strtotime($row['password_changed_at']);
                                                    if ($ts === false) {
                                                        echo ' <span class="small-muted">Invalid date</span>';
                                                    } else {
                                                        // compute elapsed full days; protect against future timestamps
                                                        $now = time();
                                                        $elapsed = (int) floor(($now - $ts) / 86400);
                                                        if ($elapsed < 0) $elapsed = 0; // avoid negative elapsed when clocks/timezones differ
                                                        $left = 30 - $elapsed;
                                                        if ($left > 0) {
                                                            // show remaining days (clamped to 30..0)
                                                            $left = $left > 30 ? 30 : $left;
                                                            $title = htmlspecialchars($row['password_changed_at'] . ' (elapsed: ' . $elapsed . ' days)');
                                                            echo ' <span class="small-muted" title="' . $title . '">' . htmlspecialchars($left . ' days') . '</span>';
                                                        } else {
                                                            $title = htmlspecialchars($row['password_changed_at'] . ' (elapsed: ' . $elapsed . ' days)');
                                                            echo ' <span class="expired-text" title="' . $title . '">Expired</span>';
                                                        }
                                                    }
                                                } else {
                                                    echo ' <span class="small-muted">Never</span>';
                                                }
                                            ?>
                                        </td>
                                <?php
                                    // Format displayed dates for readability: keep raw values in data-* attributes,
                                    // but render with slashes and AM/PM (e.g. 2025/11/29 03:45 PM).
                                    $display_last_login = '';
                                    if (!empty($row['last_login'])) {
                                        $ts = strtotime($row['last_login']);
                                        if ($ts !== false) $display_last_login = date('Y/m/d h:i A', $ts);
                                    }
                                    $display_created = '';
                                    if (!empty($row['created_at'])) {
                                        $ts2 = strtotime($row['created_at']);
                                        if ($ts2 !== false) $display_created = date('Y/m/d h:i A', $ts2);
                                    }
                                ?>
                                <td class="users-col-last-login" data-ll="<?= htmlspecialchars($row['last_login'] ?? '') ?>"><?= htmlspecialchars($display_last_login) ?></td>
                                <td class="users-col-created" data-created="<?= htmlspecialchars($row['created_at'] ?? '') ?>"><?= htmlspecialchars($display_created) ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <!-- Create user modal (keeps department/team selection using $departments) -->
    <div id="createModal" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Create User</h3>
                <button type="button" class="modal-close" aria-label="Close dialog">&times;</button>
            </div>
            <div id="createMsg" class="small-muted"></div>
            <form id="createForm">
                <label for="u_name">Name</label>
                <input id="u_name" name="name" type="text" required>

                <label for="u_username">Username</label>
                <input id="u_username" name="user_name" type="text" placeholder="Optional username">

                <label for="u_email">Email</label>
                <input id="u_email" name="email" type="email" required>

                <!-- Department / Director / Team / Manager fields -->
                <label for="u_department">Department</label>
                <select id="u_department" name="department_id">
                    <option value="">--Select Department--</option>
                    <?php foreach ($departments as $did => $d): ?>
                        <option value="<?= (int)$did ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="u_director_display">Director</label>
                <input id="u_director_display" type="text" disabled aria-disabled="true" value="Unassigned">
                <input type="hidden" name="director_name" id="u_director_name" value="">

                <label for="u_team">Team</label>
                <select id="u_team" name="team_id" disabled>
                    <option value="">-- Select department first --</option>
                </select>

                <label for="u_manager_display">Manager</label>
                <input id="u_manager_display" type="text" disabled aria-disabled="true" value="Unassigned">
                <input type="hidden" name="manager_name" id="u_manager_name" value="">

                <!-- Role: disabled until a department is selected. Shows active roles matching the department_id or global (no department) -->
                <label for="u_role">Role</label>
                <select id="u_role" name="role_id" required disabled>
                    <?php foreach ($active_roles as $ro): ?>
                        <option value="<?= (int)$ro['role_id'] ?>" data-department-id="<?= empty($ro['department_id']) ? '' : (int)$ro['department_id'] ?>"><?= htmlspecialchars($ro['role_name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="u_password">Password</label>
                <input id="u_password" name="password" type="password" required>

                <label for="u_send_invite" style="display:flex;align-items:center;gap:10px;margin-top:8px;" hidden>
                    <span hidden>Send invitation email</span>
                    <input id="u_send_invite" name="send_invite" type="checkbox" checked aria-checked="true" hidden />
                </label>

                <div class="modal-actions">
                    <button type="button" id="cancelCreate" class="btn">Cancel</button>
                    <button type="submit" class="btn">Create</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Force Reset Confirmation Modal -->
    <div id="bulkForceModal" class="modal-overlay" style="display:none;">
        <div class="modal-card" role="dialog" aria-modal="true" style="max-width:520px;padding:18px;">
            <div class="modal-header">
                <h3 id="bulkForceTitle">Confirm Bulk Force Password Reset</h3>
                <button type="button" class="modal-close" aria-label="Close dialog">&times;</button>
            </div>
            <div id="bulkForceBody" style="margin-top:8px;color:#111;">Are you sure?</div>
            <div id="bulkForceActions" style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
                <button id="bulkCancelBtn" type="button" class="btn">Cancel</button>
                <button id="bulkConfirmBtn" type="button" class="btn btn-orange">Confirm</button>
            </div>
        </div>
    </div>

</main>

    <script>
// Client-side logic for Users admin: modal open, department->team wiring, edit viewer, create modal
const DEPARTMENTS = <?= $departments_json ?> || {};

// Simple debounce helper — returns a debounced function.
// Use to limit frequency of expensive operations like filterTable.
function debounce(fn, wait) {
    let timer = null;
    return function(...args) {
        const ctx = this;
        if (timer) clearTimeout(timer);
        timer = setTimeout(function() { timer = null; fn.apply(ctx, args); }, wait);
    };
}
<?php $roleNorm = isset($user['role']) ? strtolower(trim($user['role'])) : ''; $is_admin = in_array('users_view', $_SESSION['user']['access_keys'] ?? []) || in_array($roleNorm, ['admin','master admin','master_admin','master-admin','masteradmin'], true); ?>
const IS_ADMIN = <?= json_encode($is_admin) ?>;
document.addEventListener('DOMContentLoaded', function(){
    const openBtn = document.getElementById('openCreateBtn');
    const createModal = document.getElementById('createModal');
    const cancelCreate = document.getElementById('cancelCreate');
    const createForm = document.getElementById('createForm');
    const u_send_invite = document.getElementById('u_send_invite');
    // close button inside Create modal
    const createCloseBtn = createModal && createModal.querySelector('.modal-close');
    if (createCloseBtn) createCloseBtn.addEventListener('click', closeCreate);
    const u_department = document.getElementById('u_department');
    const u_team = document.getElementById('u_team');
    const u_director_display = document.getElementById('u_director_display');
    const u_director_name = document.getElementById('u_director_name');
    const u_manager_display = document.getElementById('u_manager_display');
    const u_manager_name = document.getElementById('u_manager_name');

    // Use shared Notify.push for notifications (notify.js). Example: Notify.push({ from:'Users', message:'...', color:'#10b981' })

    function openCreate(){
        if (!createModal) return;
        createModal.setAttribute('role','dialog');
        createModal.setAttribute('aria-modal','true');
        createModal.style.display = 'flex';
        // focus first input for accessibility
        const first = document.getElementById('u_name');
        if (first) first.focus();
        // ensure role select is disabled until department chosen
        try { const urole = document.getElementById('u_role'); if (urole) { urole.value = ''; urole.disabled = true; } } catch(e) {}
    }
    function closeCreate(){
        if (createModal) createModal.style.display = 'none';
        if (createForm) createForm.reset();
        if (u_team) { u_team.innerHTML = '<option value="">-- Select department first --</option>'; u_team.disabled = true; }
        if (u_director_display) u_director_display.value = 'Unassigned';
        if (u_director_name) u_director_name.value = '';
        if (u_manager_display) u_manager_display.value = 'Unassigned';
        if (u_manager_name) u_manager_name.value = '';
        // reset and disable role select so it requires department selection next time
        try { const urole = document.getElementById('u_role'); if (urole) { urole.value = ''; urole.disabled = true; } } catch(e) {}
    }

    // invite toggle visual behavior: checked = send (green), unchecked = don't send (red)
    try {
        if (u_send_invite) {
            function updateInviteVisual() {
                if (u_send_invite.checked) u_send_invite.classList.remove('invite-off'); else u_send_invite.classList.add('invite-off');
            }
            u_send_invite.addEventListener('change', updateInviteVisual);
            updateInviteVisual();
        }
    } catch(e) { console.warn('invite toggle init failed', e); }

    openBtn && openBtn.addEventListener('click', openCreate);
    cancelCreate && cancelCreate.addEventListener('click', closeCreate);
    createModal && createModal.addEventListener('click', (e)=>{ if (e.target === createModal) closeCreate(); });

    // --- Filters: populate department/team selects and implement filtering ---
    const usersTable = document.getElementById('usersTable');
    const filterName = document.getElementById('filter-name');
    const filterRole = document.getElementById('filter-role');
    const filterDept = document.getElementById('filter-department');
    const filterTeam = document.getElementById('filter-team');
    const filterActive = document.getElementById('filter-active');
    const filterDateFrom = document.getElementById('filter-date-from');
    const filterDateTo = document.getElementById('filter-date-to');
    const clearFiltersBtn = document.getElementById('clearFiltersBtn');
    const totalCountEl = document.getElementById('totalCount');
    const visibleCountEl = document.getElementById('visibleCount');
    const filterForceReset = document.getElementById('filter-force-reset');

    // Sorting state for table columns (last_login, created_at)
    const tbody = usersTable ? usersTable.querySelector('tbody') : null;
    let currentSortKey = null; // 'last_login' or 'created_at'
    let currentSortDir = 1; // 1 = asc, -1 = desc

    function getSortableValue(row, key) {
        if (!row || !key) return '';
        // normalize key to dataset camelCase (e.g. last_login -> lastLogin)
        const camel = key.replace(/[_-](.)/g, (_, c) => c.toUpperCase());
        let val = (row.dataset && row.dataset[camel] !== undefined) ? row.dataset[camel] : undefined;
        if (val === undefined) {
            // fallback to cell with class users-col-<key> (convert underscores to dashes)
            const cls = '.users-col-' + key.replace(/_/g,'-');
            const cell = row.querySelector(cls);
            if (cell) val = cell.textContent.trim();
            else {
                // last resort: attempt to find column by matching header data-sort index
                val = '';
            }
        }
        return String(val === null || val === undefined ? '' : val).trim();
    }

    function applyTableSort() {
        if (!tbody || !currentSortKey) return;
        const rows = Array.from(tbody.querySelectorAll('tr'));
        rows.sort((a,b) => {
            const va = getSortableValue(a, currentSortKey);
            const vb = getSortableValue(b, currentSortKey);

            // Try numeric comparison
            const na = parseFloat(String(va).replace(/[^0-9.-]+/g, ''));
            const nb = parseFloat(String(vb).replace(/[^0-9.-]+/g, ''));
            const naOk = !isNaN(na);
            const nbOk = !isNaN(nb);
            if (naOk && nbOk) return (na - nb) * currentSortDir;

            // Try date comparison
            const da = Date.parse(va);
            const db = Date.parse(vb);
            const daOk = !isNaN(da);
            const dbOk = !isNaN(db);
            if (daOk && dbOk) return (da - db) * currentSortDir;

            // Fallback to string compare (case-insensitive)
            return va.localeCompare(vb, undefined, { sensitivity: 'base' }) * currentSortDir;
        });
        rows.forEach(r => tbody.appendChild(r));
    }

    // populate departments from DEPARTMENTS JSON
    (function populateDeptFilter(){
        try {
            Object.keys(DEPARTMENTS).forEach(k => {
                const d = DEPARTMENTS[k];
                const o = document.createElement('option');
                o.value = k; // use department id as the option value (stable)
                o.textContent = d.department_name || ('Dept ' + k);
                filterDept.appendChild(o);
            });
        } catch(e) { /* silent */ }
    })();

    // wire force-reset filter
    if (filterForceReset) filterForceReset.addEventListener('change', filterTable);

    // wire sortable headers
    const sortableThs = usersTable ? Array.from(usersTable.querySelectorAll('thead th.sortable')) : [];
    sortableThs.forEach(th => {
        th.addEventListener('click', function(){
            const key = th.dataset.sort;
            if (!key) return;
            if (currentSortKey === key) currentSortDir = -currentSortDir; else { currentSortKey = key; currentSortDir = 1; }
            // update indicators
            sortableThs.forEach(x => { const s = x.querySelector('.sort-indicator'); if (s) s.textContent = ''; });
            const ind = th.querySelector('.sort-indicator'); if (ind) ind.textContent = currentSortDir === 1 ? '▲' : '▼';
            applyTableSort();
        });
    });

    // when department changes populate team select
    if (filterDept) {
        filterDept.addEventListener('change', function(){
            const val = this.value;
            filterTeam.innerHTML = '';
            const opt0 = document.createElement('option'); opt0.value=''; opt0.textContent='All teams'; filterTeam.appendChild(opt0);
            filterTeam.disabled = true;
            if (!val) return;
            // value is department id (key into DEPARTMENTS)
            const deptKey = val;
            if (!deptKey || !DEPARTMENTS[deptKey]) return;
            const dept = DEPARTMENTS[deptKey];
            if (dept && Array.isArray(dept.teams) && dept.teams.length) {
                // Deduplicate teams by id/name to avoid duplicate options if data contains duplicates
                const seenTeams = new Set();
                dept.teams.forEach(t => {
                    const key = (t.team_id !== undefined && t.team_id !== null) ? String(t.team_id) : String(t.team_name || '');
                    if (seenTeams.has(key)) return;
                    seenTeams.add(key);
                    const o = document.createElement('option');
                    o.value = t.team_id; // use team id as value
                    o.textContent = t.team_name || ('Team ' + t.team_id);
                    o.dataset.manager = t.manager_name || '';
                    filterTeam.appendChild(o);
                });
                filterTeam.disabled = false;
            }
            filterTable();
        });
    }

    if (filterTeam) filterTeam.addEventListener('change', filterTable);
    if (filterName) filterName.addEventListener('input', debounce(filterTable, 250));
    if (filterRole) filterRole.addEventListener('change', filterTable);
    if (filterActive) filterActive.addEventListener('change', filterTable);
    if (filterDateFrom) filterDateFrom.addEventListener('change', filterTable);
    if (filterDateTo) filterDateTo.addEventListener('change', filterTable);
    if (clearFiltersBtn) clearFiltersBtn.addEventListener('click', function(){
        if (filterName) filterName.value = '';
        if (filterRole) filterRole.value = '';
        if (filterDept) filterDept.value = '';
        if (filterTeam) { filterTeam.innerHTML = '<option value="">All teams</option>'; filterTeam.disabled = true; }
        if (filterActive) filterActive.value = 'all';
        if (filterDateFrom) filterDateFrom.value = '';
        if (filterDateTo) filterDateTo.value = '';
        filterTable();
    });

    // Bulk Force Password Reset: apply to all currently visible rows
    const bulkForceResetBtn = document.getElementById('bulkForceResetBtn');
    const bulkForceModal = document.getElementById('bulkForceModal');
    const bulkForceBody = document.getElementById('bulkForceBody');
    const bulkConfirmBtn = document.getElementById('bulkConfirmBtn');
    const bulkCancelBtn = document.getElementById('bulkCancelBtn');
    const bulkModalClose = bulkForceModal ? bulkForceModal.querySelector('.modal-close') : null;
    let bulkPendingIds = [];
    let bulkProcessing = false;

    // open modal when admin clicks the bulk action button: gather visible rows' ids
    if (bulkForceResetBtn) {
        bulkForceResetBtn.addEventListener('click', function(){
            if (bulkProcessing) return;
            if (!usersTable) return;
            const rows = Array.from(usersTable.querySelectorAll('tbody tr'));
            const visibleRows = rows.filter(r => r.style.display !== 'none');
            const ids = visibleRows.map(r => parseInt(r.dataset.id, 10)).filter(Boolean);
            if (!ids.length) {
                try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'No users visible to apply bulk reset', color: '#f59e0b' }); } catch(e){}
                return;
            }
            openBulkModal(ids.length, ids);
        });
    }

    if (bulkCancelBtn) bulkCancelBtn.addEventListener('click', function(){ if (!bulkProcessing) closeBulkModal(); });

    function openBulkModal(count, ids) {
        if (!bulkForceModal) return;
        bulkPendingIds = ids || [];
        bulkProcessing = false;
        bulkForceBody.textContent = 'Require password reset for ' + count + ' users? This will force them to set a new password on next login.';
        if (bulkConfirmBtn) { bulkConfirmBtn.disabled = false; bulkConfirmBtn.textContent = 'Confirm'; }
        if (bulkCancelBtn) { bulkCancelBtn.disabled = false; }
        bulkForceModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeBulkModal() {
        if (!bulkForceModal) return;
        bulkForceModal.style.display = 'none';
        document.body.style.overflow = '';
        bulkPendingIds = [];
        bulkProcessing = false;
    }

    // clicking the overlay closes modal when not processing
    if (bulkForceModal) {
        bulkForceModal.addEventListener('click', function(e){ if (e.target === bulkForceModal && !bulkProcessing) closeBulkModal(); });
    }
    if (bulkModalClose) bulkModalClose.addEventListener('click', function(){ if (!bulkProcessing) closeBulkModal(); });

    if (bulkConfirmBtn) {
        bulkConfirmBtn.addEventListener('click', async function(){
            if (bulkProcessing || !bulkPendingIds.length) return;
            bulkProcessing = true;
            if (bulkConfirmBtn) { bulkConfirmBtn.disabled = true; bulkConfirmBtn.textContent = 'Working...'; }
            if (bulkCancelBtn) bulkCancelBtn.disabled = true;
            try {
                const res = await fetch('bulk_force_password_reset.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ ids: bulkPendingIds }) });
                const clone = res.clone();
                let json = null;
                if (res.ok) {
                    try { json = await res.json(); } catch(parseErr) { const txt = await clone.text(); try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Server error: ' + txt.slice(0,200), color: '#dc2626' }); } catch(e){} closeBulkModal(); return; }
                } else { const txt = await clone.text(); try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Request failed: ' + txt.slice(0,200), color: '#dc2626' }); } catch(e){} closeBulkModal(); return; }

                if (json && json.success) {
                    const updated = Array.isArray(json.updated_ids) ? json.updated_ids : (json.updated_ids ? json.updated_ids : []);
                    const failed = Array.isArray(json.failed_ids) ? json.failed_ids : (json.failed_ids ? json.failed_ids : []);
                    // update UI for updated rows
                    updated.forEach(id => {
                        const row = usersTable.querySelector('tbody tr[data-id="' + id + '"]');
                        if (row) {
                            const frCell = row.querySelector('.users-col-force-reset');
                            if (frCell) frCell.innerHTML = '<span class="active-badge">1</span>';
                            row.dataset.forcePasswordReset = '1';
                        }
                    });
                    const succCount = updated.length || 0;
                    const failCount = failed.length || 0;
                    try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Force reset applied to ' + succCount + ' users' + (failCount ? (', ' + failCount + ' failed') : ''), color: failCount ? '#f59e0b' : '#10b981' }); } catch(e){}
                } else {
                    const err = json && json.error ? json.error : 'Bulk update failed';
                    try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: err, color: '#dc2626' }); } catch(e){}
                }
            } catch (err) {
                console.error(err);
                try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Request failed', color: '#dc2626' }); } catch(e){}
            

        } finally {
            // restore modal state and close
            bulkProcessing = false;
            try { if (bulkConfirmBtn) { bulkConfirmBtn.disabled = false; bulkConfirmBtn.textContent = 'Confirm'; } } catch(e){}
            try { if (bulkCancelBtn) bulkCancelBtn.disabled = false; } catch(e){}
            try { closeBulkModal(); } catch(e){}
        }
    });
}

function parseDateFromCell(text){ // try to parse YYYY-MM-DD or fallback
        if (!text) return null;
        // strip time if present
        const s = text.trim().replace(/\s+/, ' ');
        const d = new Date(s);
        if (!isNaN(d.getTime())) return d;
        // try only date portion
        const dateOnly = s.split(' ')[0];
        const d2 = new Date(dateOnly);
        return isNaN(d2.getTime()) ? null : d2;
    }

    function filterTable(){
        if (!usersTable) return;
        const rows = Array.from(usersTable.querySelectorAll('tbody tr'));
        let visible = 0;
        const nameQ = (filterName && filterName.value) ? (filterName.value.trim().toLowerCase()) : '';
        const roleQ = (filterRole && filterRole.value) ? filterRole.value : '';
        const deptQ = (filterDept && filterDept.value) ? filterDept.value : '';
        const teamQ = (filterTeam && filterTeam.value) ? filterTeam.value : '';
        const activeQ = (filterActive && filterActive.value) ? filterActive.value : 'all';
        const fromVal = filterDateFrom && filterDateFrom.value ? new Date(filterDateFrom.value) : null;
        const toVal = filterDateTo && filterDateTo.value ? new Date(filterDateTo.value) : null;

        rows.forEach(r => {
            let keep = true;
            const name = (r.children[1] && r.children[1].textContent || '').toLowerCase();
            const email = (r.children[3] && r.children[3].textContent || '').toLowerCase();
            const dept = (r.dataset && r.dataset.departmentId !== undefined) ? String(r.dataset.departmentId) : ((r.children[4] && r.children[4].textContent) || '').trim();
            const team = (r.dataset && r.dataset.teamId !== undefined) ? String(r.dataset.teamId) : ((r.children[6] && r.children[6].textContent) || '').trim();
            const role = (r.children[8] && r.children[8].textContent || '').trim().toLowerCase();
            const active = (r.dataset.active !== undefined) ? String(r.dataset.active) : ((r.querySelector('.users-col-active') && r.querySelector('.users-col-active').textContent||'').trim());
            const createdText = (r.querySelector('.users-col-created') && r.querySelector('.users-col-created').textContent || '').trim();
            const createdDate = parseDateFromCell(createdText);

            if (nameQ) { if (!(name.indexOf(nameQ) !== -1 || email.indexOf(nameQ) !== -1)) keep = false; }
            if (roleQ) { if (role.toLowerCase() !== roleQ.toLowerCase()) keep = false; }
            if (deptQ) { if (String(dept) !== String(deptQ)) keep = false; }
            if (teamQ) { if (String(team) !== String(teamQ)) keep = false; }
            if (activeQ && activeQ !== 'all') { if (String(active) !== String(activeQ)) keep = false; }
            // force reset filter
            try {
                const frQ = (filterForceReset && filterForceReset.value) ? filterForceReset.value : 'all';
                if (frQ && frQ !== 'all') {
                    const frVal = String(r.dataset.forcePasswordReset !== undefined ? r.dataset.forcePasswordReset : (r.querySelector('.users-col-force-reset') ? (r.querySelector('.users-col-force-reset').textContent||'').trim() : ''));
                    if (String(frVal) !== String(frQ)) keep = false;
                }
            } catch(e) { /* ignore */ }
            if (fromVal && createdDate) { if (createdDate < fromVal) keep = false; }
            if (toVal && createdDate) { // include day end
                const toEnd = new Date(toVal.getTime()); toEnd.setHours(23,59,59,999);
                if (createdDate > toEnd) keep = false;
            }

            if (keep) { r.style.display = ''; visible++; } else { r.style.display = 'none'; }
        });

        if (visibleCountEl) visibleCountEl.textContent = visible;
        if (totalCountEl) totalCountEl.textContent = rows.length;
        // Keep current sort applied after filtering
        try { applyTableSort(); } catch(e) {}
        return visible;
    }

    // department -> teams wiring
    if (u_department) {
        u_department.addEventListener('change', function(){
            const val = this.value;
            if (!u_team) return;
            u_team.innerHTML = '';
            u_team.disabled = true;
            if (u_director_display) u_director_display.value = 'Unassigned';
            if (u_director_name) u_director_name.value = '';
            // reset role select when department cleared
            try { const urole = document.getElementById('u_role'); if (urole) { urole.innerHTML = '<option value="">-- Select Role --</option>'; urole.disabled = true; urole.value = ''; } } catch(e) {}
            if (!val) return;
            const dept = DEPARTMENTS[val];
            if (!dept) return;
            if (u_director_display) u_director_display.value = dept.director_name || 'Unassigned';
            if (u_director_name) u_director_name.value = dept.director_name || '';
                    if (Array.isArray(dept.teams) && dept.teams.length) {
                        u_team.disabled = false;
                        const opt0 = document.createElement('option'); opt0.value=''; opt0.textContent='-- Select Team --'; u_team.appendChild(opt0);
                        // dedupe teams before appending
                        const seen = new Set();
                        dept.teams.forEach(t => {
                            const key = (t.team_id !== undefined && t.team_id !== null) ? String(t.team_id) : String(t.team_name || '');
                            if (seen.has(key)) return;
                            seen.add(key);
                            const o = document.createElement('option'); o.value = t.team_id; o.textContent = t.team_name; o.dataset.managerName = t.manager_name || ''; u_team.appendChild(o);
                        });
                    }
            // filter and enable role select for this department (allow global roles with empty dept)
            try { const urole = document.getElementById('u_role'); if (urole) { filterRoleOptions(urole, val); urole.disabled = false; } } catch(e) {}
        });
    }

    if (u_team) {
        u_team.addEventListener('change', function(){
            const sel = this.selectedOptions && this.selectedOptions[0];
            if (!sel || !sel.value) { if (u_manager_display) u_manager_display.value='Unassigned'; if (u_manager_name) u_manager_name.value=''; return; }
            const mgr = sel.dataset.managerName || '';
            if (u_manager_display) u_manager_display.value = mgr || 'Unassigned';
            if (u_manager_name) u_manager_name.value = mgr || '';
                // Removed erroneous filterRoleOptions call
        });
    }

        // edit/viewer modal: open when clicking a user row
        const toggleBtn = document.getElementById('toggleActiveBtn');
        let selectedRow = null;

        // edit modal elements
        const editModal = document.createElement('div');
        editModal.id = 'editModal';
        editModal.className = 'modal-overlay';
        editModal.style.display = 'none';
        editModal.innerHTML = `
            <div class="modal-card">
                <div class="modal-header">
                    <h3>Edit User</h3>
                    <button type="button" class="modal-close" aria-label="Close dialog">&times;</button>
                </div>
                <div id="editMsg" class="small-muted"></div>
                <form id="editForm">
                    <input type="hidden" id="e_id" name="id">
                        <label for="e_name">Name</label>
                        <input id="e_name" name="name" type="text" required>
                        <label for="e_user_name">Username</label>
                        <input id="e_user_name" name="user_name" type="text">
                        <label for="e_email">Email</label>
                        <input id="e_email" name="email" type="email" required>
                        <label for="e_department">Department</label>
                    <select id="e_department" name="department_id">
                        <option value="">--Select Department--</option>
                    </select>
                    <label for="e_director_display">Director</label>
                    <input id="e_director_display" type="text" disabled value="Unassigned">
                    <input type="hidden" id="e_director_name" name="director_name" value="">
                    <label for="e_team">Team</label>
                    <select id="e_team" name="team_id">
                        <option value="">-- Select team --</option>
                    </select>
                    <label for="e_manager_display">Manager</label>
                    <input id="e_manager_display" type="text" disabled value="Unassigned">
                    <input type="hidden" id="e_manager_name" name="manager_name" value="">
                    <!-- role select is placed after department/team/manager so department filters available roles -->
                    <label for="e_role">Role</label>
                    <select id="e_role" name="role_id" required disabled>
                        <?php foreach ($active_roles as $ro2): ?>
                            <option value="<?= (int)$ro2['role_id'] ?>" data-department-id="<?= empty($ro2['department_id']) ? '' : (int)$ro2['department_id'] ?>"><?= htmlspecialchars($ro2['role_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div style="display:flex;gap:8px;justify-content:space-between;margin-top:12px;">
                        <div>
                            <button type="button" id="e_cancel" class="btn">Close</button>
                        </div>
                        <div style="display:flex;gap:8px;">
                            ${IS_ADMIN ? '<button type="button" id="e_reset_pwd" class="btn">Reset Password</button>' : ''}
                            ${IS_ADMIN ? '<button type="button" id="e_resend_invite" class="btn" hidden>Resend Invitation</button>' : ''}
                            ${IS_ADMIN ? '<button type="button" id="e_copy_invite" class="btn">Copy Invitation</button>' : ''}
                            ${IS_ADMIN ? '<button type="button" id="e_toggle_active" class="btn">Toggle Active</button>' : ''}
                            <button type="submit" id="e_save" class="btn">Save</button>
                        </div>
                    </div>
                </form>
            </div>`;
        document.body.appendChild(editModal);
        // attach close handler for the dynamically-created edit modal
        const editCloseBtn = editModal.querySelector('.modal-close');
        if (editCloseBtn) editCloseBtn.addEventListener('click', function(){ editModal.style.display = 'none'; });

        const e_form = document.getElementById('editForm');
        const e_id = document.getElementById('e_id');
        const e_name = document.getElementById('e_name');
        const e_user_name = document.getElementById('e_user_name');
        const e_email = document.getElementById('e_email');
        const e_role = document.getElementById('e_role');
        const e_department = document.getElementById('e_department');
        const e_team = document.getElementById('e_team');
        const e_director_display = document.getElementById('e_director_display');
        const e_director_name = document.getElementById('e_director_name');
        const e_manager_display = document.getElementById('e_manager_display');
        const e_manager_name = document.getElementById('e_manager_name');
        const e_cancel = document.getElementById('e_cancel');
        const e_toggle_active = document.getElementById('e_toggle_active');
        const e_reset_pwd = document.getElementById('e_reset_pwd');
        const e_resend_invite = document.getElementById('e_resend_invite');

        // populate department select for edit modal
        (function populateEditDepartments(){
            Object.keys(DEPARTMENTS).forEach(k => {
                const d = DEPARTMENTS[k];
                const o = document.createElement('option'); o.value = k; o.textContent = d.department_name || d.department_name; e_department.appendChild(o);
            });
        })();

        // store original role options for filtering
        function collectRoleOptions(selectEl){
            if (!selectEl) return [];
            return Array.from(selectEl.options).map(o => ({ value: o.value, text: o.textContent, dept: o.dataset.departmentId || '' }));
        }
        const U_ROLE_OPTS = collectRoleOptions(document.getElementById('u_role'));
        const E_ROLE_OPTS = collectRoleOptions(document.getElementById('e_role'));

        function filterRoleOptions(selectEl, deptId){
            if (!selectEl) return;
            const source = (selectEl.id === 'u_role') ? U_ROLE_OPTS : E_ROLE_OPTS;
            // clear and re-add default option
            const currentVal = selectEl.value;
            selectEl.innerHTML = '';
            const dflt = document.createElement('option'); dflt.value = ''; dflt.textContent = '-- Select Role --'; selectEl.appendChild(dflt);
            source.forEach(opt => {
                // dept empty means global role
                if (!opt.dept || opt.dept === '' || String(opt.dept) === String(deptId)) {
                    const o = document.createElement('option'); o.value = opt.value; o.textContent = opt.text; selectEl.appendChild(o);
                }
            });
            // try to restore previously-selected value if still present
            try { if (currentVal) selectEl.value = currentVal; } catch(e){}
        }

        // store original edit values so we can detect no-op saves
        let E_ORIGINAL = null;

        function openEditModal(row){
            selectedRow = row;
            const id = row.dataset.id;
            
                e_id.value = id;
                // Prefer dataset values (more robust) and fall back to table cells
                e_name.value = (row.dataset.name || (row.children[1] && row.children[1].textContent) || '').trim();
                if (e_user_name) e_user_name.value = (row.dataset.username || (row.children[2] && row.children[2].textContent) || '').trim();
                e_email.value = (row.dataset.email || (row.children[3] && row.children[3].textContent) || '').trim();
            // set role select by role_id data attribute (preferred) or by label fallback
            var roleId = row.dataset.roleId || '';
            if (roleId) {
                e_role.value = roleId;
            } else {
                var label = (row.dataset.role || (row.children[8] && row.children[8].textContent) || '').toString().trim();
                // try to select option by matching label
                var found = false;
                for (var i=0;i<e_role.options.length;i++) {
                    if (e_role.options[i].textContent.trim().toLowerCase() === label.toLowerCase()) { e_role.selectedIndex = i; found = true; break; }
                }
                if (!found) e_role.value = '';
            }
            // set department/team using dataset values
            const deptText = (row.dataset.department || (row.children[4] && row.children[4].textContent) || '').trim();
            const teamText = (row.dataset.team || (row.children[6] && row.children[6].textContent) || '').trim();
            // find department id by name in DEPARTMENTS
            let deptKey = '';
            Object.keys(DEPARTMENTS).forEach(k => { if ((DEPARTMENTS[k].department_name||'') === deptText) deptKey = k; });
            e_department.value = deptKey;
            // trigger change to populate teams
            const ev = new Event('change'); e_department.dispatchEvent(ev);
            // select team by name if found
            if (teamText && e_team.options.length) {
                for (let i=0;i<e_team.options.length;i++){ if (e_team.options[i].textContent.trim() === teamText) { e_team.selectedIndex = i; e_team.dispatchEvent(new Event('change')); break; } }
            }
            // director & manager displays (prefer dataset)
            e_director_display.value = (row.dataset.director || (row.children[5] && row.children[5].textContent) || 'Unassigned').trim();
            e_director_name.value = e_director_display.value;
            e_manager_display.value = (row.dataset.manager || (row.children[7] && row.children[7].textContent) || 'Unassigned').trim();
            e_manager_name.value = e_manager_display.value;
            // capture original values for change detection
            try {
                E_ORIGINAL = {
                    name: (e_name.value || '').trim(),
                    user_name: (e_user_name ? (e_user_name.value||'') : '').trim(),
                    email: (e_email.value || '').trim(),
                    role_id: String(e_role.value || ''),
                    department_id: String(e_department.value || ''),
                    team_id: String(e_team.value || ''),
                    manager_name: (e_manager_name.value || '').trim(),
                    director_name: (e_director_name.value || '').trim()
                };
            } catch(e) { E_ORIGINAL = null; }
            // show modal
                // set dialog accessibility attributes and focus
                editModal.setAttribute('role','dialog');
                editModal.setAttribute('aria-modal','true');
                const card = editModal.querySelector('.modal-card'); if (card) card.setAttribute('tabindex','0');
                editModal.style.display = 'flex';
                // focus the first editable field
                setTimeout(()=>{ if (e_name) e_name.focus(); if (card) card.focus(); }, 50);
        }

        if (usersTable) {
                usersTable.addEventListener('click', function(e){
                        const row = e.target && e.target.closest && e.target.closest('.user-row');
                        if (!row) return;
                        openEditModal(row);
                });
        }

        // keep the old global toggle button disabled - modal provides toggle
        if (toggleBtn) { toggleBtn.style.display = 'none'; }

        // edit modal wiring: department -> teams
        if (e_department) {
            e_department.addEventListener('change', function(){
                const val = this.value;
                e_team.innerHTML = '';
                if (!val) { e_team.disabled = true; e_director_display.value = 'Unassigned'; e_director_name.value = ''; return; }
                const dept = DEPARTMENTS[val];
                if (!dept) return;
                e_director_display.value = dept.director_name || 'Unassigned';
                e_director_name.value = dept.director_name || '';
                // filter role options for edit modal and enable select
                try { const er = document.getElementById('e_role'); if (er) { filterRoleOptions(er, val); er.disabled = false; } } catch(e) {}
                if (Array.isArray(dept.teams) && dept.teams.length) {
                    e_team.disabled = false;
                    const opt0 = document.createElement('option'); opt0.value=''; opt0.textContent='-- Select Team --'; e_team.appendChild(opt0);
                    const seen = new Set();
                    dept.teams.forEach(t => {
                        const key = (t.team_id !== undefined && t.team_id !== null) ? String(t.team_id) : String(t.team_name || '');
                        if (seen.has(key)) return;
                        seen.add(key);
                        const o = document.createElement('option'); o.value = t.team_id; o.textContent = t.team_name; o.dataset.managerName = t.manager_name || ''; e_team.appendChild(o);
                    });
                } else { e_team.disabled = true; }
            });
        }

        if (e_team) {
            e_team.addEventListener('change', function(){
                const sel = this.selectedOptions && this.selectedOptions[0];
                if (!sel || !sel.value) { e_manager_display.value='Unassigned'; e_manager_name.value=''; return; }
                const mgr = sel.dataset.managerName || '';
                e_manager_display.value = mgr || 'Unassigned';
                e_manager_name.value = mgr || '';
            });
        }

        // close edit modal
        if (e_cancel) e_cancel.addEventListener('click', function(){ editModal.style.display='none'; });

        // toggle active inside modal (admin only)
        if (e_toggle_active) {
            e_toggle_active.addEventListener('click', async function(){
                if (!selectedRow) return;
                const id = parseInt(selectedRow.dataset.id,10);
                const current = parseInt(selectedRow.dataset.active,10) || 0;
                const newActive = current ? 0 : 1;
                    try {
                        const res = await fetch('toggle_user.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id: id, active: newActive }) });
                        const clone = res.clone();
                        let json = null;
                        if (res.ok) {
                            try { json = await res.json(); } catch(parseErr) {
                                const txt = await clone.text();
                                try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Server: ' + txt.replace(/<[^>]+>/g,' ').slice(0,220), color: '#dc2626' }); } catch(e){}
                                return;
                            }
                        } else {
                            const txt = await clone.text();
                            try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Request failed: ' + txt.replace(/<[^>]+>/g,' ').slice(0,220), color: '#dc2626' }); } catch(e){}
                            return;
                        }
                        if (json && json.success) {
                            const cell = selectedRow.querySelector('.users-col-active');
                            if (cell) cell.innerHTML = newActive ? '<span class="active-badge">1</span>' : '<span class="inactive-badge">0</span>';
                            selectedRow.dataset.active = newActive;
                            // update button text
                            e_toggle_active.textContent = newActive ? 'Toggle Active' : 'Toggle Active';
                            try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'User #' + json.id + (newActive ? ' activated' : ' deactivated'), color: '#10b981' }); } catch(e){}
                        } else {
                            const err = json && json.error ? json.error : 'Update failed';
                            try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: err, color: '#dc2626' }); } catch(e){}
                        }
                    } catch (err) { console.error(err); try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Request failed', color: '#dc2626' }); } catch(e){} }
            });
        }

        // reset password inside modal (admin only)
        if (e_reset_pwd) {
            e_reset_pwd.addEventListener('click', async function(){
                if (!selectedRow) return;
                const id = parseInt(selectedRow.dataset.id,10);
                // proceed without confirmation (admin action)
                try {
                    const res = await fetch('reset_user_password.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id: id }) });
                    const clone = res.clone();
                    let json = null;
                    if (res.ok) {
                        try { json = await res.json(); } catch(parseErr) { const txt = await clone.text(); document.getElementById('editMsg').textContent = txt.replace(/<[^>]+>/g,' '); try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Server error: see message', color: '#dc2626' }); } catch(e){} return; }
                    } else { const txt = await clone.text(); document.getElementById('editMsg').textContent = txt.replace(/<[^>]+>/g,' '); try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Request failed', color: '#dc2626' }); } catch(e){} return; }
                    if (json && json.success) {
                        // show inline message under title (in red)
                        try {
                            var em = document.getElementById('editMsg');
                            if (em) { em.textContent = 'Password has been reset successfully.'; em.classList.add('edit-reset-success'); }
                        } catch(e){}
                        // show notify with the password for admin to copy (longer duration). Make the toast clickable to copy the password.
                        try {
                            if (window.Notify && typeof window.Notify.push === 'function') {
                                var toastEl = Notify.push({ from: 'Users', message: 'New password for user #' + json.id + ': ' + (json.password || ''), color: '#10b981', duration: 30000 });
                                try {
                                    if (toastEl && toastEl.addEventListener && navigator.clipboard) {
                                        toastEl.style.cursor = 'pointer';
                                        toastEl.title = 'Click to copy password to clipboard';
                                        toastEl.addEventListener('click', function(){
                                            var pw = String(json.password || '');
                                            if (!pw) return;
                                            navigator.clipboard.writeText(pw).then(function(){
                                                Notify.push({ from: 'Users', message: 'Password copied to clipboard', color: '#10b981', duration: 4000 });
                                                // close the edit modal after copying
                                                try { if (editModal) editModal.style.display = 'none'; } catch(e){}
                                            }).catch(function(){
                                                Notify.push({ from: 'Users', message: 'Copy failed', color: '#dc2626', duration: 4000 });
                                            });
                                        });
                                    }
                                } catch(innerErr) { console.warn(innerErr); }
                            }
                        } catch(e){}
                    } else {
                        const err = json && json.error ? json.error : 'Reset failed';
                        try { var em2 = document.getElementById('editMsg'); if (em2) { em2.textContent = err; em2.classList.remove('edit-reset-success'); } } catch(e){}
                        try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: err, color: '#dc2626' }); } catch(e){}
                    }
                } catch (err) { console.error(err); try { document.getElementById('editMsg').textContent = 'Request failed'; } catch(e){} try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Request failed', color: '#dc2626' }); } catch(e){} }
            });
        }

        // resend invitation inside modal (admin only)
        if (e_resend_invite) {
            e_resend_invite.addEventListener('click', async function(){
                if (!selectedRow) return;
                const id = parseInt(selectedRow.dataset.id,10);
                try {
                    const res = await fetch('resend_invitation.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id: id }) });
                    const clone = res.clone();
                    let json = null;
                    if (res.ok) {
                        try { json = await res.json(); } catch(parseErr) { const txt = await clone.text(); document.getElementById('editMsg').textContent = txt.replace(/<[^>]+>/g,' '); try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Server error: see message', color: '#dc2626' }); } catch(e){} return; }
                    } else { const txt = await clone.text(); document.getElementById('editMsg').textContent = txt.replace(/<[^>]+>/g,' '); try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Request failed', color: '#dc2626' }); } catch(e){} return; }

                    if (json && json.success) {
                        // show inline message
                        try { var em = document.getElementById('editMsg'); if (em) { em.textContent = 'Invitation resent.'; em.classList.add('edit-reset-success'); } } catch(e){}
                        try {
                            if (window.Notify && typeof window.Notify.push === 'function') {
                                var msg = 'Invitation resent for user #' + json.id;
                                if (!json.email_sent) msg += ' (email failed' + (json.email_error ? ': ' + json.email_error : '') + ')';
                                var toastEl = Notify.push({ from: 'Users', message: msg, color: json.email_sent ? '#10b981' : '#f59e0b', duration: 20000 });
                                // if server returned a password, make the toast copyable like reset
                                try {
                                    if (toastEl && toastEl.addEventListener && navigator.clipboard && json.password) {
                                        toastEl.style.cursor = 'pointer';
                                        toastEl.title = 'Click to copy password to clipboard';
                                        toastEl.addEventListener('click', function(){
                                            var pw = String(json.password || '');
                                            if (!pw) return;
                                            navigator.clipboard.writeText(pw).then(function(){
                                                Notify.push({ from: 'Users', message: 'Password copied to clipboard', color: '#10b981', duration: 4000 });
                                                try { if (editModal) editModal.style.display = 'none'; } catch(e){}
                                            }).catch(function(){ Notify.push({ from: 'Users', message: 'Copy failed', color: '#dc2626', duration: 4000 }); });
                                        });
                                    }
                                } catch(innerErr) { console.warn(innerErr); }
                            }
                        } catch(e){}
                    } else {
                        const err = json && json.error ? json.error : 'Resend failed';
                        try { var em2 = document.getElementById('editMsg'); if (em2) { em2.textContent = err; em2.classList.remove('edit-reset-success'); } } catch(e){}
                        try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: err, color: '#dc2626' }); } catch(e){}
                    }
                } catch (err) { console.error(err); try { document.getElementById('editMsg').textContent = 'Request failed'; } catch(e){} try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Request failed', color: '#dc2626' }); } catch(e){} }
            });
        }

        // copy invitation message (admin only) - builds a plain-text message without signature
        const e_copy_invite = document.getElementById('e_copy_invite');
        if (e_copy_invite) {
            e_copy_invite.addEventListener('click', async function(){
                if (!selectedRow) return;
                try {
                    const name = (selectedRow.dataset.name || '').trim();
                    const username = (selectedRow.dataset.username || '').trim();
                    const email = (selectedRow.dataset.email || '').trim();
                    const siteOrigin = (window.location && window.location.origin) ? window.location.origin : (window.location.protocol + '//' + window.location.host);
                    const loginUrl = siteOrigin + '/index.php';
                    // If the admin already used Resend Invitation, the server returns a password in JSON and the toast copy handler covers copying it.
                    // Here we build a clean, signature-free message suitable for manual sending via external mailbox.
                    let msg = '';
                    msg += 'Hello ' + (name || '');
                    msg += "\n\n";
                    msg += 'An account has been created for you on ' + (window.location.hostname || 'our site') + '.';
                    msg += "\n\n";
                    if (username) msg += 'Username: ' + username + "\n";
                    // include the temporary password line as requested
                    msg += 'Password: temp' + "\n\n";
                    msg += 'Please sign in and set your password here: ' + loginUrl + "\n\n";
                    msg += 'If you would like a different temporary password, please reply to this message or contact the administrator.';
                    msg += "\n\n";
                    msg += 'Thank you.';

                    // attempt navigator.clipboard first
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        await navigator.clipboard.writeText(msg);
                        try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Invitation message copied to clipboard', color: '#10b981' }); } catch(e){}
                        return;
                    }

                    // fallback: create a temporary textarea, select and copy
                    const ta = document.createElement('textarea');
                    ta.value = msg;
                    ta.style.position = 'fixed'; ta.style.left = '-9999px'; ta.style.top = '0';
                    document.body.appendChild(ta);
                    ta.focus(); ta.select();
                    let ok = false;
                    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
                    document.body.removeChild(ta);
                    if (ok) {
                        try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Invitation message copied to clipboard', color: '#10b981' }); } catch(e){}
                    } else {
                        try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Copy failed - please select and copy manually', color: '#f59e0b' }); } catch(e){}
                    }
                } catch (err) {
                    console.error('Copy invite failed', err);
                    try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Copy failed', color: '#dc2626' }); } catch(e){}
                }
            });
        }

        // save edits
        if (e_form) {
            e_form.addEventListener('submit', async function(ev){
                ev.preventDefault();
                const payload = {
                        id: parseInt(e_id.value,10) || 0,
                        name: (e_name.value || '').trim(),
                        user_name: (e_user_name ? (e_user_name.value||'') : '').trim(),
                        email: (e_email.value || '').trim(),
                        role_id: parseInt((e_role.value || ''), 10) || 0,
                        department_id: parseInt(e_department.value,10) || 0,
                        team_id: parseInt(e_team.value,10) || 0,
                        manager_name: (e_manager_name.value || '').trim(),
                        director_name: (e_director_name.value || '').trim()
                    };
                        // if no changes compared to original, notify and skip
                        try {
                            if (E_ORIGINAL) {
                                const same = String((E_ORIGINAL.name||'')).trim() === String((payload.name||'')).trim()
                                    && String((E_ORIGINAL.user_name||'')).trim() === String((payload.user_name||'')).trim()
                                    && String((E_ORIGINAL.email||'')).trim() === String((payload.email||'')).trim()
                                    && String((E_ORIGINAL.role_id||'')).trim() === String((payload.role_id||'')).trim()
                                    && String((E_ORIGINAL.department_id||'')).trim() === String((payload.department_id||'')).trim()
                                    && String((E_ORIGINAL.team_id||'')).trim() === String((payload.team_id||'')).trim()
                                    && String((E_ORIGINAL.manager_name||'')).trim() === String((payload.manager_name||'')).trim()
                                    && String((E_ORIGINAL.director_name||'')).trim() === String((payload.director_name||'')).trim();
                                if (same) {
                                    try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'No changes detected to save', color: '#f59e0b' }); } catch(e){}
                                    return;
                                }
                            }
                        } catch(e) {}
                    try {
                        const res = await fetch('update_user.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
                        const clone = res.clone();
                        let json = null;
                        if (res.ok) {
                            try { json = await res.json(); } catch(parseErr) { const txt = await clone.text(); document.getElementById('editMsg').textContent = txt.replace(/<[^>]+>/g,' '); try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Server error: see message', color: '#dc2626' }); } catch(e){} return; }
                        } else { const txt = await clone.text(); document.getElementById('editMsg').textContent = txt.replace(/<[^>]+>/g,' '); try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Request failed', color: '#dc2626' }); } catch(e){} return; }
                        if (json && json.success) {
                            // update table row cells using class selectors (more robust than numeric indices)
                            const nameCell = selectedRow.querySelector('.users-col-name'); if (nameCell) nameCell.textContent = payload.name;
                            const usernameCell = selectedRow.querySelector('.users-col-username'); if (usernameCell) usernameCell.textContent = payload.user_name || '';
                            const emailCell = selectedRow.querySelector('.users-col-email'); if (emailCell) emailCell.textContent = payload.email;
                            const deptCell = selectedRow.querySelector('.users-col-dept'); if (deptCell) deptCell.textContent = e_department.options[e_department.selectedIndex] ? e_department.options[e_department.selectedIndex].textContent : '';
                            const directorCell = selectedRow.querySelector('.users-col-director'); if (directorCell) directorCell.textContent = payload.director_name || '';
                            const teamCell = selectedRow.querySelector('.users-col-team'); if (teamCell) teamCell.textContent = e_team.options[e_team.selectedIndex] ? e_team.options[e_team.selectedIndex].textContent : '';
                            const managerCell = selectedRow.querySelector('.users-col-manager'); if (managerCell) managerCell.textContent = payload.manager_name || '';
                            // role cell has no dedicated class; update by index relative to row
                            try { const roleCell = selectedRow.children[8]; if (roleCell) roleCell.textContent = (json.role || payload.role || '') ? String(json.role || payload.role).charAt(0).toUpperCase() + String(json.role || payload.role).slice(1) : ''; } catch(e){}
                            // update data-role-id attribute so future edits select the correct option
                            try { selectedRow.dataset.roleId = (json.role_id || payload.role_id || ''); } catch(e) {}
                            // close modal
                            editModal.style.display='none';
                            try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'User #' + json.id + ' updated', color: '#10b981' }); } catch(e){}
                        } else {
                            const err = json && json.error ? json.error : 'Update failed';
                            document.getElementById('editMsg').textContent = err;
                            try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: err, color: '#dc2626' }); } catch(e){}
                        }
                    } catch (err) { console.error(err); document.getElementById('editMsg').textContent = 'Request failed'; }
                });
        }

        // keyboard: Escape to close modals
        document.addEventListener('keydown', function(ev){
            if (ev.key === 'Escape') {
                if (editModal && editModal.style.display === 'flex') { editModal.style.display = 'none'; }
                if (createModal && createModal.style.display === 'flex') { closeCreate(); }
            }
        });

    // create user submit
    if (createForm) {
        createForm.addEventListener('submit', async function(e){
            e.preventDefault();
                const data = {
                    name: (document.getElementById('u_name') || {}).value || '',
                    user_name: (document.getElementById('u_username') || {}).value || '',
                    email: (document.getElementById('u_email') || {}).value || '',
                    password: (document.getElementById('u_password') || {}).value || '',
                    send_invite: (document.getElementById('u_send_invite') || {}).checked ? 1 : 0,
                    role_id: parseInt((document.getElementById('u_role') || {}).value || '', 10) || 0,
                    manager_name: (document.getElementById('u_manager_name') || {}).value || '',
                    department_id: parseInt((document.getElementById('u_department') || {}).value) || 0,
                    team_id: parseInt((document.getElementById('u_team') || {}).value) || 0
                };
            try {
                const res = await fetch('create_user.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(data) });
                const clone = res.clone();
                let json = null;
                if (res.ok) {
                    try { json = await res.json(); } catch(parseErr) { const txt = await clone.text(); document.getElementById('createMsg').textContent = txt.replace(/<[^>]+>/g,' '); try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Server error', color: '#dc2626' }); } catch(e){} return; }
                } else { const txt = await clone.text(); document.getElementById('createMsg').textContent = txt.replace(/<[^>]+>/g,' '); try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Request failed', color: '#dc2626' }); } catch(e){} return; }
                if (json && json.success) {
                    try {
                        var createdMsg = 'User created';
                        if (json.invite_sent === false) createdMsg += ' (invitation send failed' + (json.invite_error ? ': ' + json.invite_error : '') + ')';
                        if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: createdMsg, color: json.invite_sent === false ? '#f59e0b' : '#10b981' });
                    } catch(e){}
                    setTimeout(()=> window.location.reload(), 700);
                } else {
                    const err = json && json.error ? json.error : 'Failed to create user';
                    document.getElementById('createMsg').textContent = err;
                    try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: err, color: '#dc2626' }); } catch(e){}
                }
            } catch (err) { console.error(err); document.getElementById('createMsg').textContent = 'Request failed'; try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Request failed', color: '#dc2626' }); } catch(e){} }
        });
    }

    // initialize counts and filters on load
    try { filterTable(); } catch(e) { console.warn('filterTable init failed', e); }

});



</script>
</body>
</html>
