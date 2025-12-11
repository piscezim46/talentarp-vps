<?php
session_start();
require_once '../includes/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['error'=>'Not authenticated']);
    exit;
}

// accept both form POST and JSON payload
$input = $_POST;
if (empty($input)) {
    $json = json_decode(file_get_contents('php://input'), true);
    if (is_array($json)) $input = $json;
}

// helper
function get($k, $def = '') {
    global $input;
    return isset($input[$k]) ? trim($input[$k]) : $def;
}

$title = get('title');
$experience_level = get('experience_level', '');
$education_level = get('education_level', '');
$employment_type = get('employment_type', '');
$openings = (int)(get('openings', 1) ?: 1);
$hiring_deadline = get('hiring_deadline', null);
$salary = (float) (get('salary', 0)); // NEW: numeric salary
$department_id = (int)(get('department_id', 0));
$team_id = (int)(get('team_id', 0));
$manager_name_in = get('manager_name', '');
$description = get('description', '');
$requirements = get('requirements', '');
$status = get('status', 'open');
$created_by = (int)($_SESSION['user']['id'] ?? 0);

// basic validation
if ($title === '') {
    http_response_code(400);
    echo json_encode(['error'=>'Title is required']);
    exit;
}

// resolve department/team names if IDs supplied (positions table stores names)
$department_name = '';
if ($department_id > 0) {
    $dstmt = $conn->prepare("SELECT department_name FROM departments WHERE department_id = ? LIMIT 1");
    if ($dstmt) {
        $dstmt->bind_param('i', $department_id);
        $dstmt->execute();
        $dres = $dstmt->get_result();
        $drow = $dres ? $dres->fetch_assoc() : null;
        $department_name = $drow['department_name'] ?? '';
        $dstmt->close();
    }
}
$team_name = '';
$manager_name = $manager_name_in;
if ($team_id > 0) {
    $tstmt = $conn->prepare("SELECT team_name, manager_name FROM teams WHERE team_id = ? LIMIT 1");
    if ($tstmt) {
        $tstmt->bind_param('i', $team_id);
        $tstmt->execute();
        $tres = $tstmt->get_result();
        $trow = $tres ? $tres->fetch_assoc() : null;
        if ($trow) {
            $team_name = $trow['team_name'] ?? '';
            // prefer manager from teams table if not provided
            if (empty($manager_name)) $manager_name = $trow['manager_name'] ?? '';
        }
        $tstmt->close();
    }
}
// fallback: if department_id not provided but department text provided in form
if (empty($department_name)) $department_name = get('department', '');

// Duplicate check: prevent the same creator from creating a position with
// the exact same title and manager (case-insensitive, trimmed). If a
// duplicate exists, return 409 for AJAX requests or redirect back with
// an error message for normal form posts.
try {
    $dupeMsg = 'Duplicated position';
    $dq = "SELECT id FROM positions WHERE TRIM(LOWER(title)) = TRIM(LOWER(?)) AND created_by = ? AND TRIM(LOWER(COALESCE(manager_name,''))) = TRIM(LOWER(COALESCE(?,''))) LIMIT 1";
    if ($dstmt = $conn->prepare($dq)) {
        $dstmt->bind_param('sis', $title, $created_by, $manager_name);
        $dstmt->execute();
        $dres = $dstmt->get_result();
        if ($dres && $dres->num_rows > 0) {
            // duplicate found
            $dstmt->close();
            // If AJAX/json expected, respond with 409 and JSON
            $isAjax = (
                (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
            );
            if ($isAjax) {
                http_response_code(409);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'duplicate', 'message' => $dupeMsg]);
                exit;
            } else {
                // Non-AJAX: redirect back to positions view with msg (dashboard will show via notify)
                $msg = rawurlencode($dupeMsg);
                header('Location: view_positions.php?msg=' . $msg . '&type=error');
                exit;
            }
        }
        $dstmt->close();
    }
} catch (Throwable $_) { /* ignore duplicate-check errors and continue to insertion */ }

