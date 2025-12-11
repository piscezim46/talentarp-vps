<?php
session_start();
require_once '../includes/db.php';
// central user utilities for safe timestamp updates
if (file_exists(__DIR__ . '/../includes/user_utils.php')) require_once __DIR__ . '/../includes/user_utils.php';

// TEMPORARY: helper to fetch a user's display name by login (email or username)
// This is intentionally short-lived and will be removed after debugging.
function __tmp_get_user_name_by_login($conn, $login) {
  try {
    $sql = "SELECT COALESCE(name, user_name, email, '') AS display_name FROM users WHERE (email = ? OR user_name = ?) LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return '';
    $stmt->bind_param('ss', $login, $login);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
      $row = $res->fetch_assoc();
      $stmt->close();
      return isset($row['display_name']) ? (string)$row['display_name'] : '';
    }
    $stmt->close();
  } catch (Throwable $_) { /* ignore */ }
  return '';
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // allow login by email or by username (user_name)
    $login = trim((string)($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT * FROM users WHERE (email = ? OR user_name = ?) AND active = 1 LIMIT 1");
    $stmt->bind_param('ss', $login, $login);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    // Determine if login is valid: prefer hashed verification, fallback to plaintext match
    $stored = isset($user['password']) ? (string)$user['password'] : '';
    $login_ok = false;
    try {
      if ($user && $stored !== '' && password_verify($password, $stored)) {
        $login_ok = true;
      }
    } catch (Throwable $_) {
      // ignore verification errors and fall back to direct compare
    }
    if (!$login_ok && $user && is_string($stored) && is_string($password) && $stored !== '') {
      if (function_exists('hash_equals')) {
        try { if (hash_equals($stored, $password)) $login_ok = true; } catch (Throwable $_) { if ($stored === $password) $login_ok = true; }
      } else {
        if ($stored === $password) $login_ok = true;
      }
    }

    if ($user && $login_ok) {
        // If the user's password is older than their configured expiry days, mark them for force reset.
        // Behavior: if password_expiration_days = 0 -> never expire. If missing, fallback to 90 days.
        try {
        $pwdChanged = isset($user['password_changed_at']) ? strtotime($user['password_changed_at']) : null;
        $expDays = isset($user['password_expiration_days']) ? intval($user['password_expiration_days']) : 90;
        $expired = false;
        if ($expDays === 0) {
          // 0 means never expire
          $expired = false;
        } else {
          if ($pwdChanged === null || $pwdChanged === false) {
            // no recorded change -> treat as expired
            $expired = true;
          } else {
            if ($pwdChanged < strtotime("-{$expDays} days")) $expired = true;
          }
        }
        if ($expired) {
          $uup = $conn->prepare('UPDATE users SET force_password_reset = 1 WHERE id = ?');
          if ($uup) { $uidx = (int)$user['id']; $uup->bind_param('i', $uidx); $uup->execute(); $uup->close(); }
          $user['force_password_reset'] = 1;
        }
        } catch (Throwable $_) { /* ignore expiry enforcement failure */ }
      // build session user and include access keys from roles tables when available
      // prefer role_id when present (newer schema); keep role name for backward compatibility
      $role_id = isset($user['role_id']) ? (int)$user['role_id'] : null;
      $role_name = isset($user['role']) ? $user['role'] : null;

      $_SESSION['user'] = [
        'id' => $user['id'],
        'name' => $user['name'],
        'role' => $role_name,
        'role_id' => $role_id,
        'force_password_reset' => !empty($user['force_password_reset']) ? 1 : 0
      ];

      // expose user's configured password expiry days in session for downstream checks (fallback to 90)
      $_SESSION['user']['password_expiration_days'] = isset($user['password_expiration_days']) ? intval($user['password_expiration_days']) : 90;

      // Top-level session keys used by department access helpers
      // Keep both `$_SESSION['user']['role']` and `$_SESSION['role']` in sync.
      $_SESSION['role'] = $_SESSION['user']['role'] ?? '';
      // Store department on login so filtering helpers can use it.
      $dept = '';
      if (isset($user['department'])) $dept = $user['department'];
      if (!$dept && isset($user['department_name'])) $dept = $user['department_name'];
      // If user has department_id (older schema), resolve department_name from departments table
      if (!$dept && isset($user['department_id']) && !empty($user['department_id'])) {
        try {
          $dstmt = $conn->prepare("SELECT department_name FROM departments WHERE department_id = ? LIMIT 1");
          if ($dstmt) {
            $did = (int)$user['department_id'];
            $dstmt->bind_param('i', $did);
            $dstmt->execute();
            $dres = $dstmt->get_result();
            $drow = $dres ? $dres->fetch_assoc() : null;
            if ($drow && isset($drow['department_name'])) $dept = $drow['department_name'];
            $dstmt->close();
          }
        } catch (Throwable $_) { /* ignore */ }
      }
      $dept = (string)($dept ?? '');
      $_SESSION['department'] = $dept;
      $_SESSION['user']['department'] = $dept;

      // update last_login timestamp for this user (centralized helper)
      try {
        if (function_exists('update_last_login')) {
          update_last_login($conn, $user['id']);
        } else {
          // fallback: attempt prepared statement directly
          $lu = $conn->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
          if ($lu) { $uidx = (int)$user['id']; $lu->bind_param('i', $uidx); $lu->execute(); $lu->close(); }
        }
      } catch (Throwable $e) { /* ignore update failures */ }

      // If role name isn't present (old users.role column dropped), but role_id is present,
      // fetch the role_name from roles table and set it in session for backward compatibility.
      if (empty($_SESSION['user']['role']) && !empty($_SESSION['user']['role_id'])) {
        try {
          $rstmt = $conn->prepare("SELECT role_name FROM roles WHERE role_id = ? LIMIT 1");
          if ($rstmt) {
            $rid = (int)$_SESSION['user']['role_id'];
            $rstmt->bind_param('i', $rid);
            $rstmt->execute();
            $rres = $rstmt->get_result();
            if ($rres) {
              $rrow = $rres->fetch_assoc();
              if ($rrow && isset($rrow['role_name'])) {
                $_SESSION['user']['role'] = $rrow['role_name'];
                $role_name = $rrow['role_name'];
              }
            }
            $rstmt->close();
          }
        } catch (Throwable $e) {
          // ignore — leave role as null if query fails
        }
      }

      // store scope and department_id in session for filtering helpers
      $_SESSION['user']['scope'] = (!empty($user['scope']) && $user['scope'] === 'global') ? 'global' : 'local';
      if (!empty($user['department_id'])) $_SESSION['user']['department_id'] = (int)$user['department_id'];

        // load access keys for this user's role (prefer role_id when available)
        try {
            $access_keys = [];
            if ($role_id) {
                $stmt2 = $conn->prepare("SELECT ar.access_key FROM role_access_rights rar JOIN access_rights ar ON rar.access_id = ar.access_id WHERE rar.role_id = ?");
                if ($stmt2) {
                    $stmt2->bind_param('i', $role_id);
                    $stmt2->execute();
                    $res2 = $stmt2->get_result();
                    while ($row = $res2->fetch_assoc()) {
                        $access_keys[] = $row['access_key'];
                    }
                    $stmt2->close();
                }
            } else {
                $stmt2 = $conn->prepare("SELECT ar.access_key FROM role_access_rights rar JOIN roles r ON rar.role_id = r.role_id JOIN access_rights ar ON rar.access_id = ar.access_id WHERE r.role_name = ?");
                if ($stmt2) {
                    $stmt2->bind_param('s', $role_name);
                    $stmt2->execute();
                    $res2 = $stmt2->get_result();
                    while ($row = $res2->fetch_assoc()) {
                        $access_keys[] = $row['access_key'];
                    }
                    $stmt2->close();
                }
            }
            // Ensure top-level session role mirrors updated user role for helpers
            $_SESSION['role'] = $_SESSION['user']['role'] ?? '';

            $_SESSION['user']['access_keys'] = $access_keys;
        } catch (Throwable $e) {
            // ignore — session will simply not have access_keys
        }
        // If account is marked for password reset, redirect to password set page
        if (!empty($_SESSION['user']['force_password_reset'])) {
            header("Location: set_password.php");
            exit;
        }
        header("Location: dashboard.php");
        exit;
    } else {
      // Generic error message for invalid credentials or deactivated accounts.
      // Removed debug behavior that disclosed a user's name on failed password attempts.
      $error = "Invalid credentials or account deactivated.";
    }
  }

?>

<?php $pageTitle = 'Login | Talent ARP'; ?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($pageTitle ?? 'App') ?></title>
    <?php $ver = @filemtime(__DIR__ . '/styles/login.css') ?: time(); ?>
    <link rel="stylesheet" href="styles/login.css?v=<?php echo $ver; ?>">
    <!-- Favicons for login page (ensure browsers pick up the white logo instead of default PHP icon) -->
    <link rel="icon" type="image/png" href="bugatti-logo.php" sizes="64x64">
    <link rel="icon" type="image/png" href="bugatti-logo.php" sizes="128x128">
    <link rel="alternate icon" type="image/webp" href="assets/White-Bugatti-Logo.webp" sizes="64x64">
</head>
<body>

<div class="login-image" aria-hidden="true"></div>
<div class="login-layout">
    
    <div class="login-left">
        <div class="login-logo-wrap">
          <img src="assets/White-Bugatti-Logo.webp" alt="Company logo" class="login-logo" style="width:150px;height:auto;" onerror="this.style.display='none'" />
        </div>
        <div class="login-title">Talent Acquisition</div>
        <div class="login-subtitle">Recruitment Platform</div>
        <div class="login-quote">HELPING YOU HIRE WONDERFUL PEOPLE</div>
    </div>

    <div class="container">
        <h2>Login to your account</h2>
        <form method="POST" id="loginForm" autocomplete="on">
            <?php if (!empty($error)) echo "<p style='color:red;'>$error</p>"; ?>

            <div class="field">
              <label class="field-label" for="email">Email or Username</label>
              <input type="text" id="email" name="email" placeholder="Enter your email or username" required autocomplete="username" />
              <div class="input-hint" id="emailHint" style="color: white;"></div>
            </div>

            <div class="field" style="position:relative;">
                <label class="field-label" for="password">Password</label>
                <div class="input-with-toggle" style="position:relative;">
                  <input type="password" name="password" id="password" placeholder="Enter your password" required autocomplete="current-password" style="max-width: 100%; min-width: -webkit-fill-available; padding-right:42px;" />
                  <!-- Interactive SVG placed directly so it doesn't block input focus; styled to sit inside the field -->
                  <svg id="pwdToggleIcon" role="button" tabindex="0" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-pressed="false" aria-label="Show password" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--muted);background:transparent;padding:4px;border-radius:4px;">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                </div>
                <div class="input-hint" id="pwdHint" style="color: white;"></div>
            </div>

            <button type="submit">Login</button>
        </form>

        <div style="margin-top:12px; font-size:13px; color:var(--muted);">
          <a href="#" id="forgotLink">Forgot password?</a>
        </div>
    </div>

    
