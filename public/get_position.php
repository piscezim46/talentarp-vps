<?php
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: text/html; charset=utf-8');
// start session only if not already active to avoid PHP notice when this
// fragment is loaded via AJAX inside pages that already started a session
if (function_exists('session_status')) {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
} else {
  // fallback for very old PHP: attempt to start session
  @session_start();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) {
    echo '<div class="error">Missing id</div>';
    exit;
}

$sql = "SELECT p.*, COALESCE(s.status_name,'') AS status_name, COALESCE(s.status_color,'') AS status_color FROM positions p LEFT JOIN positions_status s ON p.status_id = s.status_id WHERE p.id = ? LIMIT 1";
 $stmt = $conn->prepare($sql);
 if (!$stmt) {
   echo '<div class="error">Prepare failed: ' . htmlspecialchars($conn->error) . '</div>';
   exit;
 }
 $stmt->bind_param('i', $id);
 $stmt->execute();
 $res = $stmt->get_result();
 $pos = $res ? $res->fetch_assoc() : null;
 $stmt->close();

if (!$pos) {
    echo '<div class="error">Not found</div>';
    exit;
}

// determine director for this department (if any)
$director = '';
if (!empty($pos['department'])) {
    $dstmt = $conn->prepare("SELECT director_name FROM departments WHERE department_name = ? LIMIT 1");
    if ($dstmt) {
        $dstmt->bind_param('s', $pos['department']);
        $dstmt->execute();
        $dres = $dstmt->get_result();
        $drow = $dres ? $dres->fetch_assoc() : null;
        if ($drow && !empty($drow['director_name'])) $director = $drow['director_name'];
        $dstmt->close();
    }
}
if ($director === '') $director = 'Unassigned';

// fetch allowed transitions from positions_status_transitions
// BEFORE this, add current user and permission flag.
// Prefer the structured session `$_SESSION['user']['id']` (used elsewhere).
$currentUserId = 0;
if (isset($_SESSION['user']) && is_array($_SESSION['user']) && isset($_SESSION['user']['id'])) {
  $currentUserId = (int)$_SESSION['user']['id'];
} elseif (isset($_SESSION['user_id'])) {
  $currentUserId = (int)$_SESSION['user_id'];
} elseif (isset($_SESSION['id'])) {
  $currentUserId = (int)$_SESSION['id'];
}

// A user can edit only when the position is in 'Open' (status_id === 1)
// and the current user is the creator (created_by matches the user's id).
$isCreator = ($currentUserId > 0) && ((int)$pos['created_by'] === $currentUserId);
$inCreatedStatus = ((int)$pos['status_id'] === 1);
$canEdit = $inCreatedStatus && $isCreator;

