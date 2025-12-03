<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/access.php';

if (!isset($_SESSION['user']) || !_has_access('flows_view', ['admin','hr'])) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$pools = [];
$pRes = $conn->query("SELECT id, pool_name FROM status_pools ORDER BY pool_name ASC");
if ($pRes) { while ($p = $pRes->fetch_assoc()) $pools[] = $p; $pRes->free(); }

// Determine whether we're editing Positions or Applicants based on ?type=
$type = isset($_GET['type']) && $_GET['type'] === 'applicants' ? 'applicants' : 'positions';

$active_statuses = [];
if ($type === 'positions') {
    $aRes = $conn->query("SELECT status_id, status_name, COALESCE(sort_order,0) AS sort_order FROM positions_status WHERE active = 1 ORDER BY sort_order ASC, status_name ASC");
    if ($aRes) { while ($r = $aRes->fetch_assoc()) $active_statuses[] = $r; $aRes->free(); }
} else {
    $aRes = $conn->query("SELECT status_id, status_name, COALESCE(sort_order,0) AS sort_order FROM applicants_status WHERE active = 1 ORDER BY sort_order ASC, status_name ASC");
    if ($aRes) { while ($r = $aRes->fetch_assoc()) $active_statuses[] = $r; $aRes->free(); }
}

// If editing an existing status (e.g. ?id=123), load its data for applicants only
$editing = false;
$record = null;
$selectedTransitions = [];
if ($type === 'applicants' && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $editing = true;
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare('SELECT status_id, status_name, status_color, pool_id, active, COALESCE(sort_order,0) AS sort_order FROM applicants_status WHERE status_id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $record = $res->fetch_assoc();
        $stmt->close();
    }
    if ($record) {
        // fetch current transitions
        $tstmt = $conn->prepare('SELECT to_status_id FROM applicants_status_transitions WHERE from_status_id = ?');
        if ($tstmt) {
            $tstmt->bind_param('i', $id);
            $tstmt->execute();
            $tres = $tstmt->get_result();
            while ($tr = $tres->fetch_assoc()) $selectedTransitions[] = (int)$tr['to_status_id'];
            $tstmt->close();
        }
    }
}

if (file_exists(__DIR__ . '/../includes/header.php')) include __DIR__ . '/../includes/header.php';
if (file_exists(__DIR__ . '/../includes/navbar.php')) include __DIR__ . '/../includes/navbar.php';
?>
<link rel="stylesheet" href="styles/users.css">
<link rel="stylesheet" href="styles/flows.css">