</div>

<div class="footer">
    <p>&copy; 2025 Recruitment Platform. All rights reserved.</p>
    <p>Developed by <a href="https://github.com/piscezim46" target="_blank">Hayder Khder</a></p>
</div>

<script>
/* Improved Caps Lock & Arabic detection for form inputs */
(function(){
  // returns true if string contains any Arabic character
  // Use explicit Unicode ranges for broad Arabic script coverage
  function hasArabic(s){
    return /[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]/.test(String(s || ''));
  }

  function setHint(hintEl, msg, type){
    if(!hintEl) return;
    hintEl.textContent = msg || '';
    hintEl.classList.remove('warn','error','visible');
    if (msg) {
      hintEl.classList.add('warn');
      if(type === 'error') hintEl.classList.add('error');
      hintEl.classList.add('visible');
    }
  }

  // heuristic fallback when getModifierState not available:
  // if there are letters and they are all uppercase -> likely caps on
  function capsFallback(inputEl){
    try {
      const v = String(inputEl.value || '');
      const letters = v.replace(/[^A-Za-z]/g,'');
      if (!letters) return false;
      return letters === letters.toUpperCase() && letters !== letters.toLowerCase();
    } catch(e){ return false; }
  }

  // monitor inputs
  var email = document.getElementById('email');
  var pwd = document.getElementById('password');
  var monitors = [email, pwd].filter(Boolean);

  monitors.forEach(function(el){
    var hintId = el.id === 'email' ? 'emailHint' : 'pwdHint';
    var hintEl = document.getElementById(hintId);

    function evaluate(e){
      var value = el.value || '';
      // Arabic detection (strong)
      if (hasArabic(value + (e && e.key ? e.key : ''))) {
        setHint(hintEl, 'You are typing in Arabic — change keyboard language if unintended', 'warn');
        return;
      }

      // Caps detection via getModifierState if available
      var caps = false;
      if (e && typeof e.getModifierState === 'function') {
        try { caps = e.getModifierState('CapsLock'); } catch(err){ caps = false; }
      }
      // fallback heuristic if getModifierState unavailable or not conclusive
      if (!caps) caps = capsFallback(el);

      if (caps && el === pwd) {
        setHint(hintEl, 'Caps Lock is ON', 'warn');
      } else {
        // clear hint
        setHint(hintEl, '');
      }
    }

    // events
    el.addEventListener('keydown', evaluate, false);
    el.addEventListener('keyup', evaluate, false);
    el.addEventListener('input', evaluate, false);
    el.addEventListener('focus', function(e){
      // show caps state on focus if detectable
      if (e.getModifierState && e.getModifierState('CapsLock')) setHint(hintEl, 'Caps Lock is ON', 'warn');
      else if (capsFallback(el) && el === pwd) setHint(hintEl, 'Caps Lock is ON', 'warn');
    }, false);
    el.addEventListener('blur', function(){ setHint(hintEl, ''); }, false);
  });
})();
</script>
<script>
// Show/Hide password toggle (kept inside the input field, no layout changes)
document.addEventListener('DOMContentLoaded', function(){
  var input = document.getElementById('password');
  var icon = document.getElementById('pwdToggleIcon');
  if (!input || !icon) return;

  // Toggle handler used by both click on SVG and keyboard activation on SVG
  function togglePasswordVisibility(e) {
    if (e && e.preventDefault) e.preventDefault();
    var isPwd = input.getAttribute('type') === 'password';
    if (isPwd) {
      input.setAttribute('type','text');
      icon.setAttribute('aria-pressed','true');
      icon.setAttribute('aria-label','Hide password');
      icon.setAttribute('title','Hide password');
      // switch to eye-off icon
      icon.innerHTML = '<path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a20.3 20.3 0 0 1 5.06-6.06"/><path d="M1 1l22 22"/>';
    } else {
      input.setAttribute('type','password');
      icon.setAttribute('aria-pressed','false');
      icon.setAttribute('aria-label','Show password');
      icon.setAttribute('title','Show password');
      // switch to eye icon
      icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"></path><circle cx="12" cy="12" r="3"></circle>';
    }
    try { input.focus(); } catch(e){}
  }

  // Only pointer events on SVG itself; button has pointer-events:none so clicks fall through except on icon
  icon.addEventListener('click', togglePasswordVisibility);
  icon.addEventListener('keydown', function(e){
    if (e.key === 'Enter' || e.key === ' ') { togglePasswordVisibility(e); }
  });
});
</script>
<script>
// Show top-right notify for forgot password guidance
document.addEventListener('DOMContentLoaded', function(){
  var forgot = document.getElementById('forgotLink');
  if (!forgot) return;
  forgot.addEventListener('click', function(e){
    e.preventDefault();

    // Ensure a notify container exists at top-right
    var container = document.querySelector('.notify-top-right');
    if (!container) {
      container = document.createElement('div');
      container.className = 'notify-top-right';
      document.body.appendChild(container);
    }

    // Create toast element (reuses center-notify visual language)
    var toast = document.createElement('div');
    toast.className = 'center-notify';
    toast.setAttribute('role','status');
    toast.setAttribute('aria-live','polite');

    // Minimal body: message and small hint. Remove large button to avoid increasing toast height.
    toast.innerHTML = '\n      <div class="center-notify__body">\n        <div>\n          <div class="center-notify__title">Password reset instructions</div>\n          <div class="center-notify__msg">For security reasons, password resets must be handled by your administrator. Click this notification to email the admin at master@talentarp.com.</div>\n        </div>\n        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">\n          <div class="center-notify__hint" aria-hidden="true" style="font-size:12px;color:rgba(255,255,255,0.85)">Click to contact admin</div>\n        </div>\n      </div>\n      <div class="center-notify__progress" style="--duration:10s;"></div>\n    ';

    container.appendChild(toast);

    // show transition
    window.requestAnimationFrame(function(){
      toast.classList.add('show');
      var bar = toast.querySelector('.center-notify__progress');
      if (bar) bar.style.setProperty('--duration', '10s');
    });

    var cleanup = function(){
      try { toast.classList.remove('show'); } catch(e){}
      setTimeout(function(){ if (toast && toast.parentNode) toast.parentNode.removeChild(toast); }, 320);
    };

    // auto-dismiss after 10s
    var to = setTimeout(function(){ cleanup(); }, 10000);

    // clear timeout if user interacts with the toast
    toast.addEventListener('mouseenter', function(){ clearTimeout(to); toast.style.filter = 'brightness(0.98)'; });
    toast.addEventListener('mouseleave', function(){ to = setTimeout(function(){ cleanup(); }, 6000); toast.style.filter = ''; });

    // make the entire toast clickable and open mail client to contact admin
    try {
      toast.style.cursor = 'pointer';
      toast.addEventListener('click', function(e){
        // avoid interfering if user selected text inside toast
        try { clearTimeout(to); } catch(_){}
        var userEmail = document.getElementById('email') ? (document.getElementById('email').value || '') : '';
        var subject = encodeURIComponent('Password reset request');
        var body = encodeURIComponent('Please assist with a password reset for: ' + (userEmail || '[please provide user email]') + '\n\nThank you.');
        // use window.location to trigger default mail client
        window.location.href = 'mailto:master@talentarp.com?subject=' + subject + '&body=' + body;
        // cleanup toast after launching mail client
        setTimeout(function(){ cleanup(); }, 300);
      });
    } catch(err) { console.warn('failed to attach mailto handler', err); }
  });
});
</script>
<style>
/* ensure hints are readable and visible */
.input-hint { font-size:13px; color:var(--muted); min-height:18px; transition:opacity .12s ease; opacity: .9; }
.input-hint.warn { color: #ffd27a; font-weight:700; opacity: 1; }
.input-hint.warn.error { color: #ff6b6b; }
.input-hint.visible { opacity: 1; }
.footer {
    position: fixed;
    right: 32px;
    bottom: 24px;
    text-align: right;
    color: #fff;
    font-size: 14px;
    z-index: 10;
    background: transparent;
}
.footer a {
    color: #ffffffff;
    text-decoration: underline;
}
</style>
 
