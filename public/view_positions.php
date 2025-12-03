<?php
session_start();
require_once '../includes/db.php';

// page variables required by includes/header.php so header renders correctly
$activePage = 'positions';
$pageTitle = 'Positions';

if (file_exists(__DIR__ . '/../includes/header.php')) include __DIR__ . '/../includes/header.php';

if (file_exists(__DIR__ . '/../includes/navbar.php')) include __DIR__ . '/../includes/navbar.php';

// ensure Font Awesome is present and force icon font as fallback
?>
  <?php // debug banner removed ?>
<?php
// authorize: prefer access_keys (role_id-aware). allow legacy role names ['admin','hr','manager'] if access_keys missing
require_once __DIR__ . '/../includes/access.php';
if (!isset($_SESSION['user']) || !_has_access('positions_view', ['admin','hr','manager'])) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$user = $_SESSION['user'];

// Fetch departments (include director_name) - only active departments
$departments = [];
$dept_res = $conn->query("SELECT department_id AS id, department_name AS name, director_name FROM departments WHERE active = 1 ORDER BY department_name");
if ($dept_res) {
  while ($r = $dept_res->fetch_assoc()) {
    $departments[] = $r;
  }
  $dept_res->free();
}

// Fetch teams grouped by department (use actual column names) - only active teams
$teams_by_dept = [];
$teams_res = $conn->query("SELECT team_id AS id, team_name AS name, department_id, manager_name FROM teams WHERE active = 1 ORDER BY team_name");
if ($teams_res) {
    while ($t = $teams_res->fetch_assoc()) {
        $deptId = (int)$t['department_id'];
        if (!isset($teams_by_dept[$deptId])) $teams_by_dept[$deptId] = [];
        $teams_by_dept[$deptId][] = $t;
    }
    $teams_res->free();
}
$teams_json = json_encode($teams_by_dept, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

// --- fetch statuses (id, name, color) into a map for rendering ----------------
// detect if status_color column exists, use default if not
$status_map = [];
$has_color_col = false;
$colCheck = $conn->query("SHOW COLUMNS FROM positions_status LIKE 'status_color'");
if ($colCheck && $colCheck->num_rows > 0) {
    $has_color_col = true;
    $colCheck->free();
}

// query statuses: include color if column exists, otherwise return default color
if ($has_color_col) {
    $sr = $conn->query("SELECT status_id, status_name, COALESCE(status_color, '#777777') AS status_color FROM positions_status");
} else {
    $sr = $conn->query("SELECT status_id, status_name, '#777777' AS status_color FROM positions_status");
}

if ($sr) {
    while ($s = $sr->fetch_assoc()) {
        $status_map[(int)$s['status_id']] = [
            'name'  => $s['status_name'],
            'color' => $s['status_color']
        ];
    }
    $sr->free();
} else {
    // non-fatal fallback: leave $status_map empty and continue
    error_log('view_positions: could not load positions_status: ' . $conn->error);
}

// helper: choose light/dark text for a hex color
function status_text_color($hex) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    $r = hexdec(substr($hex,0,2));
    $g = hexdec(substr($hex,2,2));
    $b = hexdec(substr($hex,4,2));
    $luma = (0.299*$r + 0.587*$g + 0.114*$b);
    // return dark text for light backgrounds, white for dark backgrounds
    return ($luma > 186) ? '#111111' : '#ffffff';
}

// calm palette fallback by status name (used if DB color missing)
function status_palette_color($name) {
    $k = strtolower(trim((string)$name));
    switch ($k) {
        case 'applicants active':                return '#3B82F6'; // calm blue
        case 'approve':                           return '#10B981'; // emerald
        case 'complete':                          return '#64748B'; // slate/neutral
        case 'created':                           return '#6B7280'; // neutral
        case 'hire confirmed':                    return '#16A34A'; // green
        case 'hire partially confirmed':          return '#F59E0B'; // amber
        case 'hiring active':                     return '#3B82F6'; // blue
        case 'interviews active':                 return '#0EA5E9'; // sky
        case 're-open':
        case 'reopen':                            return '#3B82F6'; // blue
        case 'rejected':                          return '#EF4444'; // soft red
        case 'send for approval':                 return '#F97316'; // orange
        case 'short-close':
        case 'short close':                       return '#94A3B8'; // light slate
        default:                                  return '#6B7280'; // fallback neutral
    }
}

// enrich loaded status_map with palette if color is missing/default
foreach ($status_map as $sid => $info) {
    $nm = $info['name'] ?? '';
    $clr = $info['color'] ?? '';
    if (!$clr || $clr === '#777777') {
        $status_map[$sid]['color'] = status_palette_color($nm);
    }
}

// --- fetch positions (same as before) -----------------------------------------
// get DB current unix timestamp to avoid PHP/DB timezone skew
$dbNow = time();
try {
    $rnow = $conn->query("SELECT UNIX_TIMESTAMP() AS now_ts");
    if ($rnow && ($rowNow = $rnow->fetch_assoc())) {
        $dbNow = (int)$rowNow['now_ts'];
    }
    if ($rnow) $rnow->free();
} catch (Throwable $e) {
    // ignore and use PHP time()
}

// include created_ts in the result so we can compute diff against DB time
$positions_all = [];
$sql = "
  SELECT
    p.id,
    p.title,
    p.department,
    p.team,
    p.manager_name,
    p.requirements,
    p.employment_type,
    p.openings,
    p.status_id,
    p.created_at,
    UNIX_TIMESTAMP(p.created_at) AS created_ts,
    COALESCE(s.status_name, '') AS status_name
  FROM positions p
  LEFT JOIN positions_status s ON p.status_id = s.status_id
  ORDER BY p.created_at DESC
";
$pos_res = $conn->query($sql);
if ($pos_res) {
    while ($r = $pos_res->fetch_assoc()) $positions_all[] = $r;
    $pos_res->free();
} else {
    // DB query failed — show short debug and stop further rendering so we can see the error
    echo '<div class="db-debug-error">';
    echo '<strong>DEBUG:</strong> positions query failed: ' . htmlspecialchars($conn->error) . '<br>';
    echo '<small>SQL:</small> ' . htmlspecialchars($sql);
    echo '</div>';
    // optional: halt to avoid rendering broken page (remove "exit" after debugging)
    // exit;
}

// expose a simple count for debugging/visibility
$positions_count = count($positions_all);

// quick debug removed

// build a map of applicant counts per position so we can show counts on cards
$applicant_counts = [];
$acRes = $conn->query("SELECT position_id, COUNT(*) AS cnt FROM applicants GROUP BY position_id");
if ($acRes) {
  while ($ar = $acRes->fetch_assoc()) {
    $applicant_counts[(int)$ar['position_id']] = (int)$ar['cnt'];
  }
  $acRes->free();
}

// Fetch distinct lists for list-based filters
$titles = [];
$tres = $conn->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(title),''),'') AS title FROM positions WHERE title IS NOT NULL AND TRIM(title) <> '' ORDER BY title");
if ($tres) { while ($r = $tres->fetch_assoc()) $titles[] = $r['title']; $tres->free(); }

$dept_list = [];
$dres = $conn->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(department),''),'') AS department FROM positions WHERE department IS NOT NULL AND TRIM(department) <> '' ORDER BY department");
if ($dres) { while ($r = $dres->fetch_assoc()) $dept_list[] = $r['department']; $dres->free(); }