// collect bind variables (ensure they are set) including new fields
$bind_title = $title;
$bind_exp = $experience_level;
$bind_edu = $education_level;
$bind_emp = $employment_type;
$bind_open = $openings;
$bind_salary = $salary;
$bind_dead = $hiring_deadline ?: null;
$bind_dept = $department_name;
$bind_team = $team_name;
$bind_mgr = $manager_name;
$bind_desc = $description;
$bind_role_responsibilities = isset($input['role_responsibilities']) ? trim($input['role_responsibilities']) : '';
$bind_role_expectations = isset($input['role_expectations']) ? trim($input['role_expectations']) : '';
$bind_reqs = $requirements;
$bind_creator = $created_by;
$bind_status = $status;
$bind_gender = isset($input['gender']) ? trim($input['gender']) : 'Any';
$bind_nationality = isset($input['nationality_requirement']) ? trim($input['nationality_requirement']) : 'Any';
$bind_min_age = isset($input['min_age']) ? intval($input['min_age']) : 18;
if ($bind_min_age < 18) $bind_min_age = 18;
// new fields
$bind_work_location = isset($input['work_location']) ? trim($input['work_location']) : 'Remote';
$bind_reason_for_opening = isset($input['reason_for_opening']) ? trim($input['reason_for_opening']) : 'New Position';
$bind_working_hours = isset($input['working_hours']) ? trim($input['working_hours']) : '9-5';

// Decide initial numeric status id for new positions (Open)
$initial_status_id = 1; // Open

// single INSERT including new fields (22 columns -> 22 placeholders)
$insert_sql = "INSERT INTO positions
    (title, experience_level, education_level, employment_type, openings, salary, hiring_deadline,
     department, team, manager_name, description, role_responsibilities, role_expectations, requirements, created_by, status_id,
     gender, nationality_requirement, min_age, work_location, reason_for_opening, working_hours)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($insert_sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error'=>'Prepare failed: '.$conn->error]);
    exit;
}

// Build types string (must match the order and count of VALUES above)
$types = implode('', array(
    's','s','s','s',    // title, experience_level, education_level, employment_type
    'i',                // openings
    'd',                // salary (double)
    's',                // hiring_deadline
    's','s','s','s','s','s','s', // department, team, manager_name, description, role_responsibilities, role_expectations, requirements
    'i',                // created_by
    'i',                // status_id
    's','s','i',        // gender, nationality_requirement, min_age
    's','s','s'         // work_location, reason_for_opening, working_hours
));

// bind params by reference in the exact order of VALUES above
$params = array();
$params[] = & $types;
foreach (array(
    'bind_title','bind_exp','bind_edu','bind_emp','bind_open','bind_salary','bind_dead',
    'bind_dept','bind_team','bind_mgr','bind_desc','bind_role_responsibilities','bind_role_expectations','bind_reqs','bind_creator',
    'initial_status_id','bind_gender','bind_nationality','bind_min_age',
    'bind_work_location','bind_reason_for_opening','bind_working_hours'
) as $var) {
    // ensure variable exists to avoid notices
    if (!isset($$var)) { $$var = null; }
    $params[] = & $$var;
}

call_user_func_array(array($stmt, 'bind_param'), $params);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error'=>'Insert failed: '.$stmt->error]);
    $stmt->close();
    exit;
}

$newId = (int)($stmt->insert_id ?? $conn->insert_id ?? 0);
$stmt->close();

// Insert initial history record using the initial_status_id (use correct table name)
if ($newId > 0) {
    $updated_by = ($_SESSION['user']['name'] ?? $_SESSION['user']['username'] ?? ($_SESSION['user']['id'] ?? 'system'));
    $reason = trim(get('reason','Created'));

    $h = $conn->prepare(
        "INSERT INTO positions_status_history (position_id, status_id, updated_by, updated_at, reason)
         VALUES (?, ?, ?, NOW(), ?)"
    );
    if ($h) {
        $h->bind_param('iiss', $newId, $initial_status_id, $updated_by, $reason);
        $h->execute();
        $h->close();
    } else {
        error_log("create_position.php: failed preparing history insert: " . $conn->error);
    }
}

// respond JSON for AJAX or redirect for normal form post
if (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'],'application/json') !== false)
) {
    echo json_encode(['success'=>true, 'position_id'=>$newId]);
    exit;
}

// otherwise redirect back to positions page
// optional: flash for one-time notify
// NOTE: $newId was captured from the insert statement above. Do NOT overwrite it
// with $conn->insert_id after subsequent inserts (e.g. history) because that
// would return the last insert id (history row) instead of the positions id.
$_SESSION['position_created_id'] = (string)$newId;

// redirect back to list with created id
header('Location: view_positions.php?created=' . urlencode((string)$newId));
exit;
?>
