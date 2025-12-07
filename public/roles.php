<?php
// Start session only if not already active
if (function_exists('session_status')) {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
} else {
  @session_start();
}
require_once '../includes/db.php';
require_once __DIR__ . '/../includes/access.php';

// authorization: prefer access_keys (role_id-aware)
if (!isset($_SESSION['user']) || !_has_access('roles_view')) {
  http_response_code(403);
  echo 'Access denied.';
  exit;
}

$user = $_SESSION['user'];

// fetch departments for optional association (only active)
$departments = [];
$dres = $conn->query("SELECT department_id, department_name FROM departments WHERE active = 1 ORDER BY department_name");
if ($dres) { while ($r = $dres->fetch_assoc()) $departments[] = $r; $dres->free(); }

// fetch available access rights
$access_rights = [];
$ar = $conn->query("SELECT access_id, access_key, access_name, description FROM access_rights ORDER BY access_name");
if ($ar) { while ($r = $ar->fetch_assoc()) $access_rights[] = $r; $ar->free(); }

// fetch existing roles with department name and access rights
$roles = [];
$sql = "SELECT r.role_id, r.role_name, r.department_id, r.description, r.created_at, r.active, d.department_name, GROUP_CONCAT(DISTINCT ar.access_key ORDER BY ar.access_key SEPARATOR ', ') AS access_keys FROM roles r LEFT JOIN departments d ON r.department_id = d.department_id LEFT JOIN role_access_rights rar ON rar.role_id = r.role_id LEFT JOIN access_rights ar ON ar.access_id = rar.access_id GROUP BY r.role_id ORDER BY r.role_name";
$rs = $conn->query($sql);
if ($rs) { while ($r = $rs->fetch_assoc()) $roles[] = $r; $rs->free(); }

?>
<?php $pageTitle = 'Roles'; ?>
<?php if (file_exists(__DIR__ . '/../includes/header.php')) include __DIR__ . '/../includes/header.php'; ?>
<?php if (file_exists(__DIR__ . '/../includes/navbar.php')) include __DIR__ . '/../includes/navbar.php'; ?>
<link rel="stylesheet" href="styles/layout.css">
<link rel="stylesheet" href="styles/roles.css">
<link rel="stylesheet" href="styles/users.css">
<link rel="stylesheet" href="assets/css/notify.css">
<script src="assets/js/notify.js"></script>

<?php if (!empty($_SESSION['flash'])): $flash = $_SESSION['flash']; unset($_SESSION['flash']); ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  try {
    var msg = <?php echo json_encode($flash['success'] ?? $flash['error'] ?? ''); ?>;
    var color = <?php echo json_encode(!empty($flash['success']) ? '#16a34a' : '#dc2626'); ?>;
    if (msg && window.Notify && typeof Notify.push === 'function') {
      Notify.push({ from: 'Roles', message: msg, color: color });
    }
  } catch(e) { /* ignore */ }
});
</script>
<?php endif; ?>

