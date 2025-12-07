<?php
// public/flows.php - Manage Position Status Flows
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/access.php';

if (!isset($_SESSION['user']) || !_has_access('flows_view', ['admin','hr'])) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$user = $_SESSION['user'];

// Fetch statuses with pool name and transitions (comma-separated)
$sql = "
SELECT p.status_id,
       p.status_name,
       COALESCE(p.status_color, '') AS status_color,
       p.pool_id,
       COALESCE(sp.pool_name, '') AS pool_name,
       p.active,
       COALESCE(p.sort_order, 0) AS sort_order,
       (SELECT GROUP_CONCAT(ps.status_name ORDER BY ps.status_name SEPARATOR ', ')
    FROM positions_status_transitions t
    JOIN positions_status ps ON t.to_status_id = ps.status_id
    WHERE t.from_status_id = p.status_id AND t.active = 1) AS transitions,
       (SELECT GROUP_CONCAT(t.to_status_id SEPARATOR ',')
    FROM positions_status_transitions t
    WHERE t.from_status_id = p.status_id AND t.active = 1) AS transition_ids
FROM positions_status p
LEFT JOIN status_pools sp ON p.pool_id = sp.id
ORDER BY p.sort_order ASC, p.status_id ASC
";

$res = $conn->query($sql);
if ($res === false) {
    error_log('flows.php: statuses query failed: ' . $conn->error);
    $statuses = [];
} else {
    $statuses = [];
    while ($r = $res->fetch_assoc()) $statuses[] = $r;
    $res->free();
}

// Fetch status pools for the modal dropdown
$pools = [];
$pRes = $conn->query("SELECT id, pool_name FROM status_pools ORDER BY pool_name ASC");
if ($pRes) {
    while ($p = $pRes->fetch_assoc()) $pools[] = $p;
    $pRes->free();
}

// Fetch active statuses for transitions list and sort-order checks
$active_statuses = [];
$aRes = $conn->query("SELECT status_id, status_name, COALESCE(sort_order,0) AS sort_order FROM positions_status WHERE active = 1 ORDER BY sort_order ASC, status_name ASC");
if ($aRes) {
    while ($a = $aRes->fetch_assoc()) $active_statuses[] = $a;
    $aRes->free();
}

// Also fetch the same for Applicants flows (separate table names)
$app_statuses = [];
$appRes = $conn->query("SELECT a.status_id, a.status_name, COALESCE(a.status_color,'') AS status_color, a.pool_id, COALESCE(sp.pool_name,'') AS pool_name, a.active, COALESCE(a.sort_order,0) AS sort_order, (SELECT GROUP_CONCAT(as2.status_name ORDER BY as2.status_name SEPARATOR ', ') FROM applicants_status_transitions t JOIN applicants_status as2 ON t.to_status_id = as2.status_id WHERE t.from_status_id = a.status_id AND t.active = 1) AS transitions, (SELECT GROUP_CONCAT(t.to_status_id SEPARATOR ',') FROM applicants_status_transitions t WHERE t.from_status_id = a.status_id AND t.active = 1) AS transition_ids FROM applicants_status a LEFT JOIN status_pools sp ON a.pool_id = sp.id ORDER BY a.sort_order ASC, a.status_id ASC");
if ($appRes === false) {
    error_log('flows.php: applicants statuses query failed: ' . $conn->error);
    $app_statuses = [];
} else {
    while ($r = $appRes->fetch_assoc()) $app_statuses[] = $r;
    $appRes->free();
}

// Fetch active applicants statuses for transitions list and sort-order checks
$app_active_statuses = [];
$aaRes = $conn->query("SELECT status_id, status_name, COALESCE(sort_order,0) AS sort_order FROM applicants_status WHERE active = 1 ORDER BY sort_order ASC, status_name ASC");
if ($aaRes) {
    while ($a = $aaRes->fetch_assoc()) $app_active_statuses[] = $a;
    $aaRes->free();
}

// Fetch interview statuses and active interview statuses for the Interviews Flow panel
$interview_statuses = [];
$intRes = $conn->query("SELECT i.id, i.name, COALESCE(i.status_color,'') AS status_color, i.pool_id, COALESCE(sp.pool_name,'') AS pool_name, i.active, COALESCE(i.sort_order,0) AS sort_order, (SELECT GROUP_CONCAT(s2.name ORDER BY s2.name SEPARATOR ', ') FROM interviews_status_transitions t JOIN interview_statuses s2 ON t.to_status_id = s2.id WHERE t.from_status_id = i.id AND t.active = 1) AS transitions, (SELECT GROUP_CONCAT(t.to_status_id SEPARATOR ',') FROM interviews_status_transitions t WHERE t.from_status_id = i.id AND t.active = 1) AS transition_ids FROM interview_statuses i LEFT JOIN status_pools sp ON i.pool_id = sp.id ORDER BY i.sort_order ASC, i.id ASC");
if ($intRes === false) {
    error_log('flows.php: interview statuses query failed: ' . $conn->error);
    $interview_statuses = [];
} else {
    while ($r = $intRes->fetch_assoc()) $interview_statuses[] = $r;
    $intRes->free();
}

// Active interview statuses for transitions and sort checks
$interview_active_statuses = [];
$iaRes = $conn->query("SELECT id, name, COALESCE(sort_order,0) AS sort_order FROM interview_statuses WHERE active = 1 ORDER BY sort_order ASC, name ASC");
if ($iaRes) { while ($r = $iaRes->fetch_assoc()) $interview_active_statuses[] = $r; $iaRes->free(); }

if (file_exists(__DIR__ . '/../includes/header.php')) {
    $pageTitle = 'Flows';
    include __DIR__ . '/../includes/header.php';
}
if (file_exists(__DIR__ . '/../includes/navbar.php')) include __DIR__ . '/../includes/navbar.php';
?>
<link rel="stylesheet" href="styles/users.css">
<link rel="stylesheet" href="styles/flows.css">
<link rel="stylesheet" href="styles/roles.css">
<link rel="stylesheet" href="assets/css/notify.css">
<script src="assets/js/notify.js"></script>