$team_list = [];
$tmres = $conn->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(team),''),'') AS team FROM positions WHERE team IS NOT NULL AND TRIM(team) <> '' ORDER BY team");
if ($tmres) { while ($r = $tmres->fetch_assoc()) $team_list[] = $r['team']; $tmres->free(); }

$manager_list = [];
$mres = $conn->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(manager_name),''),'') AS manager_name FROM positions WHERE manager_name IS NOT NULL AND TRIM(manager_name) <> '' ORDER BY manager_name");
if ($mres) { while ($r = $mres->fetch_assoc()) $manager_list[] = $r['manager_name']; $mres->free(); }

$employment_list = [];
$eres = $conn->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(employment_type),''),'') AS employment FROM positions WHERE employment_type IS NOT NULL AND TRIM(employment_type) <> '' ORDER BY employment");
if ($eres) { while ($r = $eres->fetch_assoc()) $employment_list[] = $r['employment']; $eres->free(); }

$openings_list = [];
$ores = $conn->query("SELECT DISTINCT COALESCE(openings, '') AS openings FROM positions ORDER BY openings ASC");
if ($ores) { while ($r = $ores->fetch_assoc()) $openings_list[] = $r['openings']; $ores->free(); }
?>
<link rel="stylesheet" href="styles/view_positions.css">
<link rel="stylesheet" href="assets/css/notify.css">
<script src="assets/js/notify.js"></script>

<?php
// one-time read of created id from query or session flash
$__createdId = '';
if (isset($_GET['created'])) {
  $__createdId = preg_replace('/\D+/', '', (string)$_GET['created']);
} elseif (isset($_SESSION['position_created_id'])) {
  $__createdId = (string)$_SESSION['position_created_id'];
  unset($_SESSION['position_created_id']);
}
?>
<script>
(function(){
  // Server flash first, then fall back to URL param if present
  var createdId = <?php echo json_encode($__createdId); ?>;
  if (!createdId) {
    try {
      var u = new URL(window.location.href);
      var qp = u.searchParams.get('created');
      if (qp) createdId = String(qp).replace(/\D+/g,'');
    } catch(e){}
  }
  if (!createdId) return;

  // Avoid duplicate toasts within this tab session
  if (sessionStorage.getItem('posCreatedShown') === String(createdId)) {
    try {
      var u2 = new URL(window.location.href);
      if (u2.searchParams.has('created')) {
        u2.searchParams.delete('created');
        history.replaceState(null, document.title, u2.pathname + (u2.search ? '?' + u2.searchParams.toString() : '') + u2.hash);
      }
    } catch(e){}
    return;
  }

  function showToast(){
    try {
      Notify.push({
        from: 'Positions',
        message: 'Position Ticket ID #' + createdId + ' has been created',
        color: '#16a34a',
        duration: 8000
      });
    } catch(e){}
    // Mark as shown and strip ?created so reloads don’t re-toast
    try {
      sessionStorage.setItem('posCreatedShown', String(createdId));
      var url = new URL(window.location.href);
      if (url.searchParams.has('created')) {
        url.searchParams.delete('created');
        history.replaceState(null, document.title, url.pathname + (url.search ? '?' + url.searchParams.toString() : '') + url.hash);
      }
    } catch(e){}
  }

  if (window.Notify && typeof window.Notify.push === 'function') showToast();
  else window.addEventListener('load', showToast);
})();
</script>
<main class="content-area">
  <?php
  // Temporary debug banner: shows whether server reached this point
  // and how many positions were returned by the query. Remove after debugging.
  $first_pos = !empty($positions_all) ? $positions_all[0] : null;
  ?>
  <!-- debug banner styling moved to external CSS when needed -->
  <script>console.log('view_positions debug: positions_count=' + <?php echo (int)$positions_count; ?>);</script>
    
    <!-- Modal Overlay (hidden by default) -->
    <div id="positionModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-card">
            <div class="modal-header">
              <h3 class="modal-title">Create New Position</h3>
              <button type="button" class="modal-close-x" aria-label="Close">&times;</button>
            </div>
            <form method="POST" action="create_position.php">
                <div class="field">
                    <label>Title</label>
                    <input name="title" required class="modal-input">
                </div>

                <!-- Department / 
                  / Team / Manager -->
                <div class="field inline">
                    <div>
                        <label>Department</label>
                        <select name="department_id" id="deptSelect" class="modal-input" required>
                            <option value="">-- Select Department --</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" data-director="<?= htmlspecialchars($d['director_name'] ?? '') ?>"><?= htmlspecialchars($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Director</label>
                        <!-- visible but disabled so user cannot change -->
                        <input type="text" id="directorDisplay" value="Unassigned" disabled class="modal-input">
                        <!-- hidden input to submit director value -->
                        <input type="hidden" name="director_name" id="directorNameField" value="">
                    </div>

                    <div>
                        <label>Team</label>
                        <select name="team_id" id="teamSelect" class="modal-input" disabled required>
                            <option value="">Select department first</option>
                        </select>
                    </div>

                    <div>
                        <label>Manager</label>
                        <input type="text" id="managerDisplay" value="Unassigned" disabled class="modal-input">
                        <input type="hidden" name="manager_name" id="managerNameField" value="">
                        <input type="hidden" name="manager_id" id="managerIdField" value="">
                    </div>
                </div>

                <!-- Experience Level / Min Age / Gender / Ethnicity -->
                <div class="field inline">
                    <div>
                        <label>Experience Level</label>
                        <input name="experience_level" type="text" placeholder="e.g. 2-4 years, Senior" class="modal-input" required>
                    </div>

                    <div>
                        <label>Minimum Age</label>
                        <input type="number" name="min_age" min="17" value="18" class="modal-input" required>
                    </div>

                    <div>
                        <label>Gender</label>
                        <select name="gender" class="modal-input" required>
                            <option value="">-- Select Gender --</option>
                            <option value="Any">Any</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>

                    <div>
                        <label>Ethnicity</label>
                        <select name="nationality_requirement" class="modal-input" required>
                            <option value="">-- Select Ethnicity --</option>
                            <option value="Any">Any</option>
                            <option value="Arab">Arab</option>
                            <option value="Foreign">Foreign</option>
                        </select>
                    </div>
                </div>

                <!-- Work location / Reason for opening / Working hours / Education Level (same line) -->
                <div class="field inline">
                    <div>
                        <label>Work Location</label>
                        <select name="work_location" class="modal-input" required>
                            <option value="">-- Select Work Location --</option>
                            <option value="Remote">Remote</option>
                            <option value="HQ">HQ</option>
                            <option value="Kuwait Office">Kuwait Office</option>
                            <option value="Saudi Office">Saudi Office</option>
                            <option value="Dubai Office">Dubai Office</option>
                            <option value="Hybrid">Hybrid</option>
                        </select>
                    </div>

                    <div>
                        <label>Reason for Opening</label>
                        <select name="reason_for_opening" class="modal-input" required>
                            <option value="">-- Select Reason --</option>
                            <option value="Replacement">Replacement</option>
                            <option value="Vacancy">Vacancy</option>
                            <option value="New Position">New Position</option>
                            <option value="Temporary">Temporary</option>
                        </select>
                    </div>

                    <div>
                        <label>Working Hours</label>
                        <select name="working_hours" class="modal-input" required>
                            <option value="">-- Select Working Hours --</option>
                            <option value="9-5">9-5</option>
                            <option value="Shifts">Shifts</option>
                        </select>
                    </div>

                    <div>
                        <label>Education Level</label>
                        <select name="education_level" class="modal-input" required>
                            <option value="">-- Select Education --</option>
                            <option value="Bachelors">Bachelors</option>
                            <option value="Masters">Masters</option>
                            <option value="PHD">PHD</option>
                            <option value="REAL LIFE EXPERIENCE">REAL LIFE EXPERIENCE</option>
                        </select>
                    </div>
                </div>

                <!-- Employment Type / Openings / Salary / Hiring Deadline (single row restored) -->
                <div class="field inline">
                    <div>
                        <label>Employment Type</label>
                        <select name="employment_type" class="modal-input" required>
                            <option value="">-- Select Employment Type --</option>
                            <option value="Full-time">Full-time</option>
                            <option value="Part-time">Part-time</option>
                            <option value="Contract">Contract</option>
                            <option value="Internship">Internship</option>
                        </select>
                    </div>

                    <div>
                        <label>Openings</label>
                        <input type="number" name="openings" min="1" value="1" class="modal-input" required>
                    </div>

                    <div>
                        <label>Salary</label>
                        <input type="number" name="salary" min="450" step="1" value="450" class="modal-input" required>
                    </div>

                    <div>
                        <label>Hiring Deadline</label>
                        <input type="date" name="hiring_deadline" class="modal-input" required>
                    </div>
                </div>

        <div class="field">
          
          <label>Description</label>
          <textarea name="description" rows="10" class="modal-input" required></textarea>
        </div>

        <div class="field">
          
          <label>Role Responsibilities</label>
          <textarea name="role_responsibilities" id="role_responsibilities" rows="6" class="modal-input"></textarea>
        </div>

        <div class="field">
          <label>Role Expectations </label><br>
          <textarea name="role_expectations" id="role_expectations" rows="6" class="modal-input"></textarea>
        </div>

                <div class="field">
                  
                    <label>Requirements/Skills/Languages/Certificats:</label>   
                    <div id="requirements">
                      <input type="text" id="reqInput" placeholder="e.g. Excel + Enter" class="modal-input">
                        <!-- add a focusable "+" so users can Tab to it and press Enter/Space -->
                        <button type="button" id="reqAddBtn" class="modal-add-btn" aria-label="Add requirement" title="Add requirement">+</button>
                    </div>
                    <div id="reqList" class="req-tags"></div>
                    <input type="hidden" name="requirements" id="requirementsField">
                </div>
                  <div class="modal-actions">
                    <button type="button" onclick="closeModal()" class="modal-submit-btn">Cancel</button>
                    <button type="submit" class="modal-submit-btn">Submit</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // preserve existing teamsByDept variable usage
    const teamsByDept = <?= $teams_json ?> || {};

    // When department changes, set director display and hidden field (if department option provides director)
    const deptSelectEl = document.getElementById('deptSelect');
    const directorDisplay = document.getElementById('directorDisplay');
    const directorNameField = document.getElementById('directorNameField');
    const teamSelectEl = document.getElementById('teamSelect');
    const managerDisplay = document.getElementById('managerDisplay');
    const managerNameField = document.getElementById('managerNameField');
    const managerIdField = document.getElementById('managerIdField');

    if (deptSelectEl) {
      deptSelectEl.addEventListener('change', function () {
        const opt = this.selectedOptions[0];
        const director = opt ? (opt.dataset.director || '') : '';
        directorDisplay.value = director || 'Unassigned';
        directorNameField.value = director || '';

        // populate teams for this department id using teamsByDept if available
        const did = String(this.value || '');
        teamSelectEl.innerHTML = '';
        managerDisplay.value = 'Unassigned';
        managerNameField.value = '';
        managerIdField.value = '';
        if (!did || !teamsByDept[did] || teamsByDept[did].length === 0) {
            teamSelectEl.disabled = true;
            teamSelectEl.innerHTML = '<option value=\"\">Unassigned</option>';
            return;
        }
        const opt0 = document.createElement('option'); opt0.value=''; opt0.textContent='-- Select Team --'; teamSelectEl.appendChild(opt0);
        teamsByDept[did].forEach(t => {
            const o = document.createElement('option');
            o.value = t.id;
            o.textContent = t.name;
            o.dataset.managerId = t.manager_id || '';
            o.dataset.managerName = t.manager_name || '';
            teamSelectEl.appendChild(o);
        });
        teamSelectEl.disabled = false;
      });
    }

    // when team selected set manager fields
    teamSelectEl && teamSelectEl.addEventListener('change', function () {
        const sel = this.selectedOptions[0];
        if (!sel || !sel.value) {
            managerDisplay.value = 'Unassigned';
            managerNameField.value = '';
            managerIdField.value = '';
            return;
        }
        managerDisplay.value = sel.dataset.managerName || 'Unassigned';
        managerNameField.value = sel.dataset.managerName || '';
        managerIdField.value = sel.dataset.managerId || '';
    });
    </script>

    <script>
