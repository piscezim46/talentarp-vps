<?php 
// public/applicants.php 
session_start(); 
require_once '../includes/db.php'; 

if (!isset($_SESSION['user'])) { 
    header("Location: index.php"); 
    exit; 
} 

$user = $_SESSION['user']; 

// Positions list (for linking applicant -> position)
// Build positions grouped by department so modal can require department -> position selection
$positions = [];
$positions_by_dept = [];
$pos_stmt = $conn->prepare("SELECT p.id, p.title, COALESCE(p.department, '') AS department, COALESCE(p.team, '') AS team, p.status_id, COALESCE(s.status_name, '') AS status FROM positions p LEFT JOIN positions_status s ON p.status_id = s.status_id ORDER BY p.id DESC");
$pos_stmt->execute();
$pos_res = $pos_stmt->get_result();
while ($p = $pos_res->fetch_assoc()) {
    $positions[$p['id']] = $p['title'];
  $dept = trim($p['department']) !== '' ? $p['department'] : 'Unassigned';
  if (!isset($positions_by_dept[$dept])) $positions_by_dept[$dept] = [];
  $positions_by_dept[$dept][] = [
    'position_id' => (int)$p['id'],
    'title' => $p['title'],
    'team' => $p['team'] ?? '',
    'status' => $p['status'] ?? '',
    'status_id' => isset($p['status_id']) ? (int)$p['status_id'] : 0
  ];
}
$positions_json = json_encode($positions_by_dept, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

// Flash message (from create_applicant.php redirects) 
$flash = $_GET['msg'] ?? ''; 
$flash_type = $_GET['type'] ?? ''; 

// ----------------------------- 
// Fetch filter option datasets 
// ----------------------------- 

// load applicant statuses from DB so the filter reflects canonical values
$filter_statuses = [];
$st_stmt = $conn->prepare("SELECT status_id, status_name FROM applicants_status WHERE active = 1 ORDER BY COALESCE(sort_order, status_id) ASC, status_name ASC");
if ($st_stmt) {
  $st_stmt->execute();
  $st_res = $st_stmt->get_result();
  while ($s = $st_res->fetch_assoc()) {
    $filter_statuses[] = $s;
  }
}


$filter_roles = []; 
$role_stmt = $conn->prepare("SELECT DISTINCT title AS role_applied FROM positions WHERE title IS NOT NULL AND title <> ''");
$role_stmt->execute();
$roles_result = $role_stmt->get_result();
while ($r = $roles_result->fetch_assoc()) {
    $filter_roles[] = $r['role_applied'];
}

// Departments should reflect the canonical departments table and only active ones
$filter_departments = [];
$dept_stmt = $conn->prepare("SELECT department_name FROM departments WHERE active = 1 ORDER BY department_name ASC");
if ($dept_stmt) {
  $dept_stmt->execute();
  $dept_result = $dept_stmt->get_result();
  while ($d = $dept_result->fetch_assoc()) {
    $filter_departments[] = $d['department_name'];
  }
}

// Teams: prefer teams table active entries so updates are reflected in filters
$filter_teams = [];
$team_stmt = $conn->prepare("SELECT DISTINCT team_name FROM teams WHERE active = 1 AND team_name IS NOT NULL AND team_name <> '' ORDER BY team_name ASC");
if ($team_stmt) {
  $team_stmt->execute();
  $team_res = $team_stmt->get_result();
  while ($t = $team_res->fetch_assoc()) {
    $filter_teams[] = $t['team_name'];
  }
}

// distinct ages, genders, nationalities for header multi-select filters
$filter_ages = [];
$age_res = $conn->query("SELECT DISTINCT age FROM applicants WHERE age IS NOT NULL ORDER BY age ASC");
if ($age_res) { while ($a = $age_res->fetch_assoc()) { $filter_ages[] = $a['age']; } $age_res->free(); }

$filter_genders = [];
$g_res = $conn->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(gender),''),'') AS gender FROM applicants WHERE gender IS NOT NULL ORDER BY gender");
if ($g_res) { while ($gg = $g_res->fetch_assoc()) { if ($gg['gender'] !== '') $filter_genders[] = $gg['gender']; } $g_res->free(); }

$filter_nationalities = [];
$n_res = $conn->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(nationality),''),'') AS nat FROM applicants WHERE nationality IS NOT NULL ORDER BY nat");
if ($n_res) { while ($nn = $n_res->fetch_assoc()) { if ($nn['nat'] !== '') $filter_nationalities[] = $nn['nat']; } $n_res->free(); }

// Managers list: include active users with manager/admin/hr roles and any manager names referenced by teams/positions
$filter_managers = [];
$mgr_names = [];
$mgr_stmt = $conn->prepare("SELECT DISTINCT u.name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE r.role_name IN ('manager','admin','hr') AND COALESCE(u.active,1) = 1 ORDER BY u.name");
if ($mgr_stmt) {
  $mgr_stmt->execute();
  $mgr_res = $mgr_stmt->get_result();
  while ($m = $mgr_res->fetch_assoc()) {
    $n = trim($m['name'] ?? ''); if ($n !== '') $mgr_names[$n] = $n;
  }
}
// include manager_name from teams table
if ($tres = $conn->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(manager_name),''),'') AS mgr FROM teams WHERE manager_name IS NOT NULL")) {
  while ($r = $tres->fetch_assoc()) { $n = trim($r['mgr'] ?? ''); if ($n !== '') $mgr_names[$n] = $n; }
  $tres->free();
}
// include manager_name from positions table
if ($pres = $conn->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(manager_name),''),'') AS mgr FROM positions WHERE manager_name IS NOT NULL")) {
  while ($r = $pres->fetch_assoc()) { $n = trim($r['mgr'] ?? ''); if ($n !== '') $mgr_names[$n] = $n; }
  $pres->free();
}
// final list sorted
if (count($mgr_names)) {
  ksort($mgr_names, SORT_NATURAL|SORT_FLAG_CASE);
  $filter_managers = array_values($mgr_names);
}

    // small helpers for status colors (applicants palette — different from positions)
    function status_text_color_app($hex) {
      $hex = ltrim((string)$hex, '#');
      if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
      if (strlen($hex) !== 6) return '#ffffff';
      $r = hexdec(substr($hex,0,2));
      $g = hexdec(substr($hex,2,2));
      $b = hexdec(substr($hex,4,2));
      $luma = (0.299*$r + 0.587*$g + 0.114*$b);
      return ($luma > 186) ? '#111111' : '#ffffff';
    }

    function status_palette_color_app($name) {
      $k = strtolower(trim((string)$name));
      switch ($k) {
        case 'applicants active': return '#2563EB';
        case 'approve': return '#059669';
        case 'complete': return '#475569';
        case 'created': return '#4B5563';
        case 'hire confirmed': return '#0F766E';
        case 'hire partially confirmed': return '#D97706';
        case 'hiring active': return '#1E40AF';
        case 'interviews active': return '#0891B2';
        case 're-open':
        case 'reopen': return '#1E3A8A';
        case 'rejected': return '#B91C1C';
        case 'send for approval': return '#C2410C';
        case 'short-close':
        case 'short close': return '#64748B';
        default: return '#374151';
      }
    }

