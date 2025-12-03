<?php
session_start();
require_once '../includes/db.php';
// central user utilities for safe timestamp updates
if (file_exists(__DIR__ . '/../includes/user_utils.php')) require_once __DIR__ . '/../includes/user_utils.php';

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

    if ($user && password_verify($password, $user['password'])) {
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

        // as a fallback, admin gets all access (handle both role_name and role_id mapping)
        $is_admin = ($role_name === 'admin');
        if (!$is_admin && $role_id) {
          $rstmt = $conn->prepare("SELECT role_name FROM roles WHERE role_id = ? LIMIT 1");
          if ($rstmt) {
            $rstmt->bind_param('i', $role_id);
            $rstmt->execute();
            $rres = $rstmt->get_result()->fetch_assoc();
            if ($rres && isset($rres['role_name']) && $rres['role_name'] === 'admin') $is_admin = true;
            $rstmt->close();
          }
        }

        if (empty($access_keys) && $is_admin) {
          $rk = $conn->query("SELECT access_key FROM access_rights");
          if ($rk) {
            while ($rr = $rk->fetch_assoc()) $access_keys[] = $rr['access_key'];
            $rk->free();
          }
        }
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
        $error = "Invalid credentials or account deactivated.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Talent Acquisition RP | Login</title>
    <?php $ver = @filemtime(__DIR__ . '/styles/login.css') ?: time(); ?>
    <link rel="stylesheet" href="styles/login.css?v=<?php echo $ver; ?>">
    <!-- Favicons for login page (ensure browsers pick up the white logo instead of default PHP icon) -->
    <link rel="icon" type="image/webp" href="assets/White-Bugatti-Logo.webp" sizes="64x64">
    <link rel="icon" type="image/webp" href="assets/White-Bugatti-Logo.webp" sizes="128x128">
    <link rel="icon" type="image/png" href="assets/White-Bugatti-Logo.png" sizes="64x64">
    <link rel="icon" type="image/png" href="assets/White-Bugatti-Logo.png" sizes="128x128">
</head>
<body>

<div class="login-image" aria-hidden="true"></div>
<div class="login-layout">
    
    <div class="login-left">
        <div class="login-logo-wrap">
          <div class="login-logo" role="img" aria-label="Company logo"></div>
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

            <div class="field">
                <label class="field-label" for="password">Password</label>
                <input type="password" name="password" id="password" placeholder="Enter your password" required autocomplete="current-password" />
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

    toast.innerHTML = '\n+      <div class="center-notify__body">\n+        <div>\n+          <div class="center-notify__title">Password reset instructions</div>\n+          <div class="center-notify__msg">For security reasons, password resets must be handled by your administrator. Please contact the admin team at rp-support@rp.com to request a password reset. They will verify your identity and assist you promptly.</div>\n+        </div>\n+        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">\n+          <button class="center-notify__close" aria-label="Close">\n+            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">\n+              <path d="M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n+              <path d="M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>\n+            </svg>\n+          </button>\n+        </div>\n+      </div>\n+      <div class="center-notify__progress" style="--duration:10s;"></div>\n+    ';

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

    var closeBtn = toast.querySelector('.center-notify__close');
    if (closeBtn) closeBtn.addEventListener('click', function(){ cleanup(); });

    // auto-dismiss after 10s
    var to = setTimeout(function(){ cleanup(); }, 10000);

    // clear timeout if user interacts with the toast
    toast.addEventListener('mouseenter', function(){ clearTimeout(to); });
    toast.addEventListener('mouseleave', function(){ to = setTimeout(function(){ cleanup(); }, 6000); });
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
 
