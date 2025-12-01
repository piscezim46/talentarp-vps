<?php
// Sidebar navigation (no inline styles). Active link is detected programmatically.
$current = basename($_SERVER['PHP_SELF'] ?? '');
function _is_active($file, $current) { return $file === $current ? 'active' : ''; }
?>

<nav id="sidebar" class="sidebar" aria-label="Main sidebar">
    <?php
    // use centralized access helper (role_id-aware)
    if (file_exists(__DIR__ . '/access.php')) include_once __DIR__ . '/access.php';
    // ensure DB available for role label lookup when needed
    if (file_exists(__DIR__ . '/db.php')) include_once __DIR__ . '/db.php';
    ?>
    <?php // user info moved to header for top-right display ?>

    <div class="nav-links">
        <?php if (_has_access('dashboard_view')): ?>
        <a class="<?= _is_active('dashboard.php', $current) ?>" href="dashboard.php"><i class="fas fa-chart-line"></i><span class="navbar-label">Dashboard</span></a>
        <?php endif; ?>
        <?php if (_has_access('positions_view')): ?>
        <a class="<?= _is_active('view_positions.php', $current) ?>" href="view_positions.php"><i class="fas fa-briefcase"></i><span class="navbar-label">Positions</span></a>
        <?php endif; ?>
        <?php if (_has_access('applicants_view')): ?>
        <a class="<?= _is_active('applicants.php', $current) ?>" href="applicants.php"><i class="fas fa-users"></i><span class="navbar-label">Applicants</span></a>
        <?php endif; ?>
        <?php if (_has_access('interviews_view')): ?>
        <a class="<?= _is_active('interviews.php', $current) ?>" href="interviews.php"><i class="fas fa-calendar-alt"></i><span class="navbar-label">Interviews</span></a>
        <?php endif; ?>
        <?php if (_has_access('departments_view')): ?>
        <a class="<?= _is_active('departments.php', $current) ?>" href="departments.php"><i class="fas fa-building"></i><span class="navbar-label">Departments</span></a>
        <?php endif; ?>
        <?php if (_has_access('roles_view')): ?>
        <a class="<?= _is_active('roles.php', $current) ?>" href="roles.php"><i class="fas fa-user-tag"></i><span class="navbar-label">Roles</span></a>
        <?php endif; ?>
        <?php if (_has_access('users_view')): ?>
        <a class="<?= _is_active('users.php', $current) ?>" href="users.php"><i class="fas fa-user-cog"></i><span class="navbar-label">Users</span></a>
        <?php endif; ?>
        <?php if (_has_access('flows_view')): ?>
        <a class="<?= _is_active('flows.php', $current) ?>" href="flows.php"><i class="fas fa-project-diagram"></i><span class="navbar-label">Flows</span></a>
        <?php endif; ?> 
    </div>
    <div class="logout">
        <a class="logout" href="logout.php"><i class="fas fa-sign-out-alt"></i><span class="navbar-label">Logout</span></a>
    </div>
</nav>
