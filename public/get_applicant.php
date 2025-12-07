<?php
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: text/html; charset=utf-8');
// Start session only if not already active to avoid PHP notice when this
// fragment is loaded via AJAX inside pages that already started a session
if (function_exists('session_status')) {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
} else {
  // fallback for older PHP versions
  @session_start();
}

$id = isset($_GET['applicant_id']) ? intval($_GET['applicant_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
if (!$id) { echo '<div class="error">Missing applicant_id</div>'; exit; }

$sql = "SELECT a.*, COALESCE(s.status_name,'') AS status_name FROM applicants a LEFT JOIN applicants_status s ON a.status_id = s.status_id LEFT JOIN positions p ON a.position_id = p.id WHERE a.applicant_id = ? LIMIT 1";
 $stmt = $conn->prepare($sql);
 if (!$stmt) { echo '<div class="error">Prepare failed: '.htmlspecialchars($conn->error).'</div>'; exit; }
 $stmt->bind_param('i', $id);
 $stmt->execute();
 $res = $stmt->get_result();
 $app = $res ? $res->fetch_assoc() : null;
 $stmt->close();

if (!$app) { echo '<div class="error">Applicant not found</div>'; exit; }

// Normalise resume path stored in DB and prepare safe URLs for link/iframe
$raw_resume = isset($app['resume_file']) ? (string)$app['resume_file'] : '';
$resume_url = $raw_resume;
// If the stored value is just a filename (no uploads/ prefix), prepend the public uploads folder
if ($resume_url !== '' && strpos($resume_url, 'uploads/') !== 0) {
  $resume_url = 'uploads/applicants/' . ltrim($resume_url, '/');
}
// Encode each path segment for safe insertion into href/src while preserving slashes
$resume_href = '';
if ($resume_url !== '') {
  $parts = explode('/', $resume_url);
  $parts = array_map('rawurlencode', $parts);
  $resume_href = implode('/', $parts);
}

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

// Status color helpers (small palette to match positions styling)
function status_text_color($hex) {
  $hex = ltrim($hex, '#');
  if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
  $r = hexdec(substr($hex,0,2));
  $g = hexdec(substr($hex,2,2));
  $b = hexdec(substr($hex,4,2));
  $luma = (0.299*$r + 0.587*$g + 0.114*$b);
  return ($luma > 186) ? '#111111' : '#ffffff';
}

function status_palette_color($name) {
  // Applicant-specific palette: intentionally different shades from positions
  $k = strtolower(trim((string)$name));
  switch ($k) {
    case 'applicants active': return '#2563EB'; // deeper blue
    case 'approve': return '#059669'; // teal-green
    case 'complete': return '#475569'; // darker slate
    case 'created': return '#4B5563'; // neutral
    case 'hire confirmed': return '#0F766E'; // deep teal
    case 'hire partially confirmed': return '#D97706'; // amber-dark
    case 'hiring active': return '#1E40AF'; // indigo
    case 'interviews active': return '#0891B2'; // cyan-teal
    case 're-open':
    case 'reopen': return '#1E3A8A'; // indigo-strong
    case 'rejected': return '#B91C1C'; // darker red
    case 'send for approval': return '#C2410C'; // rust-orange
    case 'short-close':
    case 'short close': return '#64748B'; // slate
    default: return '#374151'; // fallback neutral
  }
}

// compute badge colors for this applicant status — prefer DB-defined color if present
$app_status_name = trim((string)($app['status_name'] ?? ''));
$app_status_color = '#6B7280';
$status_map = [];
$sr = $conn->query("SELECT status_id, status_name, COALESCE(status_color,'') AS status_color FROM applicants_status");
if ($sr) {
  while ($s = $sr->fetch_assoc()) {
    $status_map[(int)$s['status_id']] = $s;
  }
  $sr->free();
}
if (isset($status_map[(int)$app['status_id']]) && !empty($status_map[(int)$app['status_id']]['status_color'])) {
  $app_status_color = $status_map[(int)$app['status_id']]['status_color'];
} else {
  $app_status_color = status_palette_color($app_status_name);
}
$app_status_text_color = status_text_color($app_status_color);

ob_start();
?>
<div id="applicantTicket" class="applicant-ticket" style="color:#ddd;font-family:Inter,Arial,Helvetica,sans-serif;">
  <?php
  // Load related position info (if any) so we can show a small top bar with quick access
  $position = null;
  $position_title = '';
  $position_dept = '';
  $position_team = '';
  $position_manager = '';
  $position_id = 0;
  if (!empty($app['position_id'])) {
    $position_id = (int)$app['position_id'];
    $pstmt = $conn->prepare('SELECT id, title, department, team, manager_name FROM positions WHERE id = ? LIMIT 1');
    if ($pstmt) {
      $pstmt->bind_param('i', $position_id);
      $pstmt->execute();
      $pres = $pstmt->get_result();
      $position = $pres ? $pres->fetch_assoc() : null;
      $pstmt->close();
    }
    if ($position) {
      $position_title = $position['title'] ?? '';
      $position_dept = $position['department'] ?? '';
      $position_team = $position['team'] ?? '';
      $position_manager = $position['manager_name'] ?? '';
    }
  }
  ?>
  <style>
    .app-ticket { background: linear-gradient(180deg,#262626,#1f1f1f); border:1px solid rgba(255,255,255,0.03); border-left:5px solid #3B82F6; padding:18px; border-radius:12px; box-shadow:none; width:100%; box-sizing:border-box; }
    /* When rendered inside a modal .modal-card (viewer), remove the inner framing to avoid double borders/shadows */
    .modal-card .app-ticket { background: transparent !important; border: 0 !important; box-shadow: none !important; padding: 12px 0 !important; }
    .app-ticket .head { display:flex; justify-content:space-between; gap:8px; align-items:flex-start; }
    .app-ticket .title { font-size:18px; font-weight:700; color:#fff; }
    .app-ticket .status { font-weight:700; padding:6px 10px; border-radius:999px; background:#374151; color:#fff; }
    /* status badge style (match positions list look) */
    .status-badge { display:inline-block; padding:6px 10px; border-radius:999px; font-weight:700; font-size:13px; box-shadow: none; }
     /* Use the same close-button styling as the Positions modal to ensure
       consistent appearance and accessible focus/hover states. Avoid using
       the site accent as background so the X remains visible on dark modals. */
     .modal-close-x { appearance:none; border:1px solid transparent; background:transparent; color: var(--text-muted, #9aa4b2); font-size:22px; line-height:1; cursor:pointer; padding:6px 8px; border-radius:6px; }
     .modal-close-x:hover, .modal-close-x:focus { background: rgba(0,0,0,0.04); color: var(--text-main, #111827); outline: none; box-shadow: 0 0 0 3px rgba(53,156,246,0.06); }

    /* Keep profile/details and resume side-by-side on wide viewports */
    .app-lr { display:flex; gap:18px; margin-top:12px; align-items:flex-start; flex-wrap:nowrap; }
    /* Left column (profile + details) increased to 45% so resume takes the remaining 55% */
    .app-left { flex:0 0 45%; min-width:220px; max-width:48%; }
    .app-right { flex:1 1 55%; min-width:320px; }

    .app-panel { display:flex; gap:16px; margin:0; align-items:flex-start; flex-wrap:wrap; }
    /* Make details take less width so resume iframe has room beside it */
    .app-profile { background:transparent; flex:0 0 60%; min-width:100%; border-radius:8px; padding:8px; box-sizing:border-box; }
    .app-details { background:transparent; flex:1 1 40%; min-width:100%; border-radius:8px; padding:8px; box-sizing:border-box; }

     /* Profile fields: force two columns (50% each) so fields appear two-per-line
       without changing the resume column. On small screens the media queries
       will collapse this to a single column. */
     .app-profile .fields { display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:10px; }
    /* Details remain flexible (auto-fit) */
    .app-details .fields { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:10px; }
    .app-profile .field, .app-details .field { margin:0; }
    .app-profile label, .app-details label { display:block; color:#9aa4b2; font-size:13px; margin-bottom:6px; }
    .app-profile input, .app-profile textarea, .app-details input, .app-details textarea, .app-profile select { width:100%; padding:10px; border-radius:8px; background:#0f1720; color:#fff; border:1px solid rgba(255,255,255,0.04); box-sizing:border-box; }

    /* Skills tag input */
    .skills-tags { display:flex; gap:8px; flex-wrap:wrap; margin:6px 0 8px 0; }
    .skill-chip { background:#0b1220; border:1px solid rgba(255,255,255,0.04); padding:6px 8px; border-radius:999px; color:#fff; font-size:13px; display:inline-flex; align-items:center; gap:8px; }
    .skill-chip .remove { background:transparent; border:0; color:#9aa4b2; cursor:pointer; font-weight:700; padding:0 4px; }
    #ap_skills_input { width:100%; padding:8px; border-radius:8px; background:#0f1720; color:#fff; border:1px solid rgba(255,255,255,0.04); box-sizing:border-box; }

     .app-profile .actions, .app-details .actions { display:flex; gap:8px; justify-content:flex-end; margin-top:8px; }
     /* Use site primary button styles for Edit / Save / Cancel so they match Create button
       and any shared button CSS in the page. Specific classes are added to the markup
       so these inherit the global `.btn.primary` / `.btn-primary` rules. */
     .btn-edit, .btn-save, .btn-cancel { border-radius:8px; padding:8px 12px; border:1px solid rgba(15,23,42,0.06); cursor:pointer; }
     .btn-edit { background: var(--accent, #359cf6); color: #fff; }
     .btn-save { background: var(--accent, #359cf6); color: #fff; }
     .btn-cancel { background: #374151; color: #fff; }
    .small-muted { color:#9aa4b2; font-size:13px; }

    .app-inline-notice { margin-top:8px; padding:8px; border-radius:8px; background:rgba(255,255,255,0.03); color:#ffd6d6; font-size:13px; }

    @media (max-width: 1100px) {
      .app-ticket { padding:14px; }
      /* allow wrapping on smaller screens */
      .app-lr { flex-wrap:wrap; }
      .app-left, .app-right { flex:1 1 100%; min-width:unset; }
      .app-profile .fields, .app-details .fields { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
    }
    @media (max-width: 700px) {
      .app-ticket { padding:12px; }
      .app-profile .fields, .app-details .fields { grid-template-columns: 1fr; }
      .btn-edit, .btn-save, .btn-cancel { padding:10px 12px; }
    }

    .app-right .resume-wrap { background:#0b0b0b; border:1px solid rgba(255,255,255,0.03); border-radius:10px; padding:8px; box-shadow:none; }

    /* interactive elevation for tickets on hover (white-based glow) */
     .app-ticket:hover { transform: translateY(-6px); box-shadow: 0 12px 34px var(--accent-shadow-strong); }
     /* When the applicant ticket is rendered inside a modal viewer, disable
       the hover elevation/transform so the modal content doesn't jump when
       the mouse moves outside the ticket area. */
     .modal-card .app-ticket:hover, .modal-overlay .modal-card .app-ticket:hover { transform: none !important; box-shadow: none !important; }
    .resume-toolbar { display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:8px; }
    .resume-toolbar .label { color:#cbd5e1; font-weight:700; }
    .resume-frame { width:100%; height:72vh; border:0; border-radius:6px; background:#ffffff; }
    /* top accumulation bar inside the applicant ticket */
    .ticket-topbar { background: rgba(255,255,255,0.01); border: 1px solid rgba(255,255,255,0.02); padding:10px 12px; border-radius:8px; }
    .open-position-link:hover { background: rgba(255,255,255,0.02); }
  </style>

  <div class="app-ticket">
    <div class="head">
      <div style="flex:1;min-width:0;">
        <div class="title">Applicant #<?php echo h($app['applicant_id']); ?></div>
        <div class="small-muted">Created: <?php echo h($app['created_at'] ?? ''); ?></div>
      </div>
      <div style="display:flex;align-items:center;gap:10px;">
        <div style="text-align:right;min-width:160px;">
          <div class="status-badge" id="appStatusBadge" style="background: <?php echo htmlspecialchars($app_status_color); ?>; color: <?php echo htmlspecialchars($app_status_text_color); ?>;"><?php echo h($app_status_name ?: ($app['status_name'] ?? '')); ?></div>
        </div>
        <div style="margin-left:6px;">
          <button type="button" class="modal-close-x" aria-label="Close">&times;</button>
        </div>
      </div>
    </div>

    <?php if (!empty($position_title)): ?>
      <div class="ticket-topbar" style="margin-top:12px;margin-bottom:12px;">
        <div class="position-meta" style="display:flex;gap:10px;align-items:center;">
          <div class="meta-item meta-clickable" data-position-id="<?php echo (int)$position_id; ?>">
            <div class="meta-field">
              <div class="meta-label">Applied Position</div>
              <div class="meta-value"><?php echo h($position_title); ?></div>
            </div>
            <div class="meta-field">
              <div class="meta-label">Department</div>
              <div class="meta-value"><?php echo h($position_dept ?: 'Unassigned'); ?></div>
            </div>
            <div class="meta-field">
              <div class="meta-label">Team</div>
              <div class="meta-value"><?php echo h($position_team ?: '—'); ?></div>
            </div>
            <div class="meta-field">
              <div class="meta-label">Manager</div>
              <div class="meta-value"><?php echo h($position_manager ?: 'Unassigned'); ?></div>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="app-lr">
      <div class="app-left">
        <div class="app-panel">
          <div class="app-profile">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <strong style="color:#fff;font-size:15px;">Profile</strong>
              <div class="actions">
                <button type="button" id="profileEditBtn" class="btn primary btn-primary btn-edit">Edit</button>
                <button type="button" id="profileSaveBtn" class="btn primary btn-primary btn-save" style="display:none;">Save</button>
                <button type="button" id="profileCancelBtn" class="btn primary btn-primary btn-cancel" style="display:none;">Cancel</button>
              </div>
            </div>

            <div class="fields" style="margin-top:8px;">
              <div class="field"><label>Full name</label><input id="ap_full_name" value="<?php echo h($app['full_name']); ?>" disabled></div>
              <div class="field"><label>Phone</label><input id="ap_phone" type="number" value="<?php echo h($app['phone']); ?>" disabled></div>
              <div class="field"><label>Email</label><input id="ap_email" type="email" value="<?php echo h($app['email']); ?>" disabled></div>
              <div class="field"><label>LinkedIn</label><input id="ap_linkedin" value="<?php echo h($app['linkedin'] ?? ''); ?>" disabled></div>
              <div class="field"><label>Age</label><input id="ap_age" type="number" min="0" value="<?php echo h($app['age'] ?? ''); ?>" disabled></div>
              <div class="field"><label>Gender</label>
                <select id="ap_gender" disabled>
                  <option value="">-- Select Gender --</option>
                  <option value="Male"<?php if (isset($app['gender']) && strcasecmp($app['gender'],'Male')===0) echo ' selected'; ?>>Male</option>
                  <option value="Female"<?php if (isset($app['gender']) && strcasecmp($app['gender'],'Female')===0) echo ' selected'; ?>>Female</option>
                  <option value="Unidentified"<?php if (isset($app['gender']) && strcasecmp($app['gender'],'Unidentified')===0) echo ' selected'; ?>>Unidentified</option>
                </select>
              </div>
              <div class="field"><label>Nationality</label>
                <select id="ap_nationality" disabled>
                  <option value="">-- Select Nationality --</option>
                  <option value="Afghan"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Afghan')===0) echo ' selected'; ?>>Afghan</option>
                  <option value="Albanian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Albanian')===0) echo ' selected'; ?>>Albanian</option>
                  <option value="Algerian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Algerian')===0) echo ' selected'; ?>>Algerian</option>
                  <option value="American"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'American')===0) echo ' selected'; ?>>American</option>
                  <option value="Andorran"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Andorran')===0) echo ' selected'; ?>>Andorran</option>
                  <option value="Angolan"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Angolan')===0) echo ' selected'; ?>>Angolan</option>
                  <option value="Antiguan or Barbudan"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Antiguan or Barbudan')===0) echo ' selected'; ?>>Antiguan or Barbudan</option>
                  <option value="Argentinian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Argentinian')===0) echo ' selected'; ?>>Argentinian</option>
                  <option value="Armenian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Armenian')===0) echo ' selected'; ?>>Armenian</option>
                  <option value="Australian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Australian')===0) echo ' selected'; ?>>Australian</option>
                  <option value="Austrian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Austrian')===0) echo ' selected'; ?>>Austrian</option>
                  <option value="Azerbaijani"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Azerbaijani')===0) echo ' selected'; ?>>Azerbaijani</option>
                  <option value="Bahamian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Bahamian')===0) echo ' selected'; ?>>Bahamian</option>
                  <option value="Bahraini"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Bahraini')===0) echo ' selected'; ?>>Bahraini</option>
                  <option value="Bangladeshi"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Bangladeshi')===0) echo ' selected'; ?>>Bangladeshi</option>
                  <option value="Barbadian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Barbadian')===0) echo ' selected'; ?>>Barbadian</option>
                  <option value="Belarusian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Belarusian')===0) echo ' selected'; ?>>Belarusian</option>
                  <option value="Belgian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Belgian')===0) echo ' selected'; ?>>Belgian</option>
                  <option value="Belizean"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Belizean')===0) echo ' selected'; ?>>Belizean</option>
                  <option value="Beninese"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Beninese')===0) echo ' selected'; ?>>Beninese</option>
                  <option value="Bhutanese"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Bhutanese')===0) echo ' selected'; ?>>Bhutanese</option>
                  <option value="Bolivian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Bolivian')===0) echo ' selected'; ?>>Bolivian</option>
                  <option value="Bosnian or Herzegovinian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Bosnian or Herzegovinian')===0) echo ' selected'; ?>>Bosnian or Herzegovinian</option>
                  <option value="Botswanan"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Botswanan')===0) echo ' selected'; ?>>Botswanan</option>
                  <option value="Brazilian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Brazilian')===0) echo ' selected'; ?>>Brazilian</option>
                  <option value="British"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'British')===0) echo ' selected'; ?>>British</option>
                  <option value="Bruneian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Bruneian')===0) echo ' selected'; ?>>Bruneian</option>
                  <option value="Bulgarian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Bulgarian')===0) echo ' selected'; ?>>Bulgarian</option>
                  <option value="Burkinabé"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Burkinabé')===0) echo ' selected'; ?>>Burkinabé</option>
                  <option value="Burundian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Burundian')===0) echo ' selected'; ?>>Burundian</option>
                  <option value="Cabo Verdean"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Cabo Verdean')===0) echo ' selected'; ?>>Cabo Verdean</option>
                  <option value="Cambodian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Cambodian')===0) echo ' selected'; ?>>Cambodian</option>
                  <option value="Cameroonian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Cameroonian')===0) echo ' selected'; ?>>Cameroonian</option>
                  <option value="Canadian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Canadian')===0) echo ' selected'; ?>>Canadian</option>
                  <option value="Central African"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Central African')===0) echo ' selected'; ?>>Central African</option>
                  <option value="Chadian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Chadian')===0) echo ' selected'; ?>>Chadian</option>
                  <option value="Chilean"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Chilean')===0) echo ' selected'; ?>>Chilean</option>
                  <option value="Chinese"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Chinese')===0) echo ' selected'; ?>>Chinese</option>
                  <option value="Colombian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Colombian')===0) echo ' selected'; ?>>Colombian</option>
                  <option value="Comoran"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Comoran')===0) echo ' selected'; ?>>Comoran</option>
                  <option value="Congolese (Congo-Brazzaville)"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Congolese (Congo-Brazzaville)')===0) echo ' selected'; ?>>Congolese (Congo-Brazzaville)</option>
                  <option value="Congolese (Congo-Kinshasa)"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Congolese (Congo-Kinshasa)')===0) echo ' selected'; ?>>Congolese (Congo-Kinshasa)</option>
                  <option value="Costa Rican"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Costa Rican')===0) echo ' selected'; ?>>Costa Rican</option>
                  <option value="Ivorian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Ivorian')===0) echo ' selected'; ?>>Ivorian</option>
                  <option value="Croatian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Croatian')===0) echo ' selected'; ?>>Croatian</option>
                  <option value="Cuban"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Cuban')===0) echo ' selected'; ?>>Cuban</option>
                  <option value="Cypriot"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Cypriot')===0) echo ' selected'; ?>>Cypriot</option>
                  <option value="Czech"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Czech')===0) echo ' selected'; ?>>Czech</option>
                  <option value="Danish"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Danish')===0) echo ' selected'; ?>>Danish</option>
                  <option value="Djiboutian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Djiboutian')===0) echo ' selected'; ?>>Djiboutian</option>
                  <option value="Dominican (Dominican Republic)"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Dominican (Dominican Republic)')===0) echo ' selected'; ?>>Dominican (Dominican Republic)</option>
                  <option value="Dominican (Dominica)"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Dominican (Dominica)')===0) echo ' selected'; ?>>Dominican (Dominica)</option>
                  <option value="Ecuadorian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Ecuadorian')===0) echo ' selected'; ?>>Ecuadorian</option>
                  <option value="Egyptian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Egyptian')===0) echo ' selected'; ?>>Egyptian</option>
                  <option value="Salvadoran"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Salvadoran')===0) echo ' selected'; ?>>Salvadoran</option>
                  <option value="Equatorial Guinean"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Equatorial Guinean')===0) echo ' selected'; ?>>Equatorial Guinean</option>
                  <option value="Eritrean"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Eritrean')===0) echo ' selected'; ?>>Eritrean</option>
                  <option value="Estonian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Estonian')===0) echo ' selected'; ?>>Estonian</option>
                  <option value="Eswatini"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Eswatini')===0) echo ' selected'; ?>>Eswatini</option>
                  <option value="Ethiopian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Ethiopian')===0) echo ' selected'; ?>>Ethiopian</option>
                  <option value="Fijian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Fijian')===0) echo ' selected'; ?>>Fijian</option>
                  <option value="Finnish"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Finnish')===0) echo ' selected'; ?>>Finnish</option>
                  <option value="French"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'French')===0) echo ' selected'; ?>>French</option>
                  <option value="Gabonese"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Gabonese')===0) echo ' selected'; ?>>Gabonese</option>
                  <option value="Gambian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Gambian')===0) echo ' selected'; ?>>Gambian</option>
                  <option value="Georgian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Georgian')===0) echo ' selected'; ?>>Georgian</option>
                  <option value="German"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'German')===0) echo ' selected'; ?>>German</option>
                  <option value="Ghanaian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Ghanaian')===0) echo ' selected'; ?>>Ghanaian</option>
                  <option value="Greek"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Greek')===0) echo ' selected'; ?>>Greek</option>
                  <option value="Grenadian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Grenadian')===0) echo ' selected'; ?>>Grenadian</option>
                  <option value="Guatemalan"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Guatemalan')===0) echo ' selected'; ?>>Guatemalan</option>
                  <option value="Guinean"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Guinean')===0) echo ' selected'; ?>>Guinean</option>
                  <option value="Guinean (Bissau)"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Guinean (Bissau)')===0) echo ' selected'; ?>>Guinean (Bissau)</option>
                  <option value="Guyanese"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Guyanese')===0) echo ' selected'; ?>>Guyanese</option>
                  <option value="Haitian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Haitian')===0) echo ' selected'; ?>>Haitian</option>
                  <option value="Holy See (Vatican)"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Holy See (Vatican)')===0) echo ' selected'; ?>>Holy See (Vatican)</option>
                  <option value="Honduran"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Honduran')===0) echo ' selected'; ?>>Honduran</option>
                  <option value="Hungarian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Hungarian')===0) echo ' selected'; ?>>Hungarian</option>
                  <option value="Icelander"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Icelander')===0) echo ' selected'; ?>>Icelander</option>
                  <option value="Indian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Indian')===0) echo ' selected'; ?>>Indian</option>
                  <option value="Indonesian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Indonesian')===0) echo ' selected'; ?>>Indonesian</option>
                  <option value="Iranian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Iranian')===0) echo ' selected'; ?>>Iranian</option>
                  <option value="Iraqi"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Iraqi')===0) echo ' selected'; ?>>Iraqi</option>
                  <option value="Irish"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Irish')===0) echo ' selected'; ?>>Irish</option>
                  <option value="Israeli"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Israeli')===0) echo ' selected'; ?>>Israeli</option>
                  <option value="Italian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Italian')===0) echo ' selected'; ?>>Italian</option>
                  <option value="Jamaican"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Jamaican')===0) echo ' selected'; ?>>Jamaican</option>
                  <option value="Japanese"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Japanese')===0) echo ' selected'; ?>>Japanese</option>
                  <option value="Jordanian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Jordanian')===0) echo ' selected'; ?>>Jordanian</option>
                  <option value="Kazakh"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Kazakh')===0) echo ' selected'; ?>>Kazakh</option>
                  <option value="Kenyan"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Kenyan')===0) echo ' selected'; ?>>Kenyan</option>
                  <option value="Kittitian or Nevisian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Kittitian or Nevisian')===0) echo ' selected'; ?>>Kittitian or Nevisian</option>
                  <option value="Kuwaiti"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Kuwaiti')===0) echo ' selected'; ?>>Kuwaiti</option>
                  <option value="Kyrgyz"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Kyrgyz')===0) echo ' selected'; ?>>Kyrgyz</option>
                  <option value="Lao"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Lao')===0) echo ' selected'; ?>>Lao</option>
                  <option value="Latvian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Latvian')===0) echo ' selected'; ?>>Latvian</option>
                  <option value="Lebanese"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Lebanese')===0) echo ' selected'; ?>>Lebanese</option>
                  <option value="Liberian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Liberian')===0) echo ' selected'; ?>>Liberian</option>
                  <option value="Libyan"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Libyan')===0) echo ' selected'; ?>>Libyan</option>
                  <option value="Liechtensteiner"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Liechtensteiner')===0) echo ' selected'; ?>>Liechtensteiner</option>
                  <option value="Lithuanian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Lithuanian')===0) echo ' selected'; ?>>Lithuanian</option>
                  <option value="Luxembourger"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Luxembourger')===0) echo ' selected'; ?>>Luxembourger</option>
                  <option value="Macedonian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Macedonian')===0) echo ' selected'; ?>>Macedonian</option>
                  <option value="Malagasy"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Malagasy')===0) echo ' selected'; ?>>Malagasy</option>
                  <option value="Malawian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Malawian')===0) echo ' selected'; ?>>Malawian</option>
                  <option value="Malaysian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Malaysian')===0) echo ' selected'; ?>>Malaysian</option>
                  <option value="Maldivian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Maldivian')===0) echo ' selected'; ?>>Maldivian</option>
                  <option value="Malian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Malian')===0) echo ' selected'; ?>>Malian</option>
                  <option value="Maltese"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Maltese')===0) echo ' selected'; ?>>Maltese</option>
                  <option value="Marshallese"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Marshallese')===0) echo ' selected'; ?>>Marshallese</option>
                  <option value="Mauritanian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Mauritanian')===0) echo ' selected'; ?>>Mauritanian</option>
                  <option value="Mauritian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Mauritian')===0) echo ' selected'; ?>>Mauritian</option>
                  <option value="Mexican"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Mexican')===0) echo ' selected'; ?>>Mexican</option>
                  <option value="Micronesian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Micronesian')===0) echo ' selected'; ?>>Micronesian</option>
                  <option value="Moldovan"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Moldovan')===0) echo ' selected'; ?>>Moldovan</option>
                  <option value="Monégasque"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Monégasque')===0) echo ' selected'; ?>>Monégasque</option>
                  <option value="Mongolian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Mongolian')===0) echo ' selected'; ?>>Mongolian</option>
                  <option value="Montenegrin"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Montenegrin')===0) echo ' selected'; ?>>Montenegrin</option>
                  <option value="Moroccan"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Moroccan')===0) echo ' selected'; ?>>Moroccan</option>
                  <option value="Mozambican"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Mozambican')===0) echo ' selected'; ?>>Mozambican</option>
                  <option value="Myanmar"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Myanmar')===0) echo ' selected'; ?>>Myanmar</option>
                  <option value="Namibian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Namibian')===0) echo ' selected'; ?>>Namibian</option>
                  <option value="Nauruan"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Nauruan')===0) echo ' selected'; ?>>Nauruan</option>
                  <option value="Nepalese"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Nepalese')===0) echo ' selected'; ?>>Nepalese</option>
                  <option value="Dutch"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Dutch')===0) echo ' selected'; ?>>Dutch</option>
                  <option value="New Zealander"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'New Zealander')===0) echo ' selected'; ?>>New Zealander</option>
                  <option value="Nicaraguan"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Nicaraguan')===0) echo ' selected'; ?>>Nicaraguan</option>
                  <option value="Nigerien"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Nigerien')===0) echo ' selected'; ?>>Nigerien</option>
                  <option value="North Korean"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'North Korean')===0) echo ' selected'; ?>>North Korean</option>
                  <option value="Northern Irish"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Northern Irish')===0) echo ' selected'; ?>>Northern Irish</option>
                  <option value="Norwegian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Norwegian')===0) echo ' selected'; ?>>Norwegian</option>
                  <option value="Omani"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Omani')===0) echo ' selected'; ?>>Omani</option>
                  <option value="Pakistani"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Pakistani')===0) echo ' selected'; ?>>Pakistani</option>
                  <option value="Palauan"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Palauan')===0) echo ' selected'; ?>>Palauan</option>
                  <option value="Panamanian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Panamanian')===0) echo ' selected'; ?>>Panamanian</option>
                  <option value="Papua New Guinean"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Papua New Guinean')===0) echo ' selected'; ?>>Papua New Guinean</option>
                  <option value="Paraguayan"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Paraguayan')===0) echo ' selected'; ?>>Paraguayan</option>
                  <option value="Peruvian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Peruvian')===0) echo ' selected'; ?>>Peruvian</option>
                  <option value="Philippine"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Philippine')===0) echo ' selected'; ?>>Philippine</option>
                  <option value="Polish"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Polish')===0) echo ' selected'; ?>>Polish</option>
                  <option value="Portuguese"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Portuguese')===0) echo ' selected'; ?>>Portuguese</option>
                  <option value="Qatari"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Qatari')===0) echo ' selected'; ?>>Qatari</option>
                  <option value="Romanian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Romanian')===0) echo ' selected'; ?>>Romanian</option>
                  <option value="Russian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Russian')===0) echo ' selected'; ?>>Russian</option>
                  <option value="Rwandan"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Rwandan')===0) echo ' selected'; ?>>Rwandan</option>
                  <option value="Saint Lucian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Saint Lucian')===0) echo ' selected'; ?>>Saint Lucian</option>
                  <option value="Salvadoran"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Salvadoran')===0) echo ' selected'; ?>>Salvadoran</option>
                  <option value="Samoan"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Samoan')===0) echo ' selected'; ?>>Samoan</option>
                  <option value="San Marinese"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'San Marinese')===0) echo ' selected'; ?>>San Marinese</option>
                  <option value="Sao Tomean"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Sao Tomean')===0) echo ' selected'; ?>>Sao Tomean</option>
                  <option value="Saudi"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Saudi')===0) echo ' selected'; ?>>Saudi</option>
                  <option value="Scottish"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Scottish')===0) echo ' selected'; ?>>Scottish</option>
                  <option value="Senegalese"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Senegalese')===0) echo ' selected'; ?>>Senegalese</option>
                  <option value="Serbian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Serbian')===0) echo ' selected'; ?>>Serbian</option>
                  <option value="Seychellois"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Seychellois')===0) echo ' selected'; ?>>Seychellois</option>
                  <option value="Sierra Leonean"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Sierra Leonean')===0) echo ' selected'; ?>>Sierra Leonean</option>
                  <option value="Singaporean"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Singaporean')===0) echo ' selected'; ?>>Singaporean</option>
                  <option value="Slovak"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Slovak')===0) echo ' selected'; ?>>Slovak</option>
                  <option value="Slovenian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Slovenian')===0) echo ' selected'; ?>>Slovenian</option>
                  <option value="Solomon Islander"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Solomon Islander')===0) echo ' selected'; ?>>Solomon Islander</option>
                  <option value="Somali"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Somali')===0) echo ' selected'; ?>>Somali</option>
                  <option value="South African"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'South African')===0) echo ' selected'; ?>>South African</option>
                  <option value="South Korean"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'South Korean')===0) echo ' selected'; ?>>South Korean</option>
                  <option value="South Sudanese"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'South Sudanese')===0) echo ' selected'; ?>>South Sudanese</option>
                  <option value="Spanish"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Spanish')===0) echo ' selected'; ?>>Spanish</option>
                  <option value="Sri Lankan"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Sri Lankan')===0) echo ' selected'; ?>>Sri Lankan</option>
                  <option value="Sudanese"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Sudanese')===0) echo ' selected'; ?>>Sudanese</option>
                  <option value="Surinamese"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Surinamese')===0) echo ' selected'; ?>>Surinamese</option>
                  <option value="Swedish"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Swedish')===0) echo ' selected'; ?>>Swedish</option>
                  <option value="Swiss"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Swiss')===0) echo ' selected'; ?>>Swiss</option>
                  <option value="Syrian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Syrian')===0) echo ' selected'; ?>>Syrian</option>
                  <option value="Taiwanese"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Taiwanese')===0) echo ' selected'; ?>>Taiwanese</option>
                  <option value="Tajik"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Tajik')===0) echo ' selected'; ?>>Tajik</option>
                  <option value="Tanzanian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Tanzanian')===0) echo ' selected'; ?>>Tanzanian</option>
                  <option value="Thai"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Thai')===0) echo ' selected'; ?>>Thai</option>
                  <option value="Timorese"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Timorese')===0) echo ' selected'; ?>>Timorese</option>
                  <option value="Togolese"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Togolese')===0) echo ' selected'; ?>>Togolese</option>
                  <option value="Tongan"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Tongan')===0) echo ' selected'; ?>>Tongan</option>
                  <option value="Trinidadian or Tobagonian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Trinidadian or Tobagonian')===0) echo ' selected'; ?>>Trinidadian or Tobagonian</option>
                  <option value="Tunisian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Tunisian')===0) echo ' selected'; ?>>Tunisian</option>
                  <option value="Turkish"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Turkish')===0) echo ' selected'; ?>>Turkish</option>
                  <option value="Turkmen"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Turkmen')===0) echo ' selected'; ?>>Turkmen</option>
                  <option value="Tuvaluan"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Tuvaluan')===0) echo ' selected'; ?>>Tuvaluan</option>
                  <option value="Ugandan"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Ugandan')===0) echo ' selected'; ?>>Ugandan</option>
                  <option value="Ukrainian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Ukrainian')===0) echo ' selected'; ?>>Ukrainian</option>
                  <option value="Emirati"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Emirati')===0) echo ' selected'; ?>>Emirati</option>
                  <option value="British (UK)"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'British (UK)')===0) echo ' selected'; ?>>British (UK)</option>
                  <option value="American (US)"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'American (US)')===0) echo ' selected'; ?>>American (US)</option>
                  <option value="Uruguayan"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Uruguayan')===0) echo ' selected'; ?>>Uruguayan</option>
                  <option value="Uzbek"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Uzbek')===0) echo ' selected'; ?>>Uzbek</option>
                  <option value="Vanuatuan"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Vanuatuan')===0) echo ' selected'; ?>>Vanuatuan</option>
                  <option value="Venezuelan"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Venezuelan')===0) echo ' selected'; ?>>Venezuelan</option>
                  <option value="Vietnamese"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Vietnamese')===0) echo ' selected'; ?>>Vietnamese</option>
                  <option value="Yemeni"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Yemeni')===0) echo ' selected'; ?>>Yemeni</option>
                  <option value="Zambian"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Zambian')===0) echo ' selected'; ?>>Zambian</option>
                  <option value="Zimbabwean"<?php if (isset($app['nationality']) && strcasecmp($app['nationality'],'Zimbabwean')===0) echo ' selected'; ?>>Zimbabwean</option>
                </select>
              </div>
            </div>
          </div>

          <div class="app-details">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <strong style="color:#fff;font-size:15px;">Key Details</strong>
              <div class="actions">
                <button type="button" id="detailsEditBtn" class="btn primary btn-primary btn-edit">Edit</button>
                <button type="button" id="detailsSaveBtn" class="btn primary btn-primary btn-save" style="display:none;">Save</button>
                <button type="button" id="detailsCancelBtn" class="btn primary btn-primary btn-cancel" style="display:none;">Cancel</button>
              </div>
            </div>

            <div class="fields" style="margin-top:8px;">
              <div class="field"><label>Degree / Faculty</label>
                <select id="ap_degree" disabled>
                  <option value="">-- Select Faculty --</option>
                  <!-- Medical & Health -->
                  <option value="Medicen"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Medicen')===0) echo ' selected'; ?>>Medicen</option>
                  <option value="Health Sciences"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Health Sciences')===0) echo ' selected'; ?>>Health Sciences</option>
                  <option value="Dentistry"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Dentistry')===0) echo ' selected'; ?>>Dentistry</option>
                  <option value="Nursing"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Nursing')===0) echo ' selected'; ?>>Nursing</option>
                  <option value="Pharmacy"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Pharmacy')===0) echo ' selected'; ?>>Pharmacy</option>
                  <option value="Allied Health"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Allied Health')===0) echo ' selected'; ?>>Allied Health</option>
                  <option value="Biomedical Engineering"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Biomedical Engineering')===0) echo ' selected'; ?>>Biomedical Engineering</option>

                  <!-- Engineering faculties -->
                  <option value="Engineering - Mechanical"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Engineering - Mechanical')===0) echo ' selected'; ?>>Engineering — Mechanical</option>
                  <option value="Engineering - Electrical"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Engineering - Electrical')===0) echo ' selected'; ?>>Engineering — Electrical</option>
                  <option value="Engineering - Civil"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Engineering - Civil')===0) echo ' selected'; ?>>Engineering — Civil</option>
                  <option value="Engineering - Chemical"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Engineering - Chemical')===0) echo ' selected'; ?>>Engineering — Chemical</option>
                  <option value="Engineering - Aerospace"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Engineering - Aerospace')===0) echo ' selected'; ?>>Engineering — Aerospace</option>
                  <option value="Engineering - Industrial"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Engineering - Industrial')===0) echo ' selected'; ?>>Engineering — Industrial</option>
                  <option value="Engineering - Materials"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Engineering - Materials')===0) echo ' selected'; ?>>Engineering — Materials</option>
                  <option value="Engineering - Mechatronics"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Engineering - Mechatronics')===0) echo ' selected'; ?>>Engineering — Mechatronics</option>
                  <option value="Engineering - Software"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Engineering - Software')===0) echo ' selected'; ?>>Engineering — Software</option>

                  <!-- Computer & Sciences -->
                  <option value="Computer Science"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Computer Science')===0) echo ' selected'; ?>>Computer Science</option>
                  <option value="Science"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Science')===0) echo ' selected'; ?>>Science</option>
                  <option value="Mathematics"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Mathematics')===0) echo ' selected'; ?>>Mathematics</option>
                  <option value="Physics"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Physics')===0) echo ' selected'; ?>>Physics</option>
                  <option value="Chemistry"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Chemistry')===0) echo ' selected'; ?>>Chemistry</option>
                  <option value="Biology"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Biology')===0) echo ' selected'; ?>>Biology</option>

                  <!-- Business / Management -->
                  <option value="Business - Marketing"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Business - Marketing')===0) echo ' selected'; ?>>Business — Marketing</option>
                  <option value="Business - Finance"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Business - Finance')===0) echo ' selected'; ?>>Business — Finance</option>
                  <option value="Business - Management"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Business - Management')===0) echo ' selected'; ?>>Business — Management</option>
                  <option value="Business - Accounting"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Business - Accounting')===0) echo ' selected'; ?>>Business — Accounting</option>
                  <option value="Business - Human Resources"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Business - Human Resources')===0) echo ' selected'; ?>>Business — Human Resources</option>
                  <option value="Business - International Business"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Business - International Business')===0) echo ' selected'; ?>>Business — International Business</option>
                  <option value="Business - Entrepreneurship"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Business - Entrepreneurship')===0) echo ' selected'; ?>>Business — Entrepreneurship</option>
                  <option value="Business - Operations Management"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Business - Operations Management')===0) echo ' selected'; ?>>Business — Operations Management</option>
                  <option value="Business - Supply Chain"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Business - Supply Chain')===0) echo ' selected'; ?>>Business — Supply Chain</option>

                  <!-- Other faculties -->
                  <option value="Architecture"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Architecture')===0) echo ' selected'; ?>>Architecture</option>
                  <option value="Agriculture"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Agriculture')===0) echo ' selected'; ?>>Agriculture</option>
                  <option value="Law"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Law')===0) echo ' selected'; ?>>Law</option>
                  <option value="Education"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Education')===0) echo ' selected'; ?>>Education</option>
                  <option value="Social Sciences"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Social Sciences')===0) echo ' selected'; ?>>Social Sciences</option>
                  <option value="Psychology"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Psychology')===0) echo ' selected'; ?>>Psychology</option>
                  <option value="Fine Arts"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Fine Arts')===0) echo ' selected'; ?>>Fine Arts</option>
                  <option value="Hospitality"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Hospitality')===0) echo ' selected'; ?>>Hospitality</option>
                  <option value="Environmental Studies"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Environmental Studies')===0) echo ' selected'; ?>>Environmental Studies</option>
                  <option value="Other"<?php if (isset($app['degree']) && strcasecmp($app['degree'],'Other')===0) echo ' selected'; ?>>Other</option>
                </select>
              </div>
              <div class="field"><label>Years experience</label><input id="ap_years_experience" type= number value="<?php echo h($app['years_experience'] ?? ''); ?>" disabled></div>
              <div class="field"><label>Experience level</label><input id="ap_experience_level" value="<?php echo h($app['experience_level'] ?? ''); ?>" disabled></div>
              <div class="field"><label>Education level</label>
                <select id="ap_education_level" disabled>
                  <option value="">-- Select Education Level --</option>
                  <option value="High School"<?php if (isset($app['education_level']) && strcasecmp($app['education_level'],'High School')===0) echo ' selected'; ?>>High School</option>
                  <option value="Bachelors"<?php if (isset($app['education_level']) && (strcasecmp($app['education_level'],'Bachelors')===0 || strcasecmp($app['education_level'],'Bachelor')===0 || strcasecmp($app['education_level'],"Bachelor's")===0)) echo ' selected'; ?>>Bachelor's</option>
                  <option value="Masters"<?php if (isset($app['education_level']) && strcasecmp($app['education_level'],'Masters')===0) echo ' selected'; ?>>Masters</option>
                  <option value="PhD"<?php if (isset($app['education_level']) && (strcasecmp($app['education_level'],'PhD')===0 || strcasecmp($app['education_level'],'PHD')===0)) echo ' selected'; ?>>PhD</option>
                </select>
              </div>
              <div class="field" style="grid-column:1/-1;"><label>Description</label><textarea id="ap_description" rows="6" disabled><?php echo h($app['description'] ?? ''); ?></textarea></div>
              <div class="field" style="grid-column:1/-1;">
                <label>Skills</label>
                <div id="ap_skills_tags" class="skills-tags" data-initial="<?php echo htmlspecialchars($app['skills'] ?? '', ENT_QUOTES); ?>"></div>
                <input id="ap_skills_input" placeholder="Add a skill and press Enter or paste comma-separated" disabled />
                <input type="hidden" id="ap_skills" value="<?php echo h($app['skills'] ?? ''); ?>" disabled />
                <div class="small-muted" style="margin-top:6px;font-size:12px;">Press Enter to add a skill, or paste comma-separated skills.</div>
              </div>
            </div>
          </div>

        </div> <!-- /.app-panel -->
      </div> <!-- /.app-left -->

      <div class="app-right">
        <div class="resume-wrap">
          <div class="resume-toolbar"><div class="label">Resume</div><div><a href="<?php echo htmlspecialchars($resume_href ?: $resume_url, ENT_QUOTES) ?>" target="_blank" rel="noopener" style="color:#9aa4b2;font-size:13px;">Open in new tab</a></div></div>
          <?php if (!empty($resume_url)): ?>
            <iframe class="resume-frame" src="<?php echo htmlspecialchars($resume_href ?: $resume_url, ENT_QUOTES) ?>"></iframe>
          <?php else: ?>
            <div style="padding:18px;color:#9aa4b2;">No resume uploaded.</div>
          <?php endif; ?>
        </div>
      </div> <!-- /.app-right -->

    </div> <!-- /.app-lr -->

      <!-- Status action buttons (next status transitions) -->
      <div id="statusActionsHolder" style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-start;">
        <!-- Buttons will be injected here -->
      </div>

      <!-- Interview logs: placed above Activity/History and under resume/status -->
      <?php
      // load interview statuses for the select (id, name, status_color)
      $interview_statuses = [];
      $isr = $conn->query("SELECT id, name, status_color FROM interview_statuses ORDER BY id");
      if ($isr) { while ($row = $isr->fetch_assoc()) $interview_statuses[] = $row; $isr->free(); }
      ?>
      <details id="appInterviewLogs" open style="margin-top:14px;color:#ddd;">
        <summary style="cursor:pointer;font-weight:700;display:flex;align-items:center;justify-content:space-between;gap:12px;">
          <span>Interview Logs</span>
          <div style="display:flex;gap:8px;align-items:center;">
            <button id="btnScheduleInterview" class="btn" type="button">Schedule Interview</button>
          </div>
        </summary>

        <div style="margin-top:10px;overflow:auto;">
          <table id="iv_table" style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
              <tr style="color:#9aa4b2;text-align:left;border-bottom:1px solid rgba(255,255,255,0.04);">
                <th style="padding:8px 6px;min-width:180px;">Date & Time</th>
                <th style="padding:8px 6px;min-width:120px;">Status</th>
                <th style="padding:8px 6px;min-width:120px;">Result</th>
                <th style="padding:8px 6px;min-width:140px;">Location</th>
                <th style="padding:8px 6px;min-width:140px;">Created By</th>
                <th style="padding:8px 6px;">Comments</th>
                <th style="padding:8px 6px;text-align:right;min-width:140px;">Actions</th>
              </tr>
            </thead>
            <tbody id="iv_table_body">
              <tr><td id="iv_empty" colspan="7" style="padding:12px;color:#9aa4b2;">Loading interviews...</td></tr>
            </tbody>
          </table>
        </div>

        <!-- Hidden reference of interview statuses for mapping in JS -->
        <select id="iv_status_ref" style="display:none;">
          <?php foreach ($interview_statuses as $st): ?>
            <option value="<?= (int)$st['id'] ?>" data-color="<?= htmlspecialchars($st['status_color'] ?? '') ?>"><?= htmlspecialchars($st['name']) ?></option>
          <?php endforeach; ?>
        </select>

      </details>

      <!-- Schedule Interview Modal -->
      <div id="ivScheduleModal" class="modal-overlay" style="display:none;z-index:12000;">
        <div class="modal-card" style="max-width:680px;width:92%;">
          <div class="modal-header" style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.03);">
            <h3 style="margin:0;color:#fff;font-size:16px;">Schedule Interview</h3>
            <button type="button" class="modal-close-x" aria-label="Close">&times;</button>
          </div>
          <div id="ivScheduleContent" style="padding:14px;">
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:8px;">
                <div style="flex:1;min-width:200px;"><label style="color:#9aa4b2;font-size:13px;">Applicant</label><div id="iv_sched_applicant" style="color:#fff;font-weight:700;margin-top:6px;"><?php echo h($app['full_name']); ?></div></div>
                <div style="flex:1;min-width:200px;"><label style="color:#9aa4b2;font-size:13px;">Position</label><div id="iv_sched_position" style="color:#fff;margin-top:6px;"><?php echo h($position_title ?: '—'); ?></div></div>
              </div>

              <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
                <div style="flex:0 0 220px;"><label style="color:#9aa4b2;font-size:13px;">Date</label><input id="iv_sched_date" type="date" style="width:100%;padding:8px;border-radius:8px;background:#0f1720;color:#fff;border:1px solid rgba(255,255,255,0.04);" /></div>
                <div style="flex:0 0 160px;"><label style="color:#9aa4b2;font-size:13px;">Time</label><input id="iv_sched_time" type="time" style="width:100%;padding:8px;border-radius:8px;background:#0f1720;color:#fff;border:1px solid rgba(255,255,255,0.04);" /></div>
                <div style="flex:1;min-width:180px;"><label style="color:#9aa4b2;font-size:13px;">Location</label>
                  <select id="iv_sched_location" style="width:100%;padding:8px;border-radius:8px;background:#0f1720;color:#fff;border:1px solid rgba(255,255,255,0.04);">
                    <option value="">-- Select Location --</option>
                  <option value="Abraj - HR">Abraj - HR</option>
                    <option value="Abraj - CC">Abraj - CC</option>
                    <option value="Promenad - HR">Promenad - HR</option>
                    <option value="Online Interview">Online Interview</option>
                  
                  </select>
                </div>
              </div>

              <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;">
                <button id="iv_sched_save" type="button" class="btn">Save</button>
              </div>
          </div>
        </div>
      </div>

      <!-- Interview Viewer Modal -->
      <div id="ivViewModal" class="modal-overlay" style="display:none;z-index:12010;">
        <div class="modal-card" style="max-width:720px;width:94%;">
          <div class="modal-header" style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.03);">
            <h3 id="iv_view_title" style="margin:0;color:#fff;font-size:16px;">Interview</h3>
            <button type="button" class="modal-close-x" aria-label="Close">&times;</button>
          </div>
          <div id="ivViewContent" style="padding:14px;color:#ddd;">
            <div id="iv_view_meta" style="margin-bottom:8px;color:#cbd5e1;font-size:13px;"></div>
            <div id="iv_view_body" style="background:transparent;border-radius:8px;padding:8px;"></div>
            <div style="margin-top:12px;display:flex;gap:8px;align-items:center;">
              <select id="iv_view_result" style="padding:8px;border-radius:8px;background:#0f1720;color:#fff;border:1px solid rgba(255,255,255,0.04);min-width:180px;">
                <option value="">-- Result --</option>
                <option>Recommended</option>
                <option>Excellent</option>
                <option>Not Recommended</option>
                <option>Average</option>
              </select>
              <input id="iv_view_location" type="text" placeholder="Location" style="padding:8px;border-radius:8px;background:#0f1720;color:#fff;border:1px solid rgba(255,255,255,0.04);min-width:200px;" />
            </div>
            <div style="margin-top:10px;">
              <label for="iv_view_comments" style="color:#9aa4b2;font-size:13px;margin-bottom:6px;display:block;">Comments</label>
              <textarea id="iv_view_comments" rows="4" style="width:100%;padding:10px;border-radius:8px;background:#0f1720;color:#fff;border:1px solid rgba(255,255,255,0.04);"></textarea>
            </div>
            <div id="iv_view_actions" style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;">
              <button id="iv_view_cancel_btn" type="button" class="btn-ghost" style="display:none;">Cancelled</button>
              <button id="iv_view_complete_btn" type="button" class="btn-orange" style="display:none;">Completed</button>
            </div>
          </div>
        </div>
      </div>

    <?php
    // Build Activity / History HTML from applicants_status_history (if table exists)
    $historyHtml = '<div style="color:#bbb;padding:8px;">No activity yet.</div>';
    $entries = [];
    $s_check = $conn->query("SHOW TABLES LIKE 'applicants_status_history'");
    if ($s_check && $s_check->num_rows > 0) {
      $hs = $conn->prepare(
        "SELECT h.history_id AS id, h.applicant_id, h.position_id, h.status_id, COALESCE(s.status_name,'') AS status_name, h.updated_by, h.updated_at, h.reason
         FROM applicants_status_history h
         LEFT JOIN applicants_status s ON h.status_id = s.status_id
         WHERE h.applicant_id = ? ORDER BY h.updated_at DESC"
      );
      if ($hs) {
        $hs->bind_param('i', $app['applicant_id']);
        $hs->execute();
        $hres = $hs->get_result();
        while ($r = $hres->fetch_assoc()) {
          $entries[] = array_merge($r, ['type' => 'status', 'ts' => $r['updated_at']]);
        }
        $hs->close();
      }
    }
    if (count($entries) > 0) {
      $historyHtml = '';
      foreach ($entries as $h) {
        $who = htmlspecialchars($h['updated_by'] ?: 'System');
        $at = htmlspecialchars($h['updated_at'] ?? '');
        $statusName = htmlspecialchars($h['status_name'] ?: '');
        $reason = nl2br(htmlspecialchars($h['reason'] ?? ''));
        $historyHtml .= '<div style="padding:8px;border-bottom:1px solid rgba(255,255,255,0.03);">';
        $historyHtml .= "<div style=\"font-size:13px;color:#ccc;\"><strong>Status</strong> — <span style=\"color:#aab;\">by {$who}</span> <span style=\"color:#777;font-size:12px;margin-left:8px;\">{$at}</span></div>";
        $historyHtml .= "<div style=\"margin-top:6px;color:#ddd;font-size:13px;\"><em>To:</em> <strong>{$statusName}</strong>";
        if (strlen(trim(strip_tags($reason))) > 0) $historyHtml .= "<div style=\"margin-top:6px;color:#cfd8e3;font-size:13px;\">Reason: {$reason}</div>";
        $historyHtml .= '</div>';
      }
    }
    ?>

    <hr style="margin-top:12px;margin-bottom:12px;">
    <details id="applicantHistory" style="color:#ddd;">
      <summary style="cursor:pointer;font-weight:700;">Activity / History</summary>
      <div id="appHistoryList" style="margin-top:10px;"><?php echo $historyHtml; ?></div>
    </details>

  </div> <!-- /.app-ticket -->

  <div id="appInlineNoticeHolder"></div>
