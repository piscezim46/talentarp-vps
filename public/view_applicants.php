<?php
// Start session only if not already active
if (function_exists('session_status')) {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
} else {
  @session_start();
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/access.php';

// page variables required by header.php
$activePage = 'applicants';
$pageTitle = 'Applicants';

// include header and navbar early so head tags, CSS and JS load correctly
$headerPath = __DIR__ . '/../includes/header.php';
$navPath = __DIR__ . '/../includes/navbar.php';
if (file_exists($headerPath)) include $headerPath;
if (file_exists($navPath)) include $navPath;

// Page-level layout and sidebar styling moved to `public/styles/layout.css`.
// Keep page CSS files for local components only.

// Ensure Font Awesome is present (styles for icons centralized in layout.css)
echo '<script>(function(){ try{ var found = Array.from(document.styleSheets||[]).some(s=>s.href && s.href.indexOf("font-awesome")!==-1); if(!found){ var l = document.createElement("link"); l.rel = "stylesheet"; l.href = "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"; l.crossOrigin = "anonymous"; l.referrerPolicy = "no-referrer"; document.head.appendChild(l); } }catch(e){} })();</script>';

// Note: per-page layout variable syncing was removed to prevent overriding centralized layout.css values.
// If runtime sync is required, add a single shared script (e.g., in includes/header.php) instead.

// Fetch positions
$positions = [];
// Load positions with department filtering when applicable
try {
  $sqlPos = "SELECT p.id, p.title FROM positions p" . _scope_clause('positions','p', true) . " ORDER BY p.title";

  $stmtPos = $conn->prepare($sqlPos);
  if ($stmtPos) {
    $stmtPos->execute();
    $res = $stmtPos->get_result();
    while ($r = $res->fetch_assoc()) $positions[] = $r;
    $stmtPos->close();
  }
} catch (Throwable $_) { /* fallback to empty positions */ }

// Fetch departments (only active)
$departments = [];
if ($res = $conn->query("SELECT d.department_id AS id, d.department_name AS name, d.director_name FROM departments d WHERE d.active = 1" . _scope_clause('departments','d', false) . " ORDER BY d.department_name")) {
  while ($r = $res->fetch_assoc()) $departments[(int)$r['id']] = $r;
  $res->free();
}

// Fetch teams grouped by department (only active)
$teams_by_dept = [];
if ($res = $conn->query("SELECT t.team_id AS id, t.team_name AS name, t.department_id, t.manager_name FROM teams t WHERE t.active = 1" . _scope_clause('teams','t', false) . " ORDER BY t.team_name")) {
  while ($r = $res->fetch_assoc()) {
    $deptId = (int)$r['department_id'];
    if (!isset($teams_by_dept[$deptId])) $teams_by_dept[$deptId] = [];
    $teams_by_dept[$deptId][] = $r;
  }
  $res->free();
}

// Fetch applicants for list — restrict by position.department when applicable
$applicants = [];
try {
    $sqlApp = "SELECT a.applicant_id, a.full_name, a.email, a.phone, a.resume_file, a.created_at FROM applicants a LEFT JOIN positions p ON a.position_id = p.id" . _scope_clause('applicants','a', true) . " ORDER BY a.created_at DESC";

    $stmtApp = $conn->prepare($sqlApp);
    if ($stmtApp) {
      $stmtApp->execute();
      $res = $stmtApp->get_result();
      while ($r = $res->fetch_assoc()) $applicants[] = $r;
      $stmtApp->close();
    }
} catch (Throwable $_) { /* fallback */ }

// JSON for client
$positions_json = json_encode($positions, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$departments_json = json_encode($departments, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$teams_json = json_encode($teams_by_dept, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
?>
<link rel="stylesheet" href="styles/applicants.css">
<link rel="stylesheet" href="styles/users.css">

<!-- Create Applicant Modal -->
<div id="createApplicantModal" class="modal-overlay" style="display:none;">
  <div class="modal-card" role="dialog" aria-modal="true">
    <button type="button" id="closeCreateApplicant" class="modal-close">×</button>
    <h3>Create Applicant</h3>
    <form id="createApplicantForm" method="post" action="create_applicant.php" enctype="multipart/form-data">
      <div class="field"><label>Full name</label><input name="full_name" class="modal-input" required></div>

      <div class="field">
        <label>Position</label>
        <select name="position_id" id="ap_position" class="modal-input" required>
          <option value="">-- Select Position --</option>
          <?php foreach ($positions as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field inline">
        <div>
          <label>Department</label>
          <select id="ap_department" name="department_id" class="modal-input">
            <option value="">-- Select Department --</option>
            <?php foreach ($departments as $did => $d): ?>
              <option value="<?= (int)$did ?>"><?= htmlspecialchars($d['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Team</label>
          <select id="ap_team" name="team_id" class="modal-input" disabled>
            <option value="">Select department first</option>
          </select>
        </div>
        <div>
          <label>Manager</label>
          <input id="ap_manager_display" type="text" disabled class="modal-input" value="Unassigned">
          <input type="hidden" name="manager_name" id="ap_manager_name" value="">
        </div>
      </div>

      <div class="field">
        <label>Upload PDF(s)</label>
        <input id="ap_files" name="uploads[]" type="file" accept="application/pdf" multiple required class="modal-input">
        <div class="small-muted">Select multiple PDFs. One applicant record will be created per PDF.</div>
      </div>

      <div class="modal-actions">
        <button type="button" id="cancelCreateApplicant" class="btn">Cancel</button>
        <button type="submit" class="btn primary">Create</button>
      </div>
    </form>
  </div>
</div>

<script>
window._positions = <?= $positions_json ?> || [];
window._departments = <?= $departments_json ?> || {};
window._teamsByDept = <?= $teams_json ?> || {};

document.addEventListener('DOMContentLoaded', function(){
  const openBtn = document.getElementById('openCreateApplicantBtn');
  const modal = document.getElementById('createApplicantModal');
  const closeBtn = document.getElementById('closeCreateApplicant');
  const cancelBtn = document.getElementById('cancelCreateApplicant');
  const deptEl = document.getElementById('ap_department');
  const teamEl = document.getElementById('ap_team');
  const managerDisplay = document.getElementById('ap_manager_display');
  const managerHidden = document.getElementById('ap_manager_name');
  const fileInput = document.getElementById('ap_files');

  function openModal(){
    if (modal) modal.style.display = 'flex';
    // ensure file input allows multiple
    if (fileInput) fileInput.multiple = true;
    // reset form without replacing file input element
    const form = document.getElementById('createApplicantForm');
    if (form) form.reset();
    if (fileInput) fileInput.value = '';
    // reset UI fields
    if (teamEl) { teamEl.innerHTML = '<option value=\"\">Select department first</option>'; teamEl.disabled = true; }
    if (managerDisplay) { managerDisplay.value = 'Unassigned'; managerHidden.value = ''; }
  }
  function closeModal(){ if (modal) modal.style.display = 'none'; }

  openBtn && openBtn.addEventListener('click', openModal);
  closeBtn && closeBtn.addEventListener('click', closeModal);
  cancelBtn && cancelBtn.addEventListener('click', closeModal);
  modal && modal.addEventListener('click', (e)=> { if (e.target === modal) closeModal(); });

  // teams population
  const teamsByDept = window._teamsByDept || {};
  if (deptEl) {
    deptEl.addEventListener('change', function(){
      const did = String(this.value || '');
      teamEl.innerHTML = '';
      managerDisplay.value = 'Unassigned';
      managerHidden.value = '';
      if (!did || !teamsByDept[did] || teamsByDept[did].length === 0) {
        teamEl.disabled = true;
        teamEl.innerHTML = '<option value=\"\">No teams</option>';
        return;
      }
      const opt0 = document.createElement('option'); opt0.value=''; opt0.textContent='-- Select Team --'; teamEl.appendChild(opt0);
      teamsByDept[did].forEach(t => {
        const o = document.createElement('option');
        o.value = t.id || t.team_id || '';
        o.textContent = t.name || t.team_name || '';
        o.dataset.managerName = t.manager_name || '';
        teamEl.appendChild(o);
      });
      teamEl.disabled = false;
    });
  }
  if (teamEl) {
    teamEl.addEventListener('change', function(){
      const sel = this.selectedOptions[0];
      if (!sel || !sel.value) { managerDisplay.value='Unassigned'; managerHidden.value=''; return; }
      const mgr = sel.dataset.managerName || '';
      managerDisplay.value = mgr || 'Unassigned';
      managerHidden.value = mgr || '';
    });
  }

  // debug on submit to confirm multiple files exist
  const form = document.getElementById('createApplicantForm');
  if (form) {
    form.addEventListener('submit', function(){ if (fileInput && fileInput.files) console.log('Creating applicants, files:', fileInput.files.length); });
  }
});
</script>

<!-- Applicants list (table style to match Users page) -->
<main class="content-area">
  <h2 class="page-title">Applicants</h2>

  <div class="widget app-list">
    <div class="table-scroll">
      <table class="users-table" id="applicantsTable">
        <thead>
          <tr>
            <th class="sortable" data-sort="id">ID</th>
            <th class="sortable" data-sort="name">Name</th>
            <th class="sortable" data-sort="email">Email</th>
            <th class="sortable" data-sort="phone">Phone</th>
            <th class="sortable" data-sort="created_at">Created At</th>
            <th class="sortable" data-sort="status">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($applicants as $a): ?>
            <?php $aid = (int)$a['applicant_id']; $name = htmlspecialchars($a['full_name'] ?? '—'); $stat = htmlspecialchars($a['status'] ?? ''); $created = htmlspecialchars($a['created_at'] ?? ''); $email = htmlspecialchars($a['email'] ?? '—'); $phone = htmlspecialchars($a['phone'] ?? '—'); ?>
            <tr class="app-row" data-id="<?= $aid ?>" tabindex="0" data-created-at="<?= $created ?>">
              <td class="col-id"><?= $aid ?></td>
              <td class="users-col-name"><?= $name ?></td>
              <td class="users-col-email"><?= $email ?></td>
              <td class="users-col-phone"><?= $phone ?></td>
              <td class="users-col-created"><?= $created ?></td>
              <td class="users-col-status"><?= $stat ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<script>
// Applicant card click -> open applicant fragment (modal viewer)
document.addEventListener('DOMContentLoaded', function () {
  console.log('[applicants] viewer script loaded');

  function ensureViewer() {
    let ov = document.getElementById('applicantViewerModal');
    if (ov) return ov;

    ov = document.createElement('div');
    ov.id = 'applicantViewerModal';
    ov.className = 'modal-overlay';
    ov.setAttribute('aria-hidden', 'true');
  ov.innerHTML = '<div class="modal-card"><div class="modal-header"><h3 id="appViewerTitle">Applicant</h3><button type="button" class="modal-close-x" aria-label="Close">&times;</button></div><div id="appViewerContent"></div></div>';
    document.body.appendChild(ov);
    // sizing and colors are handled by CSS in styles/applicants.css
    // log computed styles to help debug sizing issues
    try {
      const mc = ov.querySelector('.modal-card');
      const contentEl = ov.querySelector('#appViewerContent');
      setTimeout(() => {
        try {
          if (mc) console.log('[applicantViewer] modal computed style', window.getComputedStyle(mc).width, window.getComputedStyle(mc).height);
          if (contentEl) console.log('[applicantViewer] content computed style', window.getComputedStyle(contentEl).width, window.getComputedStyle(contentEl).height);
        } catch(e){ console.warn('computedStyle read failed', e); }
      }, 50);
    } catch(e){console.warn('log modal styles failed', e);}    
    // close when clicking the close button
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
    const content = ov.querySelector('#appViewerContent');
    content.innerHTML = '<div class="loading-placeholder">Loading...</div>';
    openViewer();

    try {
      const res = await fetch('get_applicant.php?applicant_id=' + encodeURIComponent(id), { credentials: 'same-origin' });
      const html = await res.text();
      content.innerHTML = html;

      // run any inline scripts returned in the fragment
      Array.from(content.querySelectorAll('script')).forEach(function (s) {
        try {
          if (s.src) {
            (async function(){
              try {
                const r = await fetch(s.src, { credentials: 'same-origin' });
                if (r && r.ok) {
                  const raw = await r.text();
                  if (/\bimport\b|\bexport\b/.test(raw) || (s.type && s.type.toLowerCase && s.type.toLowerCase() === 'module')) {
                    const m = document.createElement('script'); m.type = 'module'; m.text = raw; document.body.appendChild(m); try{ m.remove(); }catch(e){}
                  } else if (/\bawait\b/.test(raw)) {
                    const wrapper = '(async function(){\n' + raw + '\n})().catch(function(e){console.error(e)});';
                    const w = document.createElement('script'); w.text = wrapper; document.body.appendChild(w); try{ w.remove(); }catch(e){}
                  } else {
                    const ext = document.createElement('script'); ext.type = 'module'; ext.src = s.src; ext.async = false; document.body.appendChild(ext); ext.onload = function(){ try{ ext.remove(); }catch(e){} };
                  }
                  return;
                }
              } catch (err) {}
              try { const fallback = document.createElement('script'); fallback.src = s.src; fallback.type = 'module'; fallback.async = false; document.body.appendChild(fallback); fallback.onload = function(){ try{ fallback.remove(); }catch(e){} }; } catch(e){}
            })();
            return;
          }

          const raw = s.textContent || s.innerText || '';
          if (/\bimport\b|\bexport\b/.test(raw)) {
            const m = document.createElement('script'); m.type = 'module'; m.text = raw; document.body.appendChild(m); try{ m.remove(); }catch(e){}
          } else if (/\bawait\b/.test(raw)) {
            const wrapper = '(async function(){\n' + raw + '\n})().catch(function(e){console.error(e)});';
            const w = document.createElement('script'); w.text = wrapper; document.body.appendChild(w); try{ w.remove(); }catch(e){}
          } else {
            const ns = document.createElement('script'); ns.type = 'module'; ns.text = raw; document.body.appendChild(ns); try{ ns.remove(); }catch(e){}
          }
        } catch (err) { console.warn('inject fragment script failed', err); }
      });
    } catch (e) {
      console.error('openApplicant failed', e);
    }
  }

  // delegated click handler for applicant cards or table rows (inside DOMContentLoaded)
  document.addEventListener('click', function (e) {
    try {
      const card = e.target && e.target.closest && (e.target.closest('.applicant-card') || e.target.closest('.app-row'));
      if (!card) return;
      const id = card.getAttribute('data-id');
      if (!id) return;
      console.log('[applicants] click detected, id=', id);
      e.preventDefault();
      openApplicant(id);
    } catch (err) {
      console.error('[applicants] click handler error', err);
    }
  });
});
</script>

<?php
// Temporary recent-applicants widget: read latest applicants and display a small list.
// Use a LEFT JOIN to positions_status (if present) to resolve a human-readable status name.
$tickets_widget = [];
if ($conn) {
  try {
    $q = "SELECT a.applicant_id AS ticket_id, COALESCE(NULLIF(a.full_name, ''), 'Unknown') AS subject, COALESCE(s.status_name, '') AS status, a.created_at
        FROM applicants a
        LEFT JOIN positions p ON a.position_id = p.id
        LEFT JOIN positions_status s ON a.status_id = s.status_id
        ORDER BY a.created_at DESC LIMIT 10";

    $stmtW = $conn->prepare($q);
    if ($stmtW) {
        $stmtW->execute();
        $res = $stmtW->get_result();
        while ($r = $res->fetch_assoc()) $tickets_widget[] = $r;
        $stmtW->close();
    }
  } catch (Throwable $_) { /* ignore */ }
}
?>

<div class="widget tickets-widget">
  <h3>Tickets (temporary widget)</h3>

  <?php if (empty($tickets_widget)): ?>
    <div class="no-tickets">No tickets found.</div>
  <?php else: ?>
    <ul>
      <?php foreach ($tickets_widget as $t): ?>
        <li>
          <div class="row">
            <div>
              <strong>#<?= htmlspecialchars($t['ticket_id']) ?></strong>
              <span class="subject"><?= htmlspecialchars($t['subject'] ?: 'No subject') ?></span>
            </div>
            <div class="status"><?= htmlspecialchars($t['status']) ?></div>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<!-- applicants table removed. Create modal remains and still posts to create_applicant.php which inserts into DB -->