<main class="content-area">
  <div>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;">
        <h2 class="section-title">Roles</h2>
        <button id="openCreateRole" class="btn" style="padding:10px 12px;border-radius:6px;border:none;cursor:pointer;">Create Role</button>
    </div>

    <div class="table-wrap" style="min-width:0;">
        <table class="roles-table users-table">
          <thead class="roles-thead"><tr><th>ID</th><th>Role</th><th>Department</th><th>Access Rights</th><th>Active</th><th>Created</th></tr></thead>
          <tbody>
            <?php if (empty($roles)): ?>
              <tr><td colspan="5" style="padding:10px;border:1px solid #eee;color:#666;">No roles defined yet.</td></tr>
            <?php else: foreach ($roles as $r): ?>
              <tr data-role-id="<?= (int)$r['role_id'] ?>">
                <td class="roles-col-id"><?= (int)$r['role_id'] ?></td>
                <td class="roles-col-name"><strong><?= htmlspecialchars($r['role_name']) ?></strong><div class="roles-desc"><?= htmlspecialchars($r['description']) ?></div></td>
                <td class="roles-col-dept"><?= htmlspecialchars($r['department_name'] ?? '') ?></td>
                <td class="access-rights roles-col-access">
                  <?= htmlspecialchars($r['access_keys'] ?? '') ?>
                </td>
                <td class="roles-col-active">
                  <?php if (isset($r['active']) && $r['active'] == 1): ?>
                    <span style="color:green;font-weight:600;">Active</span>
                  <?php else: ?>
                    <span style="color:#b33;font-weight:600;">Inactive</span>
                  <?php endif; ?>
                </td>
                <td class="roles-col-created"><?= htmlspecialchars($r['created_at']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>
<!-- Create Role Modal -->
<div id="createRoleModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);align-items:center;justify-content:center;z-index:1200;">
  <div role="dialog" aria-modal="true" class="modal-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
      <h3 style="margin:0;">Create Role</h3>
      <button id="closeCreateRole" style="background:transparent;border:0;font-size:20px;line-height:1;cursor:pointer;">&times;</button>
    </div>
    <form method="POST" action="create_role.php">
      <div style="margin-bottom:8px;"><label>Role Name</label><br><input name="role_name" required style="width:100%;padding:8px;border-radius:6px;border:1px solid #ccc;"/></div>
      <div style="margin-bottom:8px;"><label>Department (optional)</label><br>
        <select name="department_id" style="width:100%;padding:8px;border-radius:6px;border:1px solid #ccc;">
          <option value="">-- none --</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= (int)$d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="margin-bottom:8px;"><label>Description</label><br><textarea name="description" style="width:100%;min-height:80px;padding:8px;border-radius:6px;border:1px solid #ccc;"></textarea></div>

      <div style="margin-bottom:8px;"><label>Access Rights</label>
        <div style="display:flex;flex-direction:column;gap:6px;padding:8px;border:1px solid #eee;border-radius:6px;background:#fff;max-height:320px;overflow:auto;">
          <?php foreach ($access_rights as $a): ?>
            <label style="display:flex;gap:8px;align-items:center;"><input type="checkbox" name="access_ids[]" value="<?= (int)$a['access_id'] ?>"> <strong><?= htmlspecialchars($a['access_name']) ?></strong> <small style="color:#666;margin-left:8px;">(<?= htmlspecialchars($a['access_key']) ?>)</small></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end;"><button type="button" id="cancelCreateRole" style="padding:8px 10px;border-radius:6px;border:1px solid #ccc;background:#fff;">Cancel</button><button type="submit" class="btn">Create</button></div>
    </form>
  </div>
</div>

<!-- Edit Role Modal -->
<div id="editRoleModal" class="modal-overlay">
  <div class="modal-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
      <h3 style="margin:0;">Edit Role</h3>
      <button id="closeEditRole" style="background:transparent;border:0;font-size:20px;line-height:1;cursor:pointer;">&times;</button>
    </div>
    <div id="editMsg" class="small-muted"></div>
    <form id="editRoleForm">
      <input type="hidden" name="role_id" id="er_role_id" value="">
      <div style="margin-bottom:8px;"><label>Role Name</label><br><input id="er_role_name" name="role_name" required style="width:100%;padding:8px;border-radius:6px;border:1px solid #ccc;"/></div>
      <div style="margin-bottom:8px;"><label>Department (optional)</label><br>
        <select id="er_department_id" name="department_id" style="width:100%;padding:8px;border-radius:6px;border:1px solid #ccc;">
          <option value="">-- Global --</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= (int)$d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="margin-bottom:8px;"><label>Description</label><br><textarea id="er_description" name="description" style="width:100%;min-height:80px;padding:8px;border-radius:6px;border:1px solid #ccc;"></textarea></div>

      <div style="margin-bottom:8px;"><label>Access Rights</label>
        <div id="er_access_list" style="display:flex;flex-direction:column;gap:6px;padding:8px;border:1px solid #eee;border-radius:6px;background:#fff;max-height:320px;overflow:auto;">
          <?php foreach ($access_rights as $a): ?>
            <label style="display:flex;gap:8px;align-items:center;"><input type="checkbox" name="access_ids[]" value="<?= (int)$a['access_id'] ?>" data-access-key="<?= htmlspecialchars($a['access_key']) ?>"> <strong><?= htmlspecialchars($a['access_name']) ?></strong> <small style="color:#666;margin-left:8px;">(<?= htmlspecialchars($a['access_key']) ?>)</small></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div style="display:flex;gap:8px;align-items:center;justify-content:space-between;margin-top:12px;">
        <div>
          <button type="button" id="er_cancel" class="btn">Cancel</button>
        </div>
        <div style="display:flex;gap:8px;">
          <button type="button" id="er_toggle_active" class="btn" style="min-width:96px;">Toggle Active</button>
          <button type="submit" id="er_save" class="btn">Save Changes</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  // Create Role modal handlers
  var openCreateBtn = document.getElementById('openCreateRole');
  var createModal = document.getElementById('createRoleModal');
  var closeCreateBtn = document.getElementById('closeCreateRole');
  var cancelCreateBtn = document.getElementById('cancelCreateRole');
  function openCreateModal(){ if (!createModal) return; createModal.style.display = 'flex'; document.body.style.overflow = 'hidden'; var first = createModal.querySelector('input[name="role_name"]'); if (first) first.focus(); }
  function closeCreateModal(){ if (!createModal) return; createModal.style.display = 'none'; document.body.style.overflow = ''; }
  if (openCreateBtn) openCreateBtn.addEventListener('click', function(e){ e.preventDefault(); openCreateModal(); });
  if (closeCreateBtn) closeCreateBtn.addEventListener('click', function(e){ e.preventDefault(); closeCreateModal(); });
  if (cancelCreateBtn) cancelCreateBtn.addEventListener('click', function(e){ e.preventDefault(); closeCreateModal(); });
  if (createModal) createModal.addEventListener('click', function(e){ if (e.target === createModal) closeCreateModal(); });

  // Edit Role modal wiring
  var table = document.querySelector('.roles-table');
  var editModal = document.getElementById('editRoleModal');
  var er_form = document.getElementById('editRoleForm');
  var er_role_id = document.getElementById('er_role_id');
  var er_role_name = document.getElementById('er_role_name');
  var er_department_id = document.getElementById('er_department_id');
  var er_description = document.getElementById('er_description');
  var er_access_list = document.getElementById('er_access_list');
  var er_cancel = document.getElementById('er_cancel');
  var er_toggle_active = document.getElementById('er_toggle_active');
  var er_close = document.getElementById('closeEditRole');
  var editMsg = document.getElementById('editMsg');

  function collectAccessIds(container){ var checked = []; var inputs = container.querySelectorAll('input[type="checkbox"][name="access_ids[]"]'); inputs.forEach(function(i){ if (i.checked) checked.push(i.value); }); return checked; }

  function openEditRoleModal(row){
  if (!editModal || !row) return;
  var roleId = row.getAttribute('data-role-id') || '';
  var roleNameEl = row.children[1] ? row.children[1].querySelector('strong') : null;
  var roleName = roleNameEl ? roleNameEl.textContent.trim() : '';
  var descEl = row.children[1] ? row.children[1].querySelector('div') : null;
  var desc = descEl ? descEl.textContent.trim() : '';
  var dept = row.children[2] ? row.children[2].textContent.trim() : '';
  var accessKeys = row.querySelector('.access-rights') ? row.querySelector('.access-rights').textContent.trim() : '';
  var activeText = row.children[4] ? row.children[4].textContent.trim() : '';

    er_role_id.value = roleId;
    er_role_name.value = roleName;
    er_description.value = desc;
    // set department select by visible name
    var setDept = '';
    for (var i=0;i<er_department_id.options.length;i++){ if ((er_department_id.options[i].textContent||'').trim() === dept) { setDept = er_department_id.options[i].value; break; } }
    er_department_id.value = setDept;
    // set access checkboxes
    var keys = accessKeys.split(',').map(function(s){ return s.trim(); }).filter(Boolean);
    var inputs = er_access_list.querySelectorAll('input[type="checkbox"][name="access_ids[]"]');
    inputs.forEach(function(cb){ var key = cb.dataset.accessKey || ''; cb.checked = keys.indexOf(key) !== -1; });
    // toggle button label and style
    try {
      var at = (activeText || '').toString().toLowerCase();
      if (at.indexOf('inactive') !== -1) {
        // explicitly inactive -> action should be Activate
        er_toggle_active.dataset.active = '0';
        er_toggle_active.textContent = 'Activate';
      } else if (at.indexOf('active') !== -1) {
        // explicitly active -> action should be Deactivate
        er_toggle_active.dataset.active = '1';
        er_toggle_active.textContent = 'Deactivate';
      } else {
        // fallback to inactive state
        er_toggle_active.dataset.active = '0';
        er_toggle_active.textContent = 'Activate';
      }
    } catch(e) {
      er_toggle_active.dataset.active = '0';
      er_toggle_active.textContent = 'Activate';
    }
    updateToggleButtonStyle();
    editMsg.textContent = '';
    editModal.style.display = 'flex'; document.body.style.overflow = 'hidden';
  }

  // cache original role values for no-op detection
  var ROLE_ORIGINAL = null;
  function captureRoleOriginal() {
    try {
      var access = [];
      var inputs = er_access_list.querySelectorAll('input[type="checkbox"][name="access_ids[]"]');
      inputs.forEach(function(i){ if (i.checked) access.push(String(i.value)); });
      ROLE_ORIGINAL = {
        role_id: String(er_role_id.value || ''),
        role_name: String(er_role_name.value || ''),
        department_id: String(er_department_id.value || ''),
        description: String(er_description.value || ''),
        access_ids: access.sort()
      };
    } catch(e){ ROLE_ORIGINAL = null; }
  }

  function closeEditRoleModal(){ if (!editModal) return; editModal.style.display = 'none'; document.body.style.overflow = ''; }
  if (er_cancel) er_cancel.addEventListener('click', function(e){ e.preventDefault(); closeEditRoleModal(); });
  if (er_close) er_close.addEventListener('click', function(e){ e.preventDefault(); closeEditRoleModal(); });
  if (editModal) editModal.addEventListener('click', function(e){ if (e.target === editModal) closeEditRoleModal(); });

  // attach row click handlers
  if (table) {
    table.querySelectorAll('tbody tr').forEach(function(row){ row.style.cursor = 'pointer'; row.addEventListener('click', function(){ openEditRoleModal(row); }); });
  }

  // capture original after modal opens (listen for display change)
  var obs = new MutationObserver(function(){ if (editModal && editModal.style.display === 'flex') { captureRoleOriginal(); } });
  if (editModal) obs.observe(editModal, { attributes: true, attributeFilter: ['style'] });

  // toggle active via existing endpoint
    if (er_toggle_active) {
    er_toggle_active.addEventListener('click', function(e){
      var rid = er_role_id.value || '';
      if (!rid) {
        try { if (window.Notify && typeof Notify.push === 'function') Notify.push({ from: 'Roles', message: 'Missing role id', color: '#dc2626' }); } catch(e){}
        return;
      }
      var fd = new FormData(); fd.append('role_id', rid);
      er_toggle_active.disabled = true; er_toggle_active.textContent = 'Working...';
      fetch('toggle_role.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function(res){ return res.json(); }).then(function(json){
        if (!json || !json.ok) {
          var errMsg = 'Error: ' + (json && json.error ? json.error : 'Unknown');
          try { if (window.Notify && typeof Notify.push === 'function') Notify.push({ from: 'Roles', message: errMsg, color: '#dc2626' }); } catch(e){}
          er_toggle_active.disabled = false; er_toggle_active.textContent = (er_toggle_active.dataset.active=='1') ? 'Deactivate' : 'Activate';
          updateToggleButtonStyle();
          return;
        }
        var newActive = json.active ? 1 : 0;
        er_toggle_active.dataset.active = newActive; er_toggle_active.textContent = newActive ? 'Deactivate' : 'Activate';
        updateToggleButtonStyle();
        // update table row active
        var row = document.querySelector('tr[data-role-id="' + rid + '"]'); if (row) { row.children[4].innerHTML = newActive==1 ? '<span style="color:green;font-weight:600;">Active</span>' : '<span style="color:#b33;font-weight:600;">Inactive</span>'; }
        er_toggle_active.disabled = false;
        try { if (window.Notify && typeof Notify.push === 'function') Notify.push({ from: 'Roles', message: (newActive ? 'Role activated' : 'Role deactivated'), color: newActive ? '#16a34a' : '#f59e0b' }); } catch(e){}
      }).catch(function(){ try { if (window.Notify && typeof Notify.push === 'function') Notify.push({ from: 'Roles', message: 'Network error', color: '#dc2626' }); } catch(e){} er_toggle_active.disabled = false; er_toggle_active.textContent = (er_toggle_active.dataset.active=='1') ? 'Deactivate' : 'Activate'; });
    });
  }

  // update toggle button color based on state (1 = active -> show Deactivate in red; 0 = inactive -> show Activate in green)
  function updateToggleButtonStyle(){
    try {
      if (!er_toggle_active) return;
      var a = String(er_toggle_active.dataset.active || '0');
      // reset styles
      er_toggle_active.style.background = '';
      er_toggle_active.style.color = '';
      er_toggle_active.style.borderColor = '';
      if (a === '1') {
        // currently active -> the action is to Deactivate -> red
        er_toggle_active.style.background = '#dc2626';
        er_toggle_active.style.color = '#ffffff';
        er_toggle_active.style.border = '1px solid #b91c1c';
      } else {
        // currently inactive -> the action is to Activate -> green
        er_toggle_active.style.background = '#16a34a';
        er_toggle_active.style.color = '#ffffff';
        er_toggle_active.style.border = '1px solid #15803d';
      }
    } catch(e){}
  }

  // submit updates
  if (er_form) {
    er_form.addEventListener('submit', function(e){
      e.preventDefault();
      var payload = { role_id: parseInt(er_role_id.value,10)||0, role_name: (er_role_name.value||'').trim(), department_id: er_department_id.value === '' ? '' : parseInt(er_department_id.value,10), description: (er_description.value||'').trim(), access_ids: collectAccessIds(er_access_list) };
      // no-op detection: compare with ROLE_ORIGINAL
      try {
        if (ROLE_ORIGINAL) {
          var curAccess = (payload.access_ids || []).map(String).sort();
          var same = String(ROLE_ORIGINAL.role_id||'') === String(payload.role_id||'') && String((ROLE_ORIGINAL.role_name||'')).trim() === String((payload.role_name||'')).trim() && String((ROLE_ORIGINAL.department_id||'')).trim() === String((payload.department_id||'')).trim() && String((ROLE_ORIGINAL.description||'')).trim() === String((payload.description||'')).trim() && JSON.stringify(ROLE_ORIGINAL.access_ids||[]) === JSON.stringify(curAccess||[]);
          if (same) {
            try { if (window.Notify && typeof Notify.push === 'function') Notify.push({ from: 'Roles', message: 'No changes detected to save', color: '#f59e0b' }); } catch(e){}
            return;
          }
        }
      } catch(e) {}
      var saveBtn = document.getElementById('er_save'); if (saveBtn){ saveBtn.disabled = true; saveBtn.textContent = 'Saving...'; }
      fetch('update_role.php', { method: 'POST', credentials: 'same-origin', body: JSON.stringify(payload) }).then(function(res){ return res.json(); }).then(function(json){
        if (!json || !json.ok) {
          var em = json && json.error ? json.error : 'Save failed';
          editMsg.textContent = em;
          try { if (window.Notify && typeof Notify.push === 'function') Notify.push({ from: 'Roles', message: em, color: '#dc2626' }); } catch(e){}
          if (saveBtn){ saveBtn.disabled=false; saveBtn.textContent='Save Changes'; }
          return;
        }
        var rid = payload.role_id;
        var row = document.querySelector('tr[data-role-id="' + rid + '"]');
          if (row && json.role) {
          var nameCell = row.children[1];
          if (nameCell) {
            var s = nameCell.querySelector('strong'); if (s) s.textContent = json.role.role_name || payload.role_name;
            var d = nameCell.querySelector('div'); if (d) d.textContent = json.role.description || payload.description || '';
          }
          // department
          var depText = '';
          for (var i=0;i<er_department_id.options.length;i++){ if (String(er_department_id.options[i].value) === String(payload.department_id)) { depText = er_department_id.options[i].textContent; break; } }
          if (row.children[2]) row.children[2].textContent = depText || '';
          // access rights
          var arCell = row.querySelector('.access-rights'); if (arCell) arCell.textContent = json.role.access_keys || '';
          // active
          if (row.children[4]) row.children[4].innerHTML = (json.role.active==1) ? '<span style="color:green;font-weight:600;">Active</span>' : '<span style="color:#b33;font-weight:600;">Inactive</span>';
        }
        editMsg.textContent = 'Saved.';
        try { if (window.Notify && typeof Notify.push === 'function') Notify.push({ from: 'Roles', message: 'Role saved', color: '#16a34a' }); } catch(e){}
        if (saveBtn){ saveBtn.disabled=false; saveBtn.textContent='Save Changes'; }
        setTimeout(function(){ closeEditRoleModal(); }, 700);
      }).catch(function(err){ editMsg.textContent = 'Request failed'; if (saveBtn){ saveBtn.disabled=false; saveBtn.textContent='Save Changes'; } });
    });
  }

  // global Escape handler closes modals
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') { if (createModal && createModal.style.display === 'flex') closeCreateModal(); if (editModal && editModal.style.display === 'flex') closeEditRoleModal(); } });
});
</script>

<?php if (file_exists(__DIR__ . '/../includes/footer.php')) include __DIR__ . '/../includes/footer.php'; ?>