// ---------------------------------
// Fetch applicants list from the applicants table
// Columns requested: applicant_id, full_name, degree, age, gender,
// nationality, years_experience, skills, resume_file, parsing_status,
// experience_level, education_level, created_at
// build positions_by_team on server so client just looks up by team name
$positions_by_team = [];
$posSql = "SELECT p.id, p.title, COALESCE(p.team, '') AS team, COALESCE(s.status_name, '') AS status FROM positions p LEFT JOIN positions_status s ON p.status_id = s.status_id";
if ($posRes = $conn->query($posSql)) {
  while ($prow = $posRes->fetch_assoc()) {
    $teamName = trim($prow['team'] ?? ''); // normalize
    if ($teamName === '') $teamName = 'Unassigned';
    $positions_by_team[$teamName][] = [
      'id' => (int)$prow['id'],
      'title' => $prow['title'],
      'status' => $prow['status'] ?? ''
    ];
  }
}

// ---------------------------------
// Fetch applicants list from the applicants table
// Columns requested: applicant_id, full_name, degree, age, gender,
// nationality, years_experience, skills, resume_file, parsing_status,
// experience_level, education_level, created_at
$sql = "
  SELECT
    a.applicant_id,
    a.status_id,
    COALESCE(s.status_name,'') AS applicant_status_name,
    COALESCE(s.status_color,'') AS status_color,
    a.full_name,
    a.degree,
    a.age,
    a.gender,
    a.nationality,
    a.years_experience,
    a.skills,
    a.resume_file,
    a.parsing_status,
    a.experience_level,
    a.education_level,
    a.created_at,
    a.position_id,
    COALESCE(p.manager_name,'') AS position_manager,
    COALESCE(p.title,'') AS position_title,
    COALESCE(p.department,'') AS position_department,
    COALESCE(p.team,'') AS position_team
  FROM applicants a
  LEFT JOIN applicants_status s ON a.status_id = s.status_id
  LEFT JOIN positions p ON a.position_id = p.id
  ORDER BY a.created_at DESC
";
  // execute applicants query and expose result set for rendering below
  $applicants = $conn->query($sql);
  if ($applicants === false) {
    // query failed - log and create an empty result to avoid fatal errors later
    error_log('Applicants query failed: ' . $conn->error);
    $applicants = $conn->query("SELECT 0 AS applicant_id LIMIT 0");
  }

  // ensure we have a positions_for_modal array for the client-side modal population
  // build a simple list from the earlier $positions map (id => title)
  $positions_for_modal = [];
  // Flatten positions_by_dept into a simple array that includes department/team/status
  foreach ($positions_by_dept as $deptName => $plist) {
    foreach ($plist as $pinfo) {
      $positions_for_modal[] = array_merge([
        'department' => $deptName
      ], $pinfo);
    }
  }

// Fetch teams (to get manager_name). We'll expose teams grouped by department id/name — only active teams
$teams_by_dept = [];
if ($res = $conn->query("SELECT team_id AS id, team_name AS name, department_id, manager_name FROM teams WHERE active = 1 ORDER BY team_name")) {
  while ($t = $res->fetch_assoc()) {
    $deptId = isset($t['department_id']) ? (int)$t['department_id'] : 0;
    if (!isset($teams_by_dept[$deptId])) $teams_by_dept[$deptId] = [];
    $teams_by_dept[$deptId][] = $t;
  }
  $res->free();
}

// Fetch departments that have positions in allowed active statuses
$departments = [];
$dept_sql = "
  SELECT d.department_id AS id, d.department_name AS name, d.director_name
  FROM departments d
  WHERE d.active = 1
  ORDER BY d.department_name
";
if ($dres = $conn->query($dept_sql)) {
  while ($dr = $dres->fetch_assoc()) {
    $departments[(int)$dr['id']] = $dr;
  }
  $dres->free();
}

