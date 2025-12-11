<?php
if (!isset($_SESSION)) session_start();
// If the logged-in account requires a forced password reset, redirect
// to the set_password flow unless we're already on that page or related endpoints.
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$allowedDuringReset = ['set_password.php', 'update_password.php', 'get_profile.php', 'logout.php', 'index.php'];
if (!empty($_SESSION['user']['force_password_reset']) && !in_array($currentScript, $allowedDuringReset, true)) {
    header('Location: set_password.php');
    exit;
}

// Audit: run early (before HTML output) so errors won't end up inside CSS
if (!empty($_SESSION['user'])) {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $skip = ['bugatti-logo.php'];
    if (!in_array($script, $skip, true)) {
        if (file_exists(__DIR__ . '/audit.php')) require_once __DIR__ . '/audit.php';
        try {
            if (function_exists('audit_log_auto')) audit_log_auto('view', 'page', 0, null, ['script' => $script, 'query' => $_SERVER['QUERY_STRING'] ?? '']);
        } catch (Throwable $_) { /* ignore audit errors */ }
    }

    // Generic POST CRUD capture (lightweight heuristic)
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $skipCrud = ['bugatti-logo.php'];
        if (!in_array($script, $skipCrud, true)) {
            if (file_exists(__DIR__ . '/audit.php')) require_once __DIR__ . '/audit.php';
            try {
                $lower = strtolower($script);
                $action = 'create';
                if (preg_match('/\bdelete\b|^delete_|_delete|delete-/i', $lower)) $action = 'delete';
                elseif (preg_match('/^update|_update|^toggle|_toggle|^set_|^reset_/i', $lower)) $action = 'update';
                else {
                    $idKeys = ['id','applicant_id','position_id','user_id','department_id','team_id','role_id','interview_id','ticket_id','job_id','status_id'];
                    foreach ($idKeys as $k) { if (isset($_POST[$k]) && $_POST[$k] !== '') { $action = 'update'; break; } }
                }

                $entity = preg_replace('/\.(php|phtml)$/i', '', $script);
                $entity = preg_replace('/^(create|update|delete|add|set|toggle|reset|bulk|batch)[_\-]?/i', '', $entity);
                $entity = preg_replace('/[_\-].*/', '', $entity);
                $entity = $entity ?: 'unknown';

                $entity_id = 0;
                $idCandidates = ['id','applicant_id','position_id','user_id','department_id','team_id','role_id','interview_id','ticket_id','job_id','status_id'];
                foreach ($idCandidates as $k) { if (isset($_POST[$k]) && $_POST[$k] !== '') { $entity_id = (int)$_POST[$k]; break; } }

                $redactKeys = ['password','pwd','pass','token','csrf','secret'];
                $postCopy = [];
                foreach ($_POST as $k => $v) {
                    $lk = strtolower($k);
                    $sensitive = false;
                    foreach ($redactKeys as $rk) { if (strpos($lk, $rk) !== false) { $sensitive = true; break; } }
                    if ($sensitive) $postCopy[$k] = '[REDACTED]';
                    else {
                        if (is_array($v)) {
                            $postCopy[$k] = $v;
                        } else {
                            $str = (string)$v;
                            if (strlen($str) > 2000) $str = substr($str,0,2000) . '...[truncated]';
                            $postCopy[$k] = $str;
                        }
                    }
                }

                if (function_exists('audit_log_auto')) {
                    audit_log_auto($action, $entity, $entity_id, null, $postCopy);
                }
            } catch (Throwable $_) { /* ignore audit errors */ }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($pageTitle ?? 'App') ?></title>
    <!-- Site favicon served via PHP endpoint to centralize the asset and headers -->
    <link rel="icon" type="image/png" href="bugatti-logo.php" sizes="32x32">
    <link rel="icon" type="image/png" href="bugatti-logo.php" sizes="64x64">
    <link rel="shortcut icon" href="bugatti-logo.php">
    <!-- Keep webp/png static assets as fallback if needed (not used by default) -->
    <link rel="alternate icon" type="image/webp" href="assets/White-Bugatti-Logo.webp" sizes="64x64">
    <!-- existing meta / css links -->
        <!-- Font Awesome (CDN with local fallback) -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.0/css/all.min.css" crossorigin="anonymous" />
        <script>
        (function(){
            var triedLocal = false;
            function hasFA(){ return Array.from(document.styleSheets||[]).some(function(s){ return s && s.href && (s.href.indexOf('fontawesome') !== -1 || s.href.indexOf('font-awesome') !== -1 || (s.ownerNode && s.ownerNode.innerText && s.ownerNode.innerText.indexOf('.fa-') !== -1)); }); }
            function injectLocal(){ if (triedLocal) return; triedLocal = true; var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = 'assets/vendor/fontawesome/css/all.min.css'; l.crossOrigin = 'anonymous'; document.head.appendChild(l); }
            // if stylesheet not applied after a short delay, try local fallback
            if (!hasFA()) {
                // first try CDN again (some proxies block initial requests)
                var l=document.createElement('link'); l.rel='stylesheet'; l.href='https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.0/css/all.min.css'; l.crossOrigin='anonymous'; document.head.appendChild(l);
                setTimeout(function(){ if (!hasFA()) injectLocal(); }, 700);
            }
        })();
        </script>
    <style>
    /* Defensive: force FA font for icon elements and pseudo-elements */
    .sidebar i, .sidebar .fa, .sidebar .fas, .sidebar [class*="fa-"], .sidebar .svg-icon {
        font-family: "Font Awesome 6 Free", "Font Awesome 5 Free", "Font Awesome 5 Pro", sans-serif !important;
        font-weight: 900 !important;
        visibility: visible !important;
        opacity: 1 !important;
    }
    /* Some FA icons use ::before content — ensure pseudo element uses FA font too */
    .sidebar i::before, .sidebar .fa::before, .sidebar .fas::before, .sidebar [class*="fa-"]::before {
        font-family: "Font Awesome 6 Free", "Font Awesome 5 Free", sans-serif !important;
        font-weight: 900 !important;
    }
    /* If icons are SVG elements ensure they are visible */
    .sidebar svg { display:inline-block !important; visibility:visible !important; opacity:1 !important; }
    </style>
    <style>
    /* Header logo sizing and hover effect */
    .logo-section .site-logo { 
        height: 60px;
        width: 55px;
        display: inline-block; 
        vertical-align: middle; 
        transition: transform .12s ease, box-shadow .12s ease; 
        cursor: pointer;
        background-image: url('assets/bugatti-logo.png');
        background-repeat: no-repeat;
        background-position: center left;
        background-size: contain;
    }
    .logo-section .site-logo:hover {
        transform: translateY(-2px) scale(1.10); /* 10% scale on hover */
        box-shadow: 0 6px 18px rgba(12,18,24,0.08);
    }
    @media (max-width:720px) {
        .logo-section .site-logo { height: 72px; }
    }
    </style>
    <!-- layout and base styles (served from public/styles) -->
    <link rel="stylesheet" href="styles/layout.css">
    <!-- page-specific styles should be linked by individual pages after this include -->
</head>

<body>

<div class="topbar">
    <div class="logo-section">
        <div class="site-logo site-logo--black" role="img" aria-label="Bugatti logo"></div>
        <span class="tagline">HELPING YOU HIRE WONDERFUL PEOPLE</span>
    </div>
    <?php
    // topbar user: minimal render — profile popup will be loaded lazily via AJAX
    $userName = $_SESSION['user']['name'] ?? $_SESSION['user']['email'] ?? '';
    $userId = $_SESSION['user']['id'] ?? null;
    $userNameShort = htmlspecialchars(substr($userName,0,1) ?: 'U');
    $userNameEsc = htmlspecialchars($userName);
    $userRoleEsc = htmlspecialchars($_SESSION['user']['role'] ?? '');
    ?>
    <div class="topbar-user" style="margin-left:auto;display:flex;align-items:center;gap:12px;padding-right:16px;position:relative;">
                <div style="display:flex;flex-direction:column;align-items:flex-end;min-width:0;">
                        <div class="topbar-user-name" style="font-weight:700;color:#000000;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px;"><?= $userNameEsc ?></div>
                        <?php if (!empty($userRoleEsc)): ?>
                            <div class="topbar-user-role" style="color:#6b7280;font-size:13px;line-height:1;margin-top:2px;"><?= $userRoleEsc ?></div>
                        <?php endif; ?>
                </div>
        <div class="topbar-avatar" data-user-id="<?= htmlspecialchars($userId) ?>" aria-haspopup="true" aria-expanded="false" style="width:40px;height:40px;border-radius:999px;background:#f3f3f3;display:flex;align-items:center;justify-content:center;font-weight:700;color:#333;user-select:none;">
            <?= $userNameShort ?>
        </div>
        <div class="topbar-profile-popup" role="dialog" aria-hidden="true" style="display:none;position:absolute;min-width:280px;background:#fff;border:1px solid rgba(0,0,0,0.08);box-shadow:0 8px 30px rgba(12,18,24,0.12);border-radius:10px;padding:12px;z-index:1200;">
            <div class="profile-loading" style="text-align:center;color:#6b7280;padding:20px;">Loading...</div>
        </div>
    </div>
</div>
<script>
    (function(){
        try {
            window.USER_SCOPE = <?= json_encode($_SESSION['user']['scope'] ?? 'local') ?>;
            window.USER_DEPARTMENT_ID = <?= json_encode($_SESSION['user']['department_id'] ?? null) ?>;
            window.USER_DEPARTMENT_NAME = <?= json_encode($_SESSION['user']['department_name'] ?? '') ?>;
            window.USER_ACCESS_KEYS = <?= json_encode($_SESSION['user']['access_keys'] ?? []) ?>;
            window.USER_TEAM_ID = <?= json_encode($_SESSION['user']['team_id'] ?? null) ?>;
            window.USER_TEAM_NAME = <?= json_encode($_SESSION['user']['team'] ?? '') ?>;
        } catch(e) { /* ignore */ }
    })();
</script>
<script>
// Global client-side audit: capture button clicks and send to server-side audit endpoint.
(function(){
    if (!window || !document) return;
    const endpoint = 'log_audit.php';
    const selector = 'button, [role="button"], .btn, a.btn, input[type="submit"], input[type="button"], [data-audit]';
    const lastSeen = new WeakMap();
    const debounceMs = 300; // avoid duplicate rapid clicks

    function collectDataset(el){
        const out = {};
        try {
            for (let i=0;i<el.attributes.length;i++){
                const a = el.attributes[i];
                if (!a.name || !a.name.startsWith('data-')) continue;
                const k = a.name.slice(5);
                out[k] = a.value;
            }
        } catch(e) {}
        return out;
    }

    function summarizeElement(el){
        return {
            tag: el.tagName,
            text: (el.innerText || el.value || '').toString().trim().slice(0,200),
            classes: el.className || '',
            href: el.getAttribute ? (el.getAttribute('href') || '') : '',
            dataset: collectDataset(el)
        };
    }

    function findEntity(el){
        // prefer explicit data attributes
        const e = el.closest && el.closest('[data-entity-type]');
        if (e && e.dataset && e.dataset.entityType) return { type: e.dataset.entityType, id: e.dataset.entityId || 0 };
        // try the element itself
        if (el.dataset && (el.dataset.entityType || el.dataset.entity)) return { type: el.dataset.entityType || el.dataset.entity, id: el.dataset.entityId || 0 };
        // fallback to page path
        return { type: window.location.pathname || 'page', id: 0 };
    }

    function send(payload){
        try {
            const body = JSON.stringify(payload);
            // Use navigator.sendBeacon when available for reliable background send
            if (navigator.sendBeacon) {
                try {
                    navigator.sendBeacon(endpoint, body);
                    return;
                } catch(e) { /* fallthrough to fetch */ }
            }
            // Use fetch keepalive for modern browsers
            if (window.fetch) {
                fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, credentials: 'same-origin', keepalive: true }).catch(()=>{});
            }
        } catch(e) { /* ignore */ }
    }

    document.addEventListener('click', function(ev){
        try {
            const el = ev.target && ev.target.closest && ev.target.closest(selector);
            if (!el) return;
            // ignore if inside inputs/interactive controls where clicks are not meaningful
            if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT') return;
            const now = Date.now();
            const last = lastSeen.get(el) || 0;
            if (now - last < debounceMs) return;
            lastSeen.set(el, now);

            const elem = summarizeElement(el);
            const found = findEntity(el);
            const payload = {
                action_type: 'click',
                entity_type: String(found.type || 'page').slice(0,50),
                entity_id: found.id ? parseInt(found.id,10) : 0,
                element: elem,
                page: window.location.pathname + (window.location.search || ''),
                timestamp: new Date().toISOString(),
                meta: { ua: navigator.userAgent ? (navigator.userAgent.slice(0,200)) : '' }
            };
            // avoid sending large or sensitive dataset values client-side; server will redact further
            send(payload);
        } catch(e) { /* ignore */ }
    }, true);
})();
</script>
    <style>
    .topbar-avatar { transition: transform .08s ease, box-shadow .12s ease, background .12s ease; cursor: pointer; }
    .topbar-avatar:hover { transform: translateY(-1px) scale(1.03); box-shadow: 0 6px 18px rgba(12,18,24,0.08); }
    .topbar-profile-popup { max-width:360px; }
    .topbar-profile-popup .profile-loading { color: #6b7280; }
    .topbar-profile-popup::-webkit-scrollbar { height:8px; width:8px; }
    .profile-row { display:flex;gap:12px;align-items:center;margin-bottom:8px; }
    .profile-label { color:#374151;font-weight:600;margin-right:6px; }
    .profile-value { color:#374151 }
    </style>
    <script>
    (function(){
        var avatar = document.querySelector('.topbar-avatar');
        var popup = document.querySelector('.topbar-profile-popup');
        if (!avatar || !popup) return;

        var isOpen = false;

        function escapeHtml(s){ return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

        function formatDateString(s){
            if (!s) return '';
            try {
                // try ISO-like parsing, replace space with T for 'YYYY-MM-DD HH:MM:SS'
                var t = s.replace(' ', 'T');
                var d = new Date(t);
                if (!isNaN(d.getTime())) return d.toLocaleString();
            } catch(e){}
            return s;
        }

        function buildProfileHtml(data){
            var user = data.user || {};
            var html = '';
            html += '<div class="profile-row">';
            html += '<div style="width:48px;height:48px;border-radius:999px;background:#f3f3f3;display:flex;align-items:center;justify-content:center;font-weight:700;color:#333;font-size:16px;">' + (user.name ? escapeHtml(user.name.charAt(0)) : 'U') + '</div>';
            html += '<div style="min-width:0;">';
            html += '<div style="font-weight:700;color:black;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px;">' + escapeHtml(user.name || '') + (user.user_name ? (' ('+escapeHtml(user.user_name)+')') : '') + '</div>';
            html += '<div style="font-size:13px;color:#6b7280;">' + escapeHtml(user.email || '') + '</div>';
            html += '</div></div>';
            html += '<div style="font-size:13px;color:#374151;margin-bottom:6px;"><span class="profile-label">Department:</span><span class="profile-value">' + escapeHtml(user.department || 'Unassigned') + '</span></div>';
            html += '<div style="font-size:13px;color:#374151;margin-bottom:6px;"><span class="profile-label">Team:</span><span class="profile-value">' + escapeHtml(user.team || 'Unassigned') + '</span></div>';
            html += '<div style="font-size:13px;color:#374151;margin-bottom:6px;"><span class="profile-label">Manager:</span><span class="profile-value">' + escapeHtml(user.manager || 'Unassigned') + '</span></div>';
            html += '<div style="font-size:13px;color:#374151;margin-bottom:6px;"><span class="profile-label">Role:</span><span class="profile-value">' + escapeHtml(user.role || 'User') + '</span></div>';
            // account created date
            if (user.created_at) {
                html += '<div style="font-size:13px;color:#374151;margin-bottom:6px;"><span class="profile-label">Created:</span><span class="profile-value">' + escapeHtml(formatDateString(user.created_at)) + '</span></div>';
            }
            html += '<div style="font-size:13px;color:#374151;margin-bottom:6px;"><span class="profile-label">Access Rights:</span></div>';
            html += '<div style="max-height:120px;overflow:auto;padding-right:6px;margin-bottom:6px;"><ul style="margin:0;padding-left:18px;color:#374151;font-size:13px;">';
            (user.access_keys || []).forEach(function(k){ html += '<li>'+escapeHtml(k)+'</li>'; });
            html += '</ul></div>';
            // (Logout link intentionally omitted from inline popup)
            return html;
        }

        function openPopup(){
            if (isOpen) return;
            isOpen = true;
            // append popup to body for consistent viewport positioning
            if (popup.parentNode !== document.body) document.body.appendChild(popup);
            popup.style.display = 'block';
            popup.setAttribute('aria-hidden','false');
            avatar.setAttribute('aria-expanded','true');
            // position popup below avatar
            var r = avatar.getBoundingClientRect();
            // ensure popup width measured
            popup.style.left = '0px'; popup.style.top = '0px';
            var left = Math.max(8, Math.round(r.right - popup.offsetWidth - 8));
            popup.style.left = left + 'px';
            popup.style.top = (window.scrollY + r.bottom + 8) + 'px';
            document.addEventListener('click', onDocClick);
            document.addEventListener('keydown', onKeyDown);
            // if popup content is loading placeholder, fetch profile
            var loading = popup.querySelector('.profile-loading');
            if (loading) {
                fetch('get_profile.php', { credentials: 'same-origin' }).then(function(res){
                    if (!res.ok) throw res;
                    return res.json();
                }).then(function(json){
                    if (json && json.success && json.user) {
                        popup.innerHTML = buildProfileHtml(json);
                    } else {
                        popup.innerHTML = '<div style="padding:12px;color:#ef4444;">Unable to load profile</div>';
                    }
                }).catch(function(err){
                    popup.innerHTML = '<div style="padding:12px;color:#ef4444;">Unable to load profile</div>';
                });
            }
        }

        function closePopup(){
            if (!isOpen) return;
            isOpen = false;
            popup.style.display = 'none';
            popup.setAttribute('aria-hidden','true');
            avatar.setAttribute('aria-expanded','false');
            document.removeEventListener('click', onDocClick);
            document.removeEventListener('keydown', onKeyDown);
        }

        function onDocClick(e){ if (!popup.contains(e.target) && !avatar.contains(e.target)) closePopup(); }
        function onKeyDown(e){ if (e.key === 'Escape') closePopup(); }

        avatar.addEventListener('click', function(e){ e.stopPropagation(); if (isOpen) closePopup(); else openPopup(); });

        // close on resize to avoid mispositioning
        window.addEventListener('resize', function(){ if (isOpen) closePopup(); });
    })();
    </script>
<script>
// Centralized layout sync: measure topbar and sidebar once and keep them in sync on resize.
(function(){
    function syncLayoutVars(){
        try {
            var docEl = document.documentElement;
            var tb = document.querySelector('.topbar');
            var sb = document.querySelector('.sidebar');
            if (tb && tb.offsetHeight) {
                docEl.style.setProperty('--topbar-height', tb.offsetHeight + 'px');
            }
            if (sb && sb.getBoundingClientRect) {
                var w = Math.round(sb.getBoundingClientRect().width);
                if (w && w > 0) docEl.style.setProperty('--sidebar-width', w + 'px');
            }
        } catch (e) { /* silent */ }
    }
    // run early and after load to catch fonts/stylesheet changes
    try { syncLayoutVars(); } catch(e){}
    window.addEventListener('resize', function(){
        // throttle to animation frames
        if (window.__layoutSyncPending) cancelAnimationFrame(window.__layoutSyncPending);
        window.__layoutSyncPending = requestAnimationFrame(function(){ syncLayoutVars(); window.__layoutSyncPending = null; });
    });
    // also ensure sync after DOMContentLoaded in case header included before DOM ready
    document.addEventListener('DOMContentLoaded', function(){ syncLayoutVars(); });
})();
</script>