<main class="content-area">
    <h2 class="section-title"><i class="fa-solid fa-project-diagram"></i> Flows</h2>

    <div class="flow-panels-list">

    <!-- Status Flow Diagram panel -->
    <div class="flow-panel" id="diagramPanel" hidden>
        <div class="flow-panel-header" role="button" aria-expanded="true">
            <div class="flow-panel-title">Status Flow Diagram</div>
            <div class="flow-panel-actions">
                <button type="button" class="btn btn-primary diagram-type-btn" data-type="positions">Position Ticket</button>
                <button type="button" class="btn btn-primary diagram-type-btn" data-type="applicants">Applicant Ticket</button>
                <button type="button" class="btn btn-primary diagram-type-btn" data-type="interviews">Interview Ticket</button>
            </div>
        </div>

        <div class="table-wrap flow-panel-body" style="display:block;">
            <div id="diagramContainer" class="status-diagram">
                <svg id="diagramSvg" class="diagram-svg" xmlns="http://www.w3.org/2000/svg"></svg>
                <div id="diagramNodes" class="diagram-nodes"></div>
            </div>
        </div>
    </div>

    <div class="flow-panel">
        <!-- Header is separated from the records card so it remains visible while table toggles -->
        <div class="flow-panel-header" role="button" aria-expanded="false">
            <div class="flow-panel-title">Position Ticket Flow</div>
            <div class="panel-count"><?= count($active_statuses) ?> Status</div>
            <div class="flow-panel-actions">
                <button id="saveAllBtn" type="button" class="btn btn-green" style="display:none;">Save</button>
                <button id="toggleEditBtn" type="button" class="btn btn-primary">Edit</button>
                <a class="btn btn-primary" href="flows_edit.php" style="width: 149.97px; text-align: center;" >New Position Status</a>
            </div>
        </div>

        <div class="table-wrap flow-panel-body">
            <table class="users-table flows-table" id="flowsTable">
            <thead>
                <tr>
                    <th style="width:6%">ID</th>
                    <th style="width:30%">Name</th>
                    <th style="width:8%">Color</th>
                    <th style="width:22%">Pool</th>
                    <th style="width:8%">Active</th>
                    <th style="width:8%">Sort</th>
                    <th style="width:18%">Transitions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($statuses as $s): ?>
                        <?php $trans_ids = $s['transition_ids'] ?? ''; ?>
                        <tr class="flow-row" tabindex="0" data-id="<?= (int)$s['status_id'] ?>" data-pool-id="<?= (int)$s['pool_id'] ?>" data-color="<?= htmlspecialchars($s['status_color'] ?? '') ?>" data-active="<?= (int)$s['active'] ?>" data-transitions="<?= htmlspecialchars($trans_ids) ?>">
                            <td class="mono">#<?= (int)$s['status_id'] ?></td>
                            <td class="cell-name"><?= htmlspecialchars($s['status_name']) ?></td>
                            <td class="cell-color">
                                <?php $c = $s['status_color'] ?? ''; $text = ($c && strlen($c)) ? htmlspecialchars($c) : ''; ?>
                                <span class="color-swatch" style="background: <?= $text ?: '#777' ?>; width:14px; height:14px; display:inline-block; vertical-align:middle; border-radius:4px; border:1px solid rgba(0,0,0,0.06);" title="<?= $text ?>"></span>
                                <span class="color-hex"><?= $text ?></span>
                            </td>
                            <td class="cell-pool"><?= htmlspecialchars($s['pool_name'] ?: '') ?></td>
                            <td class="cell-active">
                                <?= ((int)$s['active']) ? '<span class="active-badge">Yes</span>' : '<span class="inactive-badge">No</span>' ?>
                            </td>
                            <td class="cell-sort"><?= (int)$s['sort_order'] ?></td>
                            <td class="flows-transitions cell-transitions"><?= htmlspecialchars($s['transitions'] ?? '') ?></td>
                        </tr>
                <?php endforeach; ?>
            </tbody>
            </table>
        </div>
    </div>
    
    <div class="flow-panel">
        <!-- Applicants Flow panel (mirrors Position Flow UI, uses applicants_status tables) -->
        <div class="flow-panel-header" role="button" aria-expanded="false">
            <div class="flow-panel-title">Applicant Ticket Flow</div>
            <div class="panel-count"><?= count($app_active_statuses) ?> Status</div>
            <div class="flow-panel-actions">
                <button id="saveAllBtnApp" type="button" class="btn btn-green" style="display:none;">Save</button>
                <button id="toggleEditBtnApp" type="button" class="btn btn-primary">Edit</button>
                <a class="btn btn-primary" href="flows_edit.php?type=applicants" style="width: 149.97px; text-align: center;">New Applicant Status</a>
            </div>
        </div>

        <div class="table-wrap flow-panel-body" style="display:none;">
            <table class="users-table flows-table" id="flowsTableApp">
            <thead>
                <tr>
                    <th style="width:6%">ID</th>
                    <th style="width:30%">Name</th>
                    <th style="width:8%">Color</th>
                    <th style="width:22%">Pool</th>
                    <th style="width:8%">Active</th>
                    <th style="width:8%">Sort</th>
                    <th style="width:18%">Transitions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($app_statuses as $s): ?>
                        <?php $trans_ids = $s['transition_ids'] ?? ''; ?>
                        <tr class="flow-row-app" tabindex="0" data-id="<?= (int)$s['status_id'] ?>" data-pool-id="<?= (int)$s['pool_id'] ?>" data-color="<?= htmlspecialchars($s['status_color'] ?? '') ?>" data-active="<?= (int)$s['active'] ?>" data-transitions="<?= htmlspecialchars($trans_ids) ?>">
                            <td class="mono">#<?= (int)$s['status_id'] ?></td>
                            <td class="cell-name"><?= htmlspecialchars($s['status_name']) ?></td>
                            <td class="cell-color">
                                <?php $c = $s['status_color'] ?? ''; $text = ($c && strlen($c)) ? htmlspecialchars($c) : ''; ?>
                                <span class="color-swatch" style="background: <?= $text ?: '#777' ?>; width:14px; height:14px; display:inline-block; vertical-align:middle; border-radius:4px; border:1px solid rgba(0,0,0,0.06);" title="<?= $text ?>"></span>
                                <span class="color-hex"><?= $text ?></span>
                            </td>
                            <td class="cell-pool"><?= htmlspecialchars($s['pool_name'] ?: '') ?></td>
                            <td class="cell-active">
                                <?= ((int)$s['active']) ? '<span class="active-badge">Yes</span>' : '<span class="inactive-badge">No</span>' ?>
                            </td>
                            <td class="cell-sort"><?= (int)$s['sort_order'] ?></td>
                            <td class="flows-transitions cell-transitions"><?= htmlspecialchars($s['transitions'] ?? '') ?></td>
                        </tr>
                <?php endforeach; ?>
            </tbody>
            </table>
        </div>
    
    <!-- Interviews Flow panel (moved into the shared container so it's a sibling of the others) -->
    <div class="flow-panel">
        <div class="flow-panel-header" role="button" aria-expanded="false">
            <div class="flow-panel-title">Interview Ticket Flow</div>
            <div class="panel-count"><?= count($interview_active_statuses) ?> Status</div>
            <div class="flow-panel-actions">
                <button id="saveAllBtnInt" type="button" class="btn btn-green" style="display:none;">Save</button>
                <button id="toggleEditBtnInt" type="button" class="btn btn-primary">Edit</button>
                <a class="btn btn-primary" href="flows_edit.php?type=interviews" style="width: 149.97px; text-align: center;" >New Interview Status</a>
            </div>
        </div>

        <div class="table-wrap flow-panel-body" style="display:none;">
            <table class="users-table flows-table" id="flowsTableInt">
            <thead>
                <tr>
                    <th style="width:6%">ID</th>
                    <th style="width:30%">Name</th>
                    <th style="width:8%">Color</th>
                    <th style="width:22%">Pool</th>
                    <th style="width:8%">Active</th>
                    <th style="width:8%">Sort</th>
                    <th style="width:18%">Transitions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($interview_statuses as $s): ?>
                        <?php $trans_ids = $s['transition_ids'] ?? ''; ?>
                        <tr class="flow-row-int" tabindex="0" data-id="<?= (int)$s['id'] ?>" data-pool-id="<?= (int)$s['pool_id'] ?>" data-color="<?= htmlspecialchars($s['status_color'] ?? '') ?>" data-active="<?= (int)$s['active'] ?>" data-transitions="<?= htmlspecialchars($trans_ids) ?>">
                            <td class="mono">#<?= (int)$s['id'] ?></td>
                            <td class="cell-name"><?= htmlspecialchars($s['name']) ?></td>
                            <td class="cell-color">
                                <?php $c = $s['status_color'] ?? ''; $text = ($c && strlen($c)) ? htmlspecialchars($c) : ''; ?>
                                <span class="color-swatch" style="background: <?= $text ?: '#777' ?>; width:14px; height:14px; display:inline-block; vertical-align:middle; border-radius:4px; border:1px solid rgba(0,0,0,0.06);" title="<?= $text ?>"></span>
                                <span class="color-hex"><?= $text ?></span>
                            </td>
                            <td class="cell-pool"><?= htmlspecialchars($s['pool_name'] ?: '') ?></td>
                            <td class="cell-active">
                                <?= ((int)$s['active']) ? '<span class="active-badge">Yes</span>' : '<span class="inactive-badge">No</span>' ?>
                            </td>
                            <td class="cell-sort"><?= (int)$s['sort_order'] ?></td>
                            <td class="flows-transitions cell-transitions"><?= htmlspecialchars($s['transitions'] ?? '') ?></td>
                        </tr>
                <?php endforeach; ?>
            </tbody>
            </table>
        </div>
    </div>

    </div> <!-- .flow-panels-list -->

</main>

<?php if (file_exists(__DIR__ . '/../includes/footer.php')) include __DIR__ . '/../includes/footer.php'; ?>


<script>
document.addEventListener('DOMContentLoaded', function(){
    // Panels are handled below with a mutual-exclusive handler (one open at a time).
    var panels = document.querySelectorAll('.flow-panel');

    function getEditingPanel(){
        return document.querySelector('.flow-panel.editing');
    }

    // Global key handler: Escape closes modal or exits edit mode if active
    document.addEventListener('keydown', function(ev){
        if (ev.key === 'Escape') {
            var editingPanel = getEditingPanel();
            if (editingPanel) {
                editingPanel.classList.remove('editing');
                exitEditMode();
                var teb = document.getElementById('toggleEditBtn'); if (teb) teb.textContent = 'Edit';
                var tebA = document.getElementById('toggleEditBtnApp'); if (tebA) tebA.textContent = 'Edit';
                var sab = document.getElementById('saveAllBtn'); if (sab) sab.style.display = 'none';
                var sabA = document.getElementById('saveAllBtnApp'); if (sabA) sabA.style.display = 'none';
                return;
            }
            closeNewStatusModal();
        }
    });
    // modal wiring
    var newStatusModal = document.getElementById('newStatusModal');
    var newStatusClose = newStatusModal && newStatusModal.querySelector('.modal-close');
    var newStatusCancel = document.getElementById('ns_cancel');
    var newStatusForm = document.getElementById('newStatusForm');
    function openNewStatusModal(){
        if (!newStatusModal) return;
        newStatusModal.style.display = 'flex';
        var first = document.getElementById('ns_name'); if (first) first.focus();
    }
    try { window.openNewStatusModal = openNewStatusModal; } catch(e) {}
    function closeNewStatusModal(){
        if (!newStatusModal) return;
        newStatusModal.style.display = 'none';
        try { if (newStatusForm) newStatusForm.reset(); } catch(e){}
    }
    if (newStatusClose) newStatusClose.addEventListener('click', closeNewStatusModal);
    if (newStatusCancel) newStatusCancel.addEventListener('click', closeNewStatusModal);
    if (newStatusModal) newStatusModal.addEventListener('click', function(e){ if (e.target === newStatusModal) closeNewStatusModal(); });
    
    // show live color hex for positions modal
    var nsColor = document.getElementById('ns_color');
    var nsColorHex = document.getElementById('ns_color_hex');
    if (nsColor && nsColorHex) {
        nsColorHex.textContent = nsColor.value || '';
        nsColor.addEventListener('input', function(){ nsColorHex.textContent = nsColor.value || ''; });
    }
    // Global key handler: Escape closes modal or exits edit mode if active
    document.addEventListener('keydown', function(ev){
        if (ev.key === 'Escape') {
            if (panel && panel.classList.contains('editing')) {
                // exit edit mode
                panel.classList.remove('editing');
                exitEditMode();
                var teb = document.getElementById('toggleEditBtn'); if (teb) teb.textContent = 'Edit';
                var sab = document.getElementById('saveAllBtn'); if (sab) sab.style.display = 'none';
                var cab = document.getElementById('cancelEditsBtn'); if (cab) cab.style.display = 'none';
                return;
            }
            closeNewStatusModal();
        }
    });

    // Wire each panel's header New button to its matching modal by panel title
    function findPanelByTitle(title){
        var list = Array.from(document.querySelectorAll('.flow-panel'));
        return list.find(function(p){ var t = (p.querySelector('.flow-panel-title')||{}).textContent || ''; return t.trim() === title; }) || null;
    }
    var posPanel = findPanelByTitle('Position Ticket Flow');
    var appPanel = findPanelByTitle('Applicant Ticket Flow');
    var intPanel = findPanelByTitle('Interview Ticket Flow');
    if (posPanel) {
        var headerNewPos = posPanel.querySelector('.flow-panel-actions a');
        if (headerNewPos) headerNewPos.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); openNewStatusModal(); });
    }
    if (appPanel) {
        var headerNewApp = appPanel.querySelector('.flow-panel-actions a');
        if (headerNewApp) headerNewApp.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); openNewStatusModalApp(); });
    }
    if (intPanel) {
        var headerNewInt = intPanel.querySelector('.flow-panel-actions a');
        if (headerNewInt) headerNewInt.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); openNewStatusModalInt(); });
    }

    // Edit mode toggle and inline editing
    var toggleEditBtn = document.getElementById('toggleEditBtn');
    var saveAllBtn = document.getElementById('saveAllBtn');
    // cancel button removed per request; only Edit/Exit and Save remain
    if (toggleEditBtn) {
        toggleEditBtn.addEventListener('click', function(e){
            e.stopPropagation();
            // find the panel this button belongs to
            var panelEl = toggleEditBtn.closest('.flow-panel');
            if (!panelEl) return;
            var editing = panelEl.classList.toggle('editing');
            toggleEditBtn.textContent = editing ? 'Exit Edit' : 'Edit';
            if (editing) {
                enterEditMode();
                if (saveAllBtn) saveAllBtn.style.display = '';
            } else {
                exitEditMode();
                if (saveAllBtn) saveAllBtn.style.display = 'none';
            }
        });
    }

    // helper to build pool and transitions options
    var pools = <?= json_encode($pools) ?> || [];
    var allStatuses = <?= json_encode(array_map(function($r){ return ['id'=>(int)$r['status_id'],'name'=>$r['status_name'],'sort'=>(int)$r['sort_order']]; }, $active_statuses)); ?> || [];

    // Diagram data generated from server-side status arrays
    window.flowsDiagramData = <?= json_encode([
        'positions' => array_map(function($r){ return ['id'=> (int)$r['status_id'],'name'=>$r['status_name'],'color'=>$r['status_color'],'sort'=>(int)$r['sort_order'],'transitions'=> ($r['transition_ids']??'')]; }, $statuses),
        'applicants' => array_map(function($r){ return ['id'=> (int)$r['status_id'],'name'=>$r['status_name'],'color'=>$r['status_color'],'sort'=>(int)$r['sort_order'],'transitions'=> ($r['transition_ids']??'')]; }, $app_statuses),
        'interviews' => array_map(function($r){ return ['id'=> (int)$r['id'],'name'=>$r['name'],'color'=>$r['status_color'],'sort'=>(int)$r['sort_order'],'transitions'=> ($r['transition_ids']??'')]; }, $interview_statuses)
    ]) ?> || {};
    // create a global alias so non-module scripts can reference `flowsDiagramData` directly
    try { var flowsDiagramData = window.flowsDiagramData; } catch(e) { /* ignore */ }

    // small helper to escape values inside generated inputs
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&"'<>]/g, function (s) {
            return ({'&':'&amp;','"':'&quot;',"'":"&#39;","<":"&lt;",">":"&gt;"})[s];
        });
    }

    // Generic helpers to reduce duplication between Positions and Applicants flows
    function enterEditModeGeneric(rowSelector, poolsArr, allStatusesArr){
        document.querySelectorAll(rowSelector).forEach(function(row){
            if (!row.dataset.orig) row.dataset.orig = row.innerHTML;
            var id = row.dataset.id;
            var poolId = row.dataset.poolId || '';
            var color = row.dataset.color || '';
            var active = parseInt(row.dataset.active || '0',10) ? true : false;
            var trans = (row.dataset.transitions || '').split(',').filter(Boolean).map(function(x){ return parseInt(x,10); });

            // name
            var nameCell = row.querySelector('.cell-name');
            var nameVal = nameCell ? nameCell.textContent.trim() : '';
            if (nameVal !== undefined) row.dataset.origName = nameVal;
            if (nameCell) nameCell.innerHTML = '<input type="text" value="'+escapeHtml(nameVal)+'" />';

            // color
            var colorCell = row.querySelector('.cell-color');
            if (colorCell) colorCell.innerHTML = '<input type="color" value="'+(color||'#359cf6')+'" /> <span class="color-hex">'+(color||'')+'</span>';

            // pool
            var poolCell = row.querySelector('.cell-pool');
            if (poolCell) {
                var sel = document.createElement('select');
                sel.innerHTML = '<option value="">-- none --</option>';
                poolsArr.forEach(function(p){
                    var opt = document.createElement('option'); opt.value = p.id; opt.text = p.pool_name; if (String(p.id)===String(poolId)) opt.selected = true; sel.appendChild(opt);
                });
                poolCell.innerHTML = ''; poolCell.appendChild(sel);
            }

            // active
            var activeCell = row.querySelector('.cell-active');
            if (activeCell) {
                activeCell.innerHTML = '<label><input type="checkbox" '+(active? 'checked':'')+'> Active</label>';
            }

            // sort
            var sortCell = row.querySelector('.cell-sort');
            var sortVal = sortCell ? sortCell.textContent.trim() : '';
            if (sortVal !== undefined) row.dataset.origSort = sortVal;
            if (sortCell) sortCell.innerHTML = '<input type="number" min="1" value="'+(parseInt(sortVal||'0',10) || 1)+'" />';

            // transitions
            var transCell = row.querySelector('.cell-transitions');
            if (transCell) {
                var ms = document.createElement('select'); ms.multiple = true; ms.size = Math.min(8, allStatusesArr.length);
                allStatusesArr.forEach(function(s){
                    var o = document.createElement('option'); o.value = s.id; o.text = s.name + ' (sort: ' + s.sort + ')';
                    if (trans.indexOf(s.id)!==-1) o.selected = true; ms.appendChild(o);
                });
                transCell.innerHTML = ''; transCell.appendChild(ms);
            }
        });
    }

    function exitEditModeGeneric(rowSelector){
        document.querySelectorAll(rowSelector).forEach(function(row){
            if (row.dataset.orig) { row.innerHTML = row.dataset.orig; delete row.dataset.orig; }
            if (row.dataset.origName) delete row.dataset.origName;
            if (row.dataset.origSort) delete row.dataset.origSort;
            if (row.dataset.changed) delete row.dataset.changed;
        });
    }

    // Update the counter badge in a panel to reflect active rows (no reload)
    function updatePanelCount(panel){
        if (!panel) return;
        var cnt = 0;
        var rows = panel.querySelectorAll('tbody tr');
        rows.forEach(function(r){ if (parseInt(r.dataset.active||'0',10)===1) cnt++; });
        var badge = panel.querySelector('.panel-count');
        if (badge) badge.textContent = cnt + ' Status';
    }

    // Expose helpers globally so other DOMContentLoaded handlers can reuse them
    try {
        window.enterEditModeGeneric = enterEditModeGeneric;
        window.exitEditModeGeneric = exitEditModeGeneric;
        window.updatePanelCount = updatePanelCount;
        window.gatherRowData = gatherRowData;
        window.rowHasChanges = rowHasChanges;
    } catch(e) {
        // ignore if window not available for any reason
    }

    function enterEditMode(){
        enterEditModeGeneric('.flow-row', pools, allStatuses);
    }

    function exitEditMode(){
        exitEditModeGeneric('.flow-row');
    }

    // Bulk Save/Cancel handlers
    if (saveAllBtn) saveAllBtn.addEventListener('click', function(e){ e.stopPropagation(); handleSaveAll(); });

    function gatherRowData(row){
        var id = row.dataset.id;
        var name = (row.querySelector('.cell-name input') || {}).value || '';
        var color = (row.querySelector('.cell-color input[type="color"]') || {}).value || '';
        var poolSel = row.querySelector('.cell-pool select'); var pool_id = poolSel ? poolSel.value : null;
        var active = row.querySelector('.cell-active input[type="checkbox"]') ? (row.querySelector('.cell-active input[type="checkbox"]').checked ? 1 : 0) : 0;
        var sortVal = parseInt((row.querySelector('.cell-sort input') || {}).value || '0',10) || 0;
        var trans = [];
        var ms = row.querySelector('.cell-transitions select');
        if (ms) {
            // Prefer selectedOptions (clean and reliable), fall back to options scanning
            if (ms.selectedOptions && ms.selectedOptions.length) {
                for (var i=0;i<ms.selectedOptions.length;i++) {
                    var v = parseInt(ms.selectedOptions[i].value,10);
                    if (!isNaN(v)) trans.push(v);
                }
            } else if (ms.options && ms.options.length) {
                for (var j=0;j<ms.options.length;j++){
                    if (ms.options[j].selected) {
                        var vv = parseInt(ms.options[j].value,10);
                        if (!isNaN(vv)) trans.push(vv);
                    }
                }
            }
        }
        // If still empty, fall back to the original data-transitions attribute
        if (!trans.length) {
            var raw = row.dataset.transitions || '';
            if (raw) {
                raw.split(',').forEach(function(x){
                    var n = parseInt(x,10);
                    if (!isNaN(n)) trans.push(n);
                });
            }
        }
        return { status_id: parseInt(id,10), status_name: name, status_color: color, pool_id: pool_id, sort_order: sortVal, active: active, transitions: trans };
    }

    // Return true if any editable field in the row differs from the original dataset (i.e., user changed it)
    function rowHasChanges(row){
        try {
            // If an explicit change flag was set (e.g. by the active toggle), honor it
            if (row.dataset.changed === '1') return true;
            // Prefer stored originals saved during enterEditMode
            var origName = (row.dataset.origName !== undefined) ? row.dataset.origName : (row.querySelector('.cell-name') ? row.querySelector('.cell-name').textContent.trim() : '');
            var nameInput = row.querySelector('.cell-name input');
            if (nameInput && nameInput.value.trim() !== (origName || '').trim()) return true;

            var origColor = row.dataset.color || '';
            var colorInput = row.querySelector('.cell-color input[type="color"]');
            if (colorInput && (colorInput.value || '').toLowerCase() !== (origColor || '').toLowerCase()) return true;

            var origPool = (row.dataset.poolId !== undefined) ? String(row.dataset.poolId) : '';
            var poolSel = row.querySelector('.cell-pool select'); if (poolSel && String(poolSel.value) !== origPool) return true;

            var origActive = String(row.dataset.active || '0');
            var activeChk = row.querySelector('.cell-active input[type="checkbox"]'); if (activeChk && (activeChk.checked ? '1':'0') !== origActive) return true;

            var origSort = (row.dataset.origSort !== undefined) ? row.dataset.origSort : ((row.querySelector('.cell-sort') ? row.querySelector('.cell-sort').textContent.trim() : '') || '');
            var sortInput = row.querySelector('.cell-sort input[type="number"]'); if (sortInput && String(parseInt(sortInput.value||'0',10) || '') !== String(parseInt(origSort||'0',10) || '')) return true;

            // transitions
            var ms = row.querySelector('.cell-transitions select');
            var selIds = [];
            if (ms) {
                if (ms.selectedOptions && ms.selectedOptions.length) {
                    for (var i=0;i<ms.selectedOptions.length;i++){ var v = parseInt(ms.selectedOptions[i].value,10); if (!isNaN(v)) selIds.push(v); }
                } else if (ms.options && ms.options.length) {
                    for (var j=0;j<ms.options.length;j++){ if (ms.options[j].selected){ var vv = parseInt(ms.options[j].value,10); if (!isNaN(vv)) selIds.push(vv); } }
                }
            }
            var origRaw = row.dataset.transitions || '';
            var origIds = origRaw ? origRaw.split(',').filter(Boolean).map(function(x){ return parseInt(x,10); }).filter(function(x){ return !isNaN(x); }) : [];
            if (selIds.length !== origIds.length) return true;
            selIds.sort(); origIds.sort();
            for (var k=0;k<selIds.length;k++){ if (selIds[k] !== origIds[k]) return true; }

            return false;
        } catch (e) {
            console.error('rowHasChanges error', e);
            return true;
        }
    }

    function handleSaveAll(){
        var rows = [];
        document.querySelectorAll('.flow-row').forEach(function(row){
            // only gather if row is in edit state (has inputs)
            if (row.querySelector('.cell-name input')) {
                // only include rows that the user actually changed
                if (rowHasChanges(row)) rows.push(gatherRowData(row));
            }
        });
        if (!rows.length) { if (window.Notify && Notify.push) Notify.push({ from:'Flows', message:'No changes to save', color:'#f59e0b'}); return; }

        // Debug: log rows that have empty transitions to help identify issues
        rows.forEach(function(r){ if (!r.transitions || r.transitions.length===0) console.debug('Flows: empty transitions for row', r.status_id, 'dataset.transitions raw=', document.querySelector('.flow-row[data-id="'+r.status_id+'"]').dataset.transitions); });

        // client-side validation for all rows
        for (var i=0;i<rows.length;i++){
            var r = rows[i];
            if (!r.status_name) { if (window.Notify && Notify.push) Notify.push({ from:'Flows', message:'Name required on one or more rows', color:'#dc2626'}); return; }
            if (!r.pool_id) { if (window.Notify && Notify.push) Notify.push({ from:'Flows', message:'Pool required on one or more rows', color:'#dc2626'}); return; }
            // transitions may be empty (final/end statuses); no validation required
            if (!Number.isInteger(r.sort_order) || r.sort_order <= 0) { if (window.Notify && Notify.push) Notify.push({ from:'Flows', message:'Sort must be > 0 for all edited rows', color:'#dc2626'}); return; }
        }

        var payload = { rows: rows };
        fetch('flows_update.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) })
        .then(function(res){ return res.json().catch(function(){ return { success:false, error:'Invalid response' }; }); })
        .then(function(json){
            if (json && json.success) {
                if (window.Notify && Notify.push) Notify.push({ from:'Flows', message:'Saved changes', color:'#10b981' });
                setTimeout(function(){ window.location.reload(); }, 500);
            } else {
                if (window.Notify && Notify.push) Notify.push({ from:'Flows', message:(json && json.error)?json.error:'Save failed', color:'#dc2626'});
            }
        }).catch(function(err){ console.error(err); if (window.Notify && Notify.push) Notify.push({ from:'Flows', message:'Request failed', color:'#dc2626' }); });
    }

    // Toggle active state when clicking the active cell (delegated)
    // Only allow toggling while the panel is in edit mode. Do not show notifications on toggle;
    // notifications happen when the user explicitly saves.
    document.querySelector('#flowsTable').addEventListener('click', function(e){
        var cell = e.target.closest('.cell-active');
        if (!cell) return;
        var row = cell.closest('.flow-row');
        if (!row) return;
        var panel = row.closest('.flow-panel');
        if (!panel || !panel.classList.contains('editing')) return; // disallow toggling unless editing

        var statusId = parseInt(row.dataset.id,10);
        var cur = parseInt(row.dataset.active || '0',10) ? 1 : 0;
        var newActive = cur ? 0 : 1;

        // optimistic UI: toggle badge and dataset while request in-flight
        var badge = cell.querySelector('.active-badge, .inactive-badge');
        if (badge) {
            badge.textContent = newActive ? 'Yes' : 'No';
            badge.className = newActive ? 'active-badge' : 'inactive-badge';
        }
        row.dataset.active = newActive;
        // mark row as changed so Save will include this toggle
        row.dataset.changed = '1';
        // update the panel count immediately
        updatePanelCount(panel);

        fetch('flows_toggle_active.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ status_id: statusId, active: newActive }) })
        .then(function(res){ return res.json().catch(function(){ return { success:false, error:'Invalid response' }; }); })
        .then(function(json){
            if (!(json && json.success)) {
                // revert UI on failure (no notify)
                var revert = cur ? 1 : 0;
                row.dataset.active = revert;
                if (badge) { badge.textContent = revert ? 'Yes' : 'No'; badge.className = revert ? 'active-badge':'inactive-badge'; }
                // clear changed flag when revert happens
                if (row.dataset.changed) delete row.dataset.changed;
                // update count after revert
                updatePanelCount(panel);
                console.error('Toggle failed', json && json.error ? json.error : json);
            }
        }).catch(function(err){
            console.error(err);
            // revert UI on error (no notify)
            var revert = cur ? 1 : 0;
            row.dataset.active = revert;
            if (badge) { badge.textContent = revert ? 'Yes' : 'No'; badge.className = revert ? 'active-badge':'inactive-badge'; }
            if (row.dataset.changed) delete row.dataset.changed;
            updatePanelCount(panel);
        });
    });

    // Data from server: existing active sort orders and status details
    var existingSorts = <?= json_encode(array_values(array_map(function($r){ return (int)$r['sort_order']; }, $active_statuses))); ?> || [];
    var existingStatuses = <?= json_encode(array_map(function($r){ return ['id'=>(int)$r['status_id'],'name'=>$r['status_name'],'sort'=>(int)$r['sort_order']]; }, $active_statuses)); ?> || [];

    // Applicants: mirror of existing sorts/statuses for the Applicants New Status modal
    var existingSortsApp = <?= json_encode(array_values(array_map(function($r){ return (int)$r['sort_order']; }, $app_active_statuses))); ?> || [];
    var existingStatusesApp = <?= json_encode(array_map(function($r){ return ['id'=>(int)$r['status_id'],'name'=>$r['status_name'],'sort'=>(int)$r['sort_order']]; }, $app_active_statuses)); ?> || [];

    // wire sort-order validation: cannot pick a sort used by an active status
    var sortInput = document.getElementById('ns_sort');
    var sortWarn = document.getElementById('sortWarn');
    var comesAfterEl = document.getElementById('comesAfterName');
    var createBtn = document.querySelector('#newStatusForm button[type="submit"]');
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
    if (sortInput) {
        sortInput.addEventListener('input', function(){ validateSort(); updateComesAfter(); });
        // run initial check
        validateSort();
        updateComesAfter();
    }

    // Applicants modal: validate sort order and update "comes after" display
    var sortInputApp = document.getElementById('ns_sort_app');
    var sortWarnApp = document.getElementById('sortWarnApp');
    var comesAfterElApp = document.getElementById('comesAfterNameApp');
    var createBtnApp = document.querySelector('#newStatusFormApp button[type="submit"]');
    function validateSortApp(){
        if (!sortInputApp) return true;
        var raw = sortInputApp.value;
        var val = parseInt(raw === '' ? '0' : raw, 10);
        if (isNaN(val) || val <= 0) {
            if (sortWarnApp) { sortWarnApp.textContent = 'Sort must be a positive integer greater than 0.'; sortWarnApp.style.display = 'inline-block'; }
            if (createBtnApp) createBtnApp.disabled = true;
            return false;
        }
        var conflict = existingSortsApp.indexOf(val) !== -1;
        if (conflict) {
            if (sortWarnApp) { sortWarnApp.textContent = 'This sort number is already used by an active status.'; sortWarnApp.style.display = 'inline-block'; }
            if (createBtnApp) createBtnApp.disabled = true;
            return false;
        } else {
            if (sortWarnApp) sortWarnApp.style.display = 'none';
            if (createBtnApp) createBtnApp.disabled = false;
            return true;
        }
    }
    function updateComesAfterApp(){
        if (!sortInputApp || !comesAfterElApp) return;
        var val = parseInt(sortInputApp.value || '0', 10) || 0;
        var prev = null;
        for (var i=0;i<existingStatusesApp.length;i++){
            var s = existingStatusesApp[i];
            if (s.sort < val) {
                if (!prev || s.sort > prev.sort) prev = s;
            }
        }
        if (prev) {
            comesAfterElApp.textContent = prev.name + ' (sort: ' + prev.sort + ')';
        } else {
            comesAfterElApp.textContent = 'Top (no previous status)';
        }
    }
    if (sortInputApp) {
        sortInputApp.addEventListener('input', function(){ validateSortApp(); updateComesAfterApp(); });
        validateSortApp();
        updateComesAfterApp();
    }

    function updateComesAfter(){
        if (!sortInput || !comesAfterEl) return;
        var val = parseInt(sortInput.value || '0', 10) || 0;
        // find the status with max sort < val
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

    // submit form -> POST to flows_create.php (best-effort; backend handler may be absent)
    if (newStatusForm) {
        newStatusForm.addEventListener('submit', async function(ev){
            ev.preventDefault();
            var poolEl = document.getElementById('ns_pool');
            var pool_id = poolEl ? poolEl.value : '';
            var pool_name = '';
            if (poolEl && poolEl.selectedIndex >= 0) {
                pool_name = poolEl.options[poolEl.selectedIndex].text || '';
            }
            // collect transitions (multiple select)
            var transitions = [];
            var trEl = document.getElementById('ns_transitions');
            if (trEl) {
                for (var i=0;i<trEl.options.length;i++){
                    var o = trEl.options[i];
                    if (o && o.selected) transitions.push(parseInt(o.value,10) || o.value);
                }
            }

            // client-side required checks: pool, transitions, sort
            if (!pool_id) {
                if (window.Notify && Notify.push) Notify.push({ from: 'Flows', message: 'Please select a Pool', color: '#dc2626' });
                return;
            }
            // transitions may be empty (terminal statuses); do not require at least one transition
            if (!validateSort()) {
                if (window.Notify && Notify.push) Notify.push({ from: 'Flows', message: 'Please choose a different Sort Order', color: '#dc2626' });
                return;
            }

            var payload = {
                status_name: (document.getElementById('ns_name') || {}).value || '',
                status_color: (document.getElementById('ns_color') || {}).value || '',
                pool_id: pool_id || null,
                pool_name: pool_name || '',
                transitions: transitions,
                sort_order: parseInt((document.getElementById('ns_sort') || {}).value || '0',10) || 0,
                active: document.getElementById('ns_active') && document.getElementById('ns_active').checked ? 1 : 0
            };
            try {
                const res = await fetch('flows_create.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
                const clone = res.clone();
                let json = null;
                if (res.ok) {
                    try { json = await res.json(); } catch(e) { const txt = await clone.text(); if (window.Notify && Notify.push) Notify.push({ from: 'Flows', message: 'Server error: ' + txt.slice(0,200), color: '#dc2626' }); closeNewStatusModal(); return; }
                } else { const txt = await clone.text(); if (window.Notify && Notify.push) Notify.push({ from: 'Flows', message: 'Request failed: ' + txt.slice(0,200), color: '#dc2626' }); closeNewStatusModal(); return; }
                if (json && json.success) {
                    if (window.Notify && Notify.push) Notify.push({ from: 'Flows', message: 'Status created', color: '#10b981' });
                    setTimeout(function(){ window.location.reload(); }, 700);
                } else {
                    const err = (json && json.error) ? json.error : 'Create failed';
                    if (window.Notify && Notify.push) Notify.push({ from: 'Flows', message: err, color: '#dc2626' });
                }
            } catch(err) {
                console.error(err);
                if (window.Notify && Notify.push) Notify.push({ from: 'Flows', message: 'Request failed', color: '#dc2626' });
            }
        });
    }
});
</script>

<!-- Diagram rendering script -->
<script>
document.addEventListener('DOMContentLoaded', function(){
    function parseTransitions(raw){
        if (!raw) return [];
        if (Array.isArray(raw)) return raw.map(function(x){ return parseInt(x,10); }).filter(Boolean);
        // comma-separated ids
        return String(raw).split(',').filter(Boolean).map(function(x){ return parseInt(x,10); }).filter(function(n){ return !isNaN(n); });
    }

    var typeButtons = document.querySelectorAll('.diagram-type-btn');
    var diagramNodesEl = document.getElementById('diagramNodes');
    var svg = document.getElementById('diagramSvg');

    function clearDiagram(){
        if (diagramNodesEl) diagramNodesEl.innerHTML = '';
        if (svg) { while(svg.firstChild) svg.removeChild(svg.firstChild); }
    }

    function drawArrow(fromEl, toEl){
        if (!svg || !fromEl || !toEl) return;
        var rc = svg.getBoundingClientRect();
        var a = fromEl.getBoundingClientRect();
        var b = toEl.getBoundingClientRect();
        // compute start (right middle of a) and end (left middle of b) relative to svg
        var startX = a.right - rc.left;
        var startY = a.top + a.height/2 - rc.top;
        var endX = b.left - rc.left;
        var endY = b.top + b.height/2 - rc.top;
        // create a simple cubic curve path
        var dx = Math.max(20, (endX - startX) / 2);
        var path = document.createElementNS('http://www.w3.org/2000/svg','path');
        path.setAttribute('d', 'M '+startX+' '+startY+' C '+(startX+dx)+' '+startY+' '+(endX-dx)+' '+endY+' '+endX+' '+endY);
        path.setAttribute('stroke', 'rgba(53,156,246,0.8)');
        path.setAttribute('fill', 'none');
        path.setAttribute('stroke-width', '2');
        svg.appendChild(path);
        // arrowhead
        var markerId = 'arrowhead';
        if (!svg.querySelector('#'+markerId)){
            var defs = document.createElementNS('http://www.w3.org/2000/svg','defs');
            var marker = document.createElementNS('http://www.w3.org/2000/svg','marker');
            marker.setAttribute('id', markerId);
            marker.setAttribute('markerWidth','8'); marker.setAttribute('markerHeight','8'); marker.setAttribute('refX','6'); marker.setAttribute('refY','3'); marker.setAttribute('orient','auto');
            var poly = document.createElementNS('http://www.w3.org/2000/svg','polygon');
            poly.setAttribute('points','0 0, 6 3, 0 6'); poly.setAttribute('fill','rgba(53,156,246,0.8)');
            marker.appendChild(poly); defs.appendChild(marker); svg.appendChild(defs);
        }
        path.setAttribute('marker-end','url(#arrowhead)');
    }

    function renderDiagram(type){
        clearDiagram();
        var data = flowsDiagramData && flowsDiagramData[type] ? flowsDiagramData[type] : [];
        if (!data || !data.length) return;
        // sort by sort order then id
        data.sort(function(a,b){ var sa = (a.sort||0), sb=(b.sort||0); if (sa!==sb) return sa - sb; return (a.id||0)-(b.id||0); });
        // create nodes
        var nodesMap = {};
        data.forEach(function(n){
            var el = document.createElement('div'); el.className = 'diagram-node'; el.dataset.id = n.id; 
            var name = document.createElement('span'); name.className='name'; name.textContent = n.name || ('#'+n.id);
            var meta = document.createElement('span'); meta.className='meta'; meta.textContent = n.color || '';
            el.appendChild(name); el.appendChild(meta);
            diagramNodesEl.appendChild(el);
            nodesMap[n.id] = el;
        });
        // allow layout to settle then draw arrows
        setTimeout(function(){
            data.forEach(function(n){
                var trans = parseTransitions(n.transitions);
                trans.forEach(function(tid){
                    var fromEl = nodesMap[n.id]; var toEl = nodesMap[tid];
                    if (fromEl && toEl) drawArrow(fromEl, toEl);
                });
            });
        }, 80);
    }

    // wire up buttons
    typeButtons.forEach(function(b){ b.addEventListener('click', function(ev){ typeButtons.forEach(function(x){ x.classList.remove('active'); }); b.classList.add('active'); var t = b.dataset.type || 'positions'; renderDiagram(t); }); });

    // default show positions
    var first = document.querySelector('.diagram-type-btn[data-type="positions"]'); if (first) { first.classList.add('active'); renderDiagram('positions'); }
    // redraw on window resize to update arrow positions
    window.addEventListener('resize', function(){ var active = document.querySelector('.diagram-type-btn.active'); if (active) renderDiagram(active.dataset.type); });
});
</script>

<!-- Table header sorting: click a header to toggle ascending/descending -->
<script>
document.addEventListener('DOMContentLoaded', function(){
    function inferTypeFromHeaderText(text, idx){
        var t = (text||'').toLowerCase();
        if (t.indexOf('id') !== -1 && idx===0) return 'num';
        if (t.indexOf('sort') !== -1) return 'num';
        if (t.indexOf('active') !== -1) return 'active';
        return 'text';
    }

    function makeSortable(table){
        var thead = table.querySelector('thead');
        if (!thead) return;
        var ths = Array.from(thead.querySelectorAll('th'));
        ths.forEach(function(th, idx){
            th.style.cursor = 'pointer';
            th.addEventListener('click', function(e){
                // determine sort direction (toggle)
                var isAsc = th.classList.contains('sorted-asc');
                ths.forEach(function(h){ h.classList.remove('sorted-asc','sorted-desc'); });
                th.classList.add(isAsc ? 'sorted-desc' : 'sorted-asc');
                var dir = isAsc ? -1 : 1;

                var type = inferTypeFromHeaderText(th.textContent, idx);
                var tbody = table.tBodies[0];
                var rows = Array.from(tbody.querySelectorAll('tr'));

                rows.sort(function(a,b){
                    var aText = (a.children[idx] && a.children[idx].textContent) ? a.children[idx].textContent.trim() : '';
                    var bText = (b.children[idx] && b.children[idx].textContent) ? b.children[idx].textContent.trim() : '';
                    if (type === 'num') {
                        var na = parseFloat(aText.replace(/[^0-9\-\.]/g,'')) || 0;
                        var nb = parseFloat(bText.replace(/[^0-9\-\.]/g,'')) || 0;
                        return dir * (na - nb);
                    } else if (type === 'active') {
                        var map = function(v){ v = (v||'').toLowerCase(); return (v.indexOf('y')===0 || v.indexOf('1')===0) ? 1 : 0; };
                        return dir * (map(aText) - map(bText));
                    } else {
                        return dir * aText.localeCompare(bText, undefined, {numeric:true, sensitivity:'base'});
                    }
                });

                // append back in new order
                rows.forEach(function(r){ tbody.appendChild(r); });
            });
        });
    }

    document.querySelectorAll('.flows-table').forEach(function(tbl){ makeSortable(tbl); });
});
</script>

<!-- New Status modal (reuses Users modal classes for same theme) -->
<div id="newStatusModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <div class="modal-header">
            <h3>Create Position Status</h3>
            <button type="button" class="modal-close" aria-label="Close dialog">&times;</button>
        </div>
        <div id="newStatusMsg" class="small-muted"></div>
        <form id="newStatusForm">
            <label for="ns_name">Name</label>
            <input id="ns_name" name="status_name" type="text" required>

            <label for="ns_color">Color</label>
            <input id="ns_color" name="status_color" type="color" value="#359cf6">
            <span id="ns_color_hex" class="color-hex">#359cf6</span>

            <label for="ns_pool">Pool</label>
            <select id="ns_pool" name="pool_id" required>
                <option value="">-- none --</option>
                <?php foreach ($pools as $pool): ?>
                    <option value="<?= (int)$pool['id'] ?>"><?= htmlspecialchars($pool['pool_name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="ns_transitions">Applicable Transitions</label>
            <select id="ns_transitions" name="transitions[]" multiple size="6" required>
                <?php foreach ($active_statuses as $ast): ?>
                    <option value="<?= (int)$ast['status_id'] ?>"><?= htmlspecialchars($ast['status_name']) ?> (sort: <?= (int)$ast['sort_order'] ?>)</option>
                <?php endforeach; ?>
            </select>

            <div class="sort-row">
                <div class="sort-col">
                    <label for="ns_sort">Sort Order</label>
                    <input id="ns_sort" name="sort_order" type="number" value="1" min="1">
                </div>
                <div class="after-col">
                    <label>Comes After</label>
                    <div id="comesAfterName" class="small-muted">Top (no previous status)</div>
                </div>
            </div>

            <div style="display:flex;align-items:center;gap:8px;margin-top:8px;">
                <label style="margin:0;"> <input id="ns_active" name="active" type="checkbox" checked> Active</label>
                <div id="sortWarn" class="small-muted" style="margin-left:12px; color:#b45309; display:none;">This sort number is already used by an active status.</div>
            </div>

            <div class="modal-actions">
                <button type="button" id="ns_cancel" class="btn">Cancel</button>
                <button type="submit" class="btn btn-orange">Create</button>
            </div>
        </form>
    </div>
</div>
 
<!-- Applicants New Status modal (mirrors positions modal) -->
<div id="newStatusModalApp" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <div class="modal-header">
            <h3>Create Applicant Status</h3>
            <button type="button" class="modal-close" aria-label="Close dialog">&times;</button>
        </div>
        <div id="newStatusMsgApp" class="small-muted"></div>
        <form id="newStatusFormApp">
            <label for="ns_name_app">Name</label>
            <input id="ns_name_app" name="status_name" type="text" required>

            <label for="ns_color_app">Color</label>
            <input id="ns_color_app" name="status_color" type="color" value="#359cf6">
            <span id="ns_color_app_hex" class="color-hex">#359cf6</span>

            <label for="ns_pool_app">Pool</label>
            <select id="ns_pool_app" name="pool_id" required>
                <option value="">-- none --</option>
                <?php foreach ($pools as $pool): ?>
                    <option value="<?= (int)$pool['id'] ?>"><?= htmlspecialchars($pool['pool_name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="ns_transitions_app">Applicable Transitions</label>
            <select id="ns_transitions_app" name="transitions[]" multiple size="6" required>
                <?php foreach ($app_active_statuses as $ast): ?>
                    <option value="<?= (int)$ast['status_id'] ?>"><?= htmlspecialchars($ast['status_name']) ?> (sort: <?= (int)$ast['sort_order'] ?>)</option>
                <?php endforeach; ?>
            </select>

            <div class="sort-row">
                <div class="sort-col">
                    <label for="ns_sort_app">Sort Order</label>
                    <input id="ns_sort_app" name="sort_order" type="number" value="1" min="1">
                </div>
                <div class="after-col">
                    <label>Comes After</label>
                    <div id="comesAfterNameApp" class="small-muted">Top (no previous status)</div>
                </div>
            </div>

            <div style="display:flex;align-items:center;gap:8px;margin-top:8px;">
                <label style="margin:0;"> <input id="ns_active_app" name="active" type="checkbox" checked> Active</label>
                <div id="sortWarnApp" class="small-muted" style="margin-left:12px; color:#b45309; display:none;">This sort number is already used by an active status.</div>
            </div>

            <div class="modal-actions">
                <button type="button" id="ns_cancel_app" class="btn">Cancel</button>
                <button type="submit" class="btn btn-orange">Create</button>
            </div>
        </form>
    </div>
</div>

<!-- Interviews New Status modal (mirrors other modals) -->
<div id="newStatusModalInt" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <div class="modal-header">
            <h3>Create Interview Status</h3>
            <button type="button" class="modal-close" aria-label="Close dialog">&times;</button>
        </div>
        <div id="newStatusMsgInt" class="small-muted"></div>
        <form id="newStatusFormInt">
            <label for="ns_name_int">Name</label>
            <input id="ns_name_int" name="status_name" type="text" required>

            <label for="ns_color_int">Color</label>
            <input id="ns_color_int" name="status_color" type="color" value="#359cf6">
            <span id="ns_color_int_hex" class="color-hex">#359cf6</span>

            <label for="ns_pool_int">Pool</label>
            <select id="ns_pool_int" name="pool_id" required>
                <option value="">-- none --</option>
                <?php foreach ($pools as $pool): ?>
                    <option value="<?= (int)$pool['id'] ?>"><?= htmlspecialchars($pool['pool_name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="ns_transitions_int">Applicable Transitions</label>
            <select id="ns_transitions_int" name="transitions[]" multiple size="6" required>
                <?php foreach ($interview_active_statuses as $ast): ?>
                    <option value="<?= (int)$ast['id'] ?>"><?= htmlspecialchars($ast['name']) ?> (sort: <?= (int)$ast['sort_order'] ?>)</option>
                <?php endforeach; ?>
            </select>

            <div class="sort-row">
                <div class="sort-col">
                    <label for="ns_sort_int">Sort Order</label>
                    <input id="ns_sort_int" name="sort_order" type="number" value="1" min="1">
                </div>
                <div class="after-col">
                    <label>Comes After</label>
                    <div id="comesAfterNameInt" class="small-muted">Top (no previous status)</div>
                </div>
            </div>

            <div style="display:flex;align-items:center;gap:8px;margin-top:8px;">
                <label style="margin:0;"> <input id="ns_active_int" name="active" type="checkbox" checked> Active</label>
                <div id="sortWarnInt" class="small-muted" style="margin-left:12px; color:#b45309; display:none;">This sort number is already used by an active status.</div>
            </div>

            <div class="modal-actions">
                <button type="button" id="ns_cancel_int" class="btn">Cancel</button>
                <button type="submit" class="btn btn-orange">Create</button>
            </div>
        </form>
    </div>
</div>

<script>
// Applicants panel wiring (mirrors positions panel behavior)
document.addEventListener('DOMContentLoaded', function(){
    // mutual exclusive panels: expanding one collapses others
    document.querySelectorAll('.flow-panel-header').forEach(function(h){
        // set edit button disabled state initially (closed -> disabled)
        var panelInit = h.closest('.flow-panel');
        if (panelInit) {
            var editBtnInit = panelInit.querySelector('button[id^="toggleEditBtn"]');
            if (editBtnInit) editBtnInit.disabled = (h.getAttribute('aria-expanded') !== 'true');
        }

        h.addEventListener('click', function(e){
            var panel = h.closest('.flow-panel');
            if (!panel) return;

            // If any panel (other than the one clicked) is currently in edit mode,
            // prevent switching and draw attention to the Exit Edit button on that panel.
            var currentEditing = document.querySelector('.flow-panel.editing');
            if (currentEditing && currentEditing !== panel) {
                var editBtnOther = currentEditing.querySelector('button[id^="toggleEditBtn"]');
                if (editBtnOther) {
                    editBtnOther.textContent = 'Exit Edit';
                    editBtnOther.classList.add('bounce');
                    try { editBtnOther.focus(); } catch(e){}
                    setTimeout(function(){ editBtnOther.classList.remove('bounce'); }, 500);
                }
                // include the flow type (panel title) in the notification
                try {
                    var flowName = (currentEditing.querySelector('.flow-panel-title')||{}).textContent || 'Flow';
                    if (window.Notify && Notify.push) Notify.push({ from:'Flows', message: 'Exit Edit on ' + flowName.trim() + ' before switching panels', color:'#f59e0b' });
                } catch(e) {}
                return; // prevent toggling while another panel is editing
            }

            // if panel is currently in edit mode, do NOT allow closing the panel
            // instead, draw attention to the Exit Edit button
            if (panel.classList.contains('editing')) {
                var editBtn = panel.querySelector('button[id^="toggleEditBtn"]');
                if (editBtn) {
                    // ensure button shows Exit text and flash it
                    editBtn.textContent = 'Exit Edit';
                    editBtn.classList.add('bounce');
                    try { editBtn.focus(); } catch(e){}
                    setTimeout(function(){ editBtn.classList.remove('bounce'); }, 500);
                }
                // notify which flow the user must exit edit on
                try {
                    var flowNameSelf = (panel.querySelector('.flow-panel-title')||{}).textContent || 'Flow';
                    if (window.Notify && Notify.push) Notify.push({ from:'Flows', message: 'Exit Edit on ' + flowNameSelf.trim() + ' before toggling', color:'#f59e0b' });
                } catch(e) {}
                return; // prevent toggling while editing
            }

            var expanded = h.getAttribute('aria-expanded') === 'true';
            if (!expanded) {
                // collapse others
                document.querySelectorAll('.flow-panel').forEach(function(other){
                    if (other !== panel) {
                        var bh = other.querySelector('.flow-panel-header');
                        var bb = other.querySelector('.flow-panel-body');
                        if (bh && bb) { bh.setAttribute('aria-expanded','false'); bb.style.display = 'none'; other.classList.remove('expanded');
                            var otherEdit = other.querySelector('button[id^="toggleEditBtn"]'); if (otherEdit) otherEdit.disabled = true; }
                    }
                });
                h.setAttribute('aria-expanded','true');
                var body = panel.querySelector('.flow-panel-body'); if (body) body.style.display = '';
                panel.classList.add('expanded');
                // enable this panel's edit button
                var myEdit = panel.querySelector('button[id^="toggleEditBtn"]'); if (myEdit) myEdit.disabled = false;
            } else {
                h.setAttribute('aria-expanded','false');
                var body = panel.querySelector('.flow-panel-body'); if (body) body.style.display = 'none';
                panel.classList.remove('expanded');
                // disable this panel's edit button (not editing)
                var myEdit = panel.querySelector('button[id^="toggleEditBtn"]'); if (myEdit) myEdit.disabled = true;
            }
        });
    });

    // new status modal app
    var newStatusModalApp = document.getElementById('newStatusModalApp');
    var newStatusCloseApp = newStatusModalApp && newStatusModalApp.querySelector('.modal-close');
    var newStatusCancelApp = document.getElementById('ns_cancel_app');
    var newStatusFormApp = document.getElementById('newStatusFormApp');
    function openNewStatusModalApp(){ if (!newStatusModalApp) return; newStatusModalApp.style.display = 'flex'; var first = document.getElementById('ns_name_app'); if (first) first.focus(); }
    try { window.openNewStatusModalApp = openNewStatusModalApp; } catch(e) {}
    function closeNewStatusModalApp(){ if (!newStatusModalApp) return; newStatusModalApp.style.display = 'none'; try { if (newStatusFormApp) newStatusFormApp.reset(); } catch(e){} }
    if (newStatusCloseApp) newStatusCloseApp.addEventListener('click', closeNewStatusModalApp);
    if (newStatusCancelApp) newStatusCancelApp.addEventListener('click', closeNewStatusModalApp);
    if (newStatusModalApp) newStatusModalApp.addEventListener('click', function(e){ if (e.target === newStatusModalApp) closeNewStatusModalApp(); });

    // show live color hex for applicants modal
    var nsColorApp = document.getElementById('ns_color_app');
    var nsColorHexApp = document.getElementById('ns_color_app_hex');
    if (nsColorApp && nsColorHexApp) {
        nsColorHexApp.textContent = nsColorApp.value || '';
        nsColorApp.addEventListener('input', function(){ nsColorHexApp.textContent = nsColorApp.value || ''; });
    }

    // header new button for applicants: find panel by title to avoid index fragility
    var panels = document.querySelectorAll('.flow-panel');
    var panelApp = null;
    if (panels && panels.length) {
        var panelsArr = Array.from(panels);
        panelApp = panelsArr.find(function(p){ return ((p.querySelector('.flow-panel-title')||{}).textContent||'').trim() === 'Applicant Ticket Flow'; }) || null;
        if (panelApp) {
            var headerNewApp = panelApp.querySelector('.flow-panel-actions a');
            if (headerNewApp) headerNewApp.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); openNewStatusModalApp(); });
        }
    }
    var toggleEditBtnApp = document.getElementById('toggleEditBtnApp');
    var saveAllBtnApp = document.getElementById('saveAllBtnApp');
    var poolsApp = <?= json_encode($pools) ?> || [];
    var allStatusesApp = <?= json_encode(array_map(function($r){ return ['id'=>(int)$r['status_id'],'name'=>$r['status_name'],'sort'=>(int)$r['sort_order']]; }, $app_active_statuses)); ?> || [];

    function enterEditModeApp(){
        enterEditModeGeneric('.flow-row-app', poolsApp, allStatusesApp);
    }
    function exitEditModeApp(){ document.querySelectorAll('.flow-row-app').forEach(function(row){ if (row.dataset.orig) { row.innerHTML = row.dataset.orig; delete row.dataset.orig; } if (row.dataset.origName) delete row.dataset.origName; if (row.dataset.origSort) delete row.dataset.origSort; if (row.dataset.changed) delete row.dataset.changed; }); }

    if (toggleEditBtnApp) {
        toggleEditBtnApp.addEventListener('click', function(e){ e.stopPropagation(); if (!panelApp) return; var editing = panelApp.classList.toggle('editing'); toggleEditBtnApp.textContent = editing ? 'Exit Edit' : 'Edit'; if (editing) { enterEditModeApp(); if (saveAllBtnApp) saveAllBtnApp.style.display = ''; } else { exitEditModeApp(); if (saveAllBtnApp) saveAllBtnApp.style.display = 'none'; } });
    }

    function gatherRowDataApp(row){ return gatherRowData(row); }

    function rowHasChangesApp(row){ return rowHasChanges(row); }

    if (saveAllBtnApp) saveAllBtnApp.addEventListener('click', function(e){ e.stopPropagation(); handleSaveAllApp(); });

        function handleSaveAllApp(){ var rows = []; document.querySelectorAll('.flow-row-app').forEach(function(row){ if (row.querySelector('.cell-name input')) { if (rowHasChangesApp(row)) rows.push(gatherRowDataApp(row)); } }); if (!rows.length) { if (window.Notify && Notify.push) Notify.push({ from:'Flows', message:'No changes to save', color:'#f59e0b'}); return; } for (var i=0;i<rows.length;i++){ var r = rows[i]; if (!r.status_name) { if (window.Notify && Notify.push) Notify.push({ from:'Flows', message:'Name required on one or more rows', color:'#dc2626'}); return; } if (!r.pool_id) { if (window.Notify && Notify.push) Notify.push({ from:'Flows', message:'Pool required on one or more rows', color:'#dc2626'}); return; } // transitions may be empty (terminal statuses); no validation required
            if (!Number.isInteger(r.sort_order) || r.sort_order <= 0) { if (window.Notify && Notify.push) Notify.push({ from:'Flows', message:'Sort must be > 0 for all edited rows', color:'#dc2626'}); return; } } var payload = { rows: rows }; fetch('flows_update_applicants.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) }).then(function(res){ return res.json().catch(function(){ return { success:false, error:'Invalid response' }; }); }).then(function(json){ if (json && json.success) { if (window.Notify && Notify.push) Notify.push({ from:'Flows', message:'Saved changes', color:'#10b981' }); setTimeout(function(){ window.location.reload(); }, 500); } else { if (window.Notify && Notify.push) Notify.push({ from:'Flows', message:(json && json.error)?json.error:'Save failed', color:'#dc2626'}); } }).catch(function(err){ console.error(err); if (window.Notify && Notify.push) Notify.push({ from:'Flows', message:'Request failed', color:'#dc2626' }); }); }

    // Toggle active for applicants table
    var appTable = document.getElementById('flowsTableApp');
    if (appTable) {
        appTable.addEventListener('click', function(e){
            var cell = e.target.closest('.cell-active'); if (!cell) return;
            var row = cell.closest('.flow-row-app'); if (!row) return;
            var panel = row.closest('.flow-panel');
            if (!panel || !panel.classList.contains('editing')) return;
            var statusId = parseInt(row.dataset.id,10);
            var cur = parseInt(row.dataset.active || '0',10)?1:0; var newActive = cur?0:1;
            var badge = cell.querySelector('.active-badge, .inactive-badge'); if (badge) { badge.textContent = newActive ? 'Yes' : 'No'; badge.className = newActive ? 'active-badge' : 'inactive-badge'; }
            row.dataset.active = newActive; row.dataset.changed = '1';
            updatePanelCount(panel);
            fetch('flows_toggle_active_applicants.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ status_id: statusId, active: newActive }) }).then(function(res){ return res.json().catch(function(){ return { success:false, error:'Invalid response' }; }); }).then(function(json){ if (!(json && json.success)) { var revert = cur ? 1 : 0; row.dataset.active = revert; if (badge) { badge.textContent = revert ? 'Yes' : 'No'; badge.className = revert ? 'active-badge':'inactive-badge'; } if (row.dataset.changed) delete row.dataset.changed; updatePanelCount(panel); console.error('Toggle failed', json && json.error ? json.error : json); } }).catch(function(err){ console.error(err); var revert = cur ? 1 : 0; row.dataset.active = revert; if (badge) { badge.textContent = revert ? 'Yes' : 'No'; badge.className = revert ? 'active-badge':'inactive-badge'; } if (row.dataset.changed) delete row.dataset.changed; updatePanelCount(panel); });
        });
    }

    // Create applicants status form submit
    if (newStatusFormApp) {
        newStatusFormApp.addEventListener('submit', async function(ev){
            ev.preventDefault();
            var poolEl = document.getElementById('ns_pool_app'); var pool_id = poolEl ? poolEl.value : ''; var pool_name = ''; if (poolEl && poolEl.selectedIndex >= 0) pool_name = poolEl.options[poolEl.selectedIndex].text || '';
            var transitions = []; var trEl = document.getElementById('ns_transitions_app'); if (trEl) { for (var i=0;i<trEl.options.length;i++){ var o = trEl.options[i]; if (o && o.selected) transitions.push(parseInt(o.value,10) || o.value); } }
            if (!pool_id) { if (window.Notify && Notify.push) Notify.push({ from: 'Flows', message: 'Please select a Pool', color: '#dc2626' }); return; }
            // transitions may be empty (terminal statuses); do not require at least one transition
            if (typeof validateSortApp === 'function' && !validateSortApp()) { if (window.Notify && Notify.push) Notify.push({ from: 'Flows', message: 'Please choose a different Sort Order', color: '#dc2626' }); return; }
            var payload = { status_name: (document.getElementById('ns_name_app') || {}).value || '', status_color: (document.getElementById('ns_color_app') || {}).value || '', pool_id: pool_id || null, pool_name: pool_name || '', transitions: transitions, sort_order: parseInt((document.getElementById('ns_sort_app') || {}).value || '0',10) || 0, active: document.getElementById('ns_active_app') && document.getElementById('ns_active_app').checked ? 1 : 0 };
            try { const res = await fetch('flows_create_applicants.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) }); const clone = res.clone(); let json = null; if (res.ok) { try { json = await res.json(); } catch(e) { const txt = await clone.text(); if (window.Notify && Notify.push) Notify.push({ from: 'Flows', message: 'Server error: ' + txt.slice(0,200), color: '#dc2626' }); closeNewStatusModalApp(); return; } } else { const txt = await clone.text(); if (window.Notify && Notify.push) Notify.push({ from: 'Flows', message: 'Request failed: ' + txt.slice(0,200), color: '#dc2626' }); closeNewStatusModalApp(); return; } if (json && json.success) { if (window.Notify && Notify.push) Notify.push({ from: 'Flows', message: 'Status created', color: '#10b981' }); setTimeout(function(){ window.location.reload(); }, 700); } else { const err = (json && json.error) ? json.error : 'Create failed'; if (window.Notify && Notify.push) Notify.push({ from: 'Flows', message: err, color: '#dc2626' }); } } catch(err) { console.error(err); if (window.Notify && Notify.push) Notify.push({ from: 'Flows', message: 'Request failed', color: '#dc2626' }); }
        });
    }

    // Interviews panel wiring (mirrors applicants behavior)
    // find interviews panel by title and wire its New button
    var panelInt = null;
    if (panels && panels.length) {
        var panelsArr2 = Array.from(panels);
        panelInt = panelsArr2.find(function(p){ return ((p.querySelector('.flow-panel-title')||{}).textContent||'').trim() === 'Interview Ticket Flow'; }) || null;
        if (panelInt) {
            var headerNewInt = panelInt.querySelector('.flow-panel-actions a');
            if (headerNewInt) headerNewInt.addEventListener('click', function(e){
                e.preventDefault(); e.stopPropagation();
                // call the global opener if available, otherwise fallback to directly opening the modal
                if (typeof window.openNewStatusModalInt === 'function') {
                    try { window.openNewStatusModalInt(); } catch(err){ console.error('openNewStatusModalInt failed', err); }
                } else {
                    var m = document.getElementById('newStatusModalInt'); if (m) { m.style.display = 'flex'; var first = document.getElementById('ns_name_int'); if (first) first.focus(); }
                }
            });
        }
    }
    var toggleEditBtnInt = document.getElementById('toggleEditBtnInt');
    var saveAllBtnInt = document.getElementById('saveAllBtnInt');
    var poolsInt = <?= json_encode($pools) ?> || [];
    var allStatusesInt = <?= json_encode(array_map(function($r){ return ['id'=> (int)$r['id'], 'name'=>$r['name'], 'sort'=>(int)$r['sort_order']]; }, $interview_active_statuses)); ?> || [];

    function enterEditModeInt(){ enterEditModeGeneric('.flow-row-int', poolsInt, allStatusesInt); }
    function exitEditModeInt(){ document.querySelectorAll('.flow-row-int').forEach(function(row){ if (row.dataset.orig) { row.innerHTML = row.dataset.orig; delete row.dataset.orig; } if (row.dataset.origName) delete row.dataset.origName; if (row.dataset.origSort) delete row.dataset.origSort; if (row.dataset.changed) delete row.dataset.changed; }); }

    if (toggleEditBtnInt) {
        toggleEditBtnInt.addEventListener('click', function(e){ e.stopPropagation(); if (!panelInt) return; var editing = panelInt.classList.toggle('editing'); toggleEditBtnInt.textContent = editing ? 'Exit Edit' : 'Edit'; if (editing) { enterEditModeInt(); if (saveAllBtnInt) saveAllBtnInt.style.display = ''; } else { exitEditModeInt(); if (saveAllBtnInt) saveAllBtnInt.style.display = 'none'; } });
    }

    function gatherRowDataInt(row){ return gatherRowData(row); }
    function rowHasChangesInt(row){ return rowHasChanges(row); }

    if (saveAllBtnInt) saveAllBtnInt.addEventListener('click', function(e){ e.stopPropagation(); handleSaveAllInt(); });

        function handleSaveAllInt(){ var rows = []; document.querySelectorAll('.flow-row-int').forEach(function(row){ if (row.querySelector('.cell-name input')) { if (rowHasChangesInt(row)) rows.push(gatherRowDataInt(row)); } }); if (!rows.length) { if (window.Notify && Notify.push) Notify.push({ from:'Flows', message:'No changes to save', color:'#f59e0b'}); return; } for (var i=0;i<rows.length;i++){ var r = rows[i]; if (!r.status_name) { if (window.Notify && Notify.push) Notify.push({ from:'Flows', message:'Name required on one or more rows', color:'#dc2626'}); return; } if (!r.pool_id) { if (window.Notify && Notify.push) Notify.push({ from:'Flows', message:'Pool required on one or more rows', color:'#dc2626'}); return; } // transitions may be empty (terminal statuses); no validation required
            if (!Number.isInteger(r.sort_order) || r.sort_order <= 0) { if (window.Notify && Notify.push) Notify.push({ from:'Flows', message:'Sort must be > 0 for all edited rows', color:'#dc2626'}); return; } } var payload = { rows: rows }; fetch('flows_update_interviews.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) }).then(function(res){ return res.json().catch(function(){ return { success:false, error:'Invalid response' }; }); }).then(function(json){ if (json && json.success) { if (window.Notify && Notify.push) Notify.push({ from:'Flows', message:'Saved changes', color:'#10b981' }); setTimeout(function(){ window.location.reload(); }, 500); } else { if (window.Notify && Notify.push) Notify.push({ from:'Flows', message:(json && json.error)?json.error:'Save failed', color:'#dc2626'}); } }).catch(function(err){ console.error(err); if (window.Notify && Notify.push) Notify.push({ from:'Flows', message:'Request failed', color:'#dc2626' }); }); }

    // Toggle active for interviews table
    var intTable = document.getElementById('flowsTableInt');
    if (intTable) {
        intTable.addEventListener('click', function(e){
            var cell = e.target.closest('.cell-active'); if (!cell) return;
            var row = cell.closest('.flow-row-int'); if (!row) return;
            var panel = row.closest('.flow-panel');
            if (!panel || !panel.classList.contains('editing')) return;
            var statusId = parseInt(row.dataset.id,10);
            var cur = parseInt(row.dataset.active || '0',10)?1:0; var newActive = cur?0:1;
            var badge = cell.querySelector('.active-badge, .inactive-badge'); if (badge) { badge.textContent = newActive ? 'Yes' : 'No'; badge.className = newActive ? 'active-badge' : 'inactive-badge'; }
            row.dataset.active = newActive; row.dataset.changed = '1';
            updatePanelCount(panel);
            fetch('flows_toggle_active_interviews.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ status_id: statusId, active: newActive }) }).then(function(res){ return res.json().catch(function(){ return { success:false, error:'Invalid response' }; }); }).then(function(json){ if (!(json && json.success)) { var revert = cur ? 1 : 0; row.dataset.active = revert; if (badge) { badge.textContent = revert ? 'Yes' : 'No'; badge.className = revert ? 'active-badge':'inactive-badge'; } if (row.dataset.changed) delete row.dataset.changed; updatePanelCount(panel); console.error('Toggle failed', json && json.error ? json.error : json); } }).catch(function(err){ console.error(err); var revert = cur ? 1 : 0; row.dataset.active = revert; if (badge) { badge.textContent = revert ? 'Yes' : 'No'; badge.className = revert ? 'active-badge':'inactive-badge'; } if (row.dataset.changed) delete row.dataset.changed; updatePanelCount(panel); });
        });
    }

    // Interviews New Status modal wiring
    var newStatusModalInt = document.getElementById('newStatusModalInt');
    var newStatusCloseInt = newStatusModalInt && newStatusModalInt.querySelector('.modal-close');
    var newStatusCancelInt = document.getElementById('ns_cancel_int');
    var newStatusFormInt = document.getElementById('newStatusFormInt');
    function openNewStatusModalInt(){ if (!newStatusModalInt) return; newStatusModalInt.style.display = 'flex'; var first = document.getElementById('ns_name_int'); if (first) first.focus(); }
    // expose globally so click handlers added elsewhere can call it
    try { window.openNewStatusModalInt = openNewStatusModalInt; } catch(e) {}
    function closeNewStatusModalInt(){ if (!newStatusModalInt) return; newStatusModalInt.style.display = 'none'; try { if (newStatusFormInt) newStatusFormInt.reset(); } catch(e){} }
    if (newStatusCloseInt) newStatusCloseInt.addEventListener('click', closeNewStatusModalInt);
    if (newStatusCancelInt) newStatusCancelInt.addEventListener('click', closeNewStatusModalInt);
    if (newStatusModalInt) newStatusModalInt.addEventListener('click', function(e){ if (e.target === newStatusModalInt) closeNewStatusModalInt(); });

    // color preview for interviews modal
    var nsColorInt = document.getElementById('ns_color_int');
    var nsColorHexInt = document.getElementById('ns_color_int_hex');
    if (nsColorInt && nsColorHexInt) { nsColorHexInt.textContent = nsColorInt.value || ''; nsColorInt.addEventListener('input', function(){ nsColorHexInt.textContent = nsColorInt.value || ''; }); }

    // Submit create interview status form (posts to flows_create_interviews.php)
    if (newStatusFormInt) {
        newStatusFormInt.addEventListener('submit', async function(ev){
            ev.preventDefault();
            var poolEl = document.getElementById('ns_pool_int'); var pool_id = poolEl ? poolEl.value : ''; var pool_name = ''; if (poolEl && poolEl.selectedIndex >= 0) pool_name = poolEl.options[poolEl.selectedIndex].text || '';
            var transitions = []; var trEl = document.getElementById('ns_transitions_int'); if (trEl) { for (var i=0;i<trEl.options.length;i++){ var o = trEl.options[i]; if (o && o.selected) transitions.push(parseInt(o.value,10) || o.value); } }
            if (!pool_id) { if (window.Notify && Notify.push) Notify.push({ from: 'Flows', message: 'Please select a Pool', color: '#dc2626' }); return; }
            // transitions may be empty (terminal statuses); do not require at least one transition
            var payload = { status_name: (document.getElementById('ns_name_int') || {}).value || '', status_color: (document.getElementById('ns_color_int') || {}).value || '', pool_id: pool_id || null, pool_name: pool_name || '', transitions: transitions, sort_order: parseInt((document.getElementById('ns_sort_int') || {}).value || '0',10) || 0, active: document.getElementById('ns_active_int') && document.getElementById('ns_active_int').checked ? 1 : 0 };
            try { const res = await fetch('flows_create_interviews.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) }); const clone = res.clone(); let json = null; if (res.ok) { try { json = await res.json(); } catch(e) { const txt = await clone.text(); if (window.Notify && Notify.push) Notify.push({ from: 'Flows', message: 'Server error: ' + txt.slice(0,200), color: '#dc2626' }); closeNewStatusModalInt(); return; } } else { const txt = await clone.text(); if (window.Notify && Notify.push) Notify.push({ from: 'Flows', message: 'Request failed: ' + txt.slice(0,200), color: '#dc2626' }); closeNewStatusModalInt(); return; } if (json && json.success) { if (window.Notify && Notify.push) Notify.push({ from: 'Flows', message: 'Status created', color: '#10b981' }); setTimeout(function(){ window.location.reload(); }, 700); } else { const err = (json && json.error) ? json.error : 'Create failed'; if (window.Notify && Notify.push) Notify.push({ from: 'Flows', message: err, color: '#dc2626' }); } } catch(err) { console.error(err); if (window.Notify && Notify.push) Notify.push({ from: 'Flows', message: 'Request failed', color: '#dc2626' }); }
        });
    }
});
</script>
