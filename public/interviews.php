<?php
// Start session only if not already active to avoid PHP notices
if (function_exists('session_status')) {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
} else {
  @session_start();
}
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user'])) {
  header('Location: index.php'); exit;
}

$activePage = 'interviews';
$pageTitle = 'Interviews';
if (file_exists(__DIR__ . '/../includes/header.php')) include __DIR__ . '/../includes/header.php';
if (file_exists(__DIR__ . '/../includes/navbar.php')) include __DIR__ . '/../includes/navbar.php';

// Load interviews with applicant and position info and map to client shape
$interviews = [];
$statuses_map = [];
$query_error = '';
try {
    // Load interviews (no department restriction)
    try {
      // Apply scope by positions.department so local-scope users only see interviews
      // for positions in their department. No WHERE exists so request a WHERE prefix.
      $sql = "SELECT i.id, i.position_id AS position_id, i.applicant_id AS applicant_id, i.interview_datetime AS start, DATE_ADD(i.interview_datetime, INTERVAL 30 MINUTE) AS end, i.status_id, i.comments, a.full_name AS applicant_name, a.email AS applicant_email, a.phone AS applicant_phone, p.title AS position_name, p.department AS department_name, p.team AS team_name, p.manager_name AS manager_name, COALESCE(u.name,'') AS created_by_name, COALESCE(d.department_name,'') AS created_by_department, s.name AS status_name, s.status_color AS status_color
        FROM interviews i
        LEFT JOIN applicants a ON i.applicant_id = a.applicant_id
        LEFT JOIN positions p ON i.position_id = p.id
        LEFT JOIN users u ON i.created_by = u.id
        LEFT JOIN departments d ON u.department_id = d.department_id
        LEFT JOIN interview_statuses s ON i.status_id = s.id
        " . _scope_clause('positions','p', true) . "
        ORDER BY i.interview_datetime DESC";

      $stmt = $conn->prepare($sql);
      if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
          $interviews[] = [
            'id' => (int)$r['id'],
            'position_id' => isset($r['position_id']) ? (int)$r['position_id'] : null,
            'applicant_id' => isset($r['applicant_id']) ? (int)$r['applicant_id'] : null,
            'applicant_name' => $r['applicant_name'] ?? '',
            'applicant_email' => $r['applicant_email'] ?? '',
            'applicant_phone' => $r['applicant_phone'] ?? '',
            'position_name' => $r['position_name'] ?? '',
            'department_name' => $r['department_name'] ?? '',
            'team_name' => $r['team_name'] ?? '',
            'manager_name' => $r['manager_name'] ?? '',
            'created_by_name' => $r['created_by_name'] ?? '',
            'created_by_department' => $r['created_by_department'] ?? '',
            'start' => $r['start'] ?? null,
            'end' => $r['end'] ?? null,
            'status_id' => isset($r['status_id']) ? (int)$r['status_id'] : null,
            'status_name' => $r['status_name'] ?? '',
            'status_color' => $r['status_color'] ?? '#6b7280',
            'comments' => $r['comments'] ?? ''
          ];
        }
        $stmt->close();
      } else {
        $query_error = $conn->error;
      }
    } catch (Throwable $e) {
      $query_error = $conn->error;
    }
} catch (Throwable $e) {
  error_log('load interviews failed: ' . $e->getMessage());
}

// Load possible statuses and build an associative map keyed by id using DB-provided colors
try {
  $sres = $conn->query("SELECT id, name, status_color FROM interview_statuses ORDER BY id");
  if ($sres) {
    while ($s = $sres->fetch_assoc()) {
      $id = (int)$s['id'];
      $statuses_map[$id] = ['id' => $id, 'name' => $s['name'], 'color' => $s['status_color'] ?? '#6b7280'];
    }
    $sres->free();
  }
} catch (Throwable $_) {}