// expose to client JS
$positions_json = json_encode($positions_for_modal, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$teams_json = json_encode($teams_by_dept, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
echo "<script>\n  window._modalPositions = {$positions_json} || [];\n  window._teamsByDept = {$teams_json} || {};\n</script>";

// build positions_by_team on server so client just looks up by team name
// build positions_by_team on server so client just looks up by team name
$positions_by_team = [];
$posSql = "SELECT p.id, p.title, COALESCE(p.team, '') AS team, COALESCE(s.status_name, '') AS status FROM positions p LEFT JOIN positions_status s ON p.status_id = s.status_id";
if ($posRes = $conn->query($posSql)) {
  while ($prow = $posRes->fetch_assoc()) {
    $teamName = trim($prow['team'] ?? ''); // normalize
    if ($teamName === '') $teamName = 'Unassigned';
    $positions_by_team[$teamName][] = [
      'id' => (int)$prow['id'],
      'title' => $prow['title'],
      'status' => $prow['status'] ?? ''
    ];
  }
}
// Include central header and navbar early so pages render a consistent document skeleton
if (file_exists(__DIR__ . '/../includes/header.php')) include __DIR__ . '/../includes/header.php';
if (file_exists(__DIR__ . '/../includes/navbar.php')) include __DIR__ . '/../includes/navbar.php';
?>
<script>
window._positionsByTeam = <?php echo json_encode($positions_by_team, JSON_HEX_TAG); ?>;
</script>

<script>
// Apply dynamic colors to status pills (moved out of PHP inline styles)
document.addEventListener('DOMContentLoaded', function(){
  try {
    document.querySelectorAll('.status-pill').forEach(function(el){
      var bg = el.dataset.bg || '';
      var color = el.dataset.color || '';
      if (bg) el.style.background = bg;
      if (color) el.style.color = color;
      // keep border consistent
      el.style.borderColor = 'rgba(0,0,0,0.12)';
    });
  } catch(e){ console.error('status-pill color apply failed', e); }
});
</script>

<main class="content-area">
    <?php if ($flash !== ''): ?>
        <div class="flash <?= htmlspecialchars($flash_type === 'success' ? 'success' : 'error') ?>">
            <?= htmlspecialchars($flash) ?>
        </div>
    <?php endif; ?>

</script>

<link rel="stylesheet" href="styles/layout.css">
<link rel="stylesheet" href="styles/view_positions.css">
<link rel="stylesheet" href="styles/applicants.css">
<script src="assets/js/notify.js"></script>
<link rel="stylesheet" href="styles/users.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.gstatic.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">

<!-- applicants.css already loaded above -->

<div class="main">

    <?php if ($flash): ?> 
        <div class="flash <?= htmlspecialchars($flash_type) ?>"><?= htmlspecialchars($flash) ?></div> 
    <?php endif; ?> 

    <!-- Filters moved into table header for compact layout -->

    <div class="widget"> 
      <div class="widget-header positions-header-row">
          <div class="page-title"></i> Applicants</div>
          <div class="header-actions">
            <?php $can_create = in_array('applicants_create', $_SESSION['user']['access_keys'] ?? []) || in_array($user['role'], ['admin','hr']); ?>
            <button id="openCreateApplicantBtn" class="btn primary btn-primary btn-create <?= $can_create ? '' : 'disabled' ?>" <?php if (! $can_create) echo 'disabled title="Insufficient permissions" aria-disabled="true"'; ?> data-open-create="1"><i class="fa-solid fa-user-plus"></i> Create Applicant</button>
          </div>
        </div>
        <!-- Top filters toolbar (moved out of table header for clarity) -->
        <div class="filters-wrap positions-filters">
          <div class="filters-row">
              <?php
                // Ensure we have a team list (derived from positions_by_team built earlier)
                $team_list = isset($positions_by_team) && is_array($positions_by_team) ? array_keys($positions_by_team) : [];
              ?>
              <div class="filters-controls">
                <select id="f-role">
                  <option value="">All Positions</option>
                  <?php foreach ($filter_roles as $role): ?>
                    <option value="<?= htmlspecialchars(strtolower($role)) ?>"><?= htmlspecialchars($role) ?></option>
                  <?php endforeach; ?>
                </select>

                <select id="f-status">
                  <option value="">All Status</option>
                  <?php foreach ($filter_statuses as $s): ?>
                    <option value="<?= (int)$s['status_id'] ?>"><?= htmlspecialchars($s['status_name']) ?></option>
                  <?php endforeach; ?>
                </select>

                <select id="f-dept">
                  <option value="">All Departments</option>
                  <?php foreach ($filter_departments as $dname): ?>
                    <option value="<?= htmlspecialchars(strtolower($dname)) ?>"><?= htmlspecialchars($dname) ?></option>
                  <?php endforeach; ?>
                </select>

                <select id="f-team">
                  <option value="">All Teams</option>
                  <?php foreach ($filter_teams as $tname): ?>
                    <option value="<?= htmlspecialchars(strtolower($tname)) ?>"><?= htmlspecialchars($tname) ?></option>
                  <?php endforeach; ?>
                </select>

                <select id="f-manager">
                  <option value="">All Managers</option>
                  <?php foreach ($filter_managers as $mname): ?>
                    <option value="<?= htmlspecialchars(strtolower($mname)) ?>"><?= htmlspecialchars($mname) ?></option>
                  <?php endforeach; ?>
                </select>

                <input id="f-name" type="search" placeholder="Search name / skills" />

                <input id="f-date-from" type="date" title="Created from" />
                <input id="f-date-to" type="date" title="Created to" />

                <button id="clearFilters" class="btn primary" style="color: white;">Clear Filters</button>
                <button id="bulkUpdateStatusBtn" class="btn-bulk" type="button"><i class="fa-solid fa-arrows-up-down"></i> Bulk Update Status</button>
              </div>
          </div>
        </div>

        <!-- two-column layout: left = scrollable list, right = detail card -->
        <div class="app-layout">
          <div class="app-list">
              <table class="users-table">
      <thead>
        <tr>
          <th class="col-select"><input type="checkbox" id="selectAllApplicants" title="Select all"></th>
          <th class="col-id">ID</th>
          <th>Status</th>
          <th>Position</th>
          <th>Department</th>
          <th>Team</th>
          <th>Full Name</th>
          <th>Age</th>
          <th>Gender</th>
          <th>Nationality</th>
          <th>Degree</th>
          <th>Years Exp</th>
              <th>Created Date</th>
          <th class="sticky-actions" hidden>Actions</th>
        </tr>
      </thead>
      <tbody id="applicants-body">
      <?php while ($row = $applicants->fetch_assoc()): ?>
        <?php
          // prepare display values
          $appId = (int)($row['applicant_id'] ?? 0);
          $appStatusId = (int)($row['status_id'] ?? 0);
          $appStatusName = $row['applicant_status_name'] ?? '—';
          $positionTitle = $row['position_title'] ?? '—';
          $positionDept = $row['position_department'] ?? '—';
          $positionTeam = $row['position_team'] ?? '—';
          $full = $row['full_name'] ?? '—';
          $age = isset($row['age']) ? (int)$row['age'] : '—';
          $gender = $row['gender'] ?? '—';
          $nat = $row['nationality'] ?? '—';
          $degree = $row['degree'] ?? '—';
          $yrs = isset($row['years_experience']) ? (int)$row['years_experience'] : '—';
          $resume = $row['resume_file'] ?? '';
          $created = $row['created_at'] ?? '';
          $parsing = $row['parsing_status'] ?? '';
        ?>
        <tr class="app-row" data-applicant-id="<?= $appId ?>"
          data-parsing="<?= htmlspecialchars($parsing) ?>"
          data-position-id="<?= (int)($row['position_id'] ?? 0) ?>"
          data-position-title="<?= htmlspecialchars(strtolower($positionTitle)) ?>"
          data-manager="<?= htmlspecialchars($row['position_manager'] ?? '') ?>"
          data-status-id="<?= $appStatusId ?>"
          data-date="<?= substr($created, 0, 10) ?>"
          data-age="<?= htmlspecialchars((string)($row['age'] ?? '')) ?>"
          data-gender="<?= htmlspecialchars(strtolower((string)($row['gender'] ?? ''))) ?>"
          data-nationality="<?= htmlspecialchars(strtolower((string)($row['nationality'] ?? ''))) ?>"
        >
          <td class="col-select"><input type="checkbox" class="app-select" data-id="<?= $appId ?>" aria-label="Select applicant <?= $appId ?>"></td>
          <td class="col-id">#<?= $appId ?></td>
          <?php
            // determine badge color (prefer DB-defined color, otherwise fallback to palette)
            $badge_color = (!empty($row['status_color'])) ? $row['status_color'] : status_palette_color_app($appStatusName);
            $badge_text_color = status_text_color_app($badge_color);
          ?>
          <td><span class="pill status-pill" data-bg="<?= htmlspecialchars($badge_color) ?>" data-color="<?= htmlspecialchars($badge_text_color) ?>"><?= htmlspecialchars($appStatusName) ?></span></td>
          <td><?= htmlspecialchars($positionTitle) ?></td>
          <td><?= htmlspecialchars($positionDept) ?></td>
          <td><?= htmlspecialchars($positionTeam) ?></td>
          <td><?= htmlspecialchars($full) ?></td>
          <td><?= $age ?></td>
          <td><?= htmlspecialchars($gender) ?></td>
          <td><?= htmlspecialchars($nat) ?></td>
          <td><?= htmlspecialchars($degree) ?></td>
          <td><?= $yrs ?></td>
          <td><?= htmlspecialchars($created) ?></td>
          <td class="sticky-actions" hidden>
            <?php if (in_array($user['role'], ['admin','hr'])): ?>
                <?php if (!empty($resume)): ?>
                  <?php
                    // build resume href relative to public/ directory
                    $resumeHref = strpos($resume, 'uploads/') === 0 ? $resume : ('uploads/' . ltrim($resume, '/'));
                    // server-side check: only show link if file exists on disk to avoid 404s
                    $fullPath = __DIR__ . '/' . $resumeHref;
                    if (file_exists($fullPath)) {
                      // URL-encode the filename segment to avoid problems with spaces/special characters
                      $parts = explode('/', $resumeHref);
                      $parts[count($parts) - 1] = rawurlencode($parts[count($parts) - 1]);
                      $safeHref = implode('/', $parts);
                  ?>
                    <a href="<?= htmlspecialchars($safeHref) ?>" target="_blank" class="btn-ghost">Resume</a>
                  <?php } else { ?>
                    <em class="muted">No resume</em>
                  <?php } ?>
                <?php else: ?>
                    <em class="muted">No resume</em>
                <?php endif; ?>
            <?php else: ?>
              <em class="muted">No actions</em>
            <?php endif; ?>
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
              </table>
          </div><!-- .app-list -->
        </div><!-- .app-layout -->
    </div> 
</div> 

<script>
// Applicants filters + bulk-select
(function(){
  const filters = {
    role: document.getElementById('f-role'),
    status: document.getElementById('f-status'),
    dept: document.getElementById('f-dept'),
    manager: document.getElementById('f-manager'),
    dateFrom: document.getElementById('f-date-from'),
    dateTo: document.getElementById('f-date-to'),
    age: document.getElementById('f-age'),
    gender: document.getElementById('f-gender'),
    nat: document.getElementById('f-nat')
  };
  const tbody = document.getElementById('applicants-body');
  if (!tbody) return;

  function getSelectedOptions(el){
    if (!el) return [];
    // if not multiple, fallback to single selection
    if (!el.multiple) return el.value ? [el.value.toString().toLowerCase()] : [];
    return Array.from(el.selectedOptions || []).map(o => (o.value || '').toString().toLowerCase()).filter(v => v !== '');
  }

  function applyFilters(){
    const roles = getSelectedOptions(filters.role);
    const statuses = getSelectedOptions(filters.status);
    const depts = getSelectedOptions(filters.dept);
    const managers = getSelectedOptions(filters.manager);
    const ages = getSelectedOptions(filters.age);
    const genders = getSelectedOptions(filters.gender);
    const nats = getSelectedOptions(filters.nat);
    const dateFromVal = (filters.dateFrom && filters.dateFrom.value) ? filters.dateFrom.value : '';
    const dateToVal = (filters.dateTo && filters.dateTo.value) ? filters.dateTo.value : '';

    Array.from(tbody.querySelectorAll('.app-row')).forEach(row => {
      const meta = {
        positionTitle: (row.dataset.positionTitle || '').toString().toLowerCase(),
        statusId: (row.dataset.statusId || '').toString(),
        statusName: (row.querySelector('td:nth-child(3) .pill') || {}).textContent || '',
        department: ((row.querySelector('td:nth-child(5)') || {}).textContent || '').toString().toLowerCase(),
        manager: (row.dataset.manager || '').toString().toLowerCase(),
        date: (row.dataset.date || '').toString(),
        age: (row.dataset.age || '').toString(),
        gender: (row.dataset.gender || '').toString().toLowerCase(),
        nationality: (row.dataset.nationality || '').toString().toLowerCase()
      };

      // role filter (match position title)
      let ok = true;
      if (roles.length) {
        ok = roles.some(r => meta.positionTitle.indexOf(r) !== -1 || meta.positionTitle === r);
      }
      // status filter (match by status_id)
      if (ok && statuses.length) {
        ok = statuses.some(s => String(meta.statusId) === String(s));
      }
      // department filter
      if (ok && depts.length) {
        const dept = (meta.department || '').toString().toLowerCase();
        ok = depts.some(d => dept.indexOf(d) !== -1 || dept === d);
      }
      // manager filter (uses position manager name)
      if (ok && managers.length) {
        ok = managers.some(m => (meta.manager || '').toString().indexOf(m) !== -1 || (meta.manager||'') === m);
      }
      // age filter
      if (ok && ages.length) {
        ok = ages.some(a => (meta.age || '') === String(a));
      }
      // gender filter
      if (ok && genders.length) {
        ok = genders.some(g => (meta.gender || '').toString().indexOf(g) !== -1 || (meta.gender||'') === g);
      }
      // nationality filter
      if (ok && nats.length) {
        ok = nats.some(n => (meta.nationality || '').toString().indexOf(n) !== -1 || (meta.nationality||'') === n);
      }
      // date filter (range: from/to on YYYY-MM-DD as stored in data-date)
      if (ok && dateFromVal && meta.date) {
        ok = ok && (meta.date >= dateFromVal);
      }
      if (ok && dateToVal && meta.date) {
        ok = ok && (meta.date <= dateToVal);
      }

      row.style.display = ok ? '' : 'none';
    });
  }

  // hook events
  Object.values(filters).forEach(f => {
    if (!f) return;
    f.addEventListener('change', applyFilters);
    f.addEventListener('input', applyFilters);
  });

  // select all checkbox
  const selectAll = document.getElementById('selectAllApplicants');
  if (selectAll) {
    selectAll.addEventListener('change', function(){
      const checked = !!this.checked;
      Array.from(tbody.querySelectorAll('.app-select')).forEach(cb => { cb.checked = checked; try { cb.dispatchEvent(new Event('change',{bubbles:true})); } catch(e){} });
    });
  }

  // Clear filters button: reset all header controls and reapply
  const clearBtn = document.getElementById('clearFilters');
  if (clearBtn) {
    clearBtn.addEventListener('click', function(e){
      try {
        // reset selects and inputs
        ['f-role','f-status','f-dept','f-team','f-manager','f-age','f-gender','f-nat'].forEach(id => {
          const el = document.getElementById(id);
          if (!el) return;
          if (el.tagName === 'SELECT') { el.value = ''; }
          else if (el.type === 'checkbox') { el.checked = false; }
          try { el.dispatchEvent(new Event('change',{bubbles:true})); } catch(e){}
        });
        const name = document.getElementById('f-name'); if (name) { name.value = ''; name.dispatchEvent(new Event('input',{bubbles:true})); }
        const from = document.getElementById('f-date-from'); if (from) { from.value = ''; from.dispatchEvent(new Event('change',{bubbles:true})); }
        const to = document.getElementById('f-date-to'); if (to) { to.value = ''; to.dispatchEvent(new Event('change',{bubbles:true})); }
        // uncheck select all
        if (selectAll) { selectAll.checked = false; }
        applyFilters();
      } catch(err) { console.error('clearFilters failed', err); }
    });
  }

  // expose helper to get selected applicant IDs
  window.getSelectedApplicantIds = function(){
    return Array.from(document.querySelectorAll('.app-select:checked')).map(cb => cb.dataset.id).filter(Boolean);
  };

})();
</script>

<!-- Create Applicant Modal (updated: department -> team -> position, removed full name/email/phone) -->
<div id="createApplicantModal" class="modal-overlay">
  <div class="modal-card" role="dialog" aria-modal="true">
    <button type="button" id="closeCreateApplicant" class="modal-close">×</button>
    <h3>Create Applicant</h3>
    <form id="createApplicantForm" method="post" action="create_applicant.php" enctype="multipart/form-data">
      <!-- Department -->
      <div class="field">
        <label>Department</label>
        <select id="ap_department" name="department_id" class="modal-input" required>
          <option value="">-- Select Department --</option>
          <?php foreach ($departments as $did => $d): ?>
            <option value="<?= (int)$did ?>"><?= htmlspecialchars($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Team (populated after department) -->
      <div class="field">
        <label>Team</label>
        <select id="ap_team" name="team_id" class="modal-input" disabled required>
          <option value="">Select department first</option>
        </select>
      </div>

      <!-- Position (populated after team) -->
      <div class="field">
        <label>Open Position</label>
        <select id="ap_position" name="position_id" class="modal-input" disabled required>
          <option value="">Select team first</option>
        </select>
      </div>

      <!-- Manager (auto-filled from team) -->
      <div class="field">
        <label>Manager</label>
        <input id="ap_manager_display" type="text" disabled class="modal-input" value="Unassigned">
        <input type="hidden" name="manager_name" id="ap_manager_name" value="">
      </div>

      <!-- Resume upload (unchanged) -->
      <div class="field">
        <label>Upload PDF(s)</label>
        <input id="ap_files" name="uploads[]" type="file" accept="application/pdf" multiple required class="modal-input">
        <div class="small-muted">Select multiple PDFs. One applicant record will be created per PDF.</div>
      </div>

      <div class="modal-actions">
        <button type="button" id="cancelCreateApplicant" class="btn btn-primary">Cancel</button>
        <button type="submit" class="btn primary btn-primary">Create</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  const positions = window._modalPositions || [];
  const teamsByDept = window._teamsByDept || {};

  const deptEl = document.getElementById('ap_department');
  const teamEl = document.getElementById('ap_team');
  const posEl  = document.getElementById('ap_position');
  const managerDisplay = document.getElementById('ap_manager_display');
  const managerHidden = document.getElementById('ap_manager_name');
  const fileInput = document.getElementById('ap_files');

  if (fileInput) fileInput.multiple = true;

  // build a lookup of teams by name -> manager (search all teams lists)
  const teamsMapByName = {};
  Object.values(teamsByDept).forEach(arr => {
    arr.forEach(t => {
      const name = (t.name || '').toString().trim();
      if (name) teamsMapByName[name] = t.manager_name || t.manager || '';
    });
  });

  // build unique departments from positions (department names directly from positions table)
  const deptSet = new Set();
  positions.forEach(p => {
    const d = (p.department || '').toString().trim();
    if (d) deptSet.add(d);
  });

  // populate department select (append any missing departments discovered in positions)
  if (deptEl) {
    // ensure placeholder exists
    if (!deptEl.querySelector('option')) deptEl.innerHTML = '<option value="">-- Select Department --</option>';
    // collect existing option values and texts to avoid duplicates (keep server-provided id-based options)
    const existing = new Set();
    Array.from(deptEl.options).forEach(o => {
      const v = ((o.value || '') + '').toString().trim();
      const t = ((o.textContent || '') + '').toString().trim();
      if (v) existing.add(v);
      if (t) existing.add(t);
    });
    Array.from(deptSet).sort().forEach(dname => {
      if (!existing.has(dname)) {
        const o = document.createElement('option');
        o.value = dname;
        o.textContent = dname;
        deptEl.appendChild(o);
        existing.add(dname);
      }
    });
  }

  function resetTeams() {
    if (teamEl) { teamEl.innerHTML = '<option value=\"\">Select department first</option>'; teamEl.disabled = true; }
    if (posEl) { posEl.innerHTML = '<option value=\"\">Select team first</option>'; posEl.disabled = true; }
    if (managerDisplay) { managerDisplay.value = 'Unassigned'; managerHidden.value = ''; }
  }

  // when department selected: support both server-populated department IDs (teamsByDept) and client-side name-based departments
  if (deptEl) {
    deptEl.addEventListener('change', function(){
      resetTeams();
      const rawVal = (this.value || '').toString().trim();
      if (!rawVal) return;

      // If server provided department IDs (teamsByDept keyed by id), prefer that mapping.
      if (teamsByDept && teamsByDept.hasOwnProperty(rawVal)) {
        const tarr = teamsByDept[rawVal] || [];
        if (!tarr.length) {
          if (teamEl) { teamEl.innerHTML = '<option value="">No teams for this department</option>'; teamEl.disabled = true; }
          return;
        }
        teamEl.innerHTML = '';
        const opt0 = document.createElement('option'); opt0.value=''; opt0.textContent='-- Select Team --'; teamEl.appendChild(opt0);
        tarr.forEach(t => {
          const o = document.createElement('option');
          o.value = t.id;
          o.textContent = t.name;
          o.dataset.managerName = t.manager_name || '';
          o.dataset.managerId = t.manager_id || '';
          teamEl.appendChild(o);
        });
        teamEl.disabled = false;
        return;
      }

      // Otherwise assume department value is the department NAME and derive teams from positions array
      const selectedDept = rawVal;
      // get unique team names for this department
      const teamNames = [];
      const seen = new Set();
      positions.forEach(p => {
        if (((p.department||'')+'').trim() === selectedDept) {
          const tname = ((p.team_name || p.team || '') + '').trim();
          if (tname && !seen.has(tname)) { seen.add(tname); teamNames.push(tname); }
        }
      });

      if (!teamNames.length) {
        if (teamEl) { teamEl.innerHTML = '<option value="">No teams for this department</option>'; teamEl.disabled = true; }
        return;
      }

      // populate team select (name-based)
      teamEl.innerHTML = '';
      const opt0 = document.createElement('option'); opt0.value=''; opt0.textContent='-- Select Team --'; teamEl.appendChild(opt0);
      teamNames.sort().forEach(tn => {
        const o = document.createElement('option');
        o.value = tn;
        o.textContent = tn;
        // attach manager from teamsMapByName if available
        o.dataset.managerName = teamsMapByName[tn] || '';
        teamEl.appendChild(o);
      });
      teamEl.disabled = false;
    });
  }

  // when team selected: populate positions for that department+team and set manager
  if (teamEl) {
    teamEl.addEventListener('change', function(){
      const sel = this.selectedOptions[0];
      if (!sel || !sel.value) {
        if (posEl) { posEl.innerHTML = '<option value="">Select team first</option>'; posEl.disabled = true; }
        managerDisplay.value = 'Unassigned'; managerHidden.value = '';
        return;
      }
      // determine teamName (team select may contain either team NAME or server-provided team ID)
      let teamName = sel.value;
      // If department select uses server-side IDs and teamsByDept mapping exists, try to resolve id -> name
      try {
        const deptKey = (deptEl.value || '').toString().trim();
        if (teamsByDept && teamsByDept.hasOwnProperty(deptKey)) {
          const list = teamsByDept[deptKey] || [];
          const found = list.find(t => String(t.id) === String(sel.value));
          if (found) {
            teamName = (found.name || '').toString().trim();
            // ensure manager dataset present
            sel.dataset.managerName = sel.dataset.managerName || (found.manager_name || found.manager || '');
          }
        }
      } catch (e) { /* ignore resolution errors */ }

      // set manager (use dataset.managerName or lookup by teamName)
      const mgr = sel.dataset.managerName || teamsMapByName[teamName] || '';
      managerDisplay.value = mgr || 'Unassigned';
      managerHidden.value = mgr || '';

      // populate positions for selected department and team
      const deptName = (deptEl.value || '').toString().trim();
      // Include all positions that match the selected department and team (no status filtering)
      const matches = positions.filter(p => {
        const pDept = ((p.department||'') + '').toString().trim();
        const pTeam = ((p.team_name||p.team||'') + '').toString().trim();
        return (pDept === deptName) && (pTeam === teamName);
      });

      // debug: show matching counts in console
      try { console.debug('positions match check', { deptName: deptName, teamName: teamName, matches: matches.length }); } catch(e){}

      // show a helpful placeholder while we build the real options
      posEl.innerHTML = '';
      if (!matches.length) {
        // show explicit no-results placeholder inside the select so users see why it's disabled
        const none = document.createElement('option'); none.value = ''; none.textContent = 'No open positions for this team'; none.disabled = true; none.selected = true;
        posEl.appendChild(none);
        posEl.disabled = true;
        return;
      }
      // show a summary placeholder that indicates how many positions are available
      const opt0 = document.createElement('option'); opt0.value=''; opt0.textContent = matches.length + ' positions available — select one'; opt0.disabled = true; opt0.selected = true; posEl.appendChild(opt0);
      matches.forEach(m => {
        const o = document.createElement('option');
        o.value = m.position_id || m.id || '';
        o.textContent = m.title || m.position_title || '';
        posEl.appendChild(o);
      });
      posEl.disabled = false;
    });
  }

  // preserve multiple attribute if modal reset happens
  document.querySelectorAll('[data-open-create-applicant]').forEach(btn=>{
    btn.addEventListener('click', ()=> setTimeout(()=>{ if (fileInput) fileInput.multiple = true; }, 30));
  });

  // ensure modal open handler resets safely and preserves multiple
  const openBtn = document.getElementById('openCreateApplicantBtn');
  const modal = document.getElementById('createApplicantModal');
  const closeBtn = document.getElementById('closeCreateApplicant');
  const cancelBtn = document.getElementById('cancelCreateApplicant');
  function openModal() {
    if (modal) modal.style.display = 'flex';
    const form = document.getElementById('createApplicantForm');
    if (form) form.reset();
    if (fileInput) fileInput.value = '';
    resetTeams();

    // If there are no departments with active/open positions, disable department select
    try {
      const deptSelect = document.getElementById('ap_department');
      const posSelect = document.getElementById('ap_position');
      const teamSelect = document.getElementById('ap_team');
      const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
      if (deptSelect) {
        // count real department options (exclude placeholder)
        const realOpts = Array.from(deptSelect.options).filter(o => (o.value || '').toString().trim() !== '');
        if (!realOpts.length) {
          deptSelect.disabled = true;
          if (posSelect) posSelect.disabled = true;
          if (teamSelect) teamSelect.disabled = true;
          if (submitBtn) submitBtn.disabled = true;
            try {
            if (window.Notify && typeof window.Notify.push === 'function') {
              window.Notify.push({ from: 'Applicants', message: 'No open positions available — cannot create applicants.', color: '#359cf6', duration: 8000 });
            } else {
              // fallback alert
              console.warn('No open positions available — cannot create applicants.');
            }
          } catch (e) { console.error(e); }
        } else {
          deptSelect.disabled = false;
          if (submitBtn) submitBtn.disabled = false;
        }
      }
    } catch (e) { console.error('openModal dept check', e); }
  }
  function closeModal(){ if (modal) modal.style.display = 'none'; }
  openBtn && openBtn.addEventListener('click', openModal);
  closeBtn && closeBtn.addEventListener('click', closeModal);
  cancelBtn && cancelBtn.addEventListener('click', closeModal);
  if (modal) modal.addEventListener('click', (e)=> { if (e.target === modal) closeModal(); });

  // debug on submit: log files count
  const form = document.getElementById('createApplicantForm');
  if (form) form.addEventListener('submit', function(){ if (fileInput && fileInput.files) console.log('Submitting files:', fileInput.files.length); });
})();
</script>

<script>
(function(){
  const form = document.getElementById('createApplicantForm');
  if (!form) return;

  // create status container
  const statusEl = document.createElement('div');
  statusEl.id = 'aiParsingStatus';
  form.appendChild(statusEl);

  form.addEventListener('submit', async function(e){
    e.preventDefault();
    statusEl.textContent = 'Uploading...';

    function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    const fd = new FormData(form);
    try {
      const res = await fetch('create_applicant.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      });

      // If server redirected to applicants.php (normal non-AJAX flow), treat as success and follow
      if (res.redirected || (res.url && res.url.indexOf('applicants.php') !== -1)) {
        statusEl.innerHTML = '<div class="text-success">Applicant created — reloading...</div>';
        setTimeout(function(){ window.location.href = res.url || 'applicants.php'; }, 800);
        return;
      }

      const text = await res.text();
      // try parse JSON first (some endpoints may return JSON)
      try {
        const json = JSON.parse(text);
        if (json && (json.success || json.applicant_id || json.position_id)) {
          statusEl.innerHTML = '<div class="text-success">Applicant created — reloading...</div>';
          setTimeout(function(){ window.location.reload(); }, 800);
          return;
        }
        if (json && json.error) {
          statusEl.innerHTML = '<div class="text-error">' + esc(json.error) + '</div>';
          setTimeout(function(){ statusEl.textContent = ''; }, 5000);
          return;
        }
      } catch(parseErr) {
        // not JSON — show server HTML/text response sanitized for debugging
        statusEl.innerHTML = '<div class="text-error">Server response:</div><pre class="server-response">' + esc(text) + '</pre>';
        setTimeout(function(){ statusEl.textContent = ''; }, 8000);
        return;
      }

      // fallback
      statusEl.textContent = 'Unexpected server response';
      setTimeout(function(){ statusEl.textContent = ''; }, 5000);

    } catch (err) {
      statusEl.textContent = 'Upload failed';
      console.error(err);
    }
  });
})();
</script>

<script>
// replace the complex filtering — simply populate positions by team name
(function(){
  const teamEl = document.getElementById('ap_team');
  const posEl  = document.getElementById('ap_position');
  if (!teamEl || !posEl) return;

  function populatePositionsForTeam(selectedTeamName){
    posEl.innerHTML = '';
    const list = (window._positionsByTeam && window._positionsByTeam[selectedTeamName]) || [];
    // Only include positions whose status is in the allowed set (case-insensitive)
    const allowed = new Set(['hiring active','interviews active','applicants active']);
    const filtered = list.filter(p => allowed.has(((p.status||'') + '').toString().trim().toLowerCase()));
    if (!filtered.length) {
      const none = document.createElement('option'); none.value = ''; none.textContent = 'No Active Hiring positions available for this team'; none.disabled = true; none.selected = true;
      posEl.appendChild(none);
      posEl.disabled = true;
      return;
    }
    // show placeholder summary inside select
    const placeholder = document.createElement('option'); placeholder.value = ''; placeholder.textContent = filtered.length + ' positions available — select one'; placeholder.disabled = true; placeholder.selected = true; posEl.appendChild(placeholder);
    filtered.forEach(p => {
      const opt = document.createElement('option');
      opt.value = p.id;
      opt.textContent = p.title;
      posEl.appendChild(opt);
    });
    posEl.disabled = false;
  }

  // on page load, if a team is already selected, populate
  const initialTeam = teamEl.options[teamEl.selectedIndex] ? teamEl.options[teamEl.selectedIndex].text.trim() : '';
  if (initialTeam) populatePositionsForTeam(initialTeam);

  teamEl.addEventListener('change', function(){
    const teamName = teamEl.options[teamEl.selectedIndex] ? teamEl.options[teamEl.selectedIndex].text.trim() : '';
    populatePositionsForTeam(teamName);
  });
})();
</script>

<!-- Applicant viewer (modal) -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  function ensureViewer() {
    let ov = document.getElementById('applicantViewerModal');
    if (ov) return ov;

    ov = document.createElement('div');
    ov.id = 'applicantViewerModal';
    ov.className = 'modal-overlay';
    ov.setAttribute('aria-hidden', 'true');
    ov.innerHTML = '<div class="modal-card"><div id="appViewerContent"></div></div>';
    document.body.appendChild(ov);

    // inline sizing to ensure predictable layout
    try {
      const mc = ov.querySelector('.modal-card');
      // Styling for the modal-card is provided by CSS (#applicantViewerModal .modal-card)
      // so we avoid applying inline styles here.
    } catch (e) { console.warn('apply modal inline styles failed', e); }

    // close button
    ov.addEventListener('click', function (e) {
      if (e.target && e.target.closest && e.target.closest('.modal-close-x')) {
        closeViewer();
      }
    });

    // close on ESC
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && ov.classList.contains('show')) {
        closeViewer();
      }
    });

    return ov;
  }

  function openViewer() {
    const ov = ensureViewer();
    ov.classList.add('show');
    ov.setAttribute('aria-hidden', 'false');
    ov.style.display = 'flex';
  }

  function closeViewer() {
    const ov = document.getElementById('applicantViewerModal');
    if (!ov) return;
    ov.classList.remove('show');
    ov.setAttribute('aria-hidden', 'true');
    try { ov.style.display = 'none'; } catch (e) {}
    const c = document.getElementById('appViewerContent');
    if (c) c.innerHTML = '';
  }

  async function openApplicant(id) {
    const ov = ensureViewer();
    const modalContent = ov.querySelector('#appViewerContent');
    if (modalContent) modalContent.innerHTML = '<div class="loading-placeholder">Loading...</div>';
    openViewer();
    try {
      const res = await fetch('get_applicant.php?applicant_id=' + encodeURIComponent(id), { credentials: 'same-origin' });
      const html = await res.text();
      if (modalContent) modalContent.innerHTML = html;
      // execute inline scripts returned in fragment
      if (modalContent) Array.from(modalContent.querySelectorAll('script')).forEach(function (s) {
        const ns = document.createElement('script');
        if (s.src) {
          ns.src = s.src; ns.async = false; document.body.appendChild(ns); ns.onload = function () { ns.remove(); };
        } else {
          ns.text = s.textContent || s.innerText || '';
          document.body.appendChild(ns);
          ns.remove();
        }
      });
    } catch (e) {
      console.error('openApplicant failed', e);
      if (modalContent) modalContent.innerHTML = '<div class="muted">Unable to load applicant details</div>';
    }
  }

  // Delegated click handler for table rows (.app-row)
  // Exclude clicks on checkbox/select column so users can select rows
  document.addEventListener('click', function (e) {
    try {
      // If click is inside a checkbox, col-select cell, or the checkbox itself, ignore it
      if (e.target && (e.target.type === 'checkbox' || e.target.classList.contains('app-select') || e.target.closest('.col-select'))) {
        return;
      }
      const row = e.target && e.target.closest && e.target.closest('.app-row');
      if (!row) return;
      const id = row.getAttribute('data-applicant-id') || row.getAttribute('data-id') || row.dataset.applicantId;
      if (!id) return;
      e.preventDefault();
      openApplicant(id);
    } catch (err) {
      console.error('applicants table click handler error', err);
    }
  });
});
</script>