</div>

<script>
(async function(){
  const applicantId = <?php echo json_encode(intval($app['applicant_id'])); ?>;
  // Track current status so UI can enable/disable edit controls and act on status changes
  let currentStatusId = <?php echo json_encode(intval($app['status_id'])); ?>;
  let currentStatusName = <?php echo json_encode($app_status_name ?: ($app['status_name'] ?? '')); ?>;

  function showInlineNotice(msg, timeout){
    try{
      let holder = document.getElementById('appInlineNoticeHolder');
      if (!holder) return;
      holder.innerHTML = '<div class="app-inline-notice">' + String(msg) + '</div>';
      if (timeout) setTimeout(()=>{ try{ holder.innerHTML=''; }catch(e){} }, timeout);
    }catch(e){ console.warn('showInlineNotice failed', e); }
  }

  function ensureNotify(){
    return new Promise((resolve)=>{
      try{
        if (window.Notify) return resolve();
        if (!document.querySelector('link[data-injected="notify-css"]')){
          const l = document.createElement('link'); l.rel='stylesheet'; l.href='assets/css/notify.css'; l.setAttribute('data-injected','notify-css'); document.head.appendChild(l);
        }
        if (document.querySelector('script[data-injected="notify-js"]')){ setTimeout(resolve,200); return; }
        const s = document.createElement('script'); s.src='assets/js/notify.js'; s.async=true; s.setAttribute('data-injected','notify-js'); s.onload = ()=>setTimeout(resolve,50); s.onerror=()=>resolve(); document.body.appendChild(s);
      }catch(e){ resolve(); }
    });
  }

    // Applicant-specific status color mapping (JS) — intentionally different shades than positions
    function applicantStatusColorForName(name){
      const k = String(name || '').toLowerCase().trim();
      switch(k){
        case 'applicants active': return '#2563EB';
        case 'approve': return '#059669';
        case 'complete': return '#475569';
        case 'created': return '#4B5563';
        case 'hire confirmed': return '#0F766E';
        case 'hire partially confirmed': return '#D97706';
        case 'hiring active': return '#1E40AF';
        case 'interviews active': return '#0891B2';
        case 're-open':
        case 'reopen': return '#1E3A8A';
        case 'rejected': return '#B91C1C';
        case 'send for approval': return '#C2410C';
        case 'short-close':
        case 'short close': return '#64748B';
        default: return '#374151';
      }
    }
    function applicantStatusTextColor(hex){
      if (!hex) return '#ffffff';
      let h = String(hex).replace('#','');
      if (h.length === 3) h = h.split('').map(c=>c+c).join('');
      const r = parseInt(h.substr(0,2),16);
      const g = parseInt(h.substr(2,2),16);
      const b = parseInt(h.substr(4,2),16);
      const l = 0.299*r + 0.587*g + 0.114*b;
      return l > 186 ? '#111111' : '#ffffff';
    }

  // Profile controls
  const pEdit = document.getElementById('profileEditBtn');
  const pSave = document.getElementById('profileSaveBtn');
  const pCancel = document.getElementById('profileCancelBtn');
  const profileFields = ['ap_full_name','ap_phone','ap_email','ap_linkedin','ap_age','ap_gender','ap_nationality'];
  function setProfileEditable(editable){ profileFields.forEach(id=>{ const el=document.getElementById(id); if (!el) return; el.disabled = !editable; }); }
  function getProfilePayload(){ const data = {}; profileFields.forEach(id=>{ const el=document.getElementById(id); if (!el) return; data[id.replace(/^ap_/,'')] = el.value || ''; }); return data; }
  let profileSnapshot = null;

  // Track whether user has unsaved edits to prevent accidental modal close
  let __applicantUnsaved = false;

  // Global guard for preventing modal close while edits are unsaved.
  const __unsavedCloseGuard = { clickHandler: null, keyHandler: null };
  function isApplicantVisible(){ try{ const el = document.getElementById('applicantTicket'); if (!el) return false; return !!(el.offsetParent || el.getClientRects().length); }catch(e){return false;} }
  function addUnsavedCloseGuard(){ if (__unsavedCloseGuard.clickHandler) return; __unsavedCloseGuard.clickHandler = async function(ev){ try{ if (!__applicantUnsaved) return; // nothing to do
        const t = ev.target || ev.srcElement; if (!t) return; // closing X
        const isCloseX = (!!t.closest && !!t.closest('.modal-close-x')) || (!!t.classList && t.classList.contains && t.classList.contains('modal-close-x'));
        const isOverlay = (!!t.classList && t.classList.contains && t.classList.contains('modal-overlay')) || (!!t.closest && !!t.closest('.modal-overlay')) && !t.closest('.modal-card');
        if (isCloseX || isOverlay) {
          ev.stopImmediatePropagation(); ev.preventDefault(); try{ await ensureNotify(); Notify.push({ from:'Applicants', message: 'Please Save your changes before closing', color:'#F59E0B' }); }catch(e){}
        }
      }catch(e){} };
    __unsavedCloseGuard.keyHandler = async function(ev){ try{ if (!__applicantUnsaved) return; if (ev.key === 'Escape') { ev.stopImmediatePropagation(); ev.preventDefault(); try{ await ensureNotify(); Notify.push({ from:'Applicants', message: 'Please Save your changes before closing', color:'#F59E0B' }); }catch(e){} } }catch(e){} };
    document.addEventListener('click', __unsavedCloseGuard.clickHandler, true);
    document.addEventListener('keydown', __unsavedCloseGuard.keyHandler, true);
  }
  function removeUnsavedCloseGuard(){ try{ if (!__unsavedCloseGuard.clickHandler) return; document.removeEventListener('click', __unsavedCloseGuard.clickHandler, true); document.removeEventListener('keydown', __unsavedCloseGuard.keyHandler, true); __unsavedCloseGuard.clickHandler = null; __unsavedCloseGuard.keyHandler = null; }catch(e){} }
  function isScreeningMode(){ try{ return /screen/i.test((currentStatusName||'').toString()); }catch(e){ return false; } }
  async function notifyScreeningRequired(){ try{ await ensureNotify(); try{ Notify.push({ from:'Applicants', message: 'Ticket must be in screening mode to edit', color:'#F59E0B' }); }catch(e){} }catch(e){} }

  function updateEditButtonsState(){ try{
      const allow = isScreeningMode(); [pEdit, dEdit].forEach(btn=>{ if (!btn) return; btn.disabled = !allow; btn.setAttribute('aria-disabled', String(!allow)); btn.style.opacity = allow ? '1' : '0.55'; btn.title = allow ? '' : 'Ticket must be in screening mode to edit'; });
    }catch(e){}
  }

  // Disable/enable status action buttons and Schedule Interview while editing
  function updateActionButtonsState(){ try{
      const disable = !!__applicantUnsaved;
      const statusBtns = document.querySelectorAll('.status-action-btn, .status-action');
      statusBtns.forEach(b=>{ try{ b.disabled = disable; b.setAttribute('aria-disabled', String(disable)); b.style.opacity = disable ? '0.45' : ''; b.style.pointerEvents = disable ? 'none' : ''; b.title = disable ? 'Disabled while editing' : (b.title || ''); }catch(e){} });
      const sched = document.getElementById('btnScheduleInterview');
      if (sched) { try{ sched.disabled = disable; sched.setAttribute('aria-disabled', String(disable)); sched.style.opacity = disable ? '0.45' : ''; sched.title = disable ? 'Disabled while editing' : (sched.title || ''); }catch(e){} }
    }catch(e){}
  }

  pEdit && pEdit.addEventListener('click', function(ev){ try{ if (!isScreeningMode()) { ev && ev.preventDefault && ev.preventDefault(); notifyScreeningRequired(); return; } }catch(e){} profileSnapshot = getProfilePayload(); setProfileEditable(true); pEdit.style.display='none'; pSave.style.display='inline-block'; pCancel.style.display='inline-block'; __applicantUnsaved = true; addUnsavedCloseGuard(); try{ updateActionButtonsState(); }catch(e){} });
  pCancel && pCancel.addEventListener('click', function(){ if (profileSnapshot) { profileFields.forEach(id=>{ const el=document.getElementById(id); if (!el) return; el.value = profileSnapshot[id.replace(/^ap_/,'')] || ''; }); } setProfileEditable(false); pEdit.style.display='inline-block'; pSave.style.display='none'; pCancel.style.display='none'; __applicantUnsaved = false; removeUnsavedCloseGuard(); try{ updateActionButtonsState(); }catch(e){} });

  pSave && pSave.addEventListener('click', async function(){
    const payload = getProfilePayload(); payload.applicant_id = applicantId;
    try {
      pSave.disabled = true;
      const fd = new FormData(); Object.keys(payload).forEach(k=>fd.append(k, payload[k]));
      const res = await fetch('update_applicant.php', { method: 'POST', credentials:'same-origin', body: fd });
      const txt = await res.text();
      let json = {};
      try { json = JSON.parse(txt); } catch(e){ console.error('parse', txt); showInlineNotice('Server response: ' + (txt||''), 10000); return; }

      if (json && typeof json.db_message !== 'undefined' && json.db_message) {
        if (json.warning) console.warn('[Applicants][DB warning] ' + (json.db_message || json.warning));
        else console.error('[Applicants][DB message] ' + json.db_message);
      }

      if (!json.ok) { if (json.neutral) { showInlineNotice(json.error || 'No changes'); return; } await ensureNotify(); Notify.push({ from:'Applicants', message: json.error || 'Save failed', color:'#dc2626' }); return; }

      setProfileEditable(false); pEdit.style.display='inline-block'; pSave.style.display='none'; pCancel.style.display='none'; __applicantUnsaved = false; removeUnsavedCloseGuard(); try{ updateActionButtonsState(); }catch(e){}
      try {
        const updated = json.applicant || {};
        if (updated.status_name) {
          const badge = document.getElementById('appStatusBadge');
            if (badge) {
            badge.textContent = updated.status_name;
            const color = (updated.status_color && updated.status_color.length) ? updated.status_color : applicantStatusColorForName(updated.status_name);
            const tcolor = applicantStatusTextColor(color);
            try { badge.style.background = color; badge.style.color = tcolor; } catch(e){}
          }
        }
        const row = document.querySelector('.app-row[data-applicant-id="' + applicantId + '"]');
        if (row) {
          const tds = row.querySelectorAll('td');
          if (tds && tds.length > 5) {
            // Table column mapping (applicants.php): 0=select,1=ID,2=Status,3=Position,4=Department,5=Team,6=Full Name,7=Age,8=Gender,9=Nationality,10=Degree,11=Years Exp,...
            if (updated.status_name) tds[2].innerHTML = '<span class="pill">' + (updated.status_name || '—') + '</span>';
            if (updated.full_name) tds[6].textContent = updated.full_name;
            if (updated.degree) tds[10].textContent = updated.degree;
            if (typeof updated.age !== 'undefined') tds[7].textContent = (updated.age === null || updated.age === '' ? '—' : String(updated.age));
            if (updated.gender) tds[8].textContent = updated.gender;
            if (updated.nationality) tds[9].textContent = updated.nationality;
            if (typeof updated.years_experience !== 'undefined') tds[11].textContent = updated.years_experience === null || updated.years_experience === '' ? '—' : String(updated.years_experience);
            if (typeof updated.status_id !== 'undefined') row.setAttribute('data-status-id', String(updated.status_id));
          }
        }
        try { window.dispatchEvent(new CustomEvent('applicant:updated', { detail: updated })); } catch(e){}
      } catch(e){ console.warn('post-save UI update failed', e); }

      try {
        if (json && typeof json.affected_rows !== 'undefined') {
          if (Number(json.affected_rows) > 0) {
            await ensureNotify();
            try { if (!window.__applicantNotifyInFlight) { window.__applicantNotifyInFlight = true; Notify.push({ from:'Applicants', message: 'Applicant #' + applicantId + '\nProfile updated', color:'#10b981' }); setTimeout(()=>{ try{ window.__applicantNotifyInFlight = false; }catch(e){} }, 1500); } } catch(e){ console.warn('notify push failed', e); }
          } else { if (json.trigger_message) { showInlineNotice(json.trigger_message); return; } if (json.warning) { showInlineNotice(json.warning); return; } return; }
        }
      } catch(e){ console.warn('post-save notify handling failed', e); }
    } catch(err){ console.error(err); await ensureNotify(); Notify.push({ from:'Applicants', message:'Save failed (see console)', color:'#dc2626' }); }
    finally { pSave.disabled = false; }
  });

  // Details controls
  const dEdit = document.getElementById('detailsEditBtn');
  const dSave = document.getElementById('detailsSaveBtn');
  const dCancel = document.getElementById('detailsCancelBtn');
  const detailFields = ['ap_degree','ap_years_experience','ap_experience_level','ap_description','ap_education_level','ap_skills'];
  function setDetailsEditable(editable){ detailFields.forEach(id=>{ const el=document.getElementById(id); if (!el) return; el.disabled = !editable; }); const skillsInput = document.getElementById('ap_skills_input'); if (skillsInput) skillsInput.disabled = !editable; }
  function getDetailsPayload(){ const data = {}; detailFields.forEach(id=>{ const el=document.getElementById(id); if (!el) return; data[id.replace(/^ap_/,'')] = el.value || ''; }); return data; }

  // Skills tag management
  let __skillsTags = [];
  function escapeHtml(str){ return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
  function renderSkillsTagsFromValue(v){ try{ const container = document.getElementById('ap_skills_tags'); const hidden = document.getElementById('ap_skills'); __skillsTags = []; if (!container) return; const raw = (typeof v === 'undefined' || v === null) ? (hidden ? hidden.value : '') : v; if (raw && raw.length) { raw.split(',').map(s=>s.trim()).filter(Boolean).forEach(s=>{ if (!__skillsTags.some(x=>x.toLowerCase()===s.toLowerCase())) __skillsTags.push(s); }); } updateSkillsUI(); }catch(e){console.warn('renderSkillsTagsFromValue failed',e);} }
  function updateSkillsUI(){ const container = document.getElementById('ap_skills_tags'); const hidden = document.getElementById('ap_skills'); const input = document.getElementById('ap_skills_input'); if (!container || !hidden) return; container.innerHTML = ''; __skillsTags.forEach((s,i)=>{ const chip = document.createElement('span'); chip.className = 'skill-chip'; const text = document.createElement('span'); text.className='skill-text'; text.textContent = s; const btn = document.createElement('button'); btn.type = 'button'; btn.className = 'remove'; btn.setAttribute('data-index', String(i)); btn.innerHTML = '&times;'; btn.addEventListener('click', function(){ removeSkillAt(i); }); chip.appendChild(text); chip.appendChild(btn); container.appendChild(chip); }); hidden.value = __skillsTags.join(', '); if (input) { input.placeholder = __skillsTags.length ? 'Add another skill and press Enter' : 'Add a skill and press Enter or paste comma-separated'; }
  }
  function addSkillsFromString(s){ if (!s) return; const parts = s.split(',').map(x=>x.trim()).filter(Boolean); parts.forEach(p=>{ if (!__skillsTags.some(x=>x.toLowerCase()===p.toLowerCase())) __skillsTags.push(p); }); updateSkillsUI(); }
  function removeSkillAt(i){ if (typeof i !== 'number') return; __skillsTags.splice(i,1); updateSkillsUI(); }

  // Wire input events
  (function(){ try{ const input = document.getElementById('ap_skills_input'); const container = document.getElementById('ap_skills_tags'); if (!input || !container) return; input.addEventListener('keydown', function(e){ if (e.key === 'Enter'){ e.preventDefault(); const v = input.value.trim(); if (!v) return; addSkillsFromString(v); input.value = ''; } }); input.addEventListener('paste', function(e){ setTimeout(()=>{ const v = input.value || ''; if (v.includes(',')) { addSkillsFromString(v); input.value = ''; } }, 5); }); container.addEventListener('click', function(e){ const btn = e.target && e.target.closest && e.target.closest('.remove'); if (btn){ const idx = parseInt(btn.getAttribute('data-index'),10); if (!Number.isNaN(idx)) removeSkillAt(idx); } }); }catch(e){console.warn('skills wiring failed',e);} })();
  try{ const hid = document.getElementById('ap_skills'); const init = (hid && hid.value) ? hid.value : (document.getElementById('ap_skills_tags') && document.getElementById('ap_skills_tags').dataset.initial) || ''; renderSkillsTagsFromValue(init); }catch(e){}
  let detailsSnapshot = null;

  dEdit && dEdit.addEventListener('click', function(ev){ try{ if (!isScreeningMode()) { ev && ev.preventDefault && ev.preventDefault(); notifyScreeningRequired(); return; } }catch(e){} detailsSnapshot = getDetailsPayload(); setDetailsEditable(true); dEdit.style.display='none'; dSave.style.display='inline-block'; dCancel.style.display='inline-block'; __applicantUnsaved = true; addUnsavedCloseGuard(); try{ updateActionButtonsState(); }catch(e){} });
  dCancel && dCancel.addEventListener('click', function(){ if (detailsSnapshot) { detailFields.forEach(id=>{ const el=document.getElementById(id); if (!el) return; el.value = detailsSnapshot[id.replace(/^ap_/,'')] || ''; }); // restore skills tags from the hidden value
    try{ const hv = document.getElementById('ap_skills') && document.getElementById('ap_skills').value; if (typeof hv !== 'undefined') renderSkillsTagsFromValue(hv); }catch(e){} }
    setDetailsEditable(false); dEdit.style.display='inline-block'; dSave.style.display='none'; dCancel.style.display='none'; __applicantUnsaved = false; removeUnsavedCloseGuard(); try{ updateActionButtonsState(); }catch(e){} });

  dSave && dSave.addEventListener('click', async function(){
    const payload = getDetailsPayload(); payload.applicant_id = applicantId;
    try {
      dSave.disabled = true;
      const fd = new FormData(); Object.keys(payload).forEach(k=>fd.append(k, payload[k]));
      const res = await fetch('update_applicant.php', { method: 'POST', credentials:'same-origin', body: fd });
      const txt = await res.text();
      let json = {};
      try { json = JSON.parse(txt); } catch(e){ console.error('parse', txt); showInlineNotice('Server response: ' + (txt||''), 10000); return; }

      if (json && typeof json.db_message !== 'undefined' && json.db_message) { if (json.warning) console.warn('[Applicants][DB warning] ' + (json.db_message || json.warning)); else console.error('[Applicants][DB message] ' + json.db_message); }

      if (!json.ok) { if (json.neutral) { showInlineNotice(json.error || 'No changes'); return; } await ensureNotify(); Notify.push({ from:'Applicants', message: json.error || 'Save failed', color:'#dc2626' }); return; }

      setDetailsEditable(false); dEdit.style.display='inline-block'; dSave.style.display='none'; dCancel.style.display='none'; __applicantUnsaved = false; removeUnsavedCloseGuard();
      try{ updateActionButtonsState(); }catch(e){}

      try {
        const updated = json.applicant || {};
        if (updated.status_name) {
          const badge = document.getElementById('appStatusBadge');
            if (badge) {
            badge.textContent = updated.status_name;
            const color = (updated.status_color && updated.status_color.length) ? updated.status_color : applicantStatusColorForName(updated.status_name);
            const tcolor = applicantStatusTextColor(color);
            try { badge.style.background = color; badge.style.color = tcolor; } catch(e){}
          }
        }
        const row = document.querySelector('.app-row[data-applicant-id="' + applicantId + '"]');
        if (row) {
          const tds = row.querySelectorAll('td');
          if (tds && tds.length > 5) {
            // Table column mapping (applicants.php): 0=select,1=ID,2=Status,3=Position,4=Department,5=Team,6=Full Name,7=Age,8=Gender,9=Nationality,10=Degree,11=Years Exp,...
            if (updated.status_name) tds[2].innerHTML = '<span class="pill">' + (updated.status_name || '—') + '</span>';
            if (updated.full_name) tds[6].textContent = updated.full_name;
            if (updated.degree) tds[10].textContent = updated.degree;
            if (typeof updated.age !== 'undefined') tds[7].textContent = (updated.age === null || updated.age === '' ? '—' : String(updated.age));
            if (updated.gender) tds[8].textContent = updated.gender;
            if (updated.nationality) tds[9].textContent = updated.nationality;
            if (typeof updated.years_experience !== 'undefined') tds[11].textContent = updated.years_experience === null || updated.years_experience === '' ? '—' : String(updated.years_experience);
            if (typeof updated.status_id !== 'undefined') row.setAttribute('data-status-id', String(updated.status_id));
          }
        }
        try { window.dispatchEvent(new CustomEvent('applicant:updated', { detail: updated })); } catch(e){}
      } catch(e){ console.warn('post-save UI update failed', e); }

      try {
        if (json && typeof json.affected_rows !== 'undefined') {
          if (Number(json.affected_rows) > 0) {
            await ensureNotify();
            try { if (!window.__applicantNotifyInFlight) { window.__applicantNotifyInFlight = true; Notify.push({ from:'Applicants', message: 'Applicant #' + applicantId + '\nDetails updated', color:'#10b981' }); setTimeout(()=>{ try{ window.__applicantNotifyInFlight = false; }catch(e){} }, 1500); } } catch(e){ console.warn('notify push failed', e); }
          } else { if (json.trigger_message) { showInlineNotice(json.trigger_message); return; } if (json.warning) { showInlineNotice(json.warning); return; } return; }
        }
      } catch(e){ console.warn('post-save notify handling failed', e); }
    } catch(err){ console.error(err); await ensureNotify(); Notify.push({ from:'Applicants', message:'Save failed (see console)', color:'#dc2626' }); }
    finally { dSave.disabled = false; }
  });

  // ---------- Status action buttons (transitions) ----------
  async function loadStatusActions(){
    try{
      const holder = document.getElementById('statusActionsHolder');
      if (!holder) return;
      // current status id from server-rendered applicant row
      const currentStatus = <?php echo json_encode(intval($app['status_id'])); ?>;
      holder.innerHTML = '';
      // Ensure holder is visible
      holder.style.display = 'flex';
      // If currentStatus is falsy (0), attempt fallback to Created (1)
      let fromId = currentStatus && Number(currentStatus) > 0 ? Number(currentStatus) : 0;
      const tryFroms = (fromId === 0) ? [0,1] : [fromId];
      let json = null;
      for (const f of tryFroms) {
        const res = await fetch('get_status_transitions.php?from=' + encodeURIComponent(f) + '&type=applicant', { credentials: 'same-origin' });
        const txt = await res.text();
        try { json = JSON.parse(txt); } catch(e){ json = null; }
        if (json && json.ok && Array.isArray(json.transitions) && json.transitions.length) { break; }
      }
      if (!json || !json.ok) {
        // nothing to show
        holder.innerHTML = '<div style="color:#9aa4b2;font-size:13px">No status actions available.</div>';
        return;
      }
      const transitions = json.transitions || [];
      // If no transitions found, nothing to show
      if (!transitions.length) { holder.innerHTML = '<div style="color:#9aa4b2;font-size:13px">No status actions available.</div>'; return; }
      // Render all available transitions dynamically (no fixed limit)
      const show = transitions;
      for (const t of show){
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'status-action-btn';
        // larger, more prominent buttons with color hint for the target status
        try {
          // Prefer DB-defined color when provided by get_status_transitions; fall back to name-based palette
          const btnColor = (t.status_color && String(t.status_color).length) ? String(t.status_color) : applicantStatusColorForName(t.to_name || '');
          // Force white text and bold font for consistency per request
          btn.style.background = btnColor;
          btn.style.color = '#ffffff';
          btn.style.fontWeight = '700';
          try{ btn.setAttribute('data-color', btnColor); }catch(e){}
          btn.style.border = '1px solid rgba(0,0,0,0.18)';
        } catch(e) {
          btn.style.background = '#111827';
          btn.style.color = '#ffffff';
          btn.style.fontWeight = '700';
          btn.style.border = '1px solid rgba(255,255,255,0.06)';
        }
        btn.style.padding = '10px 16px';
        btn.style.fontSize = '15px';
        btn.style.borderRadius = '10px';
        btn.style.cursor = 'pointer';
        // Use only the target status name (no "Move to:" prefix) to match positions flow
        btn.textContent = (t.to_name || ('Status ' + t.to_id));
        btn.setAttribute('data-to-id', String(t.to_id));
        btn.addEventListener('click', async function(){
          const toId = parseInt(this.getAttribute('data-to-id'),10);
          if (isNaN(toId)) return;
          // If moving to Rejected (id 7) ask for reason using an inline modal (not window.prompt)
          let reason = '';
          if (toId === 7) {
            // show inline modal and require a non-empty reason or allow cancel
            reason = await new Promise(async (resolve) => {
              try {
                // ensure Notify system is ready for feedback
                await ensureNotify();
              } catch(e){}
              // create styles if not present
              if (!document.getElementById('reject-reason-styles')) {
                const st = document.createElement('style'); st.id = 'reject-reason-styles'; st.textContent = '\n.reject-reason-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.45);display:flex;align-items:center;justify-content:center;z-index:12000;}\n.reject-reason-card{background:#fff;color:#111;border-radius:8px;padding:14px;max-width:560px;width:90%;box-shadow:none;}\n.reject-reason-card h4{margin:0 0 8px 0;font-size:16px;}\n.reject-reason-card textarea{width:100%;box-sizing:border-box;padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;}\n.reject-reason-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:10px;}\n.reject-reason-actions button{padding:8px 12px;border-radius:6px;border:1px solid #cbd5e1;background:#fff;cursor:pointer;}\n.reject-reason-actions button.primary{background:#111827;color:#fff;border-color:rgba(255,255,255,0.06);}';
                document.head.appendChild(st);
              }
              // build modal
              const overlay = document.createElement('div'); overlay.className = 'reject-reason-overlay';
              overlay.innerHTML = '\n                <div class="reject-reason-card" role="dialog" aria-modal="true">\n                  <h4>Rejection Reason (required)</h4>\n                  <div style="font-size:13px;color:#6b7280;margin-bottom:8px;">Please explain why this applicant is rejected. This is required.</div>\n                  <textarea id="rejectReasonInput" rows="5" placeholder="Enter reason..."></textarea>\n                  <div class="reject-reason-actions">\n                    <button type="button" id="rejectCancel">Cancel</button>\n                    <button type="button" id="rejectSubmit" class="primary">Submit</button>\n                  </div>\n                </div>';
              document.body.appendChild(overlay);
              const inp = overlay.querySelector('#rejectReasonInput');
              const btnCancel = overlay.querySelector('#rejectCancel');
              const btnSubmit = overlay.querySelector('#rejectSubmit');
              // focus
              setTimeout(()=>{ try{ inp.focus(); }catch(e){} },60);
              // handle Escape key
              const onKey = function(e){ if (e.key === 'Escape') { cleanup(null); } };
              document.addEventListener('keydown', onKey);
              const cleanup = (val) => { try{ document.removeEventListener('keydown', onKey); overlay.remove(); }catch(e){} resolve(val); };
              btnCancel.addEventListener('click', function(){ cleanup(null); });
              btnSubmit.addEventListener('click', async function(){
                const v = (inp.value || '').trim();
                if (!v) {
                  try { Notify.push({ from:'Applicants', message: 'Reason is required', color:'#dc2626' }); } catch(e){}
                  try { inp.focus(); } catch(e){}
                  return;
                }
                cleanup(v);
              });
            });
            if (reason === null) return; // user cancelled
          }
          try{
            this.disabled = true;
            await ensureNotify();
            const fd = new FormData(); fd.append('applicant_id', String(applicantId)); fd.append('status_id', String(toId)); if (reason) fd.append('status_reason', reason);
            const up = await fetch('update_applicant.php', { method: 'POST', credentials:'same-origin', body: fd });
            const upTxt = await up.text();
            let upJson = {};
            try { upJson = JSON.parse(upTxt); } catch(e){ console.error('update parse', upTxt); try { await ensureNotify(); Notify.push({ from:'Applicants', message: 'Update failed', color:'#dc2626' }); } catch(err) { console.warn('notify failed', err); } return; }
            if (!upJson.ok) { await ensureNotify(); try{ Notify.push({ from:'Applicants', message: upJson.error || 'Status update failed', color:'#dc2626' }); }catch(e){} return; }
            // Update status badge text and color if returned
            const updated = upJson.applicant || {};
            if (updated.status_name) {
              const badge = document.getElementById('appStatusBadge');
              if (badge) {
                badge.textContent = updated.status_name;
                const color = (updated.status_color && updated.status_color.length) ? updated.status_color : applicantStatusColorForName(updated.status_name);
                const tcolor = applicantStatusTextColor(color);
                try { badge.style.background = color; badge.style.color = tcolor; } catch(e){}
              }
            }
            // update tracked status variables so UI logic stays in sync
            try {
              if (typeof updated.status_id !== 'undefined') currentStatusId = Number(updated.status_id);
              if (typeof updated.status_name !== 'undefined') currentStatusName = String(updated.status_name);
            } catch(e) {}
            // Reload available transitions for new status and update edit-button state
            setTimeout(loadStatusActions, 250);
            try { updateEditButtonsState(); } catch(e) {}

            // If the new status is screening, refresh the applicant fragment first so the
            // modal shows the updated status, then show the Notify. For other statuses
            // keep the existing behavior: show Notify and close the viewer.
            try {
              const name = (updated.status_name || '').toString();
              if (/screen/i.test(name)) {
                try {
                  // Attempt to fetch the refreshed fragment and replace the current content
                  const fragRes = await fetch('get_applicant.php?applicant_id=' + encodeURIComponent(applicantId), { credentials:'same-origin' });
                  const ftxt = await fragRes.text();
                  // Find a good host to replace the fragment: prefer #appViewerContent, then nearest .modal-card, then the ticket itself
                  const ticketEl = document.getElementById('applicantTicket');
                  let host = document.getElementById('appViewerContent') || (ticketEl && ticketEl.closest && ticketEl.closest('.modal-card')) || ticketEl;
                  if (host) {
                    host.innerHTML = ftxt;
                    // Execute inline scripts from the refreshed fragment so handlers rebind
                    Array.from(host.querySelectorAll('script')).forEach(function(s){
                      try{
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
                      }catch(err){ console.warn('inject fragment script failed', err); }
                    });
                    try { window.dispatchEvent(new CustomEvent('applicant:updated', { detail: updated })); } catch(e){}
                  } else {
                    try { window.dispatchEvent(new CustomEvent('applicant:updated', { detail: updated })); } catch(e){}
                  }
                } catch(e) {
                  console.warn('refresh fragment failed', e);
                  try { window.dispatchEvent(new CustomEvent('applicant:updated', { detail: updated })); } catch(e){}
                }

                // Now show the notify so it appears after the modal reflects the new status
                try{ await ensureNotify(); Notify.push({ from:'Applicants', message: 'Applicant #' + applicantId + '\nStatus updated to: ' + (updated.status_name || ('ID ' + String(toId))), color:'#10b981' }); }catch(e){}
              } else {
                // slight delay to allow UI updates/notify to show
                try{ await ensureNotify(); Notify.push({ from:'Applicants', message: 'Applicant #' + applicantId + '\nStatus updated to: ' + (updated.status_name || ('ID ' + String(toId))), color:'#10b981' }); }catch(e){}
                setTimeout(function(){
                  try {
                    // attempt graceful modal close
                    const ticketEl = document.getElementById('applicantTicket');
                    if (!ticketEl) {
                      try { window.dispatchEvent(new CustomEvent('applicant:updated', { detail: updated })); } catch(e){}
                      return;
                    }
                    const overlay = ticketEl.closest && ticketEl.closest('.modal-overlay');
                    if (overlay) {
                      overlay.classList.remove('show'); overlay.setAttribute('aria-hidden','true'); overlay.style.display = 'none';
                      try { window.dispatchEvent(new CustomEvent('applicant:updated', { detail: updated })); } catch(e){}
                      return;
                    }
                    const card = ticketEl.closest && ticketEl.closest('.modal-card');
                    if (card) {
                      const ov = card.closest && card.closest('.modal-overlay');
                      if (ov) { ov.classList.remove('show'); ov.setAttribute('aria-hidden','true'); ov.style.display='none'; try { window.dispatchEvent(new CustomEvent('applicant:updated', { detail: updated })); } catch(e){}; return; }
                      const closeBtn = card.querySelector && card.querySelector('.modal-close-x');
                      if (closeBtn) { try { closeBtn.click(); } catch(e) {} }
                    }
                    const anyClose = document.querySelector && document.querySelector('.modal-close-x'); if (anyClose) { try{ anyClose.click(); } catch(e){} }
                    try { window.dispatchEvent(new CustomEvent('applicant:updated', { detail: updated })); } catch(e){}
                  } catch(e) { console.warn('close applicant modal failed', e); try { window.dispatchEvent(new CustomEvent('applicant:updated', { detail: updated })); } catch(e){} }
                }, 300);
              }
            } catch(e) { try { window.dispatchEvent(new CustomEvent('applicant:updated', { detail: updated })); } catch(e){} }

            try { const hres = await fetch('get_applicant_history.php?applicant_id=' + encodeURIComponent(applicantId), { credentials:'same-origin' }); const htxt = await hres.text(); const holder = document.getElementById('appHistoryList'); if (holder) holder.innerHTML = htxt; } catch(e){ console.warn('refresh history failed', e); }
          }catch(e){ console.error('status update failed', e); } finally { try{ this.disabled = false; }catch(e){} }
        });
        holder.appendChild(btn);
      }
      try{ updateActionButtonsState(); }catch(e){}
    }catch(e){ console.warn('loadStatusActions failed', e); }
  }
  // load when fragment is ready
  setTimeout(loadStatusActions, 300);
  // ensure edit buttons initial enabled/disabled state
  try { updateEditButtonsState(); } catch(e) {}
  try { updateActionButtonsState(); } catch(e) {}

})();
</script>
<script>
// Interview logs — table rendering, schedule modal, viewer modal, and actions
(function(){
  const aid = <?php echo json_encode(intval($app['applicant_id'])); ?>;
  if (!aid) return;

  const tableBody = document.getElementById('iv_table_body');
  const ivEmpty = document.getElementById('iv_empty');
  const btnSchedule = document.getElementById('btnScheduleInterview');
  const schedModal = document.getElementById('ivScheduleModal');
  const schedCancel = document.getElementById('iv_sched_cancel');
  const schedSave = document.getElementById('iv_sched_save');
  const schedDate = document.getElementById('iv_sched_date');
  const schedTime = document.getElementById('iv_sched_time');
  const schedLocation = document.getElementById('iv_sched_location');
  const ivStatusRef = document.getElementById('iv_status_ref');

  // No hard-coded color fallbacks here — rely on DB-provided colors in
  // the hidden `#iv_status_ref` select. If a status has no DB color, fall
  // back to a neutral shade below at use-site.

  const viewModal = document.getElementById('ivViewModal');
  const viewCloseBtns = Array.from(viewModal ? viewModal.querySelectorAll('.modal-close-x') : []);
  const viewTitle = document.getElementById('iv_view_title');
  const viewMeta = document.getElementById('iv_view_meta');
  const viewBody = document.getElementById('iv_view_body');
  const viewResult = document.getElementById('iv_view_result');
  const viewLocation = document.getElementById('iv_view_location');
  const viewComments = document.getElementById('iv_view_comments');
  const viewCancelBtn = document.getElementById('iv_view_cancel_btn');
  const viewCompleteBtn = document.getElementById('iv_view_complete_btn');

  async function notify(msg, color){
    try{
      if (window.Notify) { try { Notify.push({ from:'Interviews', message: msg, color: color || '#10b981' }); } catch(e) { console.warn('Notify.push failed', e); } return; }
      // Inject CSS if missing
      if (!document.querySelector('link[data-injected="notify-css"]')){
        const l = document.createElement('link'); l.rel='stylesheet'; l.href='assets/css/notify.css'; l.setAttribute('data-injected','notify-css'); document.head.appendChild(l);
      }
      // Load script (if not already loaded) and use it when ready
      if (!document.querySelector('script[data-injected="notify-js"]')){
        const s = document.createElement('script'); s.src='assets/js/notify.js'; s.async=true; s.setAttribute('data-injected','notify-js');
        s.onload = function(){ try{ Notify.push({ from:'Interviews', message: msg, color: color || '#10b981' }); } catch(e){ console.warn('Notify.push failed after load', e); } };
        s.onerror = function(){ console.warn('Failed to load notify.js'); };
        document.body.appendChild(s);
      } else {
        // script present but Notify might not be ready yet; schedule a short retry
        setTimeout(function(){ try{ if (window.Notify) Notify.push({ from:'Interviews', message: msg, color: color || '#10b981' }); else console.log(msg); }catch(e){ console.warn('notify retry failed', e); } }, 200);
      }
    }catch(e){ console.log('notify fallback', msg); }
  }

  function formatWhen(dt){ try{ if (!dt) return '—'; const d = new Date(dt); if (isNaN(d)) return dt; return d.toLocaleString(); }catch(e){ return dt; } }

  // build a quick map of statusName -> id for convenience
  const statusMapByName = {};
  const statusMapById = {};
  try{ Array.from(ivStatusRef.options).forEach(o=>{ const id = o.value; const name = (o.textContent||o.innerText||'').trim(); statusMapByName[name.toLowerCase()] = id; statusMapById[id] = name; }); }catch(e){}

  function clearTable(){ tableBody.innerHTML = ''; }

  function makeRow(it){
    const tr = document.createElement('tr'); tr.style.borderBottom = '1px solid rgba(255,255,255,0.02)';
    const whenTd = document.createElement('td'); whenTd.style.padding = '10px 6px'; whenTd.textContent = formatWhen(it.interview_datetime || it.created_at || '');
    const statusTd = document.createElement('td'); statusTd.style.padding = '10px 6px';
    // Render status as a colored pill (use iv_status_ref.data-color when available)
    try{
      const span = document.createElement('span'); span.className = 'status-badge';
      let sc = '';
      try { const opt = Array.from(ivStatusRef.options || []).find(o=> String(o.value) === String(it.status_id) || ((o.textContent||o.innerText||'').trim().toLowerCase() === (it.status_name||'').trim().toLowerCase())); if (opt) sc = opt.getAttribute('data-color') || ''; }catch(e){}
      if (!sc) sc = '#374151';
      let tcol = '#ffffff';
      try { if (typeof applicantStatusTextColor === 'function') tcol = applicantStatusTextColor(sc); } catch(e){}
      span.style.background = sc; span.style.color = tcol; span.style.padding = '6px 10px'; span.style.borderRadius = '999px'; span.style.fontWeight = '700'; span.style.fontSize = '13px';
      span.textContent = it.status_name || (statusMapById[it.status_id]||'');
      statusTd.appendChild(span);
    }catch(e){ statusTd.textContent = it.status_name || (statusMapById[it.status_id]||''); }
    const resultTd = document.createElement('td'); resultTd.style.padding = '10px 6px'; resultTd.textContent = it.result || '—';
    const locTd = document.createElement('td'); locTd.style.padding = '10px 6px'; locTd.textContent = it.location || '—';
    const createdByTd = document.createElement('td'); createdByTd.style.padding = '10px 6px'; createdByTd.textContent = it.created_by_name || (it.created_by ? String(it.created_by) : '—');
    const commTd = document.createElement('td'); commTd.style.padding = '10px 6px'; commTd.innerHTML = it.comments ? (it.comments.length>200 ? (it.comments.substr(0,200)+'&hellip;') : it.comments) : '<em style="color:#9aa4b2;">No comments</em>';
    const actTd = document.createElement('td'); actTd.style.padding = '10px 6px'; actTd.style.textAlign = 'right';
    const viewBtn = document.createElement('button'); viewBtn.type='button'; viewBtn.className='btn-ghost'; viewBtn.textContent='View';
    viewBtn.addEventListener('click', function(){ openViewer(it); });
    actTd.appendChild(viewBtn);

    tr.appendChild(whenTd); tr.appendChild(statusTd); tr.appendChild(resultTd); tr.appendChild(locTd); tr.appendChild(createdByTd); tr.appendChild(commTd); tr.appendChild(actTd);
    return tr;
  }

  async function load(){
    try{
      ivEmpty.textContent = 'Loading interviews...';
      clearTable();
      const res = await fetch('get_interviews.php?applicant_id=' + encodeURIComponent(aid), { credentials:'same-origin' });
      const txt = await res.text();
      let j = {};
      try { j = JSON.parse(txt); } catch(parseErr) { notify('Server error loading interviews: ' + (txt || 'Invalid JSON'), '#dc2626'); ivEmpty.textContent = 'Failed to load interviews.'; clearTable(); tableBody.appendChild(ivEmpty); return; }
      if (!j.ok || !j.interviews || !j.interviews.length){ ivEmpty.textContent = 'No interviews yet.'; tableBody.appendChild(ivEmpty); return; }
      j.interviews.forEach(it=>{ tableBody.appendChild(makeRow(it)); });
    }catch(e){ notify('Failed to load interviews', '#dc2626'); ivEmpty.textContent = 'Failed to load interviews.'; clearTable(); tableBody.appendChild(ivEmpty); }
  }

  // show/hide helpers for modals
  function showModal(el){ if (!el) return; el.style.display = 'flex'; el.classList.add('show'); el.setAttribute('aria-hidden','false'); }
  function hideModal(el){ if (!el) return; el.classList.remove('show'); el.setAttribute('aria-hidden','true'); try{ el.style.display = 'none'; }catch(e){} }

  // Schedule modal wiring
  btnSchedule && btnSchedule.addEventListener('click', function(){ try{ if (schedModal) { // reset fields
      schedDate.value = ''; schedTime.value = ''; schedLocation.selectedIndex = 0; showModal(schedModal); const close = schedModal.querySelector('.modal-close-x'); if (close) close.focus(); } }catch(e){} });
  schedCancel && schedCancel.addEventListener('click', function(){ hideModal(schedModal); });
  if (schedModal) {
    const close = schedModal.querySelector('.modal-close-x'); if (close) close.addEventListener('click', function(ev){ try{ ev.stopPropagation(); }catch(e){} hideModal(schedModal); });
  }

  // Schedule save: combine date + time and POST to add_interview.php
  schedSave && schedSave.addEventListener('click', async function(){
    try{
      const date = (schedDate && schedDate.value) ? schedDate.value.trim() : '';
      const time = (schedTime && schedTime.value) ? schedTime.value.trim() : '';
      if (!date || !time) { notify('Please select both Date and Time', '#dc2626'); return; }
      const datetime = date + ' ' + time + ':00';
      // Prevent scheduling in the past: if selected date/time is before now, block
      try{
        const sel = new Date(date + 'T' + time + ':00');
        const now = new Date();
        if (isNaN(sel.getTime())) {
          notify('Invalid date/time selected', '#dc2626'); schedSave.disabled = false; return;
        }
        if (sel.getTime() < now.getTime()) { notify('Cannot schedule an interview in the past', '#dc2626'); schedSave.disabled = false; return; }
      }catch(e){ /* ignore */ }
      const fd = new FormData(); fd.append('applicant_id', String(aid)); fd.append('interview_datetime', datetime);
      // include position_id when available to avoid FK constraint errors
      fd.append('position_id', String(<?php echo json_encode((int)$position_id); ?>));
      // try to choose a sensible default status id: 'Scheduled' else first
      let defaultStatusId = '';
      for (const opt of Array.from(ivStatusRef.options || [])) { if ((opt.textContent||opt.innerText||'').toLowerCase().trim() === 'scheduled') { defaultStatusId = opt.value; break; } }
      if (!defaultStatusId && ivStatusRef.options && ivStatusRef.options.length) defaultStatusId = ivStatusRef.options[0].value;
      fd.append('status_id', defaultStatusId);
      fd.append('location', schedLocation.value || ''); fd.append('result', ''); fd.append('comments', '');
      schedSave.disabled = true;
      const res = await fetch('add_interview.php', { method:'POST', credentials:'same-origin', body: fd });
      const txt = await res.text();
      let j = {};
      try { j = JSON.parse(txt); } catch(parseErr) { notify('Server error scheduling interview: ' + (txt || 'Invalid JSON'), '#dc2626'); return; }
      if (j.ok) {
        hideModal(schedModal);
        // Ensure notify system is ready, attempt retries, and fallback to an inline toast
        async function ensureNotifyGlobal(){
          try {
            if (window.Notify && typeof window.Notify.push === 'function') return;
            // inject CSS if missing
            if (!document.querySelector('link[data-injected="notify-css"]')){
              const l = document.createElement('link'); l.rel='stylesheet'; l.href='assets/css/notify.css'; l.setAttribute('data-injected','notify-css'); document.head.appendChild(l);
            }
            // inject script if missing
            if (!document.querySelector('script[data-injected="notify-js"]')){
              const s = document.createElement('script'); s.src='assets/js/notify.js'; s.async=true; s.setAttribute('data-injected','notify-js');
              document.body.appendChild(s);
              await new Promise((resolve)=>{ s.onload = () => resolve(); s.onerror = () => resolve(); });
            }
            // small wait / retries for Notify to become available
            for (let i = 0; i < 6; i++){
              if (window.Notify && typeof window.Notify.push === 'function') return;
              // wait 80ms before next attempt
              // eslint-disable-next-line no-await-in-loop
              await new Promise(r=>setTimeout(r, 80));
            }
          } catch(e) { console.warn('ensureNotifyGlobal failed', e); }
        }

        function createInlineToast(msg, color){
          try{
            // ensure CSS present
            if (!document.querySelector('link[data-injected="notify-css"]')){
              const l = document.createElement('link'); l.rel='stylesheet'; l.href='assets/css/notify.css'; l.setAttribute('data-injected','notify-css'); document.head.appendChild(l);
            }
            const root = document.getElementById('toast-root') || (function(){ const r = document.createElement('div'); r.id = 'toast-root'; document.body.appendChild(r); return r; })();
            const el = document.createElement('div'); el.className = 'toast'; el.classList.add('show');
            el.style.setProperty('--accent', color || '#16a34a');
            const safe = String(msg || '').replace(/&/g,'&amp;').replace(/</g,'&lt;');
            el.innerHTML = '<div class="toast__body"><div><div class="toast__title">Applicants</div><div class="toast__msg">' + safe + '</div></div><button class="toast__close" aria-label="Close">&times;</button></div><div class="toast__progress"></div>';
            root.appendChild(el);
            const closer = el.querySelector('.toast__close'); if (closer) closer.addEventListener('click', function(){ try{ el.classList.add('hide'); setTimeout(()=>el.remove(),400); }catch(e){} });
            const prog = el.querySelector('.toast__progress'); if (prog) prog.style.animationDuration = '3800ms';
            // auto-remove after a short delay
            setTimeout(function(){ try{ el.classList.add('hide'); setTimeout(()=>{ try{ el.remove(); }catch(e){} }, 650); }catch(e){} }, 4000);
          }catch(e){ console.log('createInlineToast failed', e); }
        }

        try {
          await ensureNotifyGlobal();
          // try a short retry loop to call Notify.push when it becomes available
          if (window.Notify && typeof window.Notify.push === 'function'){
            try { window.Notify.push({ from: 'Applicants', message: 'Interview scheduled', color: '#16a34a' }); } catch(e){ console.warn('Notify.push failed', e); createInlineToast('Interview scheduled', '#16a34a'); }
          } else {
            // fallback to inline toast that does not depend on the library
            createInlineToast('Interview scheduled', '#16a34a');
          }
        } catch(e){ console.warn('notify dispatch failed', e); try{ createInlineToast('Interview scheduled', '#16a34a'); }catch(_){} }

        // refresh the applicant fragment so the ticket shows the new record
        try { await refreshApplicantFragment(); } catch(e){ try { await ensureNotifyGlobal(); if (window.Notify && typeof window.Notify.push === 'function') { window.Notify.push({ from: 'Applicants', message: 'Refresh failed after scheduling', color: '#F59E0B' }); } else { if (typeof notify === 'function') notify('Refresh failed after scheduling', '#f59e0b'); } } catch(_){} }
      } else {
        notify(j.message || 'Failed to schedule interview', '#dc2626');
      }
    }catch(e){ notify('Failed to schedule interview: ' + (e && e.message ? e.message : String(e)), '#dc2626'); }
    finally{ try{ schedSave.disabled = false; }catch(e){} }
  });

  // Open viewer for a given interview object
  async function openViewer(it){
    if (!viewModal) return;
    viewTitle.textContent = 'Interview — ' + (it.interview_datetime ? formatWhen(it.interview_datetime) : ('ID ' + (it.id||'')));
    viewMeta.innerHTML = '<div style="font-weight:700;color:#fff;">' + (<?php echo json_encode(h($app['full_name'])); ?> || '') + '</div>' + (<?php echo json_encode(h($position_title ?: '')); ?> ? '<div style="color:#9aa4b2;font-size:13px;">Position: ' + <?php echo json_encode(h($position_title ?: '')); ?> + '</div>' : '');

    // pick badge color for this interview status from ivStatusRef if available, otherwise fallback mapping
    let badgeColor = '';
    try{ const opt = Array.from(ivStatusRef.options || []).find(o=> String(o.value) === String(it.status_id) || ((o.textContent||o.innerText||'').trim().toLowerCase() === (it.status_name||'').trim().toLowerCase())); if (opt) badgeColor = opt.getAttribute('data-color') || ''; }catch(e){}
    if (!badgeColor) badgeColor = '#374151';
    let badgeTextColor = '#ffffff';
    try{ if (typeof applicantStatusTextColor === 'function') badgeTextColor = applicantStatusTextColor(badgeColor); }catch(e){}

    viewBody.innerHTML = '<div style="color:#cbd5e1;font-size:13px;margin-bottom:6px;"><strong>Status:</strong> <span class="status-badge" style="background:' + badgeColor + ';color:' + badgeTextColor + ';">' + (it.status_name || '') + '</span></div>' +
      '<div style="color:#cbd5e1;font-size:13px;margin-bottom:6px;"><strong>Result:</strong> ' + (it.result || '&mdash;') + '</div>' +
      '<div style="color:#cbd5e1;font-size:13px;margin-bottom:6px;"><strong>Location:</strong> ' + (it.location || '&mdash;') + '</div>' +
      '<div style="color:#cbd5e1;font-size:13px;margin-bottom:6px;"><strong>Comments:</strong><div style="margin-top:6px;color:#ddd;">' + (it.comments ? (it.comments.replace(/\n/g,'<br>')) : '<em>No comments</em>') + '</div></div>';
    viewResult.value = it.result || '';
    viewLocation.value = it.location || '';
    viewComments.value = it.comments || '';
    // attach dataset to modal for later use
    viewModal._currentInterview = it;
    // Populate interview action buttons from server-provided transitions (preferred).
    try{
      const actionsContainer = document.getElementById('iv_view_actions');
      if (actionsContainer) {
        // remove previous dynamic buttons
        Array.from(actionsContainer.querySelectorAll('.iv-dyn-action')).forEach(n=>n.remove());
      }

      // helper to render the fallback Completed/Cancelled buttons
      const showFallbackButtons = function(){
        try{
          if (viewCompleteBtn) { viewCompleteBtn.style.display = 'inline-block'; viewCompleteBtn.style.padding = '8px'; viewCompleteBtn.style.borderRadius = '6px'; }
          if (viewCancelBtn) { viewCancelBtn.style.display = 'inline-block'; viewCancelBtn.style.padding = '8px'; viewCancelBtn.style.borderRadius = '6px'; }
        }catch(e){}
      };

      // Try to fetch transitions for this interview status (prefer id). If none, fallback to showing the default buttons.
      let fromId = it.status_id ? Number(it.status_id) : 0;
      const tryFroms = (fromId === 0) ? [0,1] : [fromId];
      let transitionsJson = null;
      for (const f of tryFroms) {
        try{
          const resp = await fetch('get_status_transitions.php?from=' + encodeURIComponent(f) + '&type=interview', { credentials:'same-origin' });
          const txt = await resp.text();
          try { transitionsJson = JSON.parse(txt); } catch(e){ transitionsJson = null; }
          if (transitionsJson && transitionsJson.ok && Array.isArray(transitionsJson.transitions) && transitionsJson.transitions.length) break;
        } catch(e) { transitionsJson = null; }
      }

      if (!transitionsJson || !transitionsJson.ok || !Array.isArray(transitionsJson.transitions) || !transitionsJson.transitions.length) {
        // Nothing returned — show the old fixed buttons for backward compatibility
        // set colors on fallback buttons where possible
        try{
          const completedOpt = Array.from(ivStatusRef.options || []).find(o => ((o.textContent||o.innerText||'').toString().trim().toLowerCase() === 'completed'));
          if (completedOpt && viewCompleteBtn) { const c = completedOpt.getAttribute('data-color') || '#10b981'; viewCompleteBtn.style.background = c; try{ viewCompleteBtn.style.setProperty('color','#ffffff','important'); }catch(e){ viewCompleteBtn.style.color='#ffffff'; } try{ viewCompleteBtn.style.setProperty('font-weight','700','important'); }catch(e){ viewCompleteBtn.style.fontWeight='700'; } }
          const cancelledOpt = Array.from(ivStatusRef.options || []).find(o => ((o.textContent||o.innerText||'').toString().trim().toLowerCase() === 'cancelled' || (o.textContent||o.innerText||'').toString().trim().toLowerCase() === 'canceled'));
          if (cancelledOpt && viewCancelBtn) { const c2 = cancelledOpt.getAttribute('data-color') || '#ef4444'; viewCancelBtn.style.background = c2; try{ viewCancelBtn.style.setProperty('color','#ffffff','important'); }catch(e){ viewCancelBtn.style.color='#ffffff'; } try{ viewCancelBtn.style.setProperty('font-weight','700','important'); }catch(e){ viewCancelBtn.style.fontWeight='700'; } }
        }catch(e){}
        // if interview is terminal, hide both
        try{
          const st = (it.status_name || (statusMapById[it.status_id]||'')).toString().toLowerCase().trim();
          if (st === 'cancelled' || st === 'completed'){
            if (viewCompleteBtn) viewCompleteBtn.style.display = 'none';
            if (viewCancelBtn) viewCancelBtn.style.display = 'none';
            const existingMsg = document.getElementById('iv_no_actions_msg'); if (existingMsg) existingMsg.remove();
            try{ const msg = document.createElement('div'); msg.id = 'iv_no_actions_msg'; msg.style.color = '#9aa4b2'; msg.style.fontSize = '13px'; msg.style.marginTop = '8px'; msg.textContent = 'No further action applicable.'; const btnContainer = (viewCompleteBtn && viewCompleteBtn.parentElement) || (viewCancelBtn && viewCancelBtn.parentElement); if (btnContainer && btnContainer.parentElement) btnContainer.parentElement.insertBefore(msg, btnContainer); }catch(e){}
          } else {
            showFallbackButtons();
          }
        }catch(e){}
        return;
      }

      // we have transitions; render them as dynamic action buttons
      const transitions = transitionsJson.transitions || [];
      if (!transitions.length) { showFallbackButtons(); return; }
      const holder = document.getElementById('iv_view_actions') || (viewCompleteBtn && viewCompleteBtn.parentElement) || document.body;
      // hide fallback buttons when rendering dynamic ones
      try{ if (viewCompleteBtn) viewCompleteBtn.style.display = 'none'; if (viewCancelBtn) viewCancelBtn.style.display = 'none'; }catch(e){}

      for (const t of transitions) {
        try{
          const b = document.createElement('button'); b.type = 'button'; b.className = 'iv-dyn-action status-action-btn';
          const btnColor = (t.status_color && String(t.status_color).length) ? String(t.status_color) : '#111827';
          b.style.background = btnColor; try{ b.style.setProperty('color','#ffffff','important'); }catch(e){ b.style.color='#ffffff'; } try{ b.style.setProperty('font-weight','700','important'); }catch(e){ b.style.fontWeight='700'; }
          b.style.padding = '8px'; b.style.borderRadius = '6px'; b.style.border = '1px solid rgba(0,0,0,0.12)'; b.style.cursor = 'pointer'; b.textContent = t.to_name || ('Status ' + String(t.to_id));
          b.setAttribute('data-to-id', String(t.to_id));
          // click handler: require comment for cancellation-like transitions
          b.addEventListener('click', async function(){
            const toId = parseInt(this.getAttribute('data-to-id'),10);
            if (isNaN(toId)) return;
            const toName = (t.to_name || '').toString().toLowerCase();
            // if transition looks like cancellation/rejection, require comment
            if (/cancel|reject/i.test(toName)) {
              const comment = (viewComments && viewComments.value || '').trim();
              if (!comment) { notify('Please provide a reason in the comments field before cancelling.', '#dc2626'); return; }
              await updateInterviewStatus(it.id, toId, { result: viewResult.value || '', location: viewLocation.value || '', comments: comment });
            } else {
              await updateInterviewStatus(it.id, toId, { result: viewResult.value || '', location: viewLocation.value || '', comments: viewComments.value || '' });
            }
          });
          holder.appendChild(b);
        }catch(e){ console.warn('render transition failed', e); }
      }

    }catch(e){ console.warn('load interview actions failed', e); }

    showModal(viewModal);
  }

  // wire viewer modal close buttons
  if (viewModal) {
    const close = viewModal.querySelector('.modal-close-x'); if (close) close.addEventListener('click', function(ev){ try{ ev.stopPropagation(); }catch(e){} hideModal(viewModal); });
  }

  // update interview helper (used for Cancelled / Completed)
  async function updateInterviewStatus(interviewId, statusId, payload){
    try{
      const fd = new FormData(); fd.append('id', String(interviewId)); fd.append('status_id', String(statusId));
      if (payload) { Object.keys(payload).forEach(k=>fd.append(k, payload[k])); }
      const res = await fetch('update_interview.php', { method:'POST', credentials:'same-origin', body: fd });
      const txt = await res.text();
      let j = {};
      try { j = JSON.parse(txt); } catch(parseErr) { notify('Server error updating interview: ' + (txt || 'Invalid JSON'), '#dc2626'); return; }
      if (j.ok) {
        notify('Interview updated', '#10b981');
        hideModal(viewModal);
        try{ await refreshApplicantFragment(); }catch(e){ notify('Refresh failed after update', '#f59e0b'); }
      } else {
        notify(j.message || 'Update failed', '#dc2626');
      }
    }catch(e){ notify('Update failed: ' + (e && e.message ? e.message : String(e)), '#dc2626'); }
  }

  // Completed button
  viewCompleteBtn && viewCompleteBtn.addEventListener('click', function(){
    const it = viewModal && viewModal._currentInterview; if (!it) return; // try to find 'Completed' status id
    const target = (function(){ const k = 'completed'; return statusMapByName[k] || (ivStatusRef.options && ivStatusRef.options.length ? ivStatusRef.options[0].value : ''); })();
    updateInterviewStatus(it.id, target, { result: viewResult.value || '', location: viewLocation.value || '', comments: viewComments.value || '' });
  });

  // Cancelled button (requires comment)
  viewCancelBtn && viewCancelBtn.addEventListener('click', function(){
    const it = viewModal && viewModal._currentInterview; if (!it) return;
    const comment = (viewComments && viewComments.value || '').trim();
    if (!comment) { notify('Please provide a reason for cancellation in the comment field', '#dc2626'); return; }
    const target = (function(){ const k = 'cancelled'; return statusMapByName[k] || (ivStatusRef.options && ivStatusRef.options.length ? ivStatusRef.options[0].value : ''); })();
    updateInterviewStatus(it.id, target, { result: viewResult.value || '', location: viewLocation.value || '', comments: comment });
  });

  // refresh applicant fragment (replace current fragment content) used after changes
  async function refreshApplicantFragment(){
    try{
      const fragRes = await fetch('get_applicant.php?applicant_id=' + encodeURIComponent(aid), { credentials:'same-origin' });
      const ftxt = await fragRes.text();
      const ticketEl = document.getElementById('applicantTicket');
      let host = document.getElementById('appViewerContent') || (ticketEl && ticketEl.closest && ticketEl.closest('.modal-card')) || ticketEl;
      if (!host) { // fallback: dispatch event so outer UI can refresh
        try { window.dispatchEvent(new CustomEvent('applicant:updated', { detail: { applicant_id: aid } })); } catch(e){}
        return;
      }
      host.innerHTML = ftxt;
      // execute inlined scripts from the refreshed fragment so handlers rebind
      Array.from(host.querySelectorAll('script')).forEach(function(s){
        try{
          const ns = document.createElement('script');
          if (s.type && s.type.toLowerCase && s.type.toLowerCase() === 'module') ns.type = 'module';
          if (s.src) { ns.src = s.src; ns.async = false; document.body.appendChild(ns); ns.onload = function(){ try{ ns.remove(); }catch(e){} }; }
          else { const raw = s.textContent || s.innerText || ''; ns.type = 'module'; ns.text = raw; document.body.appendChild(ns); try{ ns.remove(); }catch(e){} }
        }catch(err){ console.warn('inject fragment script failed', err); }
      });
    }catch(e){ console.warn('refresh fragment failed', e); }
  }

  // initial load
  try{ load(); }catch(e){ console.warn('initial load failed', e); }

})();
</script>
<script>
(function(){
  // When possible, open the canonical Positions modal so behaviour/layout match exactly.
  // If the positions page modal API isn't present, fall back to the inline loader.
  async function openPositionFromApplicant(id){
    if (!id) return;
    // Preferred flow: use existing openPositionEditor(id) from view_positions.php
    if (window && typeof window.openPositionEditor === 'function') {
      try {
        // Indicate origin so we can adjust modal after it loads
        try { window.__openedFromApplicant = true; } catch (e) {}
        // openPositionEditor is async and resolves after the fragment is injected
        await window.openPositionEditor(id);
        // After the fragment loads, ensure the Applicants details are closed and
        // move the close X to the right and disable status-change buttons.
        try {
          const ov = document.getElementById('posViewerModal');
          if (ov) {
            const content = ov.querySelector('#posViewerContent');
            if (content) {
              const posApplicants = content.querySelector('#posApplicants');
              if (posApplicants) posApplicants.open = false;

              // Ensure header layout and move X to the right
              const header = ov.querySelector('.modal-card .modal-header');
              if (header) {
                header.style.display = 'flex';
                header.style.justifyContent = 'space-between';
                const closeBtn = ov.querySelector('.modal-card .modal-close-x');
                if (closeBtn) header.appendChild(closeBtn);
                // If opened from applicant, increase modal width for better readability
                try {
                  const modalCard = ov.querySelector('.modal-card');
                  if (modalCard) {
                    modalCard.style.width = '90%';
                    modalCard.style.maxWidth = '1100px';
                    modalCard.style.boxSizing = 'border-box';
                  }
                } catch(e) {}
              }

              // Disable status-change controls inside the loaded fragment
              try {
                const statusBtns = content.querySelectorAll('.status-action-btn, .status-action');
                statusBtns.forEach(b => {
                  try { b.disabled = true; b.setAttribute('aria-disabled', 'true'); b.style.opacity = '0.45'; b.style.pointerEvents = 'none'; b.title = 'Disabled when opened from applicant'; } catch (e) {}
                });
                // Add a preventative capture listener while modal is open to stop delegated handlers
                const preventer = function(ev){ const t = ev.target && ev.target.closest && ev.target.closest('.status-action-btn, .status-action'); if (!t) return; if (window.__openedFromApplicant) { ev.stopImmediatePropagation(); ev.preventDefault(); } };
                ov.addEventListener('click', preventer, true);
                document.addEventListener('click', preventer, true);
                // Clean up flag when modal closed: listen for its close X
                const cleanup = function(ev){ if (ev && ev.target && ev.target.closest && ev.target.closest('.modal-close-x')) { try { window.__openedFromApplicant = false; ov.removeEventListener('click', preventer, true); document.removeEventListener('click', preventer, true); ov.removeEventListener('click', cleanup, true); } catch(e){} } };
                ov.addEventListener('click', cleanup, true);
              } catch (e) {}
            }
          }
        } catch (e) { /* non-fatal */ }
        return;
      } catch (err) {
        console.warn('openPositionEditor failed, falling back to inline loader', err);
        // fallthrough to fallback loader
      }
    }

    // Fallback: load into a small inline modal (keeps previous behaviour)
    try {
      let ov = document.getElementById('positionOnTopModal');
      if (!ov) {
        ov = document.createElement('div');
        ov.id = 'positionOnTopModal';
        ov.className = 'modal-overlay';
        ov.style.zIndex = '11050';
        ov.innerHTML = '<div class="modal-card"><div class="modal-header"><h3 id="posOnTopTitle" style="margin:0;">Position</h3><button type="button" class="modal-close-x" aria-label="Close">&times;</button></div><div id="posOnTopContent" style="width:100%;box-sizing:border-box;"></div></div>';
        document.body.appendChild(ov);
        ov.addEventListener('click', function(e){ if (e.target && e.target.closest && e.target.closest('.modal-close-x')) { ov.classList.remove('show'); ov.setAttribute('aria-hidden','true'); try{ ov.style.display='none'; }catch(e){} const c = document.getElementById('posOnTopContent'); if (c) c.innerHTML=''; } });
        document.addEventListener('keydown', function(e){
          try {
            if (e.key !== 'Escape') return;
            // Only close if this modal is the topmost visible modal (last in document order)
            if (!ov.classList.contains('show')) return;
            const shown = Array.from(document.querySelectorAll('.modal-overlay.show'));
            if (!shown.length) return;
            const top = shown[shown.length - 1];
            if (top !== ov) return; // not the topmost, ignore
            ov.classList.remove('show'); ov.setAttribute('aria-hidden','true'); try{ ov.style.display='none'; }catch(e){};
            const c = document.getElementById('posOnTopContent'); if (c) c.innerHTML='';
          } catch(err) { console.warn('positionOnTop ESC handler failed', err); }
        });
      }
      const content = ov.querySelector('#posOnTopContent');
      const title = ov.querySelector('#posOnTopTitle');
      title.textContent = 'Position #' + id;
      content.innerHTML = '<div style="padding:12px;color:#aaa;">Loading...</div>';
      ov.classList.add('show'); ov.setAttribute('aria-hidden','false'); ov.style.display='flex';
      // enlarge fallback modal card for position display when opened from applicant
      try {
        const modalCard = ov.querySelector('.modal-card');
        if (modalCard) {
          modalCard.style.width = '92%';
          modalCard.style.maxWidth = '1100px';
          modalCard.style.boxSizing = 'border-box';
        }
      } catch(e) {}

      const res = await fetch('get_position.php?id=' + encodeURIComponent(id), { credentials:'same-origin' });
      const html = await res.text();
      content.innerHTML = html;
      // run inline scripts from the fragment so event binders attach
      Array.from(content.querySelectorAll('script')).forEach(function(s){
        try{
          const ns = document.createElement('script');
          if (s.type && s.type.toLowerCase && s.type.toLowerCase() === 'module') ns.type = 'module';
          if (s.src) { ns.src = s.src; ns.async = false; document.body.appendChild(ns); ns.onload = function(){ try{ ns.remove(); }catch(e){} }; }
          else { const raw = s.textContent || s.innerText || ''; ns.type = 'module'; ns.text = raw; document.body.appendChild(ns); try{ ns.remove(); }catch(e){} }
        }catch(err){ console.warn('inject fragment script failed', err); }
      });
      // ensure applicants panel is closed for this flow
      try { const posApplicants = content.querySelector('#posApplicants'); if (posApplicants) posApplicants.open = false; } catch(e){}
      // move close X to the right and disable status-change buttons inside fallback modal
      try {
        const header = ov.querySelector('.modal-card .modal-header');
        if (header) { header.style.display = 'flex'; header.style.justifyContent = 'space-between'; const closeBtn = ov.querySelector('.modal-card .modal-close-x'); if (closeBtn) header.appendChild(closeBtn); }
        const statusBtns = content.querySelectorAll('.status-action-btn, .status-action');
        statusBtns.forEach(b=>{ try{ b.disabled = true; b.setAttribute('aria-disabled','true'); b.style.opacity = '0.45'; b.style.pointerEvents = 'none'; b.title = 'Disabled when opened from applicant'; }catch(e){} });
      } catch(e){}
    } catch (e) {
      console.error('openPositionFromApplicant failed', e);
    }
  }

  document.addEventListener('click', function(e){
    const el = e.target && e.target.closest && (e.target.closest('.open-position-link') || e.target.closest('.meta-clickable'));
    if (!el) return;
    try{ e.preventDefault(); }catch(e){}
    const pid = el.getAttribute && el.getAttribute('data-position-id');
    if (pid) openPositionFromApplicant(pid);
  });
})();
</script>
<?php
$frag = ob_get_clean();
echo $frag;
?>