(function(){
  const form = document.querySelector('#positionModal form[action="create_position.php"]');
  if (!form) return;

  const reqInput  = document.getElementById('reqInput');
  const reqAddBtn = document.getElementById('reqAddBtn');
  const reqHidden = document.getElementById('requirementsField');
  const reqList   = document.getElementById('reqList');

  let reqTags = [];

  function normalize(s){ return String(s || '').trim(); }
  function escapeHtml(s){ return String(s).replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch])); }

  function renderReqs(){
    reqList.classList.add('req-tags');
    reqList.innerHTML = reqTags.map((t, i) =>
      '<span class="req-tag" data-i="'+ i +'">'+
        '<span>'+ escapeHtml(t) +'</span>'+
        '<button type="button" class="req-remove" aria-label="Remove">&times;</button>'+
      '</span>'
    ).join('');
    reqHidden.value = reqTags.join(', ');
  }

  function addReqTag(text){
    const v = normalize(text);
    if (!v) return;
    // avoid dupes (case-insensitive)
    if (reqTags.some(t => t.toLowerCase() === v.toLowerCase())) return;
    reqTags.push(v);
    renderReqs();
  }

  // commitInput(keepFocus = true)
  // - when keepFocus === true (Enter / +), focus returns to the input
  // - when keepFocus === false (blur or form submit), do NOT re-focus
  function commitInput(keepFocus = true){
    if (!reqInput) return;
    const val = normalize(reqInput.value);
    if (!val) {
      // if nothing to commit, do not steal focus
      if (keepFocus) reqInput.focus();
      return;
    }
    addReqTag(val);
    reqInput.value = '';
    reqInput.classList.remove('invalid');
    if (keepFocus) {
      // small delay helps on some browsers to keep typing flow
      setTimeout(() => { try { reqInput.focus(); } catch(e){} }, 0);
    }
  }

  // expose to validation script
  window.__commitReqInput = commitInput;

  // Enter or comma adds a tag, prevents form submit
  reqInput.addEventListener('keydown', function(e){
    if (e.key === 'Enter' || e.key === ',') {
      e.preventDefault();
      commitInput(true); // keep focus for rapid entry
    }
  });

  // "+" button click / keyboard
  if (reqAddBtn) {
    reqAddBtn.addEventListener('click', function(e){ e.preventDefault(); commitInput(true); });
    reqAddBtn.addEventListener('keydown', function(e){
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); commitInput(true); }
    });
  }

  // Remove tag
  reqList.addEventListener('click', function(e){
    const rm = e.target.closest('.req-remove');
    if (!rm) return;
    const wrap = rm.closest('.req-tag');
    const i = wrap ? parseInt(wrap.dataset.i, 10) : -1;
    if (!isNaN(i) && i >= 0) {
      reqTags.splice(i, 1);
      renderReqs();
      reqInput.focus();
    }
  });

  // Commit on blur but DO NOT refocus (so clicking another field works)
  reqInput.addEventListener('blur', function(){ commitInput(false); });

  // allow external reset
  window.__clearReqTags = function(){
    reqTags = [];
    reqList.innerHTML = '';
    if (reqHidden) reqHidden.value = '';
    if (reqInput) reqInput.value = '';
  };
})();
</script>


    