<main class="content-area">
    <h2 class="section-title"><?= $type === 'applicants' ? 'Create Applicant Status' : 'Create Status' ?></h2>
    <div class="card">
        <form id="createForm" method="post" action="<?= $type === 'applicants' ? 'flows_create_applicants.php' : 'flows_create.php' ?>">
            <label for="ns_name">Name</label>
            <input id="ns_name" name="status_name" type="text" required value="<?= $editing && isset($record['status_name']) ? htmlspecialchars($record['status_name']) : '' ?>">

            <label for="ns_color">Color</label>
            <input id="ns_color" name="status_color" type="color" value="<?= $editing && isset($record['status_color']) && $record['status_color'] ? htmlspecialchars($record['status_color']) : '#359cf6' ?>">
            <span id="ns_color_hex" class="color-hex"><?= $editing && isset($record['status_color']) && $record['status_color'] ? htmlspecialchars($record['status_color']) : '#359cf6' ?></span>

            <label for="ns_pool">Pool (optional)</label>
            <select id="ns_pool" name="pool_id">
                <option value="">-- none --</option>
                <?php foreach($pools as $pool): ?>
                    <option value="<?= (int)$pool['id'] ?>" <?= ($editing && isset($record['pool_id']) && (int)$record['pool_id'] === (int)$pool['id']) ? 'selected' : '' ?>><?= htmlspecialchars($pool['pool_name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="ns_transitions">Applicable Transitions (optional)</label>
            <select id="ns_transitions" name="transitions[]" multiple size="8">
                <?php foreach($active_statuses as $ast): ?>
                    <option value="<?= (int)$ast['status_id'] ?>" <?= ($editing && in_array((int)$ast['status_id'], $selectedTransitions)) ? 'selected' : '' ?>><?= htmlspecialchars($ast['status_name']) ?> (sort: <?= (int)$ast['sort_order'] ?>)</option>
                <?php endforeach; ?>
            </select>

            <div class="sort-row">
                <div class="sort-col">
                    <label for="ns_sort">Sort Order</label>
                    <input id="ns_sort" name="sort_order" type="number" value="<?= $editing && isset($record['sort_order']) ? (int)$record['sort_order'] : 1 ?>" min="1">
                </div>
                <div class="after-col">
                    <label>Comes After</label>
                    <div id="comesAfterName" class="small-muted">Top (no previous status)</div>
                </div>
            </div>

            <div style="display:flex;align-items:center;gap:8px;margin-top:8px;">
                <label style="margin:0;"> <input id="ns_active" name="active" type="checkbox" <?= ($editing && isset($record['active']) && (int)$record['active'] === 1) ? 'checked' : 'checked' ?>> Active</label>
            </div>

            <?php if ($editing && isset($record['status_id'])): ?>
                <input type="hidden" id="status_id" name="status_id" value="<?= (int)$record['status_id'] ?>">
            <?php endif; ?>

            <div style="margin-top:12px;">
                <a class="btn" href="flows.php">Cancel</a>
                <button type="submit" class="btn btn-primary">Create</button>
            </div>
        </form>
    </div>
</main>

<?php if (file_exists(__DIR__ . '/../includes/footer.php')) include __DIR__ . '/../includes/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
    // color hex preview
    var colorInput = document.getElementById('ns_color');
    var colorHex = document.getElementById('ns_color_hex');
    if (colorInput && colorHex) {
        colorHex.textContent = colorInput.value || '';
        colorInput.addEventListener('input', function(){ colorHex.textContent = colorInput.value || ''; });
    }

    // load existing sorts for validation
    var existingSorts = <?= json_encode(array_values(array_map(function($r){ return (int)$r['sort_order']; }, $active_statuses))); ?> || [];
    var existingStatuses = <?= json_encode(array_map(function($r){ return ['id'=>(int)$r['status_id'],'name'=>$r['status_name'],'sort'=>(int)$r['sort_order']]; }, $active_statuses)); ?> || [];

    var sortInput = document.getElementById('ns_sort');
    var sortWarn = document.getElementById('sortWarn');
    var comesAfterEl = document.getElementById('comesAfterName');
    var createBtn = document.querySelector('#createForm button[type="submit"]');

    function validateSort(){
        if (!sortInput) return true;
        var raw = sortInput.value;
        var val = parseInt(raw === '' ? '0' : raw, 10);
        if (isNaN(val) || val <= 0) {
            if (sortWarn) { sortWarn.textContent = 'Sort must be a positive integer greater than 0.'; sortWarn.style.display = 'inline-block'; }
            if (createBtn) createBtn.disabled = true;
            return false;
        }
        var conflict = existingSorts.indexOf(val) !== -1;
        if (conflict) {
            if (sortWarn) { sortWarn.textContent = 'This sort number is already used by an active status.'; sortWarn.style.display = 'inline-block'; }
            if (createBtn) createBtn.disabled = true;
            return false;
        } else {
            if (sortWarn) sortWarn.style.display = 'none';
            if (createBtn) createBtn.disabled = false;
            return true;
        }
    }

    function updateComesAfter(){
        if (!sortInput || !comesAfterEl) return;
        var val = parseInt(sortInput.value || '0', 10) || 0;
        var prev = null;
        for (var i=0;i<existingStatuses.length;i++){
            var s = existingStatuses[i];
            if (s.sort < val) {
                if (!prev || s.sort > prev.sort) prev = s;
            }
        }
        if (prev) {
            comesAfterEl.textContent = prev.name + ' (sort: ' + prev.sort + ')';
        } else {
            comesAfterEl.textContent = 'Top (no previous status)';
        }
    }

    if (sortInput) {
        sortInput.addEventListener('input', function(){ validateSort(); updateComesAfter(); });
        validateSort();
        updateComesAfter();
    }
    
    // If editing an existing applicant status, submit via AJAX to the update endpoint
    <?php if ($editing && $type === 'applicants'): ?>
    var form = document.getElementById('createForm');
    form.addEventListener('submit', async function(ev){
        ev.preventDefault();
        var id = document.getElementById('status_id') ? parseInt(document.getElementById('status_id').value,10) : 0;
        var name = (document.getElementById('ns_name') || {}).value || '';
        var color = (document.getElementById('ns_color') || {}).value || '';
        var pool = (document.getElementById('ns_pool') || {}).value || '';
        var active = document.getElementById('ns_active') && document.getElementById('ns_active').checked ? 1 : 0;
        var sort_order = parseInt((document.getElementById('ns_sort') || {}).value || '0',10) || 0;
        var transitions = [];
        var tr = document.getElementById('ns_transitions');
        if (tr) { for (var i=0;i<tr.options.length;i++){ var o=tr.options[i]; if (o && o.selected) transitions.push(parseInt(o.value,10)); } }

        if (!name) { try { if (window.Notify && typeof Notify.push === 'function') Notify.push({ from: 'Flows', message: 'Name required', color: '#dc2626' }); } catch(e){} return; }
        if (!pool) { try { if (window.Notify && typeof Notify.push === 'function') Notify.push({ from: 'Flows', message: 'Pool required', color: '#dc2626' }); } catch(e){} return; }
        if (!transitions.length) { try { if (window.Notify && typeof Notify.push === 'function') Notify.push({ from: 'Flows', message: 'Select at least one transition', color: '#dc2626' }); } catch(e){} return; }
        if (!validateSort()) { try { if (window.Notify && typeof Notify.push === 'function') Notify.push({ from: 'Flows', message: 'Please choose a different Sort Order', color: '#dc2626' }); } catch(e){} return; }

        var payload = { status_id: id, status_name: name, status_color: color, pool_id: pool, active: active, sort_order: sort_order, transitions: transitions };
        try {
            var res = await fetch('flows_update_applicants.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
            var json = await res.json();
            if (json && json.success) { try { if (window.Notify && typeof Notify.push === 'function') Notify.push({ from: 'Flows', message: 'Updated', color: '#16a34a' }); } catch(e){} window.location.href = 'flows.php'; }
            else { try { if (window.Notify && typeof Notify.push === 'function') Notify.push({ from: 'Flows', message: 'Update failed: ' + (json && json.error ? json.error : 'unknown'), color: '#dc2626' }); } catch(e){} }
        } catch(e){ console.error(e); alert('Request failed'); }
    });
    <?php endif; ?>
});
</script>