<script>
// Bulk update status UI + flow
(function(){
  function getEl(id){ return document.getElementById(id); }

  // Ensure notify.js is loaded and Notify.push is available.
  function ensureNotify(){
    if (window.Notify && typeof window.Notify.push === 'function') return Promise.resolve();
    return new Promise((resolve) => {
      try {
        var s = document.createElement('script');
        s.src = 'assets/js/notify.js';
        s.async = true;
        s.onload = function(){ setTimeout(resolve, 50); };
        s.onerror = function(){ console.warn('Failed to load notify.js'); resolve(); };
        document.head.appendChild(s);
      } catch (e) { console.warn('ensureNotify error', e); resolve(); }
    });
  }

  function openModal(selectedIds){
    const modal = getEl('bulkUpdateModal');
    if (!modal) return;
    const countText = getEl('bulkCountText');
    const statusSelect = getEl('bulkStatusSelect');
    const confirmBtn = getEl('bulkUpdateConfirm');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden','false');
    if (countText) countText.textContent = `Selected ${selectedIds.length} applicants.`;
    if (statusSelect) statusSelect.value = '';
    if (confirmBtn) confirmBtn.disabled = false;
  }

  function closeModal(){
    const modal = getEl('bulkUpdateModal');
    if (!modal) return; modal.style.display='none'; modal.setAttribute('aria-hidden','true');
  }

  const bulkBtn = getEl('bulkUpdateStatusBtn');
  if (bulkBtn) {
    // Update visual state of bulk button based on selection count.
    function updateBulkBtnState() {
      try {
        const ids = window.getSelectedApplicantIds ? window.getSelectedApplicantIds() : Array.from(document.querySelectorAll('.app-select:checked')).map(cb => cb.dataset.id).filter(Boolean);
        if (!ids || ids.length === 0) {
          bulkBtn.classList.add('is-disabled');
          bulkBtn.setAttribute('aria-disabled', 'true');
        } else {
          bulkBtn.classList.remove('is-disabled');
          bulkBtn.setAttribute('aria-disabled', 'false');
        }
      } catch (e) { console.warn('updateBulkBtnState error', e); }
    }

    // Hook selection checkbox changes to update button state
    document.addEventListener('change', function(e){
      try {
        if (e.target && (e.target.classList && e.target.classList.contains('app-select'))) updateBulkBtnState();
        if (e.target && e.target.id === 'selectAllApplicants') updateBulkBtnState();
      } catch (err) { console.error(err); }
    }, true);

    // Run once on init
    try { updateBulkBtnState(); } catch(e){}

    bulkBtn.addEventListener('click', function(ev){
      // If visually disabled, do nothing (preserve hover/jump animation but prevent action).
      if (bulkBtn.getAttribute('aria-disabled') === 'true' || bulkBtn.classList.contains('is-disabled')) {
        ev && ev.preventDefault && ev.preventDefault();
        return;
      }
      const ids = window.getSelectedApplicantIds ? window.getSelectedApplicantIds() : [];
      if (!ids.length) return;
      openModal(ids);
    });
  }

  // close handlers for cancel/close buttons (modal may be added later)
  document.addEventListener('click', function(e){
    const target = e.target;
    if (!target) return;
    if (target.id === 'bulkUpdateClose' || target.id === 'bulkUpdateCancel') closeModal();
  });

  // confirm button action (resolve elements lazily)
  const confirmBtn = getEl('bulkUpdateConfirm');
  if (confirmBtn) {
    confirmBtn.addEventListener('click', async function(){
      const statusSelect = getEl('bulkStatusSelect');
      const statusId = parseInt((statusSelect && statusSelect.value) || 0, 10);
      const ids = window.getSelectedApplicantIds ? window.getSelectedApplicantIds() : [];
      if (!ids.length) { closeModal(); return; }
      if (!statusId) {
        ensureNotify().then(function(){ if (window.Notify && Notify.push) Notify.push({ from:'Applicants', message: 'Select a status', color:'#dc2626' }); else console.warn('Notify.push not available: Select a status'); });
        return;
      }
      confirmBtn.disabled = true;
      const countText = getEl('bulkCountText');
      if (countText) countText.textContent = `Applying status to ${ids.length} applicants...`;

      let updated = 0, skipped = 0, failed = 0;
      for (let i = 0; i < ids.length; i++) {
        const aid = ids[i];
        try {
          const fd = new FormData();
          fd.append('applicant_id', aid);
          fd.append('status_id', statusId);
          fd.append('status_reason', 'Bulk update');
          const res = await fetch('update_applicant.php', { method: 'POST', body: fd, credentials: 'same-origin' });
          const json = await res.json().catch(()=>({ ok:false, error:'Invalid response' }));
          if (json && json.ok) {
            if (json.affected_rows && parseInt(json.affected_rows,10) > 0) {
              updated++;
              try {
                const row = document.querySelector('.app-row[data-applicant-id="' + aid + '"]');
                if (row) {
                  row.dataset.statusId = statusId;
                  const pill = row.querySelector('td:nth-child(3) .pill');
                  if (pill) pill.textContent = (json.applicant && json.applicant.status_name) ? json.applicant.status_name : pill.textContent;
                }
              } catch(e){}
            } else {
              skipped++;
            }
          } else {
            if (json && json.error && /(Transition not allowed|already in this status)/i.test(json.error)) skipped++;
            else failed++;
          }
        } catch (err) {
          console.error('bulk update failed for', aid, err);
          failed++;
        }
      }

      closeModal();
      const total = ids.length;
      const msg = `Bulk update complete — updated ${updated} of ${total} selected.` + (skipped ? ` Skipped: ${skipped}.` : '') + (failed ? ` Failed: ${failed}.` : '');
      ensureNotify().then(function(){ if (window.Notify && Notify.push) Notify.push({ from:'Applicants', message: msg, color: failed ? '#dc2626' : '#10b981', duration: 8000 }); else console.warn('Notify.push not available for bulk summary'); });
    });
  }

  // click outside modal to close
  document.addEventListener('click', function(e){
    const modal = getEl('bulkUpdateModal');
    if (!modal) return; if (e.target === modal) closeModal();
  });

})();
</script>