<div class="positions-card">
  <!-- Header (title + create) -->
    <div class="positions-header-row">
      <h2 class="page-title">Positions</h2>
      <div class="header-actions">
        <button id="openCreatePositionBtn" class="btn-orange" data-open-create="1" style="margin-right: 20px;">+ Create Position</button>
      </div>
    </div>

  <!-- Filters (always visible) -->
    <div class="positions-filters" style="margin-left: 10px;">
      <div class="filters-controls">
      <select id="fTitle">
        <option value="">All titles</option>
        <?php foreach ($titles as $tt): ?>
          <option value="<?= htmlspecialchars(strtolower($tt)) ?>"><?= htmlspecialchars($tt) ?></option>
        <?php endforeach; ?>
      </select>

      <select id="fStatus">
        <option value="">All status</option>
        <?php
          $st = $conn->query("SELECT status_id,status_name FROM positions_status ORDER BY status_id");
          if ($st) {
            while ($s = $st->fetch_assoc()) {
              echo '<option value="'.(int)$s['status_id'].'">'.htmlspecialchars($s['status_name']).'</option>';
            }
            $st->free();
          }
        ?>
      </select>

      <select id="fDept">
        <option value="">All departments</option>
        <?php foreach ($dept_list as $dname): ?>
          <option value="<?= htmlspecialchars(strtolower($dname)) ?>"><?= htmlspecialchars($dname) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="fTeam">
        <option value="">All teams</option>
        <?php foreach ($team_list as $tname): ?>
          <option value="<?= htmlspecialchars(strtolower($tname)) ?>"><?= htmlspecialchars($tname) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="fEmployment">
        <option value="">Employment</option>
        <?php foreach ($employment_list as $e): ?>
          <option value="<?= htmlspecialchars(strtolower($e)) ?>"><?= htmlspecialchars($e) ?></option>
        <?php endforeach; ?>
      </select>
      <input id="fRequirements" type="search" placeholder="Requirements" />

      <!-- Created date filter: from / to -->
      <input id="fCreatedFrom" type="date" title="Created from" />
      <input id="fCreatedTo" type="date" title="Created to" />

      <button id="clearFilters" class="btn-orange">Clear Filters</button>
    </div>
  </div>

  <!-- Grid (shows empty state when there are no positions) -->
  <div id="positionsGrid" class="positions-grid">
    <?php if (empty($positions_all)): ?>
      <div class="empty-state">
        <div class="title">No positions found</div>
        <div class="desc">Use the Create Button to initiate a Position.</div>
        <div class="desc debug">Debug: positions query returned <?php echo (int)$positions_count; ?> rows.</div>
        <?php if (in_array('positions_create', $_SESSION['user']['access_keys'] ?? []) || in_array($user['role'] ?? '', ['hr','manager'])): ?>
          <button class="btn-orange">+ Create Position</button>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <?php foreach ($positions_all as $r):
          $statusId = isset($r['status_id']) ? (int)$r['status_id'] : 0;
          $statusName = $status_map[$statusId]['name'] ?? ($r['status_name'] ?? 'Unknown');
          $statusColor = $status_map[$statusId]['color'] ?? '#777777';
          $statusTextColor = status_text_color($statusColor);
          $dataReq = htmlspecialchars($r['requirements'] ?? '');
          $ageDisplay = '&mdash;';
          if (!empty($r['created_at'])) {
              // prefer DB-supplied created_ts and dbNow to avoid timezone mismatch
              $createdTs = isset($r['created_ts']) && $r['created_ts'] !== null ? (int)$r['created_ts'] : false;
              if ($createdTs === false || $createdTs <= 0) {
                  $ts = strtotime($r['created_at']);
                  $createdTs = ($ts === false) ? false : (int)$ts;
              }
              if ($createdTs !== false) {
                  $diff = $dbNow - $createdTs; // dbNow set earlier (falls back to time())
                  // clamp negative small offsets to 0 so we don't show negative hours
                  if ($diff <= 0) {
                      $ageDisplay = '0m';
                  } elseif ($diff < 3600) {
                      // show minutes when less than 1 hour (round up to 1m)
                      $mins = (int) ceil($diff / 60);
                      $ageDisplay = $mins . 'm';
                  } elseif ($diff < 86400) {
                      $hours = (int) floor($diff / 3600);
                      $ageDisplay = $hours . 'h';
                  } else {
                      $days = (int) floor($diff / 86400);
                      $ageDisplay = $days . 'd';
                    }
                  }
                }
                // format created datetime for display (local PHP formatting)
                $createdDisplay = '';
                if (!empty($r['created_at'])) {
                  $createdDisplay = date('Y-m-d H:i', strtotime($r['created_at']));
                }
                  $appCount = isset($applicant_counts[(int)$r['id']]) ? (int)$applicant_counts[(int)$r['id']] : 0;
      ?>
            <article class="position-ticket" role="article"
          data-id="<?= (int)$r['id'] ?>"
          data-title="<?= htmlspecialchars(strtolower($r['title'])) ?>"
          data-status-id="<?= $statusId ?>"
          data-department="<?= htmlspecialchars(strtolower($r['department'] ?? '')) ?>"
          data-team="<?= htmlspecialchars(strtolower($r['team'] ?? '')) ?>"
          data-manager="<?= htmlspecialchars(strtolower($r['manager_name'] ?? '')) ?>"
          data-employment="<?= htmlspecialchars(strtolower($r['employment_type'] ?? '')) ?>"
          data-openings="<?= (int)($r['openings'] ?? 0) ?>"
              data-applicants="<?= $appCount ?>"
          data-requirements="<?= $dataReq ?>"
          data-created="<?= htmlspecialchars($r['created_at'] ?? '') ?>"
          style="border-left:5px solid <?= htmlspecialchars($statusColor) ?>;"
        >
          <div class="ticket-header">
            <div class="ticket-left">
              <div class="pos-title"><?= htmlspecialchars($r['title']) ?></div>
              <div class="pos-sub"><?= htmlspecialchars($r['department'] ?: 'Unassigned') ?> • <?= htmlspecialchars($r['team'] ?: '—') ?></div>

              <div class="ticket-actions-row">
                <div class="pos-id-badge" title="Position Ticket ID">ID #<?= (int)$r['id'] ?></div>
              </div>
            </div>

            <div class="ticket-right">
              <div class="status-badge" id="posStatusBadge-<?= (int)$r['id'] ?>" style="background: <?= htmlspecialchars($statusColor) ?>; color: <?= htmlspecialchars($statusTextColor) ?>;">
                <?= htmlspecialchars($statusName) ?>
              </div>
              <div class="status-meta">Openings: <?= (int)($r['openings'] ?? 0) ?> &nbsp;•&nbsp; Applicants: <?= $appCount ?></div>
            </div>
          </div>

          <div class="requirements-preview">
            <?= nl2br(htmlspecialchars(mb_strimwidth($r['requirements'] ?? $r['description'] ?? '', 0, 800, '...'))) ?>
          </div>

          <div class="pos-footer">
            <div>Manager: <?= htmlspecialchars($r['manager_name'] ?: 'Unassigned') ?></div>
            <div>Age <?= htmlspecialchars($ageDisplay) ?></div>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</main>