$interviews_json = json_encode($interviews, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$statuses_json = json_encode($statuses_map, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

// Attempt to load status transition table if present to provide allowed next states per status
$transitions_map = [];
try {
  $tablesToTry = ['interviews_status_transitions','interview_status_transitions','applicants_status_transitions'];
  $colsCandidates = [ ['from_status_id','to_status_id'], ['from_status','to_status'], ['from_id','to_id'], ['from','to'] ];
  foreach ($tablesToTry as $tbl) {
    // check table exists
    try {
      $chk = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($tbl) . "'");
      if (!$chk || $chk->num_rows === 0) continue;
    } catch (Throwable $_) { continue; }
    // try candidate column pairs
    foreach ($colsCandidates as $pair) {
      $fromCol = $pair[0]; $toCol = $pair[1];
      try {
        $c1 = $conn->query("SHOW COLUMNS FROM `" . $conn->real_escape_string($tbl) . "` LIKE '" . $conn->real_escape_string($fromCol) . "'");
        $c2 = $conn->query("SHOW COLUMNS FROM `" . $conn->real_escape_string($tbl) . "` LIKE '" . $conn->real_escape_string($toCol) . "'");
        if (!$c1 || !$c2 || $c1->num_rows === 0 || $c2->num_rows === 0) continue;
      } catch (Throwable $_) { continue; }
      // read transitions
      try {
        $q = "SELECT `" . $conn->real_escape_string($fromCol) . "` AS `from`, `" . $conn->real_escape_string($toCol) . "` AS `to` FROM `" . $conn->real_escape_string($tbl) . "`";
        if ($res = $conn->query($q)) {
          while ($r = $res->fetch_assoc()) {
            $from = $r['from']; $to = $r['to'];
            if ($from === null || $to === null) continue;
            // normalize numeric-ish ids to int keys when possible
            if (is_numeric($from)) $from = (int)$from;
            if (is_numeric($to)) $to = (int)$to;
            if (!isset($transitions_map[$from])) $transitions_map[$from] = [];
            // avoid duplicates
            if (!in_array($to, $transitions_map[$from], true)) $transitions_map[$from][] = $to;
          }
          $res->free();
          // if we found rows, stop trying other column sets for this table
          if (!empty($transitions_map)) break 2;
        }
      } catch (Throwable $_) { continue; }
    }
  }
} catch (Throwable $_) {}

$transitions_json = json_encode($transitions_map, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

// Load departments (from departments table) for filters — only active departments
$departments = [];
try {
  $dres = $conn->query("SELECT department_id AS id, department_name AS name FROM departments WHERE active = 1 ORDER BY department_name");
  if ($dres) {
    while ($d = $dres->fetch_assoc()) { $departments[] = $d; }
    $dres->free();
  }
} catch (Throwable $_) {}

// Load distinct managers from positions table (apply department filter when applicable)
$managers = [];
  try {
  $sqlM = "SELECT DISTINCT COALESCE(NULLIF(TRIM(p.manager_name),'') ,'') AS manager_name FROM positions p WHERE p.manager_name IS NOT NULL AND TRIM(p.manager_name) <> ''" . _scope_clause('positions','p', false) . " ORDER BY manager_name";
  $stmtM = $conn->prepare($sqlM);
  if ($stmtM) {
    $stmtM->execute();
    $mres = $stmtM->get_result();
    while ($r = $mres->fetch_assoc()) $managers[] = $r['manager_name'];
    $stmtM->close();
  }
} catch (Throwable $_) {}

// Load positions that have interviews (unique) with department restriction when needed
$positions_with_interviews = [];
  try {
  $sqlP = "SELECT DISTINCT p.id, p.title FROM positions p JOIN interviews i ON i.position_id = p.id" . _scope_clause('positions','p', true) . " ORDER BY p.title";
  $stmtP = $conn->prepare($sqlP);
  if ($stmtP) {
    $stmtP->execute();
    $pres = $stmtP->get_result();
    while ($p = $pres->fetch_assoc()) $positions_with_interviews[] = $p;
    $stmtP->close();
  }
} catch (Throwable $_) {}

$departments_json = json_encode($departments, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$managers_json = json_encode($managers, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$positions_with_interviews_json = json_encode($positions_with_interviews, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
?>

<!-- External libs: TailwindCSS (CDN), FullCalendar (global/UMD builds), Lucide -->
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<!-- Use FullCalendar global/UMD bundles (no ES module import statements) -->
<link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@fullcalendar/daygrid@5.11.3/main.min.css" rel="stylesheet">
<link href="styles/interviews.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/notify.css">
<!-- Load Lucide icons (use CDN latest dist entrypoint so minor version mismatches don't 404) -->
<script src="https://cdn.jsdelivr.net/npm/lucide/dist/lucide.min.js"></script>
<!-- Load the global/UMD builds to ensure `window.FullCalendar` is available and no `import` syntax is parsed. -->
<!-- FullCalendar is imported dynamically by the page script (module builds) to support different CDN shapes. -->

<main class="content-area p-6 bg-transparent">
  <?php if (!empty($query_error)): ?>
    <div class="db-error">Database error loading interviews: <?= htmlspecialchars($query_error) ?></div>
  <?php endif; ?>
  <div class="flex flex-col md:flex-row h-full gap-4">
    <!-- Left: calendar (takes up to 80% on md+) -->
    <section id="calendarSection" class="w-full md:w-4/5 bg-transparent calendar-wrap">
      <div id="calendarContainer" class="calendar-container">
        <div id="calendar" class="calendar-el bg-white/5 rounded-md shadow-sm p-2"></div>
      </div>
    </section>

    <!-- Right: interviews cards list (20% on md+) -->
    <aside id="interviewsCardsPanel" class="w-full md:w-1/5">
      <div class="records-section p-3 h-full flex flex-col">
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-base font-semibold">Interviews</h3>
          <div class="flex items-center gap-2">
            <button id="panelFilterBtn" class="text-sm px-2 py-1 bg-gray-600 text-white rounded">Filter</button>
          </div>
        </div>

        <!-- Inline filter panel that slides down and pushes the cards list -->
        <div id="filterPanel" class="filter-panel mb-3">
          <div class="grid grid-cols-1 gap-2">
            <div>
              <label class="block text-xs text-gray-500 mb-1">Department</label>
              <select id="f-department" class="w-full bg-white border rounded px-2 py-1"></select>
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Manager</label>
              <select id="f-manager" class="w-full bg-white border rounded px-2 py-1"></select>
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Status</label>
              <select id="f-status" class="w-full bg-white border rounded px-2 py-1"></select>
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Position</label>
              <select id="f-position" class="w-full bg-white border rounded px-2 py-1"></select>
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Created By</label>
              <select id="f-created-by" class="w-full bg-white border rounded px-2 py-1"></select>
            </div>
            <div class="text-right">
              <button id="panelClearFilters" class="px-3 py-1 bg-gray-200 rounded">Clear</button>
            </div>
          </div>
        </div>

        <div id="interviewsCards" class="flex-1 overflow-auto space-y-3">
          <?php if (!empty($interviews)): foreach ($interviews as $iv):
            $status_name = $iv['status_name'] ?? (isset($statuses_map[$iv['status_id']]) ? $statuses_map[$iv['status_id']]['name'] : '');
            $status_color = $iv['status_color'] ?? (isset($statuses_map[$iv['status_id']]) ? $statuses_map[$iv['status_id']]['color'] : '#6b7280');
          ?>
            <article class="p-3 rounded bg-transparent border-l-4 card-item" data-id="<?= (int)$iv['id'] ?>" style="border-left-color: <?= htmlspecialchars($status_color) ?>;">
              <div class="flex items-start justify-between gap-2">
                <div class="text-sm">
                  <div class="font-medium"><?= htmlspecialchars($iv['applicant_name'] ?? '—') ?></div>
                  <div class="text-xs text-gray-300"><?= htmlspecialchars($iv['position_name'] ?? '—') ?> · <?= htmlspecialchars($iv['department_name'] ?? '') ?></div>
                </div>
                <div class="text-right">
                    <div class="text-xs"><span class="status-badge px-2 py-1 rounded text-xs" style="background: <?= htmlspecialchars($status_color) ?>;"><?= htmlspecialchars($status_name) ?></span></div>
                </div>
              </div>
              <div class="mt-2 text-xs text-gray-300"><?= htmlspecialchars($iv['start'] ?? $iv['interview_datetime'] ?? '') ?></div>
              <div class="mt-3 flex justify-end"></div>
            </article>
          <?php endforeach; else: ?>
            <div class="text-sm text-gray-400">No interviews found.</div>
          <?php endif; ?>
        </div>
      </div>
    </aside>
  </div>

  <!-- Drawer (right side) -->
  <div id="interviewDrawer" class="fixed inset-y-0 right-0 w-96 bg-gray-900 text-white transform translate-x-full transition-transform duration-300 shadow-lg z-50">
    <div class="p-4 flex items-center justify-between border-b border-gray-800">
      <h3 id="drawerTitle" class="text-lg font-semibold">Interview</h3>
      <button id="drawerClose" class="text-gray-400 hover:text-white">Close</button>
    </div>
    <div class="p-4 overflow-auto h-[calc(100%-64px)]">
      <div id="drawerContent">
        <!-- dynamic content populated by JS -->
      </div>
    </div>
  </div>
  
</main>

<script>
window._INTERVIEWS = <?php echo $interviews_json; ?> || [];
window._INTERVIEW_STATUSES = <?php echo $statuses_json; ?> || [];
window._INTERVIEW_STATUS_TRANSITIONS = <?php echo $transitions_json ?? '[]'; ?> || [];
window._DB_DEPARTMENTS = <?php echo $departments_json; ?> || [];
window._DB_MANAGERS = <?php echo $managers_json; ?> || [];
window._POSITIONS_WITH_INTERVIEWS = <?php echo $positions_with_interviews_json; ?> || [];
</script>
<script>
// Server-side counts for debugging (visible in browser console)
console.log('Server interviews count: <?= (int)count($interviews) ?>');
</script>
<!-- Project toast notifications -->
<script src="assets/js/notify.js"></script>
<!-- FullCalendar is loaded dynamically (ESM) by `scripts/interviews.js` using CDN that resolves dependencies. -->
<script src="scripts/interviews.js"></script>
<script>
// If URL includes ?openInterview=ID, attempt to open the interview drawer after scripts load.
(function(){
  try{
    const params = new URLSearchParams(window.location.search || '');
    if (!params.has('openInterview')) return;
    const id = params.get('openInterview');
    if (!id) return;
    // Wait for the interviews script to expose window.openInterview
    const tryOpen = function(){
      if (typeof window.openInterview === 'function') { try { window.openInterview(id); } catch(e) { console.warn('openInterview failed', e); } }
      else { setTimeout(tryOpen, 150); }
    };
    // Start attempts after a short delay to let the script initialize
    setTimeout(tryOpen, 80);
  }catch(e){ /* ignore */ }
})();
</script>
