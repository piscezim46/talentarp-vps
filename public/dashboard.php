<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Basic access check (adjust roles as needed)
if (!isset($_SESSION['user'])) {
  http_response_code(403);
  echo 'Access denied.';
  exit;
}
$user = $_SESSION['user'];
$role = strtolower($user['role'] ?? '');
$userDept = trim((string)($user['department'] ?? ''));

// If the current user is not admin but no department is present in the session,
// try to look it up from the `users` table (helps when session was populated
// without department on login). Only apply department-based filtering when
// we actually have a non-empty department value.
if ($role !== 'admin' && $userDept === '' && !empty($user['id'])) {
  try {
    $stmtDept = $conn->prepare('SELECT department FROM users WHERE id = ? LIMIT 1');
    if ($stmtDept) {
      $uid = (int)$user['id'];
      $stmtDept->bind_param('i', $uid);
      $stmtDept->execute();
      $resDept = $stmtDept->get_result();
      if ($resDept && ($rowDept = $resDept->fetch_assoc())) {
        $userDept = trim((string)($rowDept['department'] ?? ''));
        // Persist back to session for subsequent page loads
        $_SESSION['user']['department'] = $userDept;
      }
      $stmtDept->close();
    }
  } catch (Throwable $_) { /* ignore lookup failures */ }
}

// Page chrome
$activePage = 'dashboard';
$pageTitle = 'Dashboard';
if (file_exists(__DIR__ . '/../includes/header.php')) include __DIR__ . '/../includes/header.php';
if (file_exists(__DIR__ . '/../includes/navbar.php')) include __DIR__ . '/../includes/navbar.php';

// Resolve Open status id (fallback to 1)
$openStatusId = 1;
try {
  if ($res = $conn->query("SELECT status_id FROM positions_status WHERE LOWER(status_name) = 'open' LIMIT 1")) {
    if ($row = $res->fetch_assoc()) $openStatusId = (int)$row['status_id'];
    $res->free();
  }
} catch (Throwable $e) {
  // fallback to default 1 silently
}

// Build SQL to fetch recent positions (show recent entries regardless of status).
// This is more robust for dashboards where some positions may not have a status set.
$rows = [];
$totalOpen = 0;
$sql = "
  SELECT
    p.id,
    p.title,
    p.department,
    p.team,
    p.manager_name,
    p.openings,
    p.created_at,
    p.status_id,
    COALESCE(s.status_name, '') AS status_name
  FROM positions p
  LEFT JOIN positions_status s ON p.status_id = s.status_id
  WHERE 1 = 1
";
$types = '';
$params = [];

// Department filtering disabled (show all departments)
$useDept = false;

$sql .= " ORDER BY p.created_at DESC LIMIT 100";