<script>
// Filters script (works even if there are no cards initially)
(function(){
  const filters = {
    title: document.getElementById('fTitle'),
    status: document.getElementById('fStatus'),
    department: document.getElementById('fDept'),
    team: document.getElementById('fTeam'),
    employment: document.getElementById('fEmployment'),
    requirements: document.getElementById('fRequirements'),
    createdFrom: document.getElementById('fCreatedFrom'),
    createdTo: document.getElementById('fCreatedTo'),
  };
  const clearBtn = document.getElementById('clearFilters');

  function parseDateOnly(s){
    if(!s) return null;
    const d = new Date(s);
    if (isNaN(d)) {
      const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s);
      if (!m) return null;
      return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    }
    return new Date(d.getFullYear(), d.getMonth(), d.getDate());
  }

  function applyFilters(){
    const cards = Array.from(document.querySelectorAll('.position-ticket'));
    const f = {
      title: (filters.title?.value || '').toLowerCase().trim(),
      status: (filters.status?.value || '').toLowerCase().trim(),
      department: (filters.department?.value || '').toLowerCase().trim(),
      team: (filters.team?.value || '').toLowerCase().trim(),
      employment: (filters.employment?.value || '').toLowerCase().trim(),
      requirements: (filters.requirements?.value || '').toLowerCase().trim(),
      createdFrom: parseDateOnly(filters.createdFrom?.value || ''),
      createdTo: parseDateOnly(filters.createdTo?.value || '')
    };
    cards.forEach(card => {
      const meta = {
        title: (card.dataset.title || '').toLowerCase(),
        status: (card.dataset.statusId || '').toLowerCase(),
        department: (card.dataset.department || '').toLowerCase(),
        team: (card.dataset.team || '').toLowerCase(),
        employment: (card.dataset.employment || '').toLowerCase(),
        requirements: (card.dataset.requirements || '').toLowerCase(),
        created: card.dataset.created ? parseDateOnly(card.dataset.created) : null
      };
      const okTitle = !f.title || meta.title === f.title;
      const okStatus = !f.status || meta.status === f.status;
      const okDept = !f.department || meta.department === f.department;
      const okTeam = !f.team || meta.team === f.team;
      const okEmployment = !f.employment || meta.employment === f.employment;
      const okReq = !f.requirements || meta.requirements.indexOf(f.requirements) !== -1;
      let okDate = true;
      if (f.createdFrom && meta.created) okDate = okDate && (meta.created >= f.createdFrom);
      if (f.createdTo && meta.created) okDate = okDate && (meta.created <= f.createdTo);
      card.style.display = (okTitle && okStatus && okDept && okTeam && okEmployment && okReq && okDate) ? '' : 'none';
    });
  }

  Object.values(filters).forEach(el => {
    if (!el) return;
    el.addEventListener('change', applyFilters);
    el.addEventListener('input', applyFilters);
  });
  clearBtn && clearBtn.addEventListener('click', function(){
    Object.values(filters).forEach(el => { if(el) el.value = ''; });
    applyFilters();
  });
  window.applyPositionsFilters = applyFilters;
})();

// Prefill filters from query params (so dashboard links can open view_positions with filters)
(function(){
  try{
    const params = new URL(window.location.href).searchParams;
    const mappings = {
      'fStatus': 'fStatus',
      'fDept': 'fDept',
      'fTeam': 'fTeam',
      'fCreatedFrom': 'fCreatedFrom',
      'fCreatedTo': 'fCreatedTo',
      'fTitle': 'fTitle'
    };
    let changed = false;
    Object.keys(mappings).forEach(k=>{
      if (!params.has(k)) return;
      const el = document.getElementById(mappings[k]);
      if (!el) return;
      const v = params.get(k) || '';
      // For department/team selects values in view_positions are lowercase; preserve as provided
      try { el.value = v; changed = true; } catch(e){}
    });
    if (changed && typeof window.applyPositionsFilters === 'function') {
      // small timeout to allow other scripts to initialize
      setTimeout(()=>{ try { window.applyPositionsFilters(); } catch(e){} }, 60);
    }
  }catch(e){}
})();
</script>