$transitions = [];
$tstmt = $conn->prepare("
  SELECT t.to_status_id, COALESCE(s.status_name,'') AS status_name, COALESCE(s.active,0) AS status_active
  FROM positions_status_transitions t
  LEFT JOIN positions_status s ON t.to_status_id = s.status_id
  WHERE t.from_status_id = ? AND t.active = 1 AND COALESCE(s.active,0) = 1
  ORDER BY t.to_status_id
");
if ($tstmt) {
    $tstmt->bind_param('i', $pos['status_id']);
    $tstmt->execute();
    $tres = $tstmt->get_result();
    while ($tr = $tres->fetch_assoc()) {
      // include all configured transitions (SQL filters by t.active and s.active)
      $transitions[] = $tr;
    }
    $tstmt->close();
}

// fetch combined history (status changes + field changes) and render in a unified Activity feed
$historyHtml = '<div style="color:#bbb;padding:8px;">No activity yet.</div>';
$entries = [];

// load status history if table exists
$s_check = $conn->query("SHOW TABLES LIKE 'positions_status_history'");
if ($s_check && $s_check->num_rows > 0) {
  $hs = $conn->prepare(
    "SELECT h.history_id AS id, h.position_id, h.status_id, COALESCE(s.status_name,'') AS status_name, h.updated_by, h.updated_at, h.reason
     FROM positions_status_history h
     LEFT JOIN positions_status s ON h.status_id = s.status_id
     WHERE h.position_id = ?"
  );
  if ($hs) {
    $hs->bind_param('i', $id);
    $hs->execute();
    $hres = $hs->get_result();
    while ($r = $hres->fetch_assoc()) {
      $entries[] = array_merge($r, ['type' => 'status', 'ts' => $r['updated_at']]);
    }
    $hs->close();
  }
}

// load field-change history if table exists
$c_check = $conn->query("SHOW TABLES LIKE 'positions_history'");
if ($c_check && $c_check->num_rows > 0) {
  $hc = $conn->prepare("SELECT id, position_id, changed_by, change_type, field_name, old_value, new_value, created_at FROM positions_history WHERE position_id = ?");
  if ($hc) {
    $hc->bind_param('i', $id);
    $hc->execute();
    $cres = $hc->get_result();
    while ($r = $cres->fetch_assoc()) {
      $entries[] = array_merge($r, ['type' => 'change', 'ts' => $r['created_at']]);
    }
    $hc->close();
  }
}

// sort combined entries by timestamp desc
usort($entries, function($a, $b){
  $ta = strtotime($a['ts'] ?? '1970-01-01');
  $tb = strtotime($b['ts'] ?? '1970-01-01');
  return $tb <=> $ta;
});

  if (count($entries) > 0) {
  $historyHtml = '';
  foreach ($entries as $h) {
    if (($h['type'] ?? '') === 'status') {
      $who = htmlspecialchars($h['updated_by'] ?: 'System');
      $at = htmlspecialchars($h['updated_at'] ?? '');
      $statusName = htmlspecialchars($h['status_name'] ?: '');
      $reason = nl2br(htmlspecialchars($h['reason'] ?? ''));
      $historyHtml .= '<div style="padding:8px;border-bottom:1px solid rgba(255,255,255,0.03);">';
      $historyHtml .= "<div style=\"font-size:13px;\"><strong>Status</strong> — <span class=\"history-by\">by {$who}</span> <span class=\"history-ts\">{$at}</span></div>";
      $historyHtml .= "<div style=\"margin-top:6px;font-size:13px;\"><em>To:</em> <strong>{$statusName}</strong>";
      if (strlen(trim(strip_tags($reason))) > 0) $historyHtml .= "<div style=\"margin-top:6px;font-size:13px;\">Reason: {$reason}</div>";
      $historyHtml .= '</div>';
    } else {
      $who = htmlspecialchars($h['changed_by'] ?? ($h['updated_by'] ?? 'System'));
      $at = htmlspecialchars($h['created_at'] ?? ($h['updated_at'] ?? ''));
      $field = htmlspecialchars($h['field_name'] ?: $h['change_type']);
      $old = nl2br(htmlspecialchars($h['old_value'] ?? ''));
      $new = nl2br(htmlspecialchars($h['new_value'] ?? ''));
      $historyHtml .= '<div style="padding:8px;border-bottom:1px solid rgba(255,255,255,0.03);">';
      $historyHtml .= "<div style=\"font-size:13px;\"><strong>{$field}</strong> — <span class=\"history-by\">by {$who}</span> <span class=\"history-ts\">{$at}</span></div>";
      $historyHtml .= "<div style=\"margin-top:6px;font-size:13px;\"><em>From:</em> {$old}<br><em>To:</em> {$new}</div>";
      $historyHtml .= '</div>';
    }
  }
}

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

// helper: server-side readable text color for an input hex like #rrggbb or shorthand
function status_text_color_local($hex) {
  $hex = ltrim((string)$hex, '#');
  if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
  if (strlen($hex) !== 6) return '#ffffff';
  $r = hexdec(substr($hex,0,2));
  $g = hexdec(substr($hex,2,2));
  $b = hexdec(substr($hex,4,2));
  $luma = (0.299*$r + 0.587*$g + 0.114*$b);
  return ($luma > 186) ? '#111111' : '#ffffff';
}

// option lists (same choices used in create modal)
$genders = ['' => '-- Select Gender --', 'Any' => 'Any', 'Male' => 'Male', 'Female' => 'Female'];
$ethnicities = ['' => '-- Select Ethnicity --', 'Any' => 'Any', 'Arab' => 'Arab', 'Foreign' => 'Foreign'];
$work_locations = ['' => '-- Select Work Location --', 'Remote'=>'Remote','HQ'=>'HQ','Kuwait Office'=>'Kuwait Office','Saudi Office'=>'Saudi Office','Dubai Office'=>'Dubai Office','Hybrid'=>'Hybrid'];
$reasons = ['' => '-- Select Reason --', 'Replacement'=>'Replacement','Vacancy'=>'Vacancy','New Position'=>'New Position','Temporary'=>'Temporary'];
$working_hours_opts = ['' => '-- Select Working Hours --', '9-5'=>'9-5','Shifts'=>'Shifts'];
$education_opts = ['' => '-- Select Education --', 'Bachelors'=>'Bachelors','Masters'=>'Masters','PHD'=>'PHD','REAL LIFE EXPERIENCE'=>'REAL LIFE EXPERIENCE'];
$employment_opts = ['' => '-- Select Employment Type --', 'Full-time'=>'Full-time','Part-time'=>'Part-time','Contract'=>'Contract','Internship'=>'Internship'];

// produce HTML fragment into a buffer, then add `disabled` to inputs/selects/textareas that lack it
ob_start();
?>
<div id="posEditForm" class="pos-edit-form">
  <style>
    /* unified action button style for status/edit/reset/update */
    .pos-action-btn {
      appearance: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      padding: 8px 12px;              /* fixed size */
      border-radius: 8px;
      border: 1px solid rgba(255,255,255,0.14);
      background: rgba(255,255,255,0.06);
      color: #e5e7eb;
      font-size: 13px;
      line-height: 1.2;
      white-space: nowrap;
      cursor: pointer;
      transition: background-color .15s ease, color .15s ease, box-shadow .15s ease, border-color .15s ease;
      text-decoration: none;
    }
    .pos-action-btn:hover {
      /* no size change; only subtle visual feedback */
      background: rgba(255,255,255,0.10);
      border-color: rgba(255,255,255,0.20);
      box-shadow: 0 0 0 2px rgba(255,255,255,0.05) inset;
    }
    .pos-action-btn:active {
      background: rgba(255,255,255,0.14);
      border-color: rgba(255,255,255,0.24);
    }
    .pos-action-btn:focus-visible {
      outline: 2px solid rgba(245,158,11,0.8); /* amber focus ring */
      outline-offset: 2px;
    }
    /* respect locked state applied by JS (keeps toast on click via gate()) */
    .pos-action-btn[aria-disabled="true"],
    .pos-action-btn[data-locked="1"] {
      /* Keep pointer interactions disabled when JS marks the control locked. Visual styling
         (opacity/cursor) is handled by JS to avoid conflicting transitions. */
      pointer-events: none;
    }
  </style>
   <input type="hidden" name="id" id="edit_id" value="<?php echo h($pos['id']); ?>">
   <!-- permission meta (creator + created/open status) -->
   <?php
     // determine whether current user has the 'approve' access key
     $canApprove = false;
     if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
       $ak = $_SESSION['user']['access_keys'] ?? ($_SESSION['user']['access'] ?? []);
       if (is_array($ak) && in_array('positions_approve', $ak, true)) $canApprove = true;
     }
   ?>
   <span id="editPermMeta"
     data-can-edit="<?php echo $canEdit ? '1' : '0'; ?>"
     data-is-creator="<?php echo $isCreator ? '1' : '0'; ?>"
     data-in-created-status="<?php echo $inCreatedStatus ? '1' : '0'; ?>"
     data-creator-id="<?php echo (int)$pos['created_by']; ?>"
     data-can-approve="<?php echo $canApprove ? '1' : '0'; ?>"
     style="display:none"></span>
  <!-- Modal header with title + status chip -->
  <div style="display:flex;align-items:center;gap:10px;margin:0 0 12px 0;">
    <?php
      $chipBg = (!empty($pos['status_color']) ? $pos['status_color'] : '#6b7280');
      $chipTxt = status_text_color_local($chipBg);
    ?>
        <span id="editPosStatusChip" class="status-badge"
          data-status-id="<?php echo (int)$pos['status_id']; ?>"
          style="margin-left:8px;padding:4px 10px;border-radius:999px;font-size:15px;vertical-align:middle;
                 border:1px solid rgba(0,0,0,0.06);background: <?php echo h($chipBg); ?>; color: <?php echo h($chipTxt); ?>;">
      <?php echo h($pos['status_name'] ?: 'Unknown'); ?>
    </span>
  </div>

  <div class="field">
    <label>Title</label>
    <input id="edit_title" name="title" class="modal-input" value="<?php echo h($pos['title']); ?>">
  </div>

  <div class="field inline">
    <div>
      <label>Department</label>
      <input id="edit_department" name="department" class="modal-input" value="<?php echo h($pos['department']); ?>">
    </div>

    <div>
      <label>Team</label>
      <input id="edit_team" name="team" class="modal-input" value="<?php echo h($pos['team']); ?>">
    </div>

    <div>
      <label>Director</label>
      <input id="edit_director" name="director" class="modal-input" value="<?php echo h($director); ?>" disabled>
    </div>

    <div>
      <label>Manager</label>
      <input id="edit_manager_name" name="manager_name" class="modal-input" value="<?php echo h($pos['manager_name']); ?>">
    </div>
  </div>

  <div class="field inline" style="gap:10px;">
    <div>
      <label>Experience Level</label>
      <input id="edit_experience_level" name="experience_level" class="modal-input" value="<?php echo h($pos['experience_level']); ?>">
    </div>

    <div>
      <label>Minimum Age</label>
      <input id="edit_min_age" name="min_age" type="number" min="0" class="modal-input" value="<?php echo h($pos['min_age']); ?>">
    </div>

    <div>
      <label>Gender</label>
      <select id="edit_gender" name="gender" class="modal-input">
        <?php
        foreach ($genders as $val => $label) {
            $sel = ($val !== '' && $val === $pos['gender']) ? ' selected' : '';
            echo '<option value="'.h($val).'"'.$sel.'>'.h($label).'</option>';
        }
        ?>
      </select>
    </div>

    <div>
      <label>Ethnicity</label>
      <select id="edit_ethnicity" name="nationality_requirement" class="modal-input">
        <?php
        foreach ($ethnicities as $val => $label) {
            $sel = ($val !== '' && $val === $pos['nationality_requirement']) ? ' selected' : '';
            echo '<option value="'.h($val).'"'.$sel.'>'.h($label).'</option>';
        }
        ?>
      </select>
    </div>
  </div>

  <div class="field inline" style="gap:10px;">
    <div>
      <label>Work Location</label>
      <select id="edit_work_location" name="work_location" class="modal-input">
        <?php
        foreach ($work_locations as $val => $label) {
            $sel = ($val !== '' && $val === $pos['work_location']) ? ' selected' : '';
            echo '<option value="'.h($val).'"'.$sel.'>'.h($label).'</option>';
        }
        ?>
      </select>
    </div>

    <div>
      <label>Reason for Opening</label>
      <select id="edit_reason_for_opening" name="reason_for_opening" class="modal-input">
        <?php
        foreach ($reasons as $val => $label) {
            $sel = ($val !== '' && $val === $pos['reason_for_opening']) ? ' selected' : '';
            echo '<option value="'.h($val).'"'.$sel.'>'.h($label).'</option>';
        }
        ?>
      </select>
    </div>

    <div>
      <label>Working Hours</label>
      <select id="edit_working_hours" name="working_hours" class="modal-input">
        <?php
        foreach ($working_hours_opts as $val => $label) {
            $sel = ($val !== '' && $val === $pos['working_hours']) ? ' selected' : '';
            echo '<option value="'.h($val).'"'.$sel.'>'.h($label).'</option>';
        }
        ?>
      </select>
    </div>

    <div>
      <label>Education Level</label>
      <select id="edit_education_level" name="education_level" class="modal-input">
        <?php
        foreach ($education_opts as $val => $label) {
            $sel = ($val !== '' && $val === $pos['education_level']) ? ' selected' : '';
            echo '<option value="'.h($val).'"'.$sel.'>'.h($label).'</option>';
        }
        ?>
      </select>
    </div>
  </div>

  <div class="field inline" style="gap:10px;">
    <div>
      <label>Employment Type</label>
      <select id="edit_employment_type" name="employment_type" class="modal-input">
        <?php
        foreach ($employment_opts as $val => $label) {
            $sel = ($val !== '' && $val === $pos['employment_type']) ? ' selected' : '';
            echo '<option value="'.h($val).'"'.$sel.'>'.h($label).'</option>';
        }
        ?>
      </select>
    </div>

    <div>
      <label>Openings</label>
      <input id="edit_openings" name="openings" type="number" class="modal-input" value="<?php echo h($pos['openings']); ?>">
    </div>

    <div>
      <label>Salary</label>
      <input id="edit_salary" name="salary" type="number" class="modal-input" value="<?php echo h($pos['salary']); ?>">
    </div>

    <div>
      <label>Hiring Deadline</label>
      <input id="edit_hiring_deadline" name="hiring_deadline" type="date" class="modal-input" value="<?php echo (isset($pos['hiring_deadline']) ? h(explode(" ", $pos['hiring_deadline'])[0]) : ''); ?>">
    </div>
  </div>

  <div class="field">
    <label>Description</label>
    <textarea id="edit_description" name="description" rows="6" class="modal-input"><?php echo h($pos['description']); ?></textarea>
  </div>

  <div class="field">
    <label>Role Responsibilities</label>
    <textarea id="edit_role_responsibilities" name="role_responsibilities" rows="4" class="modal-input"><?php echo h($pos['role_responsibilities']); ?></textarea>
  </div>

  <div class="field">
    <label>Role Expectations</label>
    <textarea id="edit_role_expectations" name="role_expectations" rows="4" class="modal-input"><?php echo h($pos['role_expectations']); ?></textarea>
  </div>

  <div class="field">
    <label>Requirements</label>
    <textarea id="edit_requirements" name="requirements" rows="3" class="modal-input"><?php echo h($pos['requirements']); ?></textarea>
  </div>

  <div class="field inline" style="align-items:center;">
    <!-- Right-side controls: Status actions + Reset/Edit/Update -->
    <div style="margin-left:auto;flex:1 1 auto;">
       <label>&nbsp;</label>
      <div style="display:flex;gap:8px;justify-content:flex-end;align-items:center;flex-wrap:wrap;width:100%;">
        <?php
          // Status action buttons (moved to the right beside other buttons)
          $btns = [];
          $currentId = (int)$pos['status_id'];
          // only allow status action rendering for users who can approve
          if (empty($canApprove)) {
            echo '<span class="small-muted" style="padding:8px;">You do not have permission to change status</span>';
          } else {
            // prepare a small statement to fetch the color for a status id
            $colorStmt = $conn->prepare("SELECT COALESCE(status_color,'') AS status_color FROM positions_status WHERE status_id = ? LIMIT 1");
            foreach ($transitions as $tr) {
              $to = (int)$tr['to_status_id'];
              $name = $tr['status_name'] ?: '';
              // Note: do not special-case status id 6 here; let transitions and update handler decide
              $label = $name;
              if (strcasecmp($name, 'Shortclose') === 0) $label = 'Short-Close';
              if ($to === 1 && $currentId === 9) $label = 'Re-open'; // Rejected->Open shows Re-open
              $btnColor = '';
              if ($colorStmt) {
                try {
                  $colorStmt->bind_param('i', $to);
                  $colorStmt->execute();
                  $cres = $colorStmt->get_result();
                  $crow = $cres ? $cres->fetch_assoc() : null;
                  if ($crow && !empty($crow['status_color'])) $btnColor = $crow['status_color'];
                  $colorStmt->free_result();
                } catch (Throwable $e) { /* ignore */ }
              }
              if (!$btnColor) $btnColor = '#ffffff';
              $txt = status_text_color_local($btnColor);
              $style = 'style="background: ' . h($btnColor) . '; color: ' . h($txt) . '; border:1px solid rgba(0,0,0,0.06);"';
              // initially disable the status action buttons for a short hold to avoid
              // immediate re-click/race conditions when reopening the modal rapidly.
              $btns[] = '<button type="button" class="pos-action-btn status-action-btn" data-to="'.$to.'" data-label="'.h($label).'" '. $style . ' disabled aria-disabled="true" data-init-hold="1">' . h($label) . '</button>';
            }
            if ($colorStmt) $colorStmt->close();
            if (count($btns)) {
              echo implode('', $btns);
              // Add a small inline script to enable those buttons after a 2 second hold.
              // Scope to the viewer content to avoid affecting other pages.
              echo '<script>(function(){try{var container = document.getElementById("posViewerContent") || document; var btns = container.querySelectorAll("button.pos-action-btn.status-action-btn[data-init-hold=\'1\']"); if(!btns || btns.length===0) return; setTimeout(function(){ try{ btns.forEach(function(b){ b.disabled = false; b.removeAttribute("aria-disabled"); b.removeAttribute("data-init-hold"); }); }catch(e){} }, 2000);}catch(e){console && console.warn && console.warn("status-hold",e);} })();</script>';
            } else {
              // No available next statuses (either no transitions configured or targets are inactive)
              echo '<span class="small-muted" style="padding:8px;">No further action is applicable due to flow setup</span>';
            }
          }
        ?>
         <button id="posResetBtn" type="button" class="btn-orange pos-action-btn">Reset</button>
         <button id="posEditBtn" type="button" class="btn-orange pos-action-btn" data-mode="edit">Edit</button>
         <button id="posUpdateBtn" type="button" class="btn-orange pos-action-btn" data-mode="update" style="display:none;">Update</button>
       </div>
     </div>
   </div>

  <!-- Hidden mirror (kept for script convenience if needed by refresh) -->
  <span id="posStatusName" data-status-id="<?php echo (int)$pos['status_id']; ?>" style="display:none;"><?php echo h($pos['status_name'] ?: ''); ?></span>

  <hr>

  <details id="posHistory" style="color:#111;">
    <summary style="cursor:pointer;font-weight:700;">Activity / History</summary>
    <div id="posHistoryList" style="margin-top:10px;"><?php echo $historyHtml; ?></div>
  </details>
  
  <?php
  // Fetch applicants linked to this position (position_id FK)
  $appHtml = '<div style="color:#888;padding:8px;">No applicants found for this position.</div>';
  $aStmt = $conn->prepare(
    "SELECT a.applicant_id, a.full_name, COALESCE(a.skills,'') AS skills, COALESCE(a.age,'') AS age, COALESCE(a.gender,'') AS gender, COALESCE(a.years_experience,'') AS years_experience, a.created_at, a.status_id, COALESCE(s.status_name,'') AS status_name FROM applicants a LEFT JOIN applicants_status s ON a.status_id = s.status_id WHERE a.position_id = ? ORDER BY a.created_at DESC"
  );
  if ($aStmt) {
    $aStmt->bind_param('i', $id);
    $aStmt->execute();
    $aRes = $aStmt->get_result();
    $rows = [];
    while ($ar = $aRes->fetch_assoc()) $rows[] = $ar;
    $aStmt->close();

    if (count($rows) > 0) {
      // build table with centered columns and clickable rows
      $appHtml = '<div style="margin-top:10px;overflow:auto"><table style="width:100%;border-collapse:collapse;color:#111;background:transparent;">';
      $appHtml .= '<thead><tr style="border-bottom:1px solid rgba(255,255,255,0.06);">';
      $appHtml .= '<th style="padding:8px;text-align:center;">ID</th>';
      $appHtml .= '<th style="padding:8px;text-align:center;">Status</th>';
      $appHtml .= '<th style="padding:8px;text-align:center;">Full name</th>';
      $appHtml .= '<th style="padding:8px;text-align:center;">Skills</th>';
      $appHtml .= '<th style="padding:8px;text-align:center;">Age</th>';
      $appHtml .= '<th style="padding:8px;text-align:center;">Gender</th>';
      $appHtml .= '<th style="padding:8px;text-align:center;">Years Exp</th>';
      $appHtml .= '<th style="padding:8px;text-align:center;">Created At</th>';
      $appHtml .= '</tr></thead><tbody>';
      foreach ($rows as $r) {
        $aid = (int)$r['applicant_id'];
        $fname = htmlspecialchars($r['full_name'] ?? '');
        $skills = htmlspecialchars($r['skills'] ?? '');
        $age = htmlspecialchars((string)($r['age'] ?? ''));
        $gender = htmlspecialchars($r['gender'] ?? '');
        $yrs = htmlspecialchars((string)($r['years_experience'] ?? ''));
        $created = htmlspecialchars($r['created_at'] ?? '');
        $sname = htmlspecialchars($r['status_name'] ?? '');

      // open applicant ticket in a new tab when row clicked — direct to applicants.php
      $appHtml .= '<tr onclick="window.open(\'applicants.php?applicant_id=' . $aid . '\', \'_blank\')" style="cursor:pointer;border-bottom:1px solid rgba(255,255,255,0.03);">';
        $appHtml .= '<td style="padding:8px;text-align:center;vertical-align:middle;">#' . $aid . '</td>';
        $appHtml .= '<td style="padding:8px;text-align:center;vertical-align:middle;">' . $sname . '</td>';
        $appHtml .= '<td style="padding:8px;text-align:center;vertical-align:middle;">' . $fname . '</td>';
        $appHtml .= '<td style="padding:8px;text-align:center;vertical-align:middle;">' . $skills . '</td>';
        $appHtml .= '<td style="padding:8px;text-align:center;vertical-align:middle;">' . $age . '</td>';
        $appHtml .= '<td style="padding:8px;text-align:center;vertical-align:middle;">' . $gender . '</td>';
        $appHtml .= '<td style="padding:8px;text-align:center;vertical-align:middle;">' . $yrs . '</td>';
        $appHtml .= '<td style="padding:8px;text-align:center;vertical-align:middle;">' . $created . '</td>';
        $appHtml .= '</tr>';
      }
      $appHtml .= '</tbody></table></div>';
    }
  }
  ?>

  <?php $appCount = isset($rows) ? count($rows) : 0; ?>
  <details id="posApplicants" open style="color:#111;margin-top:12px;">
    <summary style="cursor:pointer;font-weight:700;">Applicants linked to this position (<?php echo $appCount; ?>)</summary>
    <div id="posApplicantsList" style="margin-top:10px;"><?php echo $appHtml; ?></div>
  </details>