try {
  $stmt = $conn->prepare($sql);
  if ($stmt) {
    if (!empty($params)) {
      $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
  }
  $totalOpen = count($rows);
} catch (Throwable $e) {
  // Render a soft error and continue with empty list
  echo '<div class="alert-error"><strong>Dashboard error:</strong> '.htmlspecialchars($e->getMessage()).'</div>';
}

// Helper: readable age
function ago_days($tsStr) {
  if (!$tsStr) return '—';
  $ts = strtotime($tsStr);
  if ($ts === false) return '—';
  $diff = time() - $ts;
  if ($diff < 3600) return max(1, (int)floor($diff/60)).'m';
  if ($diff < 86400) return (int)floor($diff/3600).'h';
  return (int)floor($diff/86400).'d';
}
?>
<?php
// Prepare applicant tickets and interview tickets data for dashboard cards.
// Rules: include tickets that are active by status (not terminal) OR created/interviewed within the last 30 days.
$monthAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
// Terminal status name keywords (lowercase) to consider a ticket non-active
$terminalApplicantNames = [ 'closed','rejected','hired','complete','completed' ];
$terminalInterviewNames = [ 'cancelled','completed' ];

$applicants = [];
try {
  // Include position title and team where available so dashboard can display richer info
  $sqlA = "SELECT a.applicant_id, a.full_name, a.status_id, COALESCE(s.status_name,'') AS status_name, a.created_at, a.position_id, COALESCE(p.title,'') AS position_title, COALESCE(p.team,'') AS team_name FROM applicants a LEFT JOIN applicants_status s ON a.status_id = s.status_id LEFT JOIN positions p ON a.position_id = p.id WHERE (LOWER(COALESCE(s.status_name,'')) NOT IN ('" . implode("','", array_map('addslashes', $terminalApplicantNames)) . "') ) OR a.created_at >= ? ORDER BY a.created_at DESC LIMIT 500";
  $stmtA = $conn->prepare($sqlA);
  if ($stmtA) {
    $stmtA->bind_param('s', $monthAgo);
    $stmtA->execute();
    $resA = $stmtA->get_result();
    while ($r = $resA->fetch_assoc()) $applicants[] = $r;
    $stmtA->close();
  }
} catch (Throwable $_) { }

$interviews = [];
try {
  $sqlI = "SELECT i.id, i.applicant_id, COALESCE(u.name,'') AS applicant_name, i.interview_datetime, i.status_id, COALESCE(s.name,'') AS status_name, i.created_at FROM interviews i LEFT JOIN interview_statuses s ON i.status_id = s.id LEFT JOIN applicants a ON a.applicant_id = i.applicant_id LEFT JOIN users u ON i.created_by = u.id WHERE (LOWER(COALESCE(s.name,'')) NOT IN ('" . implode("','", array_map('addslashes', $terminalInterviewNames)) . "') ) OR (i.interview_datetime >= ?) ORDER BY i.interview_datetime DESC LIMIT 500";
  $stmtI = $conn->prepare($sqlI);
  if ($stmtI) {
    $stmtI->bind_param('s', $monthAgo);
    $stmtI->execute();
    $resI = $stmtI->get_result();
    while ($r = $resI->fetch_assoc()) $interviews[] = $r;
    $stmtI->close();
  }
} catch (Throwable $_) { }

// Helper counts (active by status OR within timeframe will be computed client-side)
$applicantCount = count($applicants);
$interviewCount = count($interviews);
?>
<?php
// Compute counts per timeframe (Today / Last Week / Last Month)
$todayStart = date('Y-m-d 00:00:00');
$weekAgo = date('Y-m-d H:i:s', strtotime('-7 days'));
$monthAgo = date('Y-m-d H:i:s', strtotime('-30 days'));

$ranges = [ 'today' => $todayStart, 'week' => $weekAgo, 'month' => $monthAgo ];

$terminalApplicantNames = [ 'closed','rejected','hired','complete','completed' ];
$terminalInterviewNames = [ 'cancelled','completed' ];

$positionCounts = ['today'=>0,'week'=>0,'month'=>0];
$applicantCounts = ['today'=>0,'week'=>0,'month'=>0];
$interviewCounts = ['today'=>0,'week'=>0,'month'=>0];

// Positions: count by creation date within the timeframe
foreach ($ranges as $k => $start) {
  try {
    $sqlP = "SELECT COUNT(*) AS c FROM positions p WHERE p.created_at >= ?";
    $useDept = false;
    $stmtP = $conn->prepare($sqlP);
    if ($stmtP) {
      $stmtP->bind_param('s', $start);
      $stmtP->execute();
      $r = $stmtP->get_result()->fetch_assoc();
      $positionCounts[$k] = isset($r['c']) ? (int)$r['c'] : 0;
      $stmtP->close();
    }
  } catch (Throwable $_) { $positionCounts[$k] = 0; }
}

// Applicants: count by creation date within the timeframe
foreach ($ranges as $k => $start) {
  try {
    $sqlA = "SELECT COUNT(*) AS c FROM applicants a WHERE a.created_at >= ?";
    $stmtA = $conn->prepare($sqlA);
    if ($stmtA) {
      $stmtA->bind_param('s', $start);
      $stmtA->execute();
      $r = $stmtA->get_result()->fetch_assoc();
      $applicantCounts[$k] = isset($r['c']) ? (int)$r['c'] : 0;
      $stmtA->close();
    }
  } catch (Throwable $_) { $applicantCounts[$k] = 0; }
}

// Interviews: count by interview date within the timeframe
foreach ($ranges as $k => $start) {
  try {
    $sqlI = "SELECT COUNT(*) AS c FROM interviews i WHERE i.interview_datetime >= ?";
    $stmtI = $conn->prepare($sqlI);
    if ($stmtI) {
      $stmtI->bind_param('s', $start);
      $stmtI->execute();
      $r = $stmtI->get_result()->fetch_assoc();
      $interviewCounts[$k] = isset($r['c']) ? (int)$r['c'] : 0;
      $stmtI->close();
    }
  } catch (Throwable $_) { $interviewCounts[$k] = 0; }
}

// Additional aggregates for the requested dashboard
// Positions overview
$totalPositions = 0; $activePositions = 0; $positionsInApproval = 0; $closedPositions = 0;
try {
  $q = 'SELECT COUNT(*) AS c FROM positions';
  $r = $conn->query($q); if ($r) { $row = $r->fetch_assoc(); $totalPositions = (int)($row['c'] ?? 0); $r->free(); }

  // Active = positions_status = 'open'
  $useDept = false;
  $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM positions p LEFT JOIN positions_status s ON p.status_id = s.status_id WHERE LOWER(COALESCE(s.status_name,'')) = 'open'");
  if ($stmt) {
    $stmt->execute(); $res = $stmt->get_result(); $r = $res->fetch_assoc(); $activePositions = (int)($r['c'] ?? 0); $stmt->close(); }

  // In Approval = name contains 'approval'
  $useDept = false;
  $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM positions p LEFT JOIN positions_status s ON p.status_id = s.status_id WHERE LOWER(COALESCE(s.status_name,'')) LIKE '%approval%'");
  if ($stmt) { $stmt->execute(); $res = $stmt->get_result(); $r = $res->fetch_assoc(); $positionsInApproval = (int)($r['c'] ?? 0); $stmt->close(); }

  // Closed: common keywords
  $useDept = false;
  $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM positions p LEFT JOIN positions_status s ON p.status_id = s.status_id WHERE LOWER(COALESCE(s.status_name,'')) IN ('closed','filled','cancelled')");
  if ($stmt) { $stmt->execute(); $res = $stmt->get_result(); $r = $res->fetch_assoc(); $closedPositions = (int)($r['c'] ?? 0); $stmt->close(); }
} catch (Throwable $_) {}

// Applicants overview counts per stage
$totalApplicants = 0; $screeningPending = 0; $shortlisted = 0; $hrInterviews = 0; $managerInterviews = 0; $rejected = 0; $hired = 0;
try {
  $r = $conn->query('SELECT COUNT(*) AS c FROM applicants'); if ($r) { $totalApplicants = (int)(($r->fetch_assoc())['c'] ?? 0); $r->free(); }
  $get = function($pattern){ global $conn; $sql = "SELECT COUNT(*) AS c FROM applicants a LEFT JOIN applicants_status s ON a.status_id = s.status_id WHERE LOWER(COALESCE(s.status_name,'')) LIKE ?"; $stmt = $conn->prepare($sql); if (!$stmt) return 0; $like = '%' . $pattern . '%'; $stmt->bind_param('s', $like); $stmt->execute(); $res = $stmt->get_result(); $r = $res->fetch_assoc(); $stmt->close(); return (int)($r['c'] ?? 0); };
  $screeningPending = $get('screen');
  $shortlisted = $get('shortlist');
  $hrInterviews = $get('hr interview');
  if (!$hrInterviews) $hrInterviews = $get('hr_interview');
  $managerInterviews = $get('manager interview');
  if (!$managerInterviews) $managerInterviews = $get('manager_interview');
  $rejected = $get('reject');
  $hired = $get('hire');
} catch (Throwable $_) {}

// Interviews summary
$totalInterviews = 0; $todaysInterviews = 0; $weekInterviews = 0;
try {
  $r = $conn->query('SELECT COUNT(*) AS c FROM interviews'); if ($r) { $totalInterviews = (int)(($r->fetch_assoc())['c'] ?? 0); $r->free(); }
  $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM interviews WHERE DATE(interview_datetime) = CURDATE()"); if ($stmt) { $stmt->execute(); $r = $stmt->get_result()->fetch_assoc(); $todaysInterviews = (int)($r['c'] ?? 0); $stmt->close(); }
  $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM interviews WHERE interview_datetime >= DATE_SUB(NOW(), INTERVAL 7 DAY)"); if ($stmt) { $stmt->execute(); $r = $stmt->get_result()->fetch_assoc(); $weekInterviews = (int)($r['c'] ?? 0); $stmt->close(); }
} catch (Throwable $_) {}

// Activity feed: merge recent events from positions (created), applicants_status_history, interviews (created)
$events = [];
try {
  // Positions created
  $sql = "SELECT p.created_at AS ts, COALESCE(u.name,'System') AS user_name, CONCAT('New position created: ', p.title) AS message FROM positions p LEFT JOIN users u ON p.created_by = u.id ORDER BY p.created_at DESC LIMIT 10";
  $res = $conn->query($sql); if ($res) { while ($r = $res->fetch_assoc()) $events[] = $r; $res->free(); }
  // Applicant status history
  $sql = "SELECT h.updated_at AS ts, COALESCE(u.name,'System') AS user_name, CONCAT('Applicant #', h.applicant_id, ' moved to ', COALESCE(s.status_name,'')) AS message, h.reason FROM applicants_status_history h LEFT JOIN users u ON h.updated_by = u.id LEFT JOIN applicants_status s ON h.status_id = s.status_id ORDER BY h.updated_at DESC LIMIT 10";
  $res = $conn->query($sql); if ($res) { while ($r = $res->fetch_assoc()) $events[] = $r; $res->free(); }
  // Interviews created/scheduled
  $sql = "SELECT i.created_at AS ts, COALESCE(u.name,'System') AS user_name, CONCAT('Interview scheduled for Applicant #', i.applicant_id, ' @ ', COALESCE(i.interview_datetime,'')) AS message FROM interviews i LEFT JOIN users u ON i.created_by = u.id ORDER BY i.created_at DESC LIMIT 10";
  $res = $conn->query($sql); if ($res) { while ($r = $res->fetch_assoc()) $events[] = $r; $res->free(); }
} catch (Throwable $_) {}

// Sort events by timestamp desc and keep top 10
usort($events, function($a,$b){ $ta = strtotime($a['ts']); $tb = strtotime($b['ts']); return $tb <=> $ta; });
$events = array_slice($events, 0, 10);

?>
<link rel="stylesheet" href="assets/css/notify.css">
<script src="assets/js/notify.js"></script>
<link rel="stylesheet" href="styles/dashboard.css">
<style>
  /* Dashboard mini-card hover & click affordance (apply to any clickable status-card, including Total Positions) */
  .status-card.clickable, .status-cards .status-card.clickable{ cursor:pointer; transition: transform .12s ease, box-shadow .12s ease; }
  .status-card.clickable:hover, .status-cards .status-card.clickable:hover{ transform: translateY(-6px); box-shadow: 0 8px 20px rgba(0,0,0,0.08); }
  .status-card.clickable:active, .status-cards .status-card.clickable:active{ transform: translateY(-2px); }
  /* timeframe split dropdown */
  .timeframe-split { position: relative; display: inline-block; }
  .timeframe-menu { position: absolute; right: 0; top: calc(100% + 6px); background: #fff; border: 1px solid #e5e7eb; padding: 6px; border-radius: 6px; display: none; min-width: 140px; box-shadow: 0 8px 24px rgba(0,0,0,0.08); z-index: 200; }
  .timeframe-menu.show { display: block; }
  .timeframe-menu .month-option { display: block; width:100%; padding:8px 10px; text-align:left; border:0; background:transparent; cursor:pointer; font-size:14px; }
  .timeframe-menu .month-option:hover { background: rgba(0,0,0,0.04); }
  .timeframe-split .caret { margin-left:6px; opacity:0.8; }
  /* Positions details responsive layout */
  .positions-total { flex: 0 0 220px; width: 220px; box-sizing: border-box; padding: 8px; }
  .positions-details-grid { display: grid; grid-template-columns: 1fr; gap: 12px; width: 100%; }
  .positions-group .small-muted { margin-bottom: 6px; }
  .positions-group .status-cards { display: block; }

  /* Keep Total Positions visually consistent (no size change), other cards are flexible.
     On wider viewports allow the details to flow into 3 columns. */
  @media (min-width: 980px) {
    .summary-row { align-items:flex-start; }
    .positions-details-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  }
  /* When a status-cards container has many items, enable an internal scrollbar
     so only the mini-card scrolls instead of the whole page. Limit height to
     avoid overly tall cards while keeping layout stable. */
  .status-cards {
    max-height: 260px;
    overflow-y: auto;
    padding-right: 6px; /* allow space for scrollbar */
    box-sizing: border-box;
  }

  /* Lightweight custom scrollbar for WebKit and Firefox */
  .status-cards::-webkit-scrollbar { width: 10px; }
  .status-cards::-webkit-scrollbar-thumb { background: rgba(15,23,42,0.18); border-radius: 8px; }
  .status-cards::-webkit-scrollbar-track { background: transparent; }
  .status-cards { scrollbar-width: thin; scrollbar-color: rgba(15,23,42,0.18) transparent; }

  /* Applicant mini-card grid and card styles */
  .two-col { display:flex; gap:12px; align-items:flex-start; overflow: hidden;}
  /* make main column ~67% and recent applicants column 33% */
  .two-col .col-flex { flex: 0 0 18%; max-width: 40%; box-sizing: border-box; }
  .two-col .recent-col { flex: 0 0 81%; max-width:90%; box-sizing: border-box; padding-left: 8px; overflow: hidden; }
  /* Ensure recent-list never creates a horizontal scroll; let the internal grid adapt */
  .recent-list { overflow-x: hidden; overflow-y: auto; }
  .recent-list ul { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 8px; list-style:none; padding:0; margin:0; width:100%; box-sizing:border-box; }
  .list-item-compact, .recent-list .list-item-compact { display:flex; align-items:center; justify-content:space-between; padding:8px; border-radius:8px; transition: transform .12s ease, box-shadow .12s ease; box-shadow: 0 1px 0 rgba(0,0,0,0.02); width:100%; box-sizing:border-box; background: transparent; }
  /* Make recent applicant card borders black for stronger separation */
  .recent-list .list-item-compact { border: 1px solid rgba(0, 0, 0, 0.10); background: #fff; }
  .list-item-compact:hover { transform: translateY(-6px); box-shadow: 0 8px 20px rgba(0,0,0,0.08); cursor:pointer; }
  .list-item-compact .row-ellipsis { max-width: 70%; }
  .list-item-compact .muted-right { text-align:right; font-size:13px; }
  /* small status label inside applicant card */
  .app-status-pill { font-size:12px; padding:4px 6px; border-radius:999px; opacity:0.95; }
  /* Recent applicants typography adjustments: smaller text, black color for legibility */
  .recent-list { color: #000; font-size: 90%; }
  /* Ensure list items stretch to fill their grid cell and wrap content as needed */
  .recent-list ul li { width:100%; box-sizing:border-box; }
  .recent-list .row { gap:6px; }
  .recent-list .small-muted { color: #6b7280; }
</style>

<?php
// Fetch active departments and teams to allow dashboard to only display active groups
$activeDepartments = [];
$activeTeams = [];
try {
  $sqlD = "SELECT d.department_name, COALESCE(d.active,1) AS department_active, t.team_name, COALESCE(t.active,1) AS team_active FROM departments d LEFT JOIN teams t ON t.department_id = d.department_id ORDER BY d.department_name ASC, t.team_name ASC";
  if ($resD = $conn->query($sqlD)) {
    while ($r = $resD->fetch_assoc()) {
      $dname = (string)($r['department_name'] ?? '');
      $tname = (string)($r['team_name'] ?? '');
      $dactive = isset($r['department_active']) ? (int)$r['department_active'] : 1;
      $tactive = isset($r['team_active']) ? (int)$r['team_active'] : 1;
      if ($dname !== '' && $dactive === 1) $activeDepartments[$dname] = 1;
      if ($tname !== '' && $tactive === 1) $activeTeams[$tname] = 1;
    }
    $resD->free();
  }
} catch (Throwable $_) { /* ignore */ }

// Export active sets to JS
?>

<main class="content-area">
  <div class="dashboard-container">
    <div id="summaryStack" class="summary-stack">
    <!-- Positions Card -->
    <div class="table-card">
      <div class="hdr"><h3>Positions Summary</h3>
      <div id="positionsTotalCard" class="status-card clickable positions-total" data-action="positions-total">
            <div class="label">Total Positions</div>
            <div class="value val-danger" id="positionsTotal"><?= (int)$totalPositions ?></div>
          </div>
        <div class="actions">
          <div class="action-group">
            <button type="button" class="timeframe-btn" data-target="positions" data-range="today" title="Today">Today</button>
            <button type="button" class="timeframe-btn" data-target="positions" data-range="week" title="Last Week">Last Week</button>
            <div class="timeframe-split">
              <button type="button" id="positionsMonthBtn" class="timeframe-btn" data-target="positions" data-range="months-1" aria-haspopup="true" aria-expanded="false" title="Last Month">Last Month <span class="caret">▾</span></button>
              <div id="positionsMonthMenu" class="timeframe-menu" role="menu" aria-hidden="true">
                <button type="button" class="month-option" data-months="1">1 Month</button>
                <button type="button" class="month-option" data-months="2">2 Months</button>
                <button type="button" class="month-option" data-months="3">3 Months</button>
                <button type="button" class="month-option" data-months="6">6 Months</button>
                <button type="button" class="month-option" data-months="9">9 Months</button>
                <button type="button" class="month-option" data-months="12">12 Months</button>
              </div>
            </div>
          </div>
          <a href="view_positions.php" class="btn-ghost">View All</a>
        </div>
      </div>
      <div class="inner">
        <div class="summary-row" style="display:flex;align-items:flex-start;gap:16px;">
          <div class="positions-details-grid">
        <?php
          // Fetch all configured (active) position statuses. Counts will be computed client-side
          // so they can respect the selected timeframe (today/week/month).
          $positionStatuses = [];
          try {
            $stmt = $conn->prepare("SELECT s.status_id, s.status_name, s.status_color, COALESCE(s.sort_order, s.status_id) AS sort_order FROM positions_status s WHERE COALESCE(s.active,1) != 0 ORDER BY sort_order ASC");
            if ($stmt) {
              $stmt->execute();
              $res = $stmt->get_result();
              while ($r = $res->fetch_assoc()) $positionStatuses[] = $r;
              $stmt->close();
            }
          } catch (Throwable $_) { /* ignore */ }
        ?>

        <div class="positions-group">
          <div class="small-muted">Positions by Status</div>
          <div id="statusCounters" class="status-cards" aria-live="polite"></div>
        </div>
        <!-- Department and Team counters (replaces recent positions list). Rendered client-side so timeframe filters apply immediately. -->
        <div class="positions-group">
          <div class="small-muted">Positions by Department</div>
          <div id="deptCounters" class="status-cards" aria-live="polite"></div>
        </div>
        <div class="positions-group">
          <div class="small-muted">Positions by Team</div>
          <div id="teamCounters" class="status-cards" aria-live="polite"></div>
        </div>
          </div><!-- .positions-details-grid -->
        </div><!-- .summary-row -->
      </div>
    </div>

    <!-- Applicants Card (split left/right inside same card) -->
    <div class="table-card">
      <div class="hdr"><h3>Applicants Summary</h3>
      <div id="applicantsTotalCard" class="status-card clickable positions-total" data-action="applicants-total">
              <div class="label">Total Applicants</div>
              <div class="value val-danger" id="applicantsTotalSummary"><?= (int)$totalApplicants ?></div>
            </div>
        <div class="actions">
          <div class="action-group">
            <button type="button" class="timeframe-btn" data-target="applicants" data-range="today" title="Today">Today</button>
            <button type="button" class="timeframe-btn" data-target="applicants" data-range="week" title="Last Week">Last Week</button>
            <div class="timeframe-split">
              <button type="button" id="applicantsMonthBtn" class="timeframe-btn" data-target="applicants" data-range="months-1" aria-haspopup="true" aria-expanded="false" title="Last Month">Last Month <span class="caret">▾</span></button>
              <div id="applicantsMonthMenu" class="timeframe-menu" role="menu" aria-hidden="true">
                <button type="button" class="month-option" data-months="1">1 Month</button>
                <button type="button" class="month-option" data-months="2">2 Months</button>
                <button type="button" class="month-option" data-months="3">3 Months</button>
                <button type="button" class="month-option" data-months="6">6 Months</button>
                <button type="button" class="month-option" data-months="9">9 Months</button>
                <button type="button" class="month-option" data-months="12">12 Months</button>
              </div>
            </div>
          </div>
          <a href="applicants.php" class="btn-ghost">View All</a>
        </div>
      </div>
      <div class="inner full-height">
        <div class="card-main">
          <div class="summary-row" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
            
          </div>
          <div class="two-col">
          <div class="col-flex">
            <div class="small-muted">Applicants by Status</div>
            <div class="status-stack">
              
              <div class="status-card clickable" data-app-status="screening-pending"><div class="label">Screening Pending</div><div class="value val-primary"><span id="status-screening"><?= (int)$screeningPending ?></span></div></div>
              <div class="status-card clickable" data-app-status="shortlisted"><div class="label">Shortlisted</div><div class="value val-purple"><span id="status-shortlisted"><?= (int)$shortlisted ?></span></div></div>
              <div class="status-card clickable" data-app-status="hr-interviews"><div class="label">HR Interviews</div><div class="value val-indigo"><span id="status-hr"><?= (int)$hrInterviews ?></span></div></div>
              <div class="status-card clickable" data-app-status="manager-interviews"><div class="label">Manager Interviews</div><div class="value val-teal"><span id="status-manager"><?= (int)$managerInterviews ?></span></div></div>
              <div class="status-card clickable" data-app-status="rejected"><div class="label">Rejected</div><div class="value val-danger"><span id="status-rejected"><?= (int)$rejected ?></span></div></div>
              <div class="status-card clickable" data-app-status="hired"><div class="label">Hired</div><div class="value val-success"><span id="status-hired"><?= (int)$hired ?></span></div></div>
            </div>
          </div>
          <div class="recent-col" style="min-height:0; display:flex; flex-direction:column;">
            <div class="small-muted">Recent Applicants <span id="recentApplicantsCount" class="small-muted">(<?php echo min(12, count($applicants)); ?>)</span></div>
            <div id="recentApplicantsList" class="recent-list">
              <?php if (!empty($applicants)): ?>
                    <ul class="recent-grid-single">
                      <?php foreach (array_slice($applicants, 0, 12) as $ap): ?>
                        <li class="row list-item-compact">
                          <div class="row-ellipsis"><strong class="name-strong"><?= htmlspecialchars($ap['full_name'] ?? ('Applicant #'.($ap['applicant_id']??''))) ?></strong><div class="small-muted">Status: <?= htmlspecialchars($ap['status_name'] ?? '—') ?></div></div>
                          <div class="muted-right"><?= htmlspecialchars($ap['created_at'] ?? '') ?> <div class="small-muted"><?= htmlspecialchars(ago_days($ap['created_at'] ?? '')) ?> ago</div></div>
                        </li>
                      <?php endforeach; ?>
                    </ul>
              <?php else: ?>
                <div class="empty">No applicants to show.</div>
              <?php endif; ?>
            </div>
          </div>
          </div>
        </div>
        <div class="summary-row progress-footer" style="margin-top:8px;">
          <?php
            $totalForBar = max(1, $totalApplicants);
            $pScreen = round($screeningPending / $totalForBar * 100);
            $pShort = round($shortlisted / $totalForBar * 100);
            $pHR = round($hrInterviews / $totalForBar * 100);
            $pMgr = round($managerInterviews / $totalForBar * 100);
            $pRej = round($rejected / $totalForBar * 100);
            $pHire = round($hired / $totalForBar * 100);
          ?>
          <div style="width:100%">
              <div class="progress-track">
              <div id="progress-screen" title="Screening <?= $pScreen ?>%" class="progress-seg" style="width:<?= $pScreen ?>%;background:#3b82f6;"></div>
              <div id="progress-short" title="Shortlisted <?= $pShort ?>%" class="progress-seg" style="width:<?= $pShort ?>%;background:#7c3aed;"></div>
              <div id="progress-hr" title="HR <?= $pHR ?>%" class="progress-seg" style="width:<?= $pHR ?>%;background:#8b5cf6;"></div>
              <div id="progress-mgr" title="Manager <?= $pMgr ?>%" class="progress-seg" style="width:<?= $pMgr ?>%;background:#06b6d4;"></div>
              <div id="progress-rej" title="Rejected <?= $pRej ?>%" class="progress-seg" style="width:<?= $pRej ?>%;background:#ef4444;"></div>
              <div id="progress-hire" title="Hired <?= $pHire ?>%" class="progress-seg" style="width:<?= $pHire ?>%;background:#10b981;"></div>
            </div>
            <div class="progress-legend">
              <span>Screen</span><span>Shortlist</span><span>HR</span><span>Mgr</span><span>Rejected</span><span>Hired</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Interviews Card -->
    <div class="table-card">
      <div class="hdr"><h3>Interviews at a Glance</h3>
        <div class="actions">
          <div class="action-group">
            <button type="button" class="timeframe-btn" data-target="interviews" data-range="today" title="Today">Today</button>
            <button type="button" class="timeframe-btn" data-target="interviews" data-range="week" title="Last Week">Last Week</button>
            <div class="timeframe-split">
              <button type="button" id="interviewsMonthBtn" class="timeframe-btn" data-target="interviews" data-range="months-1" aria-haspopup="true" aria-expanded="false" title="Last Month">Last Month <span class="caret">▾</span></button>
              <div id="interviewsMonthMenu" class="timeframe-menu" role="menu" aria-hidden="true">
                <button type="button" class="month-option" data-months="1">1 Month</button>
                <button type="button" class="month-option" data-months="2">2 Months</button>
                <button type="button" class="month-option" data-months="3">3 Months</button>
                <button type="button" class="month-option" data-months="6">6 Months</button>
                <button type="button" class="month-option" data-months="9">9 Months</button>
                <button type="button" class="month-option" data-months="12">12 Months</button>
              </div>
            </div>
          </div>
          <a href="interviews.php" class="btn-ghost">View All</a>
        </div>
      </div>
      <div class="inner">
        <div class="summary-row"><div>Total Interviews</div><div id="interviewsTotalSummary" class="value"><?= (int)$totalInterviews ?></div></div>
        <div class="summary-row"><div>Today's Interviews</div><div class="value" style="color:#10b981;"><?= (int)$todaysInterviews ?></div></div>
        <div class="summary-row"><div>This Week</div><div class="value" style="color:#3b82f6;"><?= (int)$weekInterviews ?></div></div>
        <div style="padding-top:8px;">
          <div class="small-muted">Upcoming / Recent Interviews</div>
          <div id="recentInterviewsList" class="recent-list">
          <?php if (!empty($interviews)): ?>
            <ul class="recent-grid">
              <?php foreach (array_slice($interviews, 0, 12) as $iv): ?>
                <?php $dt = $iv['interview_datetime'] ?? $iv['created_at']; $age = htmlspecialchars(ago_days($iv['interview_datetime'] ?? $iv['created_at'] ?? '')); ?>
                <li class="recent-item">
                  <div class="row">
                    <div class="row-ellipsis">
                      <strong class="name-strong">Applicant #<?= htmlspecialchars($iv['applicant_id'] ?? '') ?></strong>
                      <div class="small-muted"><?= htmlspecialchars($iv['status_name'] ?? '') ?></div>
                    </div>
                    <div class="muted-right muted-right-compact">
                      <?= $dt ? htmlspecialchars($dt) : '—' ?>
                      <div class="small-muted"><?= htmlspecialchars($iv['applicant_name'] ?? '') ?></div>
                      <div class="small-muted muted-top"><?= $age ?> ago</div>
                    </div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="empty">No interviews to show.</div>
          <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
// Row click -> go to Positions page and open the ticket there (simple redirect)
// OpenPositions table removed; no row click behaviour required here.

// Open "Create Position" directly from dashboard if button is visible
document.getElementById('openCreatePositionBtn')?.addEventListener('click', function(e){
  e.preventDefault();
  // Best UX: jump to positions page and open the modal there (reuses existing code)
  window.location.href = 'view_positions.php?openCreate=1';
});

/* Adjust layout to visible navbar (top) and sidebar (left) */
(function(){
  function q(sel){ return document.querySelector(sel); }
  function pickSidebar(){
    return q('#sidebar') || q('.sidebar') || q('.side-nav') || q('.sidenav') || q('nav[aria-label="Sidebar"]');
  }
  function pickTopbar(){
    // prefer explicit top bars, then generic header if it's fixed
    const cands = ['#topbar', '.topbar', 'header.navbar', '.navbar', 'header[role="banner"]', 'header'];
    for (const sel of cands) {
      const el = q(sel);
      if (!el) continue;
      const cs = getComputedStyle(el);
      if (cs.position === 'fixed' || cs.position === 'sticky' || sel !== 'header') return el;
    }
    return null;
  }
  function px(n){ return Math.max(0, Math.round(n || 0)) + 'px'; }
  function adjust(){
    const sb = pickSidebar();
    const tb = pickTopbar();
    const sbW = sb ? sb.getBoundingClientRect().width : 0;
    const tbH = tb ? tb.getBoundingClientRect().height : 0;
    // NOTE: removed per-page CSS variable overrides to keep sidebar layout centralized in styles/layout.css
    // If you need dynamic syncing of measured topbar/sidebar sizes, add a single centralized script
    // (e.g., in includes/header.php) instead of per-page assignments.
  }
  window.addEventListener('load', adjust);
  window.addEventListener('resize', adjust);
  // in case sidebar animates in after load
  setTimeout(adjust, 50); setTimeout(adjust, 300);
})();
</script>
<script>
// Embed server-provided data into JS for client-side filtering and in-place summaries
const DASH_APPLICANTS = <?php echo json_encode($applicants, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
const DASH_INTERVIEWS = <?php echo json_encode($interviews, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
const DASH_POSITIONS = <?php echo json_encode($rows, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
const DASH_POSITION_COUNTS = <?php echo json_encode($positionCounts, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
const DASH_APPLICANT_COUNTS = <?php echo json_encode($applicantCounts, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
const DASH_INTERVIEW_COUNTS = <?php echo json_encode($interviewCounts, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
const DASH_ACTIVE_DEPARTMENTS = <?php echo json_encode(array_keys($activeDepartments), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
const DASH_ACTIVE_TEAMS = <?php echo json_encode(array_keys($activeTeams), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
const DASH_STATUSES = <?php echo json_encode(array_values($positionStatuses), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
<?php
// Fetch applicant statuses (including color) so dashboard can style applicant cards
$applicantStatuses = [];
try {
  $q = "SELECT status_id, status_name, status_color FROM applicants_status WHERE COALESCE(active,1) != 0 ORDER BY status_id ASC";
  if ($rs = $conn->query($q)) {
    while ($r = $rs->fetch_assoc()) $applicantStatuses[] = $r;
    $rs->free();
  }
} catch (Throwable $_) { }
?>
const DASH_APPLICANT_STATUSES = <?php echo json_encode($applicantStatuses, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;

function parseDate(val){ const t = val ? new Date(val) : null; return (t && !isNaN(t)) ? t : null; }
function fmtWhen(val){ const d = parseDate(val); if (!d) return '—'; return d.toLocaleString(); }

// small helper for safe text insertion in templates
function escapeHtml(str){ if (str === null || str === undefined) return ''; return String(str).replace(/[&<>"'`]/g, function(s){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','`':'&#96;'})[s]; }); }

function filterByRange(rows, key, range){
  const now = new Date();
  let start = new Date();
  if (range === 'today') { start.setHours(0,0,0,0); }
  else if (range === 'week') { start.setDate(start.getDate() - 7); start.setHours(0,0,0,0); }
  else if (typeof range === 'string' && range.startsWith('months')) {
    const parts = range.split(/[-_:]/);
    const months = parseInt(parts[1], 10) || 1;
    start.setMonth(start.getMonth() - months);
    start.setHours(0,0,0,0);
  }
  else { start.setMonth(start.getMonth() - 1); start.setHours(0,0,0,0); }
  return rows.filter(r=>{
    try{
      // Match server-side semantics: strict date-based filtering
          if (key === 'positions') {
            const dt = parseDate(r.created_at);
            return dt && dt >= start;
          }
          if (key === 'applicants') {
        const dt = parseDate(r.created_at);
        return dt && dt >= start;
      }
      if (key === 'interviews') {
        const dt = parseDate(r.interview_datetime);
        return dt && dt >= start;
      }
    }catch(e){ return false; }
    return false;
  });
}

function renderPositions(range){
  const totalEl = document.getElementById('positionsTotal');
  const rows = filterByRange(DASH_POSITIONS, 'positions', range || 'today');
  if (totalEl) {
    const v = (DASH_POSITION_COUNTS && DASH_POSITION_COUNTS[range]) ? DASH_POSITION_COUNTS[range] : rows.length;
    totalEl.textContent = String(v);
  }

  // Render department counters
  const deptEl = document.getElementById('deptCounters');
  const teamEl = document.getElementById('teamCounters');
  if (deptEl) {
    if (!rows.length) {
      deptEl.innerHTML = '<div class="empty">No positions for this range.</div>';
    } else {
      const activeDepts = Array.isArray(DASH_ACTIVE_DEPARTMENTS) ? DASH_ACTIVE_DEPARTMENTS : [];
      const activeSet = new Set(activeDepts.map(d => String(d)));
      const byDept = rows.reduce((acc,r)=>{ const k = (r.department||'Unassigned'); acc[k] = (acc[k]||0)+1; return acc; }, {});
      // Build cards only for active departments; aggregate others into 'Other'
      const otherCount = Object.keys(byDept).reduce((sum,k)=> activeSet.has(k) ? sum : sum + (byDept[k]||0), 0);
      const deptCards = Object.keys(byDept).filter(d => activeSet.has(d)).sort().map(d=>{
        return '<div class="status-card clickable" data-department="'+encodeURIComponent(d)+'"><div class="label">'+escapeHtml(d)+'</div><div class="value">'+String(byDept[d])+'</div></div>';
      });
      if (otherCount > 0) deptCards.push('<div class="status-card"><div class="label">Other / Inactive</div><div class="value">'+String(otherCount)+'</div></div>');
      deptEl.innerHTML = deptCards.join('');
    }
  }

  // Render status counters (use configured statuses and color metadata)
  const statusEl = document.getElementById('statusCounters');
  if (statusEl) {
    if (!rows.length) {
      statusEl.innerHTML = '<div class="empty">No positions for this range.</div>';
    } else {
      const statuses = Array.isArray(DASH_STATUSES) ? DASH_STATUSES : [];
      const statusMap = new Map(statuses.map(s => [String(s.status_id), s]));
      const byStatus = rows.reduce((acc,r)=>{ const k = (r.status_id != null) ? String(r.status_id) : '__none__'; acc[k] = (acc[k]||0)+1; return acc; }, {});
      // Build cards for known (active) statuses in configured order
      const knownCards = [];
      let knownSum = 0;
      statuses.forEach(s=>{
        const sid = String(s.status_id);
        const cnt = byStatus[sid] || 0;
        knownSum += cnt;
        if (!cnt) return; // only show status cards with non-zero counts
        const color = (s.status_color && s.status_color !== '') ? s.status_color : '#6b7280';
        knownCards.push('<div class="status-card clickable" data-status-id="'+encodeURIComponent(sid)+'"><div class="label">'+escapeHtml(s.status_name || 'Unnamed')+'</div><div class="value" style="color:'+escapeHtml(color)+';background:transparent;">'+String(cnt)+'</div></div>');
      });
      // Aggregate any rows that don't belong to an active/known status
      const otherCount = Object.keys(byStatus).reduce((sum,k)=> statusMap.has(k) ? sum : sum + (byStatus[k]||0), 0);
      if (otherCount > 0) knownCards.push('<div class="status-card"><div class="label">Other / Inactive</div><div class="value">'+String(otherCount)+'</div></div>');
      statusEl.innerHTML = knownCards.join('');
    }
  }
  
  // Attach click handlers for navigation to view_positions with filters
  // Use event delegation on the dashboard container so handlers persist across renders
  const dashContainer = document.querySelector('.dashboard-container');
    if (dashContainer) {
    dashContainer.addEventListener('click', function(ev){
      // If a recent applicant tile was clicked, navigate to applicants.php and open modal
      try {
        const appl = ev.target.closest && ev.target.closest('.list-item-compact[data-applicant-id]');
        if (appl) {
          const aid = decodeURIComponent(appl.getAttribute('data-applicant-id') || '');
          if (aid) {
            const params = new URLSearchParams();
            // preserve active applicants timeframe if available
            const activeBtn = document.querySelector('.timeframe-btn[data-target="applicants"].active');
            const range = activeBtn ? activeBtn.getAttribute('data-range') : 'today';
            const today = new Date();
            function fmt(d){ const y = d.getFullYear(); const m = String(d.getMonth()+1).padStart(2,'0'); const day = String(d.getDate()).padStart(2,'0'); return y+'-'+m+'-'+day; }
            let start = new Date();
            if (range === 'today') { start.setHours(0,0,0,0); }
            else if (range === 'week') { start.setDate(start.getDate() - 7); start.setHours(0,0,0,0); }
            else if (typeof range === 'string' && range.startsWith('months')) { const parts = range.split(/[-_:]/); const months = parseInt(parts[1],10) || 1; start.setMonth(start.getMonth() - months); start.setHours(0,0,0,0); }
            else { start.setMonth(start.getMonth() - 1); start.setHours(0,0,0,0); }
            params.set('f-date-from', fmt(start)); params.set('f-date-to', fmt(today));
            params.set('openApplicant', aid);
            window.location.href = 'applicants.php?' + params.toString();
            return;
          }
        }
      } catch(e) { /* ignore and continue to card handling */ }

      const card = ev.target.closest && ev.target.closest('.status-card.clickable');
      if (!card) return;
      // determine which kind of card: positions (status-id), department, team or applicants status
      const statusId = card.getAttribute('data-status-id');
      const dept = card.getAttribute('data-department');
      const team = card.getAttribute('data-team');
      const action = card.getAttribute('data-action');
      const appStatus = card.getAttribute('data-app-status');
      // determine currently active timeframe for positions/applicants
      const activeBtnPositions = document.querySelector('.timeframe-btn[data-target="positions"].active');
      const activeBtnApplicants = document.querySelector('.timeframe-btn[data-target="applicants"].active');
      const range = (action === 'positions-total' || statusId || dept || team) ? (activeBtnPositions ? activeBtnPositions.getAttribute('data-range') : 'today') : (activeBtnApplicants ? activeBtnApplicants.getAttribute('data-range') : 'today');
      // compute date range (YYYY-MM-DD)
      const today = new Date();
      function fmt(d){ const y = d.getFullYear(); const m = String(d.getMonth()+1).padStart(2,'0'); const day = String(d.getDate()).padStart(2,'0'); return y+'-'+m+'-'+day; }
      let start = new Date();
      if (range === 'today') { start.setHours(0,0,0,0); }
      else if (range === 'week') { start.setDate(start.getDate() - 7); start.setHours(0,0,0,0); }
      else if (typeof range === 'string' && range.startsWith('months')) {
        const parts = range.split(/[-_:]/);
        const months = parseInt(parts[1], 10) || 1;
        start.setMonth(start.getMonth() - months);
        start.setHours(0,0,0,0);
      } else { start.setMonth(start.getMonth() - 1); start.setHours(0,0,0,0); }
      const params = new URLSearchParams();
      // set created date range so target page can pre-fill created filters
      params.set('f-date-from', fmt(start));
      params.set('f-date-to', fmt(today));

      if (appStatus) {
        // navigate to applicants page with status filter
        params.set('f-status', decodeURIComponent(appStatus));
        const url = 'applicants.php?' + params.toString();
        window.location.href = url;
        return;
      }

      if (action === 'applicants-total') {
        // navigate to applicants list with date filters only (total card)
        const url = 'applicants.php?' + params.toString();
        window.location.href = url;
        return;
      }

      if (action === 'positions-total') {
        // navigate to positions list with date filters only
        const url = 'view_positions.php?' + params.toString();
        window.location.href = url;
        return;
      }

      // fallback: positions card handling
      if (statusId || dept || team) {
        if (statusId) params.set('fStatus', decodeURIComponent(statusId));
        if (dept) params.set('fDept', decodeURIComponent(dept).toLowerCase());
        if (team) params.set('fTeam', decodeURIComponent(team).toLowerCase());
        const url = 'view_positions.php?' + params.toString();
        window.location.href = url;
      }
    });
  }

  // Render team counters
  if (teamEl) {
    if (!rows.length) {
      teamEl.innerHTML = '<div class="empty">No positions for this range.</div>';
    } else {
      const activeTeams = Array.isArray(DASH_ACTIVE_TEAMS) ? DASH_ACTIVE_TEAMS : [];
      const activeTSet = new Set(activeTeams.map(d => String(d)));
      const byTeam = rows.reduce((acc,r)=>{ const k = (r.team||'Unassigned'); acc[k] = (acc[k]||0)+1; return acc; }, {});
      const otherCountT = Object.keys(byTeam).reduce((sum,k)=> activeTSet.has(k) ? sum : sum + (byTeam[k]||0), 0);
      const teamCards = Object.keys(byTeam).filter(t => activeTSet.has(t)).sort().map(t=>{
        return '<div class="status-card clickable" data-team="'+encodeURIComponent(t)+'"><div class="label">'+escapeHtml(t)+'</div><div class="value">'+String(byTeam[t])+'</div></div>';
      });
      if (otherCountT > 0) teamCards.push('<div class="status-card"><div class="label">Other / Inactive</div><div class="value">'+String(otherCountT)+'</div></div>');
      teamEl.innerHTML = teamCards.join('');
    }
  }
}

function renderApplicants(range){
  // update ticket list and summary total
  const rows = filterByRange(DASH_APPLICANTS, 'applicants', range || 'today');
  // Update summary total (server-provided counts object or derived)
  const summaryEl = document.getElementById('applicantsTotalSummary');
  if (summaryEl) {
    const sv = (DASH_APPLICANT_COUNTS && DASH_APPLICANT_COUNTS[range]) ? DASH_APPLICANT_COUNTS[range] : rows.length;
    summaryEl.textContent = String(sv);
  }
  // Render recent applicants into the recentApplicantsList container
  const listEl = document.getElementById('recentApplicantsList');
  if (!listEl) return;
  if (!rows.length) {
    listEl.innerHTML = '<div class="empty">No applicants for this range.</div>';
  } else {
    // build a map for applicant status colors
    const statusArr = Array.isArray(DASH_APPLICANT_STATUSES) ? DASH_APPLICANT_STATUSES : [];
    const statusMap = {};
    statusArr.forEach(s => { if (s && s.status_id !== undefined) statusMap[String(s.status_id)] = s; if (s && s.status_name) statusMap[String(s.status_name).toLowerCase()] = s; });

    function getStatusColor(row){
      try {
        if (row.status_id && statusMap[String(row.status_id)] && statusMap[String(row.status_id)].status_color) return statusMap[String(row.status_id)].status_color;
        const name = (row.status_name || '').toString().toLowerCase();
        if (name && statusMap[name] && statusMap[name].status_color) return statusMap[name].status_color;
      } catch(e){}
      return '#ffffff';
    }

    function contrastColor(hex){
      try{
        if(!hex) return '#000000';
        var h = hex.replace('#',''); if (h.length === 3) h = h.split('').map(c=>c+c).join('');
        var r = parseInt(h.substring(0,2),16), g = parseInt(h.substring(2,4),16), b = parseInt(h.substring(4,6),16);
        var lum = 0.2126*r + 0.7152*g + 0.0722*b; return lum > 150 ? '#000000' : '#ffffff';
      } catch(e){ return '#000000'; }
    }

    const items = rows.slice(0, 12).map(r=>{
      const id = r.applicant_id || '';
      const name = r.full_name || ('Applicant #' + id);
      const when = r.created_at ? new Date(r.created_at).toLocaleString() : '—';
      const diff = r.created_at ? (new Date() - new Date(r.created_at)) : 0;
      let ago = '';
      if (diff) { if (diff < 86400*1000) ago = Math.max(1, Math.floor(diff/60000)) + 'm'; else ago = Math.floor(diff/86400000) + 'd'; }
      const position = r.position_title || r.position || '—';
      const team = r.team_name || '—';
            const statusName = r.status_name || '—';
            const statusColor = getStatusColor(r) || '#f3f4f6';
            const statusText = contrastColor(statusColor) || '#111';
      // Card content: ID, Name, Position, Team, Age, Status
            return '<li class="list-item-compact" data-applicant-id="'+encodeURIComponent(id)+'">'
              + '<div class="row-ellipsis"><strong class="name-strong">#'+escapeHtml(id)+' — '+escapeHtml(name)+'</strong>'
              + '<div class="small-muted">'+escapeHtml(position)+' • '+escapeHtml(team)+'</div></div>'
              + '<div class="muted-right">'+escapeHtml(when)+'<div class="small-muted small-muted-sm">'+escapeHtml(ago)+' ago</div>'
              + '<div class="app-status-pill" style="background:'+escapeHtml(statusColor)+';color:'+escapeHtml(statusText)+';display:inline-block;margin-top:6px;padding:4px 6px;border-radius:999px;font-size:12px">'+escapeHtml(statusName)+'</div>'
              + '</div>'
              + '</li>';
    }).join('');
    listEl.innerHTML = '<ul style="list-style:none;padding:0;margin:0;display:grid;grid-template-columns:repeat(3,1fr);gap:8px;">'+items+'</ul>';
  }
  // update displayed count (always update even when zero rows)
  const countEl = document.getElementById('recentApplicantsCount');
  if (countEl) {
    const shown = Math.min(rows.length, 12);
    countEl.textContent = '(' + String(shown) + ')';
  }

  // Compute per-status counts for the filtered rows and update status cards + progress bar
  (function updateStatusAndProgress(){
    const counts = { screen:0, shortlist:0, hr:0, mgr:0, rejected:0, hired:0 };
    rows.forEach(r=>{
      const s = (r.status_name || '').toString().toLowerCase();
      if (s.includes('hire')) counts.hired++;
      else if (s.includes('reject')) counts.rejected++;
      else if (s.includes('manager interview') || s.includes('manager_interview') || s.includes('manager')) counts.mgr++;
      else if (s.includes('hr interview') || s.includes('hr_interview') || (s.includes('hr') && s.includes('interview'))) counts.hr++;
      else if (s.includes('shortlist') || s.includes('shortlisted')) counts.shortlist++;
      else if (s.includes('screen') || s === '') counts.screen++;
      else counts.screen++; // default bucket
    });

    // Update status DOM elements
    const elMap = {
      screen: document.getElementById('status-screening'),
      shortlist: document.getElementById('status-shortlisted'),
      hr: document.getElementById('status-hr'),
      mgr: document.getElementById('status-manager'),
      rejected: document.getElementById('status-rejected'),
      hired: document.getElementById('status-hired')
    };
    Object.keys(elMap).forEach(k=>{ if (elMap[k]) elMap[k].textContent = String(counts[k] || 0); });

    // Update progress bar segments (percent of filtered rows)
    // Rebuild the applicants-by-status mini-cards to only show counts > 0
    try {
      const statusStack = document.querySelector('.status-stack');
      if (statusStack) {
        const defs = [
          { key: 'screen', label: 'Screening Pending', data: 'screening-pending', cls: 'val-primary' },
          { key: 'shortlist', label: 'Shortlisted', data: 'shortlisted', cls: 'val-purple' },
          { key: 'hr', label: 'HR Interviews', data: 'hr-interviews', cls: 'val-indigo' },
          { key: 'mgr', label: 'Manager Interviews', data: 'manager-interviews', cls: 'val-teal' },
          { key: 'rejected', label: 'Rejected', data: 'rejected', cls: 'val-danger' },
          { key: 'hired', label: 'Hired', data: 'hired', cls: 'val-success' }
        ];
        const cards = defs.reduce((acc,d)=>{
          const v = counts[d.key] || 0;
          if (v > 0) {
            acc.push('<div class="status-card clickable" data-app-status="'+encodeURIComponent(d.data)+'"><div class="label">'+escapeHtml(d.label)+'</div><div class="value '+d.cls+'">'+String(v)+'</div></div>');
          }
          return acc;
        }, []);
        statusStack.innerHTML = cards.length ? cards.join('') : '<div class="empty">No applicants for this range.</div>';
      }
    } catch(e){ console.warn('status-stack rebuild failed', e); }
    const total = rows.length;
    const segMap = {
      screen: document.getElementById('progress-screen'),
      shortlist: document.getElementById('progress-short'),
      hr: document.getElementById('progress-hr'),
      mgr: document.getElementById('progress-mgr'),
      rejected: document.getElementById('progress-rej'),
      hired: document.getElementById('progress-hire')
    };
    if (!total) {
      Object.values(segMap).forEach(el=>{ if (el) { el.style.width = '0%'; el.title = ''; } });
    } else {
      Object.keys(segMap).forEach(k=>{
        const el = segMap[k];
        if (!el) return;
        const pct = Math.round((counts[k] || 0) / total * 100);
        el.style.width = pct + '%';
        el.title = (k.charAt(0).toUpperCase() + k.slice(1)) + ' ' + String(pct) + '%';
      });
    }
  })();
}

function renderInterviews(range){
  // update ticket list and summary total
  const rows = filterByRange(DASH_INTERVIEWS, 'interviews', range || 'today');
  const summaryEl = document.getElementById('interviewsTotalSummary'); if (summaryEl) {
    const sv = (DASH_INTERVIEW_COUNTS && DASH_INTERVIEW_COUNTS[range]) ? DASH_INTERVIEW_COUNTS[range] : rows.length;
    summaryEl.textContent = String(sv);
  }
  const listEl = document.getElementById('recentInterviewsList'); if (!listEl) return;
  if (!rows.length) { listEl.innerHTML = '<div class="empty">No interviews for this range.</div>'; return; }
  const items = rows.slice(0, 12).map(r=>{
    const when = r.interview_datetime || r.created_at || '';
    const dt = parseDate(when);
    let ago = '';
    if (dt) {
      const diff = new Date() - dt;
      if (diff < 86400*1000) ago = Math.max(1, Math.floor(diff/60000)) + 'm'; else ago = Math.floor(diff/86400000) + 'd';
    }
    const applicantName = r.applicant_name || '';
    const status = r.status_name || '';
    // Build row so status appears under the Applicant # label (same structure as applicants list)
    return '<li class="list-item-compact-col">'
      + '<div class="row-ellipsis"><strong class="name-strong">'+escapeHtml('Applicant #' + (r.applicant_id||''))+'</strong><div class="small-muted">'+escapeHtml(status)+'</div></div>'
      + '<div class="muted-right muted-right-compact">'+escapeHtml(when)
      + (applicantName ? '<div class="small-muted">'+escapeHtml(applicantName)+'</div>' : '')
      + '<div class="small-muted small-muted-sm muted-top">'+escapeHtml(ago ? (ago + ' ago') : '')+'</div>'
      + '</div>'
      + '</li>';
  }).join('');
  listEl.innerHTML = '<ul style="list-style:none;padding:0;margin:0;display:grid;grid-template-columns:repeat(2,1fr);gap:8px;">'+items+'</ul>';
}

// Wire timeframe buttons
// Use event delegation for timeframe buttons — more robust if buttons are re-rendered.
document.addEventListener('click', function (e) {
  const btn = e.target.closest && e.target.closest('.timeframe-btn');
  if (!btn) return;
  e.preventDefault();
  const target = btn.getAttribute('data-target');
  const range = btn.getAttribute('data-range') || 'today';
  // call appropriate renderer
  if (target === 'applicants') renderApplicants(range);
  else if (target === 'interviews') renderInterviews(range);
  else if (target === 'positions') renderPositions(range);
  // toggle active state for the target group
  try { document.querySelectorAll('.timeframe-btn[data-target="'+target+'"]').forEach(b=>b.classList.remove('active')); } catch(e){}
  btn.classList.add('active');
});

// Positions month-dropdown behaviour (select 1/2/3/6/9/12 months)
(function(){
  const btn = document.getElementById('positionsMonthBtn');
  const menu = document.getElementById('positionsMonthMenu');
  if (!btn || !menu) return;
  btn.addEventListener('click', function(e){
    e.stopPropagation();
    const show = menu.classList.toggle('show');
    btn.setAttribute('aria-expanded', show ? 'true' : 'false');
  });
  menu.addEventListener('click', function(e){
    const opt = e.target.closest && e.target.closest('.month-option');
    if (!opt) return;
    const months = parseInt(opt.getAttribute('data-months'), 10) || 1;
    const range = 'months-' + months;
    btn.setAttribute('data-range', range);
    btn.innerHTML = 'Last ' + months + (months>1? ' Months':' Month') + ' <span class="caret">▾</span>';
    menu.classList.remove('show');
    btn.setAttribute('aria-expanded','false');
    // set active and render positions
    try { document.querySelectorAll('.timeframe-btn[data-target="positions"]').forEach(b=>b.classList.remove('active')); } catch(e){}
    btn.classList.add('active');
    try { renderPositions(range); } catch(e){}
  });
  // close when clicking outside
  document.addEventListener('click', function(e){ if (!btn.contains(e.target) && !menu.contains(e.target)) { menu.classList.remove('show'); btn.setAttribute('aria-expanded','false'); } });
})();

// Applicants month-dropdown behaviour
(function(){
  const btn = document.getElementById('applicantsMonthBtn');
  const menu = document.getElementById('applicantsMonthMenu');
  if (!btn || !menu) return;
  btn.addEventListener('click', function(e){
    e.stopPropagation();
    const show = menu.classList.toggle('show');
    btn.setAttribute('aria-expanded', show ? 'true' : 'false');
  });
  menu.addEventListener('click', function(e){
    const opt = e.target.closest && e.target.closest('.month-option');
    if (!opt) return;
    const months = parseInt(opt.getAttribute('data-months'), 10) || 1;
    const range = 'months-' + months;
    btn.setAttribute('data-range', range);
    btn.innerHTML = 'Last ' + months + (months>1? ' months':' month') + ' <span class="caret">▾</span>';
    menu.classList.remove('show');
    btn.setAttribute('aria-expanded','false');
    try { document.querySelectorAll('.timeframe-btn[data-target="applicants"]').forEach(b=>b.classList.remove('active')); } catch(e){}
    btn.classList.add('active');
    try { renderApplicants(range); } catch(e){}
  });
  document.addEventListener('click', function(e){ if (!btn.contains(e.target) && !menu.contains(e.target)) { menu.classList.remove('show'); btn.setAttribute('aria-expanded','false'); } });
})();

// Interviews month-dropdown behaviour
(function(){
  const btn = document.getElementById('interviewsMonthBtn');
  const menu = document.getElementById('interviewsMonthMenu');
  if (!btn || !menu) return;
  btn.addEventListener('click', function(e){
    e.stopPropagation();
    const show = menu.classList.toggle('show');
    btn.setAttribute('aria-expanded', show ? 'true' : 'false');
  });
  menu.addEventListener('click', function(e){
    const opt = e.target.closest && e.target.closest('.month-option');
    if (!opt) return;
    const months = parseInt(opt.getAttribute('data-months'), 10) || 1;
    const range = 'months-' + months;
    btn.setAttribute('data-range', range);
    btn.innerHTML = 'Last ' + months + (months>1? ' months':' month') + ' <span class="caret">▾</span>';
    menu.classList.remove('show');
    btn.setAttribute('aria-expanded','false');
    try { document.querySelectorAll('.timeframe-btn[data-target="interviews"]').forEach(b=>b.classList.remove('active')); } catch(e){}
    btn.classList.add('active');
    try { renderInterviews(range); } catch(e){}
  });
  document.addEventListener('click', function(e){ if (!btn.contains(e.target) && !menu.contains(e.target)) { menu.classList.remove('show'); btn.setAttribute('aria-expanded','false'); } });
})();

// Ensure default 'today' buttons are set active on load for each target group
['positions','applicants','interviews'].forEach(function(t){
  const b = document.querySelector('.timeframe-btn[data-target="'+t+'"][data-range="today"]');
  if (b) b.classList.add('active');
});

// Row click handlers: open applicant/interview fragment
document.getElementById('applicantTicketsBody')?.addEventListener('click', function(e){ const tr = e.target.closest('tr[data-applicant-id]'); if (!tr) return; const id = tr.getAttribute('data-applicant-id'); if (!id) return; window.location.href = 'get_applicant.php?applicant_id=' + encodeURIComponent(id); });
document.getElementById('interviewTicketsBody')?.addEventListener('click', function(e){ const tr = e.target.closest('tr[data-interview-id]'); if (!tr) return; const id = tr.getAttribute('data-interview-id'); if (!id) return; // open parent applicant
  const row = DASH_INTERVIEWS.find(x=>String(x.id) === String(id)); if (row && row.applicant_id) window.location.href = 'get_applicant.php?applicant_id=' + encodeURIComponent(row.applicant_id); else window.location.href = 'get_applicant.php';
});

// Initial render: determine applicants range from URL (supports ?appRange=today|week|months-N)
(function(){
  var defPosRange = 'today';
  var defIntRange = 'today';
  var appRange = 'today';
  try{
    const params = new URLSearchParams(window.location.search || '');
    if (params.has('appRange')) appRange = params.get('appRange') || 'today';
    else if (params.has('range')) {
      // allow a combined range param like range=applicants:week
      const r = params.get('range') || '';
      if (r.indexOf('applicants:') === 0) appRange = r.split(':',2)[1] || 'today';
    }
  }catch(e){ /* ignore */ }

  // set active state for applicants timeframe buttons
  try {
    document.querySelectorAll('.timeframe-btn[data-target="applicants"]').forEach(b=>b.classList.remove('active'));
    let btn = document.querySelector('.timeframe-btn[data-target="applicants"][data-range="'+appRange+'"]');
    if (!btn && appRange && appRange.indexOf('months-') === 0) {
      // ensure applicants month button reflects selected months (e.g. months-1)
      btn = document.getElementById('applicantsMonthBtn');
      const parts = appRange.split('-');
      const months = parseInt(parts[1],10) || 1;
      if (btn) {
        btn.setAttribute('data-range', appRange);
        btn.innerHTML = 'Last ' + months + (months>1? ' months':' month') + ' <span class="caret">▾</span>';
      }
    }
    if (btn) btn.classList.add('active');
  } catch(e) {}

  // initial renders (positions/interviews default to today)
  try { renderPositions(defPosRange); } catch(e) { renderPositions('today'); }
  try { renderApplicants(appRange); } catch(e) { renderApplicants('today'); }
  try { renderInterviews(defIntRange); } catch(e) { renderInterviews('today'); }
})();
</script>