<script>
(function(){
  const modal = document.getElementById('positionModal');
  if (!modal) return;

  const form = modal.querySelector('form[action="create_position.php"]');
  const teamSelectEl = document.getElementById('teamSelect');
  const deptSelectEl = document.getElementById('deptSelect');
  const directorDisplay = document.getElementById('directorDisplay');
  const directorNameField = document.getElementById('directorNameField');
  const managerDisplay = document.getElementById('managerDisplay');
  const managerNameField = document.getElementById('managerNameField');
  const managerIdField = document.getElementById('managerIdField');

  function resetCreateForm(){
    try { form && form.reset(); } catch(e){}
    // reset dependent fields
    if (teamSelectEl) {
      teamSelectEl.disabled = true;
      teamSelectEl.innerHTML = '<option value="">Unassigned</option>';
    }
    if (directorDisplay) directorDisplay.value = 'Unassigned';
    if (directorNameField) directorNameField.value = '';
    if (managerDisplay) managerDisplay.value = 'Unassigned';
    if (managerNameField) managerNameField.value = '';
    if (managerIdField) managerIdField.value = '';
    // clear requirements tags if present
    try { if (window.__clearReqTags) window.__clearReqTags(); } catch(e){}
    // clear validation highlights
    try { modal.querySelectorAll('.modal-input.invalid').forEach(el => el.classList.remove('invalid')); } catch(e){}
  }

  function openCreateModal(){
    try {
      modal.classList.add('show');
      modal.setAttribute('aria-hidden','false');
      modal.style.display = 'flex';
      const first = modal.querySelector('.modal-input, input, select, textarea');
      if (first && typeof first.focus === 'function') first.focus();
    } catch(e){ console.error('openCreateModal error', e); }
  }

  function closeCreateModal(){
    try { if (document.activeElement && modal.contains(document.activeElement)) document.activeElement.blur(); } catch(e){}
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden','true');
    try { modal.style.display = 'none'; } catch(e){}
  }

  window.closeModal = function(){ resetCreateForm(); closeCreateModal(); };

  // open from toolbar or empty state
  document.querySelectorAll('#openCreatePositionBtn, [data-open-create]').forEach(function(btn){
    btn.addEventListener('click', function(e){ e && e.preventDefault && e.preventDefault(); openCreateModal(); });
  });

  // Cancel and X both reset + close
  modal.addEventListener('click', function(e){
    if (e.target && e.target.closest && e.target.closest('.modal-cancel-btn')) { resetCreateForm(); closeCreateModal(); }
    if (e.target && e.target.closest && e.target.closest('.modal-close-x'))    { resetCreateForm(); closeCreateModal(); }
  });

  // Do NOT close when clicking outside (overlay)
  // (Removed previous overlay click-to-close handler)

  // Esc closes and resets
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && modal.classList.contains('show')) { resetCreateForm(); closeCreateModal(); }
  });
})();
</script>
<script>
(function(){
  // Notify is already loaded at top via assets/js/notify.js; fallback if needed:
  async function ensureNotify(){ if (window.Notify?.push) return; await new Promise(r=>setTimeout(r,0)); }

  const form = document.querySelector('#positionModal form[action="create_position.php"]');
  if (!form) return;

  const field = (name) => form.querySelector('[name="'+ name +'"]');
  const labels = {
    title: 'Title',
    department_id: 'Department',
    team_id: 'Team',
    experience_level: 'Experience Level',
    min_age: 'Minimum Age',
    gender: 'Gender',
    nationality_requirement: 'Ethnicity',
    work_location: 'Work Location',
    reason_for_opening: 'Reason for Opening',
    working_hours: 'Working Hours',
    education_level: 'Education Level',
    employment_type: 'Employment Type',
    openings: 'Openings',
    salary: 'Salary',
    hiring_deadline: 'Hiring Deadline',
    description: 'Description',
    requirements: 'Requirements'
  };

  const reqInput  = document.getElementById('reqInput');
  const reqHidden = document.getElementById('requirementsField');

  // helper: insert bullet at caret for the three textareas (description, role responsibilities, role expectations)
  function insertBulletAtCaret(textarea) {
    try {
      const el = textarea;
      const bullet = '• ';
      if (document.selection) {
        // IE
        el.focus();
        const sel = document.selection.createRange();
        sel.text = bullet;
      } else if (el.selectionStart || el.selectionStart === 0) {
        const start = el.selectionStart;
        const end = el.selectionEnd;
        const before = el.value.substring(0, start);
        const after = el.value.substring(end, el.value.length);
        el.value = before + bullet + after;
        el.selectionStart = el.selectionEnd = start + bullet.length;
        el.focus();
      } else {
        el.value += bullet;
        el.focus();
      }
    } catch (e) { console.warn('insertBulletAtCaret', e); }
  }

  // attach bullet button handlers (buttons placed near labels)
  document.querySelectorAll('button[data-insert]').forEach(btn => {
    btn.addEventListener('click', function(e){
      const name = btn.getAttribute('data-insert');
      const ta = document.querySelector('[name="' + name + '"]');
      if (ta) insertBulletAtCaret(ta);
    });
  });

  // Base required fields (only actual form field names)
  const baseRequired = [
    'title','department_id','experience_level','min_age','gender','nationality_requirement',
    'work_location','reason_for_opening','working_hours','education_level',
    'employment_type','openings','salary','hiring_deadline','description','requirements'
  ];

  function clearInvalid() {
    form.querySelectorAll('.modal-input.invalid').forEach(el => el.classList.remove('invalid'));
  }
  function markInvalid(el){ if (el) el.classList.add('invalid'); }

  // Teams map injected earlier
  const teamsByDept = window.teamsByDept || {};

  form.addEventListener('submit', async function(ev){
    // commit any pending text to tags first (prevents Enter from causing submit with empty requirements)
    try { if (window.__commitReqInput) window.__commitReqInput(); } catch(e){}

    clearInvalid();

    // If user typed into reqInput but didn't press Enter, commit it
    if (reqInput && reqHidden && !reqInput.classList.contains('committed')) {
      if (reqInput.value && (!reqHidden.value || reqHidden.value.indexOf(reqInput.value) === -1)) {
        // trigger the commit handler from the earlier script, if present
        try { reqInput.dispatchEvent(new Event('blur')); } catch(e){}
      }
    }

    // derive dynamic required: team required if selected department has teams
    const deptId = (field('department_id')?.value || '').trim();
    const teamRequired = !!(deptId && teamsByDept[deptId] && teamsByDept[deptId].length > 0);

    const required = baseRequired.slice();
    if (teamRequired) required.push('team_id');

    const missing = [];
    const firstInvalid = { el: null };

    function requireNonEmpty(name, elOverride){
      const el = elOverride || field(name);
      const val = (el?.value ?? '').toString().trim();
      if (!val) {
        missing.push(labels[name] || name);
        // for requirements, highlight the visible input instead of the hidden field
        if (name === 'requirements') markInvalid(reqInput); else markInvalid(el);
        if (!firstInvalid.el) firstInvalid.el = (name === 'requirements') ? reqInput : el;
      }
    }
    function requirePositiveInt(name, minVal){
      const el = field(name);
      const raw = (el?.value ?? '').toString().trim();
      const num = parseInt(raw, 10);
      if (!raw || isNaN(num) || num < (minVal ?? 1)) {
        missing.push(labels[name] || name);
        markInvalid(el);
        if (!firstInvalid.el) firstInvalid.el = el;
      }
    }
    function requireDate(name){
      const el = field(name);
      const raw = (el?.value ?? '').toString().trim();
      if (!raw || isNaN(new Date(raw))) {
        missing.push(labels[name] || name);
        markInvalid(el);
        if (!firstInvalid.el) firstInvalid.el = el;
      }
    }

    // non-empty checks
    required.forEach(n => {
      if (['openings','salary','min_age'].includes(n)) return;
      if (n === 'hiring_deadline') return;
      if (n === 'requirements') {
        // Requirements: consider valid if either tags exist (hidden) or input has text
        const hasReq = (reqHidden?.value || '').trim() || (reqInput?.value || '').trim();
        if (!hasReq) requireNonEmpty('requirements');
      } else {
        requireNonEmpty(n);
      }
    });

    // numeric checks
    requirePositiveInt('openings', 1);
    requirePositiveInt('salary', 1);
    requirePositiveInt('min_age', 17);

    // date
    requireDate('hiring_deadline');

    if (missing.length > 0) {
      ev.preventDefault();
      await ensureNotify();
      const msg = 'Please fill: ' + missing.join(', ');
      try { Notify.push({ from:'Positions', message: msg, color:'#dc2626', duration: 6000 }); } catch(e){}
      if (firstInvalid.el && typeof firstInvalid.el.focus === 'function') firstInvalid.el.focus();
      return false;
    }

    // ensure requirements are submitted (join tags + stray text if any)
    if (reqHidden) {
      const existing = (reqHidden.value || '').trim();
      const stray = (reqInput?.value || '').trim();
      if (stray) reqHidden.value = existing ? (existing + ', ' + stray) : stray;
    }

    return true;
  });
})();
</script>
<script>
(function(){
  function ensureViewer(){
    let ov = document.getElementById('posViewerModal');
    if (ov) return ov;
    ov = document.createElement('div');
    ov.id = 'posViewerModal';
    ov.className = 'modal-overlay';
    ov.setAttribute('aria-hidden','true');
    ov.innerHTML =
      '<div class="modal-card">'+
        '<div class="modal-header">'+
          '<h3 id="posViewerTitle">Position</h3>'+
          '<div class="viewer-header-actions">'+
            '<div id="posViewerStatusContainer"></div>'+
            '<button type="button" class="modal-close-x" aria-label="Close">&times;</button>'+
          '</div>'+
        '</div>'+
        '<div id="posViewerMeta" class="viewer-meta"></div>'+
        '<div id="posViewerContent"></div>'+
      '</div>';
    document.body.appendChild(ov);

    // close with X only (not by clicking outside)
    ov.addEventListener('click', function(e){
      if (e.target && e.target.closest && e.target.closest('.modal-close-x')) closeViewer();
    });
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape' && ov.classList.contains('show')) closeViewer();
    });
    return ov;
  }

  function openViewer(){ const ov = ensureViewer(); ov.classList.add('show'); ov.setAttribute('aria-hidden','false'); ov.style.display='flex'; }
  function closeViewer(){ const ov = document.getElementById('posViewerModal'); if (!ov) return; ov.classList.remove('show'); ov.setAttribute('aria-hidden','true'); ov.style.display='none'; const c = ov.querySelector('#posViewerContent'); if (c) c.innerHTML=''; }
  window.closePositionViewer = closeViewer; // allow fragment to close modal after actions

  // Safely execute scripts inside a container (fixes "appendChild Unexpected token 'if'")
  function runFragmentScripts(container){
    const scripts = container.querySelectorAll('script');
    scripts.forEach(old => {
      const ns = document.createElement('script');
      // copy type and src
      ns.type = old.type || 'text/javascript';
      if (old.src) {
        ns.src = old.src;
        ns.async = false;
        document.body.appendChild(ns);
        ns.addEventListener('load', () => ns.remove());
      } else {
        ns.text = old.textContent || '';
        document.body.appendChild(ns);
        ns.remove();
      }
    });
  }

  async function openPositionEditor(id){
    const ov = ensureViewer();
    const title = ov.querySelector('#posViewerTitle');
    const content = ov.querySelector('#posViewerContent');
    title.textContent = 'Position #' + id;
    content.innerHTML = '<div class="loading-placeholder">Loading...</div>';
    // populate meta (created) from the card if available
    try {
      const meta = ov.querySelector('#posViewerMeta');
      const card = document.querySelector('.position-ticket[data-id="' + id + '"]');
      if (meta) {
        if (card && card.dataset && card.dataset.created) {
          // Use the dataset.created raw value; fragment may overwrite later
          const raw = card.dataset.created || '';
          meta.textContent = 'Created: ' + raw;
        } else {
          meta.textContent = '';
        }
      }
    } catch (e) {}
    openViewer();

    try {
      const res = await fetch('get_position.php?id=' + encodeURIComponent(id), { credentials:'same-origin' });
      const html = await res.text();
      content.innerHTML = html;     // inject HTML
      runFragmentScripts(content);   // then run its scripts (binder, etc.)
      // If fragment contains its own status badge, move it into header container
      try {
        const statusContainer = document.getElementById('posViewerStatusContainer');
        const fragBadge = content.querySelector('.status-badge');
        if (statusContainer && fragBadge) {
          statusContainer.innerHTML = fragBadge.outerHTML;
          fragBadge.remove();
        }
        // If fragment contains a created line, leave it; otherwise prefer our meta line already set
      } catch (e) { console.warn('viewer badge move failed', e); }
    } catch (e) {
      console.error('openPositionEditor failed', e);
      try { Notify.push({ from:'Positions', message:'Failed to load position', color:'#dc2626' }); } catch(_) {}
    }
  }

  // Click a card to open editor
  document.addEventListener('click', function(e){
    const card = e.target && e.target.closest && e.target.closest('.position-ticket');
    if (!card) return;
    const id = card.getAttribute('data-id');
    if (!id) return;
    e.preventDefault();
    openPositionEditor(id);
  });
})();
</script>
<script>
// Calm palette and readable text
function statusColorForName(name){
  const k = String(name || '').toLowerCase().trim();
  switch (k) {
    case 'applicants active':               return '#3B82F6';
    case 'approve':                          return '#10B981';
    case 'complete':                         return '#64748B';
    case 'created':                          return '#6B7280';
    case 'hire confirmed':                   return '#16A34A';
    case 'hire partially confirmed':         return '#F59E0B';
    case 'hiring active':                    return '#3B82F6';
    case 'interviews active':                return '#0EA5E9';
    case 're-open':
    case 'reopen':                           return '#3B82F6';
    case 'rejected':                         return '#EF4444';
    case 'send for approval':                return '#F97316';
    case 'short-close':
    case 'short close':                      return '#94A3B8';
    default:                                 return '#6B7280';
  }
}
function statusTextColor(hex){
  if (!hex) return '#fff';
  hex = hex.replace('#','');
  if (hex.length===3) hex = hex.split('').map(c=>c+c).join('');
  const r = parseInt(hex.substr(0,2),16);
  const g = parseInt(hex.substr(2,2),16);
  const b = parseInt(hex.substr(4,2),16);
  const l = 0.299*r + 0.587*g + 0.114*b;
  return l > 186 ? '#111111' : '#ffffff';
}

