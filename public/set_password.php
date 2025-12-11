<?php
// Start session only if it's not already active
if (function_exists('session_status')) {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
} else {
  @session_start();
}
require_once __DIR__ . '/../includes/db.php';

$pageTitle = 'Set New Password';

// only allow access if user is logged in and flagged for password reset
if (!isset($_SESSION['user']) || empty($_SESSION['user']['id']) || empty($_SESSION['user']['force_password_reset'])) {
    header('Location: index.php');
    exit;
}

$userName = htmlspecialchars($_SESSION['user']['name'] ?? '');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($pageTitle ?? 'App') ?></title>
    <link rel="stylesheet" href="styles/login.css">
    <!-- Favicons (match login page) -->
    <link rel="icon" type="image/png" href="bugatti-logo.php" sizes="64x64">
    <link rel="icon" type="image/png" href="bugatti-logo.php" sizes="128x128">
    <link rel="alternate icon" type="image/webp" href="assets/White-Bugatti-Logo.webp" sizes="64x64">
</head>
<body>
<div class="login-layout">
  <div class="login-left">
    <div class="login-logo-wrap">
      <div class="login-logo" aria-hidden="true"></div>
    </div>
    <h2 class="login-title">Set Your New Password</h2>
    <p class="login-subtitle">Hello, <?= $userName ?>. For your account security please choose a new password. It must meet the password requirements below and must not match your previous password.</p>
    <div style="margin-top:18px;color:var(--muted);font-size:14px;">
      <strong>Password requirements:</strong>
      <ul style="margin:8px 0 0 18px;color:var(--muted);">
        <li>Minimum 6 characters</li>
        <li>At least one uppercase letter (A–Z)</li>
        <li>At least one number (0–9)</li>
        <li>At least one symbol (e.g. !@#$%^&amp;*)</li>
      </ul>
      <div style="margin-top:8px;"><strong>Tips:</strong>
        <ul style="margin:6px 0 0 18px;color:var(--muted);">
          <li>Avoid reusing old passwords.</li>
          <li>Keep it memorable but secure.</li>
        </ul>
      </div>
    </div>
  </div>
  <div class="container">
    <form id="setPwdForm">
      <div class="field">
        <label for="pwd1">New password</label>
        <input id="pwd1" name="password" type="password" required minlength="6" />
      </div>
      <div class="field">
        <label for="pwd2">Confirm new password</label>
        <input id="pwd2" name="password_confirm" type="password" required minlength="6" />
      </div>
      <div id="msg" style="color:#ef4444;min-height:18px;margin-bottom:8px;"></div>
      <div style="display:flex;gap:8px;">
        <button id="savePwdBtn" type="submit" class="btn btn-orange">Save password</button>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('setPwdForm').addEventListener('submit', async function(e){
  e.preventDefault();
  var p1 = document.getElementById('pwd1').value || '';
  var p2 = document.getElementById('pwd2').value || '';
  var msgEl = document.getElementById('msg');
  var btn = document.getElementById('savePwdBtn');
  // client-side policy: minimum 6 chars, 1 uppercase, 1 number, 1 symbol
  if (p1.length < 6) { msgEl.textContent = 'Password must be at least 6 characters'; return; }
  if (!/[A-Z]/.test(p1)) { msgEl.textContent = 'Password must include at least one uppercase letter (A-Z)'; return; }
  if (!/[0-9]/.test(p1)) { msgEl.textContent = 'Password must include at least one number (0-9)'; return; }
  if (!/[^A-Za-z0-9]/.test(p1)) { msgEl.textContent = 'Password must include at least one symbol (e.g. !@#$%)'; return; }
  if (p1 !== p2) { msgEl.textContent = 'Passwords do not match'; return; }
  msgEl.textContent = '';
  try {
    // disable submit to prevent duplicate requests
    if (btn) { btn.disabled = true; btn.dataset.orig = btn.textContent; btn.textContent = 'Saving...'; }
    var res = await fetch('update_password.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ password: p1 }) });
    var txt = await res.text();
    try { var json = JSON.parse(txt); } catch(e){ json = null; }
    if (!res.ok) {
      msgEl.textContent = (json && json.error) ? json.error : 'Request failed';
      if (btn) { btn.disabled = false; btn.textContent = btn.dataset.orig || 'Save password'; }
      return;
    }
    if (json && json.success) {
      // replace session flag on client side by reloading to dashboard
      window.location.href = 'dashboard.php';
    } else {
      msgEl.textContent = (json && json.error) ? json.error : 'Unknown error';
      if (btn) { btn.disabled = false; btn.textContent = btn.dataset.orig || 'Save password'; }
    }
  } catch (err) {
    console.error(err);
    msgEl.textContent = 'Request failed';
    if (btn) { btn.disabled = false; btn.textContent = btn.dataset.orig || 'Save password'; }
  }
});
</script>
</body>
</html>