<script>
// Sortable headers and accent-colored scrollbar
document.addEventListener('DOMContentLoaded', function(){
  const table = document.querySelector('.widget table');
  if (!table) return;
  const thead = table.tHead;
  const tbody = table.tBodies[0];
  if (!thead || !tbody) return;

  // add sortable class to relevant headers and hook click
  Array.from(thead.querySelectorAll('th')).forEach((th, idx) => {
    if (th.classList.contains('col-select') || th.classList.contains('sticky-actions')) return;
    th.classList.add('sortable');
    th.dataset.colIndex = idx;
    th.addEventListener('click', function(){
      const cur = th.getAttribute('data-sort');
      const next = cur === 'asc' ? 'desc' : 'asc';
      thead.querySelectorAll('th.sortable').forEach(h => h.removeAttribute('data-sort'));
      th.setAttribute('data-sort', next);
      sortTableByColumn(tbody, idx, next === 'asc');
    });
  });

  function cellText(row, colIndex){
    const cells = row.children;
    if (!cells || cells.length <= colIndex) return '';
    const head = thead.rows[0].cells[colIndex];
    const headText = head ? (head.textContent||'').toLowerCase() : '';
    if (headText.includes('id')) return String(row.dataset.applicantId || row.getAttribute('data-applicant-id') || (cells[colIndex].textContent||'')).replace(/[^0-9]/g,'') || '0';
    if (headText.includes('status')) return row.dataset.statusId || (cells[colIndex].textContent||'');
    if (headText.includes('position')) return row.dataset.positionTitle || (cells[colIndex].textContent||'').toLowerCase();
    if (headText.includes('department') || headText.includes('team') || headText.includes('name') || headText.includes('degree') || headText.includes('nationality')) return (cells[colIndex].textContent||'').toString().toLowerCase();
    if (headText.includes('age') || headText.includes('years')) return parseFloat((cells[colIndex].textContent||'').replace(/[^0-9\-\.]/g,'')) || 0;
    if (headText.includes('created')) return row.dataset.date || (cells[colIndex].textContent||'');
    return (cells[colIndex].textContent||'').toString().toLowerCase();
  }

  function sortTableByColumn(tbody, colIndex, asc){
    const rows = Array.from(tbody.querySelectorAll('tr'));
    rows.sort((a,b)=>{
      const va = cellText(a, colIndex);
      const vb = cellText(b, colIndex);
      const na = parseFloat(va);
      const nb = parseFloat(vb);
      if (!isNaN(na) && !isNaN(nb)) return asc ? na - nb : nb - na;
      if (/^\d{4}-\d{2}-\d{2}$/.test(va) && /^\d{4}-\d{2}-\d{2}$/.test(vb)) return asc ? (va>vb?1:-1) : (vb>va?1:-1);
      if (va === vb) return 0;
      return asc ? (va>vb?1:-1) : (vb>va?1:-1);
    });
    const frag = document.createDocumentFragment();
    rows.forEach(r=>frag.appendChild(r));
    tbody.appendChild(frag);
  }
});
</script>

<!-- Bulk Update Status Modal -->
<div id="bulkUpdateModal" class="modal-overlay" aria-hidden="true">
  <div class="modal-card" role="dialog" aria-modal="true">
    <button type="button" class="modal-close" id="bulkUpdateClose">×</button>
    <h3 id="bulkUpdateTitle">Bulk Update Applicant Status</h3>
    <div id="bulkUpdateBody">
      <p class="muted" id="bulkCountText">Preparing...</p>
      <div class="field">
        <label for="bulkStatusSelect">Move selected applicants to</label>
        <select id="bulkStatusSelect" class="modal-input">
          <option value="">-- Select status --</option>
          <?php foreach ($filter_statuses as $st): ?>
            <option value="<?= (int)$st['status_id'] ?>"><?= htmlspecialchars($st['status_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
        <div class="field" style="margin-top:12px;">
          <div class="muted">Note: If no valid transition exists for an applicant, that applicant will be skipped. Successful updates will be reported after the operation.</div>
        </div>
    </div>
    <div class="modal-actions">
      <button id="bulkUpdateCancel" class="btn">Cancel</button>
      <button id="bulkUpdateConfirm" class="btn primary btn-primary">Confirm</button>
    </div>
  </div>
</div>

</body> 
</html>