// expose server-side status map (id -> {name,color}) so client can use DB colors
window.__statusMap = <?php echo json_encode($status_map, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?> || {};

// Update card when a ticket is updated in the modal
// Centralized success handler used by both fragment and modal-driven flows.
// This ensures a single notify/redirect/ui-update path and suppresses duplicates.
window.onPositionUpdateSuccess = function(detail, opts){
  opts = opts || {};
  try {
    const id = String(detail.id || detail.position_id || (detail.position && detail.position.id) || '');
    if (!id) return;

    window.__statusSuppressed = window.__statusSuppressed || {};
    // if recently handled, skip duplicate processing
    const last = window.__statusSuppressed[id] || 0;
    if (Date.now() - last < 3000) return; // already processed very recently
    window.__statusSuppressed[id] = Date.now();

    // Update card UI (badge, color, data-status-id)
    const card = document.querySelector('.position-ticket[data-id="' + id + '"]');
    const statusName = detail.status_name || detail.status || (detail.position && detail.position.status_name) || '';
    const statusId = detail.status_id || (detail.position && detail.position.status_id) || null;
    // Prefer DB-provided color (from update payload or injected status map), fall back to palette by name
    let color = '';
    if (detail.status_color) color = detail.status_color;
    else if (detail.position && detail.position.status_color) color = detail.position.status_color;
    else if (statusId && window.__statusMap && window.__statusMap[String(statusId)] && window.__statusMap[String(statusId)].color) {
      color = window.__statusMap[String(statusId)].color;
    } else {
      color = statusColorForName(statusName || '');
    }
    const textColor = statusTextColor(color);

    if (card) {
      card.setAttribute('data-status-id', String(statusId || card.getAttribute('data-status-id') || ''));
      const badge = card.querySelector('.status-badge');
      if (badge) {
        badge.textContent = statusName || badge.textContent;
        badge.style.background = color;
        badge.style.color = textColor;
      }
      try { card.style.borderLeftColor = color; } catch(_){}
    }

    // Close viewer and clear content
    try { if (window.closePositionViewer) window.closePositionViewer(); } catch(_){}
    const ov = document.getElementById('posViewerModal');
    if (ov) {
      const content = ov.querySelector('#posViewerContent');
      if (content) content.innerHTML = '';
    }

    // show notify if requested (default true)
    const doNotify = (typeof opts.notify === 'undefined') ? true : !!opts.notify;
    const label = opts.label || statusName || 'Status updated';
    if (doNotify) {
      try { Notify.push({ from: 'Positions', message: label, color: '#16a34a', duration: 8000 }); } catch(e){}
    }

    // reapply filters so lists update
    if (typeof window.applyPositionsFilters === 'function') window.applyPositionsFilters();

    // Update the URL to include an updated=<id> param (like creation flow) without navigating.
    try {
      const u = new URL(window.location.href);
      u.searchParams.set('updated', String(id));
      // keep hash anchored to the ticket
      u.hash = 'pos-' + encodeURIComponent(id);
      history.replaceState(null, document.title, u.pathname + (u.search ? '?' + u.searchParams.toString() : '') + u.hash);
    } catch (e) { /* ignore */ }

    // optionally redirect/refresh to ensure list is fully synced (default false to mimic creation behaviour)
    const doRedirect = (typeof opts.redirect === 'undefined') ? false : !!opts.redirect;
    if (doRedirect) {
      setTimeout(() => {
        try {
          const rel = 'view_positions.php?updated=' + encodeURIComponent(id) + '#pos-' + encodeURIComponent(id);
          const target = new URL(rel, window.location.href).href;
          if (window.location.href === target) window.location.reload();
          else window.location.assign(target);
        } catch(e) { try { window.location.href = 'view_positions.php?updated=' + encodeURIComponent(id) + '#pos-' + encodeURIComponent(id); } catch(_) { location.reload(); } }
      }, (typeof opts.delay === 'number' ? opts.delay : 800));
    }
  } catch (err) {
    console.error('onPositionUpdateSuccess error', err);
  }
};

window.addEventListener('position:updated', function(e){
  try {
    const d = e.detail || {};
    const id = String(d.id || '');
    if (!id) return;
    if (window.onPositionUpdateSuccess && typeof window.onPositionUpdateSuccess === 'function') {
      window.onPositionUpdateSuccess(d, { source: 'fragment' });
    }
  } catch (err) {
    console.error('position:updated handler failed', err);
  }
});
</script>
<script>
(function(){
  // Notify is already loaded at top via assets/js/notify.js; fallback if needed:
  async function ensureNotify(){ if (window.Notify?.push) return; await new Promise(r=>setTimeout(r,0)); }

  // Helper to run the update and close/redirect on success
  async function handleModalStatusClick(btn){
    if (!btn || btn.dataset.handled === '1' || btn.disabled) return;
    // resolve id
    let id = '';
    try {
      const editId = document.getElementById('edit_id');
      if (editId && editId.value) id = String(editId.value);
      if (!id) {
        const content = document.getElementById('posViewerContent');
        if (content) {
          const fld = content.querySelector('[name="id"], #edit_id, input[name="id"]');
          if (fld && fld.value) id = String(fld.value);
        }
      }
      if (!id && btn.dataset.id) id = String(btn.dataset.id);
      if (!id && btn.closest('[data-id]')) id = btn.closest('[data-id]').getAttribute('data-id') || ''; 
    } catch(e){ console.warn('id-resolve', e); }

    const toStatus = btn.dataset.to || btn.getAttribute('data-to');
    if (!id || !toStatus) {
      await ensureNotify();
      try { Notify.push({ from:'Positions', message:'Missing id or target status', color:'#dc2626' }); } catch(e){}
      return;
    }

    // quick pre-check: if modal already shows that status, close quietly
    try {
      const content = document.getElementById('posViewerContent');
      if (content) {
        const sb = content.querySelector('.status-badge');
        if (sb && (sb.textContent || '').trim().toLowerCase() === (btn.dataset.label || '').trim().toLowerCase()) {
          // suppress duplicate notifies and close
          window.__statusSuppressed = window.__statusSuppressed || {};
          window.__statusSuppressed[id] = Date.now();
          if (window.closePositionViewer) try { window.closePositionViewer(); } catch(_) {}
          return;
        }
      }
    } catch(e){}
    // Avoid duplicate updates if fragment already handled this id recently
    window.__statusSuppressed = window.__statusSuppressed || {};
    if (window.__statusSuppressed[id] && (Date.now() - window.__statusSuppressed[id] < 3000)) {
      // already handled recently; close viewer and skip
      if (window.closePositionViewer) try { window.closePositionViewer(); } catch(_) {}
      return;
    }

    // prevent concurrent operations for the same id
    window.__positionOpInFlight = window.__positionOpInFlight || {};
    if (window.__positionOpInFlight[id]) return;
    window.__positionOpInFlight[id] = true;

    btn.disabled = true;
    try {
      const fd = new FormData();
      fd.append('id', String(id));
      fd.append('status_id', String(toStatus));
      const res = await fetch('update_position.php', { method: 'POST', credentials:'same-origin', body: fd });
      const text = await res.text();
      let json = {};
      try { json = JSON.parse(text); } catch(e){
        console.error('modal status parse', text);
        await ensureNotify();
        try { Notify.push({ from:'Positions', message:'Status update failed (invalid response)', color:'#dc2626' }); } catch(e){}
        return;
      }

      if (!json.ok) {
        await ensureNotify();
        const msg = json.message || json.error || 'Status update failed';
        try { Notify.push({ from:'Positions', message: msg, color:'#dc2626' }); } catch(e){}
        return;
      }

      // success: call centralized handler which does notify/close/redirect and suppress duplicates
      try {
        if (window.onPositionUpdateSuccess && typeof window.onPositionUpdateSuccess === 'function') {
          const payload = json.position || json;
          window.onPositionUpdateSuccess(payload, { source: 'modal', label: (btn.dataset.label || ''), notify: true, redirect: false });
        } else {
          // fallback: mark suppressed and close
          window.__statusSuppressed[id] = Date.now();
          if (window.closePositionViewer) try { window.closePositionViewer(); } catch(_) {}
        }
      } catch (err2) {
        console.error('post-success handler failed', err2);
      }
      btn.dataset.handled = '1';
    } catch (err) {
      console.error('modal status update failed', err);
      await ensureNotify();
      try { Notify.push({ from:'Positions', message:'Status update failed (see console)', color:'#dc2626' }); } catch(e){}
    } finally {
      btn.disabled = false;
      window.__positionOpInFlight[id] = false;
    }
  }

  // Delegate clicks inside the viewer content specifically (fragment buttons should be caught here first)
  document.addEventListener('click', function(e){
    const btn = e.target && e.target.closest && e.target.closest('#posViewerContent .status-action-btn, #posViewerContent .status-action, .status-action-btn');
    if (!btn) return;
    // If fragment already marked handled, skip; otherwise run handler and stop further propagation
    if (btn.dataset.handled === '1') return;
    e.stopPropagation();
    handleModalStatusClick(btn);
  }, true);
})();
</script>