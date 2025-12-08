
\n// --- next script ---\n

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

\n// --- next script ---\n

// Client-side logic for Users admin: modal open, department->team wiring, edit viewer, create modal
const DEPARTMENTS = <?= $departments_json ?> || {};
<?php $roleNorm = isset($user['role']) ? strtolower(trim($user['role'])) : ''; $is_admin = in_array('users_view', $_SESSION['user']['access_keys'] ?? []) || in_array($roleNorm, ['admin','master admin','master_admin','master-admin','masteradmin'], true); ?>
const IS_ADMIN = <?= json_encode($is_admin) ?>;
document.addEventListener('DOMContentLoaded', function(){
    const openBtn = document.getElementById('openCreateBtn');
    const createModal = document.getElementById('createModal');
    const cancelCreate = document.getElementById('cancelCreate');
    const createForm = document.getElementById('createForm');
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

    // populate departments from DEPARTMENTS JSON
    (function populateDeptFilter(){
        try {
            Object.keys(DEPARTMENTS).forEach(k => {
                const d = DEPARTMENTS[k];
                const o = document.createElement('option'); o.value = d.department_name || k; o.textContent = d.department_name || ('Dept ' + k); filterDept.appendChild(o);
            });
        } catch(e) { /* silent */ }
    })();

    // when department changes populate team select
    if (filterDept) {
        filterDept.addEventListener('change', function(){
            const val = this.value;
            filterTeam.innerHTML = '';
            const opt0 = document.createElement('option'); opt0.value=''; opt0.textContent='All teams'; filterTeam.appendChild(opt0);
            filterTeam.disabled = true;
            if (!val) return;
            // find department key by name
            let deptKey = null;
            Object.keys(DEPARTMENTS).forEach(k => { if ((DEPARTMENTS[k].department_name||'') === val) deptKey = k; });
            if (!deptKey) return;
            const dept = DEPARTMENTS[deptKey];
            if (dept && Array.isArray(dept.teams) && dept.teams.length) {
                dept.teams.forEach(t => { const o = document.createElement('option'); o.value = t.team_name || t.team_id; o.textContent = t.team_name || ('Team ' + t.team_id); o.dataset.manager = t.manager_name || ''; filterTeam.appendChild(o); });
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
            const dept = (r.children[4] && r.children[4].textContent || '').trim();
            const team = (r.children[6] && r.children[6].textContent || '').trim();
            const role = (r.children[8] && r.children[8].textContent || '').trim().toLowerCase();
            const active = (r.dataset.active !== undefined) ? String(r.dataset.active) : ((r.querySelector('.users-col-active') && r.querySelector('.users-col-active').textContent||'').trim());
            const createdText = (r.querySelector('.users-col-created') && r.querySelector('.users-col-created').textContent || '').trim();
            const createdDate = parseDateFromCell(createdText);

            if (nameQ) { if (!(name.indexOf(nameQ) !== -1 || email.indexOf(nameQ) !== -1)) keep = false; }
            if (roleQ) { if (role.toLowerCase() !== roleQ.toLowerCase()) keep = false; }
            if (deptQ) { if (dept !== deptQ) keep = false; }
            if (teamQ) { if (team !== teamQ) keep = false; }
            if (activeQ && activeQ !== 'all') { if (String(active) !== String(activeQ)) keep = false; }
            if (fromVal && createdDate) { if (createdDate < fromVal) keep = false; }
            if (toVal && createdDate) { // include day end
                const toEnd = new Date(toVal.getTime()); toEnd.setHours(23,59,59,999);
                if (createdDate > toEnd) keep = false;
            }

            if (keep) { r.style.display = ''; visible++; } else { r.style.display = 'none'; }
        });

        if (visibleCountEl) visibleCountEl.textContent = visible;
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
                dept.teams.forEach(t => {
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
                        <option value="">-- Select Role --</option>
                        <?php foreach ($roles as $ro2): ?>
                            <option value="<?= (int)$ro2['role_id'] ?>" data-department-id="<?= empty($ro2['department_id']) ? '' : (int)$ro2['department_id'] ?>"><?= htmlspecialchars($ro2['role_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div style="display:flex;gap:8px;justify-content:space-between;margin-top:12px;">
                        <div>
                            <button type="button" id="e_cancel" class="btn">Close</button>
                        </div>
                        <div style="display:flex;gap:8px;">
                            ${IS_ADMIN ? '<button type="button" id="e_reset_pwd" class="btn">Reset Password</button>' : ''}
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
                    dept.teams.forEach(t => { const o=document.createElement('option'); o.value=t.team_id; o.textContent=t.team_name; o.dataset.managerName = t.manager_name || ''; e_team.appendChild(o); });
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
                    try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'User created', color: '#10b981' }); } catch(e){}
                    setTimeout(()=> window.location.reload(), 700);
                } else {
                    const err = json && json.error ? json.error : 'Failed to create user';
                    document.getElementById('createMsg').textContent = err;
                    try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: err, color: '#dc2626' }); } catch(e){}
                }
            } catch (err) { console.error(err); document.getElementById('createMsg').textContent = 'Request failed'; try { if (window.Notify && typeof window.Notify.push === 'function') Notify.push({ from: 'Users', message: 'Request failed', color: '#dc2626' }); } catch(e){} }
        });
    }

});




\n// --- next script ---\n