</div>
<?php
  $frag = ob_get_clean();

  // add disabled attribute to inputs/selects/textareas that do NOT already include it,
  // but DO NOT add disabled to hidden inputs (so the hidden id remains sent)
  $frag = preg_replace_callback(
    '/<(?P<tag>input|select|textarea)(?P<attrs>[^>]*)>/i',
    function($m){
      $tag = strtolower($m['tag']);
      $attrs = $m['attrs'];
      // already disabled -> leave alone
      if (preg_match('/\bdisabled\b/i', $attrs)) return "<{$tag}{$attrs}>";
      // do not add disabled to hidden inputs
      if ($tag === 'input' && preg_match('/\btype\s*=\s*(["\']?)hidden\\1/i', $attrs)) return "<{$tag}{$attrs}>";
      return "<{$tag}{$attrs} disabled>";
    },
    $frag
  );
 
   echo $frag;

  // BEGIN: injected binder script (Edit/Update/Reset visibility & behaviour)
  echo <<<'SCRIPT'
<script>
(function(){
  try {
    var form = document.getElementById('posEditForm');
    // Ensure existing code that calls Notify.push doesn't fail — delegate to our resilient wrapper
    if (typeof window.__showPosNotify === 'function' && !(window.Notify && typeof window.Notify.push === 'function')) {
      window.Notify = window.Notify || {};
      window.Notify.push = window.__showPosNotify;
    }
    if (!form) {
      // Safety: notify if wrapper missing
      (function(){
        function ensure(){ return new Promise(r=>{ if (window.Notify?.push) return r(); var s=document.createElement('script'); s.src='assets/js/notify.js'; s.onload=r; document.head.appendChild(s); }); }
        ensure().then(function(){ Notify.push({ from:'Positions', message:'UI not ready. Please reload this ticket.', color:'#dc2626' }); });
      })();
      return;
    }
  var permMeta = document.getElementById('editPermMeta');
  var CAN_EDIT = !!(permMeta && permMeta.dataset.canEdit === '1');
  var IS_CREATOR = !!(permMeta && permMeta.dataset.isCreator === '1');
  var IN_CREATED_STATUS = !!(permMeta && permMeta.dataset.inCreatedStatus === '1');

    function ensureNotifyAssets(){
      return new Promise(function(resolve){
        if (window.Notify && typeof window.Notify.push === 'function') return resolve();
        var done=false; function finish(){ if(!done){ done=true; resolve(); } }
        if (!document.querySelector('link[href*="assets/css/notify.css"]')) {
          var ln=document.createElement('link'); ln.rel='stylesheet'; ln.href='assets/css/notify.css'; document.head.appendChild(ln);
        }
        var s=document.createElement('script'); s.src='assets/js/notify.js'; s.onload=finish; s.onerror=finish; document.head.appendChild(s);
        setTimeout(function(){ if (!(window.Notify && typeof window.Notify.push==='function')) finish(); }, 200);
      });
    }

    // Robust notify helper: attempts to use Notify.push, falls back to a simple inline banner
    window.__showPosNotify = function(opts){
      try {
        var payload = (typeof opts === 'string') ? { from: 'Positions', message: opts } : (opts || {});
      } catch(e) { payload = { from: 'Positions', message: String(opts) }; }
      // ensure assets then try to push; always return a resolved promise
      ensureNotifyAssets().then(function(){
        try {
          if (window.Notify && typeof window.Notify.push === 'function') {
            window.Notify.push(payload);
            return;
          }
        } catch (e) { console.error('Notify push failed', e); }
        // fallback: create a temporary inline banner at top of modal
        try {
          var existing = document.getElementById('__pos_notify_fallback');
          if (existing) existing.remove();
          var b = document.createElement('div');
          b.id = '__pos_notify_fallback';
          b.style.position = 'fixed';
          b.style.zIndex = 1400;
          b.style.left = '50%';
          b.style.top = '24px';
          b.style.transform = 'translateX(-50%)';
          b.style.background = payload.color || '#111827';
          b.style.color = '#fff';
          b.style.padding = '10px 14px';
          b.style.borderRadius = '8px';
          b.style.boxShadow = '0 6px 18px rgba(0,0,0,0.2)';
          b.textContent = (payload.from ? payload.from + ': ' : '') + (payload.message || 'Notice');
          document.body.appendChild(b);
          setTimeout(function(){ try{ b.remove(); }catch(e){} }, (payload.duration || 4000));
        } catch (e) { console.error('notify fallback failed', e); }
      }).catch(function(){ /* no-op */ });
      return Promise.resolve();
    };

    function setHeaderStatusChip(){
      try {
        var chip = document.getElementById('editPosStatusChip');
        var hidden = document.getElementById('posStatusName');
        if (chip && hidden) {
          chip.textContent = (hidden.textContent || '').trim() || 'Unknown';
          chip.dataset.statusId = hidden.dataset.statusId || '';
        }
      } catch(e){}
    }

    var inputs = Array.prototype.slice.call(form.querySelectorAll('.modal-input, input, textarea, select'));
    inputs.forEach(function(i){ try { i.disabled = true; i.setAttribute('disabled','disabled'); } catch(e){} });
    var snap = {};
    inputs.forEach(function(i){ try { if (!i.id) return; snap[i.id.replace(/^edit_/,'')] = (i.type === 'checkbox' ? (i.checked ? '1':'0') : i.value); } catch(e){} });
    window.__posEditSnapshot = { pos: snap };

    var resetBtn = form.querySelector('#posResetBtn');
    var editBtn  = form.querySelector('#posEditBtn');
    var applyBtn = form.querySelector('#posUpdateBtn');

    function setBtnLocked(btn, locked, tooltip){
      if (!btn) return;
      if (locked) {
        btn.setAttribute('aria-disabled','true');
        btn.dataset.locked = '1';
        btn.style.opacity = '0.55';
        btn.style.cursor = 'not-allowed';
        if (tooltip) btn.title = tooltip;
      } else {
        btn.removeAttribute('aria-disabled');
        btn.dataset.locked = '0';
        btn.style.opacity = '';
        btn.style.cursor = '';
        btn.title = '';
      }
    }

    function gate(handler){
      return async function(ev){
        ev && ev.preventDefault && ev.preventDefault();
        if (!CAN_EDIT || (this && this.dataset && this.dataset.locked === '1')) {
          await ensureNotifyAssets();
          // Provide a clearer reason: either the user is not the creator,
          // or the ticket is no longer in the created/open status.
          if (!IS_CREATOR) {
            Notify.push({ from:'Positions', message:'Access limited to creator', color:'#dc2626' });
          } else if (!IN_CREATED_STATUS) {
            Notify.push({ from:'Positions', message:'Changes are only applicable when the position is in the Created status', color:'#dc2626' });
          } else {
            Notify.push({ from:'Positions', message:'Access limited', color:'#dc2626' });
          }
          return;
        }
        return handler.apply(this, arguments);
      };
    }

    function showEditMode(){
      inputs.forEach(function(i){ try { i.disabled = true; i.setAttribute('disabled','disabled'); } catch(e){} });
      if (applyBtn) applyBtn.style.display = 'none';
      if (editBtn) { editBtn.style.display = ''; setBtnLocked(editBtn, !CAN_EDIT, 'Access limited to creator'); }
      if (resetBtn){ setBtnLocked(resetBtn, !CAN_EDIT, 'Access limited to creator'); }
    }
    function showApplyMode(){
      if (!CAN_EDIT) { ensureNotifyAssets().then(()=>Notify.push({from:'Positions', message:'Access limited to creator', color:'#dc2626'})); return; }
      var keepDisabled = ['edit_department','edit_team','edit_director','edit_manager_name'];
      inputs.forEach(function(i){
        try {
          if (keepDisabled.indexOf(i.id) !== -1) { i.disabled = true; i.setAttribute('disabled','disabled'); }
          else { i.disabled = false; i.removeAttribute('disabled'); }
        } catch(e){}
      });
      if (editBtn) editBtn.style.display = 'none';
      if (applyBtn) applyBtn.style.display = '';
      var first = inputs.find(function(x){ return x && !x.disabled; });
      if (first) try { first.focus(); } catch(e){}
    }

    if (resetBtn) {
      resetBtn.addEventListener('click', gate(function(ev){
        ev.preventDefault();
        var s = (window.__posEditSnapshot && window.__posEditSnapshot.pos) || {};
        Object.keys(s).forEach(function(k){
          var el = document.getElementById('edit_' + k);
          if (!el) return;
          try { if (el.type === 'checkbox') el.checked = (s[k] === '1'); else el.value = s[k] ?? ''; } catch(e){}
        });
        showEditMode();
      }), false);
      resetBtn.dataset.bound = '1';
    }
    if (editBtn) {
      editBtn.addEventListener('click', gate(function(ev){ showApplyMode(); }), false);
      editBtn.dataset.bound = '1';
    }
    if (applyBtn) {
      applyBtn.addEventListener('click', gate(async function(ev){
        ev.preventDefault();
        var btn = this;
        var fd = new FormData();
        inputs.forEach(function(el){
          try {
            if (!el.name) return;
            var tag = (el.tagName || '').toLowerCase();
            if (el.type === 'checkbox') fd.append(el.name, el.checked ? '1':'0');
            else if (tag === 'select' && el.multiple) Array.from(el.selectedOptions).forEach(function(opt){ fd.append(el.name+'[]', opt.value); });
            else fd.append(el.name, el.value ?? '');
          } catch(e){}
        });
        var idv = fd.get('id') || (document.getElementById('edit_id') && document.getElementById('edit_id').value);
        if (!idv) { await ensureNotifyAssets(); Notify.push({ from:'Positions', message:'Missing id', color:'#dc2626' }); return; }

        // compute which fields the user actually changed compared to the initial snapshot
        var submittedChanges = [];
        try {
          var snapPos = (window.__posEditSnapshot && window.__posEditSnapshot.pos) || {};
          inputs.forEach(function(el){
            try {
              if (!el.name) return;
              var key = (el.id || '').replace(/^edit_/, '') || el.name;
              var val = (el.type === 'checkbox') ? (el.checked ? '1' : '0') : (el.value ?? '');
              var old = (typeof snapPos[key] !== 'undefined') ? String(snapPos[key]) : '';
              if (String(val) !== String(old)) submittedChanges.push(key);
            } catch(e){}
          });
        } catch(e) { submittedChanges = []; }

        btn.disabled = true;
        try {
          var res = await fetch('update_position.php', { method:'POST', credentials:'same-origin', body: fd });
          var text = await res.text();
          var json = {};
          try { json = JSON.parse(text); } catch(e) {
            console.error('update_position parse error', text);
            await ensureNotifyAssets(); Notify.push({ from:'Positions', message:'Update failed (invalid response)', color:'#dc2626' });
            return;
          }
          if (!json.ok) {
            await ensureNotifyAssets();
            var serverMsg = (json && (json.message || json.error)) ? String(json.message || json.error) : 'Update failed';

            // Case: server claims "no changes" but user actually edited fields — surface server message as an error
            var isNoChanges = /no[_\s-]*changes/i.test(serverMsg) || (json && json.error === 'no_changes');
            if (isNoChanges) {
              if (Array.isArray(submittedChanges) && submittedChanges.length > 0) {
                // user changed something but server didn't apply it — show the real server-provided reason (red)
                Notify.push({ from:'Positions', message: serverMsg, color:'#dc2626', duration:8000 });
                console.warn('Submitted changes not applied:', submittedChanges, 'server:', json);
                return;
              }
              // otherwise it's a true no-op (user didn't change anything)
              Notify.push({ from:'Positions', message: 'No changes to update', color:'#64748b' });
              return;
            }

            // If update executed but affected 0 rows, show server message (this may indicate a deeper problem)
            if (json && json.error === 'no_rows_affected') {
              Notify.push({ from:'Positions', message: serverMsg, color:'#dc2626', duration:8000 });
              console.warn('No rows affected despite changed input:', submittedChanges, 'server:', json);
              return;
            }

            // Generic failure: show server message
            Notify.push({ from:'Positions', message: serverMsg || 'Update failed', color:'#dc2626' });
            return;
          }
          var updated = json.position || json;
          var changeConfirmed = false;
          try {
            if (json && ((typeof json.affected !== 'undefined' && Number(json.affected) > 0) || json.statusChanged)) changeConfirmed = true;
          } catch(e) { changeConfirmed = false; }

          if (updated && typeof updated === 'object') {
            Object.keys(updated).forEach(function(k){
              var el = document.getElementById('edit_' + k);
              if (!el) return;
              try { if (el.type === 'checkbox') el.checked = !!updated[k]; else el.value = updated[k] ?? ''; } catch(e){}
            });
            window.__posEditSnapshot = { pos: updated };
            var hidden = document.getElementById('posStatusName');
            if (hidden && (updated.status_id || updated.status_name)) {
              if (updated.status_id) hidden.dataset.statusId = String(updated.status_id);
              if (updated.status_name) hidden.textContent = updated.status_name;
              setHeaderStatusChip();
            }
          }
          await ensureNotifyAssets();
          if (changeConfirmed) {
            try {
              var posId = (updated && updated.id) ? updated.id : idv;
              Notify.push({ from:'Positions', message: 'Update successful: position #' + String(posId), color:'#16a34a', duration:6000 });
            } catch(e) {
              Notify.push({ from:'Positions', message:'Position updated', color:'#16a34a', duration:6000 });
            }
          } else {
            // Build a helpful reason for why the DB wasn't changed
            var reason = 'No changes applied';
            try {
              if (json && json.message) reason = String(json.message);
              else if (json && json.error) reason = String(json.error);
              else if (json && Array.isArray(json.changedFields) && json.changedFields.length === 0) reason = 'No fields changed (submitted values match existing values)';
              else if (json && typeof json.affected !== 'undefined' && Number(json.affected) === 0) reason = 'No rows affected (possible permission/validation or identical data)';
            } catch(e) { /* ignore */ }
            Notify.push({ from:'Positions', message: 'Update not applied: ' + reason, color:'#64748b', duration:7000 });
          }
        } catch (err) {
          console.error('Update failed', err);
          await ensureNotifyAssets(); Notify.push({ from:'Positions', message:'Update failed (see console)', color:'#dc2626' });
        } finally {
          btn.disabled = false;
          showEditMode();
        }
      }), false);
      applyBtn.dataset.bound = '1';
    }

    // Central handler for status action buttons. We attach it directly to each
    // button and also provide a delegated fallback on the form so clicks are
    // handled even if the per-button binding didn't run (fixes the reported
    // case where the first click seemed to only close the UI and not trigger
    // the update).
    async function handleStatusAction(btn, ev){
      try {
        if (ev && typeof ev.preventDefault === 'function') ev.preventDefault();
        if (ev && typeof ev.stopPropagation === 'function') { ev.stopPropagation(); ev.stopImmediatePropagation(); }

        var toId = parseInt(btn.dataset.to, 10);
        var idEl = document.getElementById('edit_id');
        if (!toId || !idEl) {
          await ensureNotifyAssets();
          Notify.push({ from:'Positions', message:'Cannot update status: missing id', color:'#dc2626' });
          return;
        }
        var idv = idEl.value;

        // Access control: prevent unauthorized users from approving
        try {
          var label = (btn.dataset.label || '').toString().trim().toLowerCase();
          var perm = document.getElementById('editPermMeta');
          var canApprove = !!(perm && perm.dataset && perm.dataset.canApprove === '1');
          if (label === 'approve' && !canApprove) {
            await ensureNotifyAssets();
            try { Notify.push({ from:'Positions', message:'Approval is not within your access scope', color:'#dc2626' }); } catch(e){}
            return;
          }
        } catch(e) { /* ignore access-check errors and proceed */ }

        var fd = new FormData();
        fd.append('id', idv);
        fd.append('status_id', String(toId));

        // disable the button while working
        try { btn.disabled = true; } catch(e){}

        var res = await fetch('update_position.php', { method:'POST', credentials:'same-origin', body: fd });
        var text = await res.text();
        var json = {};
        try { json = JSON.parse(text); } catch(e) {
          console.error('status update parse error', text);
          await ensureNotifyAssets(); window.__showPosNotify({ from:'Positions', message:'Status update failed (invalid response) — Position #' + String(idv || '?'), color:'#dc2626' });
          return;
        }
        if (!json.ok) {
          var msg = json.message || json.error || 'Status update failed';
          var neutral = /no[_\s-]*changes/i.test(msg) || /already in this status/i.test(msg);
          await ensureNotifyAssets();
          var msgWithId = (neutral ? 'No status change' : msg) + ' — Position #' + String(idv || '?');
          window.__showPosNotify({ from:'Positions', message: msgWithId, color: neutral ? '#64748b' : '#dc2626' });
          if (neutral) await refreshFragment(idv);
          return;
        }

        // Confirm the DB actually changed (server provides `affected` and `statusChanged`)
        var statusChangedConfirmed = false;
        try { statusChangedConfirmed = !!(json && ((typeof json.affected !== 'undefined' && Number(json.affected) > 0) || json.statusChanged)); } catch(e) { statusChangedConfirmed = false; }
        await ensureNotifyAssets();
        if (!statusChangedConfirmed) {
          // Try to extract a server-provided reason for the no-op
          var reason = 'No status change';
          try {
            if (json && json.message) reason = String(json.message);
            else if (json && json.error) reason = String(json.error);
            else if (json && typeof json.affected !== 'undefined' && Number(json.affected) === 0) reason = 'No rows affected (status already set or permission denied)';
          } catch(e) { /* ignore */ }
          window.__showPosNotify({ from:'Positions', message: reason + ' — Position #' + String(idv || '?'), color:'#64748b' });
          // Refresh the fragment so UI reflects authoritative server state
          await refreshFragment(idv);
          return;
        }
        var label = btn.dataset.label || 'Status updated';
        var posMsg = label + ' — Position #' + String(idv || '?');
        window.__showPosNotify({ from:'Positions', message: posMsg, color: '#2563eb' });
        // Redirect to positions page to refresh the cards and jump to this ticket
        window.location.href = 'view_positions.php?updated=' + encodeURIComponent(idv) + '#pos-' + encodeURIComponent(idv);
        return;
      } catch(err){
        console.error('status update failed', err);
        var errIdv = (idEl && idEl.value) || '?';
        await ensureNotifyAssets(); window.__showPosNotify({ from:'Positions', message:'Status update failed (see console) — Position #' + String(errIdv), color:'#dc2626' });
      } finally {
        try { btn.disabled = false; } catch(e){}
      }
    }

    var statusBtns = Array.prototype.slice.call(form.querySelectorAll('.status-action-btn'));
    statusBtns.forEach(function(btn){
      btn.addEventListener('click', function(ev){ handleStatusAction(btn, ev); }, false);
      btn.dataset.bound = '1';
    });

    // Delegated fallback: if for some reason per-button binding didn't run
    // (fragment insertion race, script execution timing, etc.), handle clicks
    // that bubble up to the form and target a status button.
    form.addEventListener('click', function(e){
      try {
        var b = e.target && e.target.closest && e.target.closest('.status-action-btn');
        if (!b) return;
        // if already bound, let the bound handler run; otherwise call handler
        if (b.dataset && b.dataset.bound === '1') return;
        handleStatusAction(b, e);
      } catch(err) { /* swallow delegation errors */ }
    }, true);

    // Delegation fallback: if a click reaches here and a button has no handler bound, notify user
    form.addEventListener('click', async function(e){
      var btn = e.target && e.target.closest && e.target.closest('.status-action-btn,#posResetBtn,#posEditBtn,#posUpdateBtn');
      if (!btn) return;
      if (btn.dataset && btn.dataset.bound === '1') return;
      await ensureNotifyAssets();
      Notify.push({ from:'Positions', message:'UI not ready. Please reload this ticket.', color:'#dc2626' });
    }, true);

    async function refreshFragment(id){
      try {
        const r = await fetch('get_position.php?id=' + encodeURIComponent(id), { credentials:'same-origin' });
        const html = await r.text();

        // Parse into a template to safely extract the new #posEditForm and its scripts
        const tpl = document.createElement('template');
        tpl.innerHTML = html;

        const newForm = tpl.content.querySelector('#posEditForm');
        if (!newForm) {
          console.error('refreshFragment: newForm not found');
          await ensureNotifyAssets();
          Notify.push({ from:'Positions', message:'UI reload failed (no fragment)', color:'#dc2626' });
          return;
        }

        // Replace the entire wrapper node to avoid nesting duplicate #posEditForm
        const oldForm = document.getElementById('posEditForm');
        if (!oldForm || !oldForm.parentNode) {
          console.error('refreshFragment: oldForm missing');
          await ensureNotifyAssets();
          Notify.push({ from:'Positions', message:'UI reload failed (no container)', color:'#dc2626' });
          return;
        }

        // Collect scripts before we replace the node
        const scripts = Array.from(tpl.content.querySelectorAll('script'));

        // Replace node
        oldForm.parentNode.replaceChild(newForm, oldForm);

        // Execute any inline/linked scripts from the fragment so handlers bind again
        scripts.forEach(function(s){
          try {
            const ns = document.createElement('script');
            if (s.src) {
              ns.src = s.src;
              ns.async = false;
              document.head.appendChild(ns);
              ns.onload = function(){ ns.remove(); };
            } else {
              ns.textContent = s.textContent || s.innerText || '';
              document.head.appendChild(ns);
              ns.remove();
            }
          } catch(e){ console.error('refreshFragment script exec error', e); }
        });
      } catch(e){
        console.error('refreshFragment failed', e);
        await ensureNotifyAssets();
        Notify.push({ from:'Positions', message:'Failed to refresh ticket (see console)', color:'#dc2626' });
      }
    }

    showEditMode();
    setHeaderStatusChip();
  } catch (e) {
    console.error('Binder error', e);
    (function(){
      function ensure(){ return new Promise(r=>{ if (window.Notify?.push) return r(); var s=document.createElement('script'); s.src='assets/js/notify.js'; s.onload=r; document.head.appendChild(s); }); }
      ensure().then(function(){ Notify.push({ from:'Positions', message:'UI error occurred. Please reload this ticket.', color:'#dc2626' }); });
    })();
  }
})();
</script>
SCRIPT;
  // END: injected binder script
?>
