<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/access.php';

 $user = $_SESSION['user'] ?? null;
 if (!$user || !_has_access('departments_view')) {
     header("Location: index.php");
     exit;
 }

$canCreate = _has_access('departments_create');
$canEdit   = _has_access('departments_edit');
$canToggle = _has_access('departments_toggle');
$activePage = 'departments';

// Fetch departments and teams (grouped)
$departments = [];
$sql = "SELECT d.department_id, d.department_name, d.short_name, d.director_name, d.active AS department_active, t.team_id, t.team_name, t.manager_name, t.active
    FROM departments d
    LEFT JOIN teams t ON t.department_id = d.department_id" .
    _scope_clause('departments','d', true) .
    " ORDER BY d.department_id ASC, t.team_id ASC";
if ($res = $conn->query($sql)) {
    while ($r = $res->fetch_assoc()) {
        $id = (int)$r['department_id'];
        if (!isset($departments[$id])) {
            $departments[$id] = [
                'department_id' => $id,
                'department_name' => $r['department_name'],
                'short_name' => $r['short_name'] ?? '',
                'director_name' => $r['director_name'],
                'active' => isset($r['department_active']) ? (int)$r['department_active'] : 1,
                'teams' => []
            ];
        }
        if (!empty($r['team_id'])) {
            // only include active teams in the initial payload
            $active = isset($r['active']) ? (int)$r['active'] : 1;
            if ($active === 1) {
                $departments[$id]['teams'][] = [
                    'team_id' => (int)$r['team_id'],
                    'team_name' => $r['team_name'],
                    'manager_name' => $r['manager_name']
                ];
            }
        }
    }
    $res->free();
}

 $departments_json = json_encode($departments, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

$pageTitle = 'Departments';

if (file_exists(__DIR__ . '/../includes/header.php')) include __DIR__ . '/../includes/header.php';
if (file_exists(__DIR__ . '/../includes/navbar.php')) include __DIR__ . '/../includes/navbar.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="styles/positions.css">
<link rel="stylesheet" href="styles/applicants.css">
<link rel="stylesheet" href="styles/department.css">
<link rel="stylesheet" href="assets/css/notify.css">
<script src="assets/js/notify.js"></script>
<style>
/* Center all headers and cells except the Teams column */
#departmentsTable th, #departmentsTable td { text-align: center; vertical-align: middle; }
#departmentsTable th.col-teams, #departmentsTable td.col-teams { text-align: left; }
#departmentsTable td.col-teams .team-list-item { text-align: left; }
/* Make the table container scrollable when there are many rows */
#departmentsWrap { max-height: 100vh; overflow: auto; position: relative; -webkit-overflow-scrolling: touch; }
/* Keep table header visible when scrolling and ensure it stays above rows */
#departmentsTable thead th { position: sticky; top: 0; background: var(--panel, #fafafa); z-index: 9999; box-shadow: 0 2px 6px rgba(0,0,0,0.04); }
/* Ensure rows don't escape above the sticky header: give them lower stacking context */
#departmentsTable tbody tr { position: relative; z-index: 0; }
</style>
<main class="content-area">
    <div class="controls" style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;">
        <h2 class="section-title">Departments</h2>
        <button id="openDeptBtn" class="btn" <?= $canCreate ? '' : 'disabled aria-disabled="true" title="Insufficient permissions"' ?>>New Department</button>
    </div>
        <!-- <div style="margin-left:auto;" class="small-muted">Departments: <span id="deptCount"><?= count($departments) ?></span></div> --!>

    <div class="table-wrap" id="departmentsWrap">
        <table class="users-table" id="departmentsTable">
            <thead>
                <tr>
                    <th class="col-id">ID</th>
                    <th class="col-dept">Department</th>
                    <th class="col-short">Short Name</th>
                    <th class="col-director">Director</th>
                    <th class="col-teams">Teams (manager)</th>
                    <th class="col-active">Active</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($departments as $d): ?>
                    <tr>
                        <td><?= htmlspecialchars($d['department_id']) ?></td>
                        <td><?= htmlspecialchars($d['department_name']) ?></td>
                        <td><?= htmlspecialchars($d['short_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($d['director_name']) ?></td>
                        <td class="col-teams">
                            <?php if (count($d['teams']) === 0): ?>
                                <span class="inactive-badge">No teams</span>
                            <?php else: ?>
                                <?php foreach ($d['teams'] as $t): ?>
                                    <div class="team-list-item">
                                        <strong><?= htmlspecialchars($t['team_name']) ?></strong>
                                        &nbsp; — &nbsp;
                                        <span class="team-manager-name"><?= htmlspecialchars($t['manager_name']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td class="dept-col-active">
                            <?php if (isset($d['active']) && (int)$d['active'] === 1): ?>
                                <span class="active-badge">Active</span>
                            <?php else: ?>
                                <span class="inactive-badge">Inactive</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>

<!-- New Department modal -->
    <div id="deptModal" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-header">
            <h3>Create Department</h3>
            <button type="button" class="modal-close" aria-label="Close dialog">&times;</button>
        </div>
        <div id="deptMsg" class="small-muted"></div>
        <form id="deptForm">
            <label for="d_name">Department Name</label>
            <input id="d_name" name="department_name" type="text" required class="modal-input">

            <label for="d_short_name" style="padding-top:5px;">Short Name</label>
            <input id="d_short_name" name="short_name" type="text" class="modal-input" placeholder="Optional short name">

            <label for="d_director" style="padding-top: 5px;">Director Name</label>
            <input id="d_director" name="director_name" type="text" required class="modal-input">

            <label style="margin-top:8px;display:block;">Teams (name & manager)</label>
            <div id="teamsContainer">
                <div class="team-row">
                    <input name="team_name[]" class="team-name team-input" placeholder="Team name" required>
                    <input name="manager_name[]" class="team-manager team-input" placeholder="Manager name" required>
                    <button type="button" class="btn" onclick="removeTeam(this)" title="Remove">−</button>
                </div>
            </div>
                <div style="margin-top:8px;">
                <button type="button" id="addTeamBtn" class="btn" <?= $canEdit ? '' : 'disabled aria-disabled="true" title="Insufficient permissions"' ?>>Add Team</button>
            </div>

                <div class="modal-actions" style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;">
                <button type="button" id="cancelDept" class="btn">Cancel</button>
                <button type="submit" class="btn" <?= $canCreate ? '' : 'disabled aria-disabled="true" title="Insufficient permissions"' ?>>Create Department</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Department modal -->
    <div id="deptEditModal" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-header">
            <h3>Edit Department</h3>
            <button type="button" class="modal-close" aria-label="Close dialog">&times;</button>
        </div>
        <div id="deptEditMsg" class="small-muted"></div>
        <form id="deptEditForm">
            <input type="hidden" id="edit_department_id" name="department_id" />
            <label for="edit_d_name">Department Name</label>
            <input id="edit_d_name" name="department_name" type="text" required class="modal-input">

            <label for="edit_d_short_name">Short Name</label>
            <input id="edit_d_short_name" name="short_name" type="text" class="modal-input" placeholder="Optional short name">

            <label for="edit_d_director">Director Name</label>
            <input id="edit_d_director" name="director_name" type="text" required class="modal-input">

            <label style="margin-top:8px;display:block;">Teams (name & manager)</label>
            <div id="teamsContainerEdit"></div>
                <div style="margin-top:8px;">
                <button type="button" id="addTeamBtnEdit" class="btn" <?= $canEdit ? '' : 'disabled aria-disabled="true" title="Insufficient permissions"' ?>>Add Team</button>
            </div>

                <div class="modal-actions" style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;">
                <button type="button" id="cancelDeptEdit" class="btn">Cancel</button>
                <button type="button" id="dept_toggle_active" class="btn" style="min-width:96px;" <?= $canToggle ? '' : 'disabled aria-disabled="true" title="Insufficient permissions"' ?>>Toggle Active</button>
                <button type="submit" id="saveDeptEdit" class="btn btn-orange" <?= $canEdit ? '' : 'disabled aria-disabled="true" title="Insufficient permissions"' ?>>Save</button>
            </div>
        </form>
    </div>
</div>

<script>
// departments modal & create logic
(function(){
    // permission flags exposed from server
    const DEPT_PERMS = {
        create: <?= $canCreate ? 'true' : 'false' ?>,
        edit:   <?= $canEdit ? 'true' : 'false' ?>,
        toggle: <?= $canToggle ? 'true' : 'false' ?>
    };

    // prevent opening create/edit flows when permission missing (extra guard)
    const deptModal = document.getElementById('deptModal');
    const openDeptBtn = document.getElementById('openDeptBtn');
    const cancelDept = document.getElementById('cancelDept');
    const deptForm = document.getElementById('deptForm');
    const deptMsg = document.getElementById('deptMsg');
    const teamsContainer = document.getElementById('teamsContainer');
    const addTeamBtn = document.getElementById('addTeamBtn');
    const deptCountEl = document.getElementById('deptCount');
    const departmentsTableBody = document.querySelector('#departmentsTable tbody');
    const deptEditModal = document.getElementById('deptEditModal');
    const deptEditForm = document.getElementById('deptEditForm');
    const teamsContainerEdit = document.getElementById('teamsContainerEdit');
    const addTeamBtnEdit = document.getElementById('addTeamBtnEdit');
    const cancelDeptEdit = document.getElementById('cancelDeptEdit');
    const deptEditMsg = document.getElementById('deptEditMsg');
    const deptToggleBtn = document.getElementById('dept_toggle_active');

    function openModal(){ deptMsg.textContent=''; deptForm.reset(); teamsContainer.innerHTML = teamsContainer.querySelector('.team-row') ? teamsContainer.querySelector('.team-row').outerHTML : ''; deptModal.style.display = 'flex'; }
    function closeModal(){ deptModal.style.display = 'none'; }

    // Edit modal open/close (do not reset form here - we populate fields before opening)
    function openEditModal(){ deptEditMsg.textContent=''; deptEditModal.style.display = 'flex'; }
    function closeEditModal(){ deptEditModal.style.display = 'none'; }

    openDeptBtn.addEventListener('click', function(){ if (!DEPT_PERMS.create) { try{ if (window.Notify && typeof Notify.push === 'function') Notify.push({ from: 'Departments', message: 'Insufficient permissions to create department', color: '#dc2626' }); }catch(e){} return; } openModal(); });
    cancelDept.addEventListener('click', closeModal);
    // wire close button in Create modal (top-right X)
    const createClose = deptModal && deptModal.querySelector('.modal-close'); if (createClose) createClose.addEventListener('click', closeModal);

    addTeamBtn.addEventListener('click', function(){ if (!DEPT_PERMS.edit) { try{ if (window.Notify && typeof Notify.push === 'function') Notify.push({ from: 'Departments', message: 'Insufficient permissions', color: '#dc2626' }); }catch(e){} return; }
        const row = document.createElement('div');
        row.className = 'team-row';
        row.innerHTML = '<input name="team_name[]" class="team-name team-input" placeholder="Team name" required><input name="manager_name[]" class="team-manager team-input" placeholder="Manager name" required><button type="button" class="btn" onclick="removeTeam(this)" title="Remove">−</button>';
        teamsContainer.appendChild(row);
    });

    // edit modal add team
    if (addTeamBtnEdit) addTeamBtnEdit.addEventListener('click', function(){ if (!DEPT_PERMS.edit) { try{ if (window.Notify && typeof Notify.push === 'function') Notify.push({ from: 'Departments', message: 'Insufficient permissions', color: '#dc2626' }); }catch(e){} return; }
        const row = document.createElement('div');
        row.className = 'team-row';
        // include empty team_id[] for new teams so backend can handle create vs update
        row.innerHTML = '<input type="hidden" name="team_id[]" value=""><input name="team_name[]" class="team-name team-input" placeholder="Team name" required><input name="manager_name[]" class="team-manager team-input" placeholder="Manager name" required><button type="button" class="btn" onclick="removeTeam(this)" title="Remove">−</button>';
        teamsContainerEdit.appendChild(row);
    });

    // wire edit modal close (top-right X)
    const editClose = deptEditModal && deptEditModal.querySelector('.modal-close'); if (editClose) editClose.addEventListener('click', closeEditModal);
    if (cancelDeptEdit) cancelDeptEdit.addEventListener('click', closeEditModal);

    window.removeTeam = function(btn){ const row = btn.closest('.team-row'); if (row) row.remove(); };

    deptForm.addEventListener('submit', async function(e){
        if (!DEPT_PERMS.create) { deptMsg.textContent = 'Insufficient permissions to create department.'; return; }
        e.preventDefault();
        deptMsg.textContent = 'Creating department...';
        const formData = new FormData(deptForm);
        const payload = { department_name: formData.get('department_name'), short_name: formData.get('short_name'), director_name: formData.get('director_name'), teams: [] };
        const teamNames = formData.getAll('team_name[]');
        const mgrNames = formData.getAll('manager_name[]');
        for (let i=0;i<teamNames.length;i++){ const tn = (teamNames[i]||'').trim(); const mn = (mgrNames[i]||'').trim(); if (tn) payload.teams.push({ team_name: tn, manager_name: mn }); }
        try {
            const res = await fetch('create_department.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Server error');
            const d = data.department;
            const tr = document.createElement('tr');
            const teamsHtml = (d.teams||[]).map(t => `<div class="team-list-item"><strong>${escapeHtml(t.team_name)}</strong> &nbsp; — &nbsp; <span class="team-manager-name">${escapeHtml(t.manager_name)}</span></div>`).join('');
            tr.innerHTML = `<td>${d.department_id}</td><td>${escapeHtml(d.department_name)}</td><td>${escapeHtml(d.short_name||'')}</td><td>${escapeHtml(d.director_name)}</td><td class="col-teams">${teamsHtml}</td><td><span class="active-badge">Active</span></td>`;
            tr.dataset.active = '1';
            departmentsTableBody.insertBefore(tr, departmentsTableBody.firstChild);
            if (deptCountEl) deptCountEl.textContent = parseInt(deptCountEl.textContent||0) + 1;
            deptMsg.textContent = 'Created successfully.';
            try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Departments', message: 'Department #' + d.department_id + ' created', color: '#10b981' }); } catch(e) {}
            setTimeout(()=>{ closeModal(); deptMsg.textContent=''; }, 700);
        } catch (err) {
            deptMsg.textContent = err.message;
        }
    });

    // (edit modal opening handled below using DEPARTMENTS_DATA)

    // departments data for edit lookup
    const DEPARTMENTS_DATA = <?= $departments_json ?> || {};
    // debug: log data to help diagnose empty modal population
    try { console.log('DEPARTMENTS_DATA:', DEPARTMENTS_DATA); } catch(e) {}

    // original values cache for edit no-op detection
    let DEPT_ORIGINAL = null;

    departmentsTableBody.addEventListener('click', function(e){
        const tr = e.target && e.target.closest && e.target.closest('tr');
        if (!tr) return;
        const idCell = tr.querySelector('td');
        if (!idCell) return;
        const idText = idCell.textContent.trim();
        const idNum = parseInt(idText, 10);

        // robust lookup: try direct key first, then scan values for matching department_id
        let dept = null;
        if (DEPARTMENTS_DATA && Object.prototype.hasOwnProperty.call(DEPARTMENTS_DATA, idText)) {
            dept = DEPARTMENTS_DATA[idText];
        } else if (DEPARTMENTS_DATA && Object.prototype.hasOwnProperty.call(DEPARTMENTS_DATA, idNum)) {
            dept = DEPARTMENTS_DATA[idNum];
        } else if (DEPARTMENTS_DATA) {
            for (const k in DEPARTMENTS_DATA) {
                if (!Object.prototype.hasOwnProperty.call(DEPARTMENTS_DATA, k)) continue;
                const cand = DEPARTMENTS_DATA[k];
                if (!cand) continue;
                if (Number(cand.department_id) === idNum) { dept = cand; break; }
            }
        }

        if (!dept) return;

        // populate edit form fields with existing data
        const depIdEl = document.getElementById('edit_department_id');
        const nameEl = document.getElementById('edit_d_name');
        const dirEl = document.getElementById('edit_d_director');
        if (depIdEl) depIdEl.value = dept.department_id;
        if (nameEl) nameEl.value = dept.department_name || '';
        const shortEl = document.getElementById('edit_d_short_name'); if (shortEl) shortEl.value = dept.short_name || '';
        if (dirEl) dirEl.value = dept.director_name || '';

        // set header to include department id
        try {
            const hdr = deptEditModal.querySelector('.modal-header h3');
            if (hdr) hdr.textContent = 'Edit Department #' + String(dept.department_id || '');
        } catch(e) {}

        teamsContainerEdit.innerHTML = '';
        if (Array.isArray(dept.teams) && dept.teams.length) {
            dept.teams.forEach(t => {
                const row = document.createElement('div');
                row.className = 'team-row';
                // include team_id so backend can identify existing teams (empty for new rows)
                row.innerHTML = '' +
                    '<input type="hidden" name="team_id[]" value="' + escapeHtml(t.team_id || '') + '">' +
                    '<input name="team_name[]" class="team-name team-input" placeholder="Team name" required value="' + escapeHtml(t.team_name || '') + '">' +
                    '<input name="manager_name[]" class="team-manager team-input" placeholder="Manager name" required value="' + escapeHtml(t.manager_name || '') + '">' +
                    '<button type="button" class="btn" onclick="removeTeam(this)" title="Remove">−</button>';
                teamsContainerEdit.appendChild(row);
            });
        }

        // capture original values for change detection
        try {
            const teams = [];
            const rows = teamsContainerEdit.querySelectorAll('.team-row');
            rows.forEach(function(r){
                const hid = r.querySelector('input[type="hidden"][name="team_id[]"]');
                const tn = r.querySelector('input[name="team_name[]"]');
                const mn = r.querySelector('input[name="manager_name[]"]');
                teams.push({ team_id: hid ? (hid.value||'') : '', team_name: tn ? (tn.value||'') : '', manager_name: mn ? (mn.value||'') : '' });
            });
            DEPT_ORIGINAL = {
                department_id: String(dept.department_id || ''),
                department_name: String(dept.department_name || ''),
                short_name: String(dept.short_name || ''),
                director_name: String(dept.director_name || ''),
                teams: teams
            };
        } catch(e){ DEPT_ORIGINAL = null; }

        openEditModal();
        // set toggle button label based on dept.active
        try {
            if (deptToggleBtn) {
                const isActive = (dept.active === 1 || String(dept.active) === '1');
                deptToggleBtn.dataset.active = isActive ? '1' : '0';
                deptToggleBtn.textContent = isActive ? 'Deactivate' : 'Activate';
                updateDeptToggleStyle();
            }
        } catch(e) {}
    });

    // save edit
    if (deptEditForm) {
        deptEditForm.addEventListener('submit', async function(ev){
            if (!DEPT_PERMS.edit) { deptEditMsg.textContent = 'Insufficient permissions to save changes.'; return; }
            ev.preventDefault();
            deptEditMsg.textContent = 'Saving...';
            const fd = new FormData(deptEditForm);
            const payload = { department_id: fd.get('department_id'), department_name: fd.get('department_name'), short_name: fd.get('short_name'), director_name: fd.get('director_name'), teams: [] };
            const teamNames = fd.getAll('team_name[]');
            const mgrNames = fd.getAll('manager_name[]');
            for (let i=0;i<teamNames.length;i++){ const tn = (teamNames[i]||'').trim(); const mn = (mgrNames[i]||'').trim(); if (tn) payload.teams.push({ team_name: tn, manager_name: mn }); }
            // no-op detection: compare payload with DEPT_ORIGINAL
            try {
                if (DEPT_ORIGINAL) {
                    const sameId = String(DEPT_ORIGINAL.department_id||'') === String(payload.department_id||'');
                    const sameName = String((DEPT_ORIGINAL.department_name||'')).trim() === String((payload.department_name||'')).trim();
                    const sameShort = String((DEPT_ORIGINAL.short_name||'')).trim() === String((payload.short_name||'')).trim();
                    const sameDir = String((DEPT_ORIGINAL.director_name||'')).trim() === String((payload.director_name||'')).trim();
                    // compare teams length and values
                    let teamsSame = true;
                    const origTeams = Array.isArray(DEPT_ORIGINAL.teams) ? DEPT_ORIGINAL.teams : [];
                    if (origTeams.length !== payload.teams.length) teamsSame = false;
                    else {
                        for (let i=0;i<origTeams.length;i++){
                            const o = origTeams[i]; const p = payload.teams[i] || {};
                            if (String((o.team_name||'')).trim() !== String((p.team_name||'')).trim() || String((o.manager_name||'')).trim() !== String((p.manager_name||'')).trim()) { teamsSame = false; break; }
                        }
                    }
                    if (sameId && sameName && sameShort && sameDir && teamsSame) {
                        try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Departments', message: 'No changes detected to save', color: '#f59e0b' }); } catch(e){}
                        return;
                    }
                }
            } catch(e) {}
            try {
                const res = await fetch('update_department.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Server error');
                // optimistic DOM update: find row and update cells
                const rows = Array.from(departmentsTableBody.querySelectorAll('tr'));
                for (let r of rows) {
                    const idCell = r.querySelector('td'); if (!idCell) continue;
                    if (String(idCell.textContent).trim() === String(payload.department_id)) {
                        r.children[1].textContent = payload.department_name;
                        r.children[2].textContent = payload.short_name || '';
                        r.children[3].textContent = payload.director_name;
                        const teamsHtml = payload.teams.map(t => `<div class="team-list-item"><strong>${escapeHtml(t.team_name)}</strong> &nbsp; — &nbsp; <span class="team-manager-name">${escapeHtml(t.manager_name)}</span></div>`).join('');
                        r.children[4].innerHTML = teamsHtml || '<span class="inactive-badge">No teams</span>';
                        break;
                    }
                }
                deptEditMsg.textContent = 'Saved.';
                try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Departments', message: 'Department #' + (data.department && data.department.department_id ? data.department.department_id : payload.department_id) + ' updated', color: '#10b981' }); } catch(e) {}
                setTimeout(()=>{ closeEditModal(); deptEditMsg.textContent=''; }, 600);
            } catch(err) { deptEditMsg.textContent = err.message || 'Save failed'; }
        });
    }

    // Toggle active state for department
    function updateDeptToggleStyle(){
        try {
            if (!deptToggleBtn) return;
            var a = String(deptToggleBtn.dataset.active || '0');
            deptToggleBtn.style.background = '';
            deptToggleBtn.style.color = '';
            deptToggleBtn.style.border = '';
            if (a === '1') {
                // currently active -> action is Deactivate (red)
                deptToggleBtn.style.background = '#dc2626'; deptToggleBtn.style.color = '#ffffff'; deptToggleBtn.style.border = '1px solid #b91c1c';
            } else {
                // currently inactive -> action is Activate (green)
                deptToggleBtn.style.background = '#16a34a'; deptToggleBtn.style.color = '#ffffff'; deptToggleBtn.style.border = '1px solid #15803d';
            }
        } catch(e){}
    }

    if (deptToggleBtn) {
        deptToggleBtn.addEventListener('click', function(){
            const depId = document.getElementById('edit_department_id') ? document.getElementById('edit_department_id').value : '';
            if (!depId) { try { if (window.Notify && typeof Notify.push === 'function') Notify.push({ from: 'Departments', message: 'Missing department id', color: '#dc2626' }); } catch(e){} return; }
            deptToggleBtn.disabled = true; deptToggleBtn.textContent = 'Working...';
            var fd = new FormData(); fd.append('department_id', depId);
            fetch('toggle_department.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function(res){ return res.json(); }).then(function(json){
                if (!json || !json.ok) {
                    var err = json && json.error ? json.error : 'Error toggling';
                    try { if (window.Notify && typeof Notify.push === 'function') Notify.push({ from: 'Departments', message: err, color: '#dc2626' }); } catch(e){}
                    deptToggleBtn.disabled = false; deptToggleBtn.textContent = (deptToggleBtn.dataset.active === '1') ? 'Deactivate' : 'Activate'; updateDeptToggleStyle();
                    return;
                }
                var newActive = json.active ? 1 : 0;
                deptToggleBtn.dataset.active = newActive ? '1' : '0'; deptToggleBtn.textContent = newActive ? 'Deactivate' : 'Activate'; updateDeptToggleStyle();
                // update DEPARTMENTS_DATA cache
                try {
                    var k = String(depId);
                    if (DEPARTMENTS_DATA && DEPARTMENTS_DATA[k]) DEPARTMENTS_DATA[k].active = newActive;
                    // update table row dataset if exists
                    var rows = Array.from(departmentsTableBody.querySelectorAll('tr'));
                    for (var i=0;i<rows.length;i++){
                        var idCell = rows[i].querySelector('td'); if (!idCell) continue; if (String(idCell.textContent).trim() === String(depId)) {
                            rows[i].dataset.active = newActive;
                            try { if (rows[i].children[5]) rows[i].children[5].innerHTML = newActive==1 ? '<span class="active-badge">Active</span>' : '<span class="inactive-badge">Inactive</span>'; } catch(e) {}
                            break;
                        }
                    }
                } catch(e){}
                try { if (window.Notify && typeof Notify.push === 'function') Notify.push({ from: 'Departments', message: newActive ? 'Department activated' : 'Department deactivated', color: newActive ? '#16a34a' : '#f59e0b' }); } catch(e){}
                deptToggleBtn.disabled = false;
            }).catch(function(){ try { if (window.Notify && typeof Notify.push === 'function') Notify.push({ from: 'Departments', message: 'Network error', color: '#dc2626' }); } catch(e){} deptToggleBtn.disabled = false; deptToggleBtn.textContent = (deptToggleBtn.dataset.active === '1') ? 'Deactivate' : 'Activate'; updateDeptToggleStyle(); });
        });
    }

    function escapeHtml(s){ return (s+'').replace(/[&<>\"]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
})();
</script>
</body>
</html>
