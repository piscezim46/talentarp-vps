<?php
// Start session only when not already active
if (function_exists('session_status')) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
} else {
    @session_start();
}
require_once '../includes/db.php';
require_once __DIR__ . '/../includes/access.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || !_has_access('departments_view')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$deptId = (int)($input['department_id'] ?? 0);
$deptName = trim($input['department_name'] ?? '');
$shortName = trim($input['short_name'] ?? '');
$director = trim($input['director_name'] ?? '');
$teams = is_array($input['teams']) ? $input['teams'] : [];

if ($deptId <= 0 || $deptName === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid department id or name']);
    exit;
}

// start transaction
$conn->begin_transaction();
try {
    // update department
    $ustmt = $conn->prepare("UPDATE departments SET department_name = ?, short_name = ?, director_name = ? WHERE department_id = ?");
    if (!$ustmt) throw new Exception('Prepare failed: ' . $conn->error);
    $ustmt->bind_param('sssi', $deptName, $shortName, $director, $deptId);
    if (!$ustmt->execute()) throw new Exception('Update failed: ' . $ustmt->error);
    $ustmt->close();

    // fetch existing team ids for this department
    $existing = [];
    $res = $conn->query("SELECT team_id FROM teams WHERE department_id = " . (int)$deptId);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $existing[(int)$r['team_id']] = true;
        }
        $res->free();
    }

    // process incoming teams
    $insertedTeams = [];
    foreach ($teams as $t) {
        $teamId = isset($t['team_id']) ? (int)$t['team_id'] : 0;
        $teamName = trim($t['team_name'] ?? '');
        $manager = trim($t['manager_name'] ?? '');
        if ($teamId > 0) {
            // update existing team
            $q = $conn->prepare("UPDATE teams SET team_name = ?, manager_name = ?, active = 1 WHERE team_id = ? AND department_id = ?");
            if (!$q) throw new Exception('Prepare failed: ' . $conn->error);
            $q->bind_param('ssii', $teamName, $manager, $teamId, $deptId);
            if (!$q->execute()) { $q->close(); throw new Exception('Failed update team: ' . $q->error); }
            $q->close();
            // mark as handled
            if (isset($existing[$teamId])) unset($existing[$teamId]);
        } else {
            // new team: insert if name present
            if ($teamName !== '') {
                $q = $conn->prepare("INSERT INTO teams (team_name, department_id, manager_name, active) VALUES (?, ?, ?, 1)");
                if (!$q) throw new Exception('Prepare failed: ' . $conn->error);
                $q->bind_param('sis', $teamName, $deptId, $manager);
                if (!$q->execute()) { $q->close(); throw new Exception('Failed insert team: ' . $q->error); }
                $insertedTeams[] = [ 'team_id' => $q->insert_id, 'team_name' => $teamName, 'manager_name' => $manager ];
                $q->close();
            }
        }
    }

    // mark teams that were removed as inactive (remaining ids in $existing)
    if (!empty($existing)) {
        $ids = array_keys($existing);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // build types and values for binding
        $types = str_repeat('i', count($ids));
        $stmt = $conn->prepare("UPDATE teams SET active = 0 WHERE team_id IN ($placeholders) AND department_id = ?");
        if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
        // bind params dynamically
        $params = [];
        foreach ($ids as $k => $v) $params[] = $v;
        $params[] = $deptId;
        // call_user_func_array requires references
        $refs = [];
        $types_all = $types . 'i';
        $refs[] = & $types_all;
        for ($i=0;$i<count($ids);$i++) { $refs[] = & $params[$i]; }
        $refs[] = & $params[count($params)-1];
        call_user_func_array([$stmt, 'bind_param'], $refs);
        if (!$stmt->execute()) { $stmt->close(); throw new Exception('Failed deactivate teams: ' . $stmt->error); }
        $stmt->close();
    }

    // commit
    $conn->commit();

    // fetch current active teams to return
    $teamsRes = [];
    $tr = $conn->prepare("SELECT team_id, team_name, manager_name FROM teams WHERE department_id = ? AND active = 1 ORDER BY team_id ASC");
    $tr->bind_param('i', $deptId);
    if ($tr->execute()) {
        $rres = $tr->get_result();
        while ($row = $rres->fetch_assoc()) {
            $teamsRes[] = [ 'team_id' => (int)$row['team_id'], 'team_name' => $row['team_name'], 'manager_name' => $row['manager_name'] ];
        }
        $tr->close();
    }

    echo json_encode([ 'success' => true, 'department' => [ 'department_id' => $deptId, 'department_name' => $deptName, 'short_name' => $shortName, 'director_name' => $director, 'teams' => $teamsRes ] ]);
    exit;

} catch (Exception $ex) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['error' => $ex->getMessage()]);
    exit;
}

?>
