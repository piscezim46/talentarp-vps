<?php
// public/positions_by_team.php
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

$team = isset($_GET['team']) ? trim($_GET['team']) : '';
if ($team === '') {
    echo json_encode([]);
    exit;
}

// If numeric, resolve to team_name from teams table
$team_name = $team;
if (ctype_digit($team)) {
    $tid = (int)$team;
    $tstmt = $conn->prepare("SELECT team_name FROM teams WHERE team_id = ? LIMIT 1");
    if ($tstmt) {
        $tstmt->bind_param('i', $tid);
        $tstmt->execute();
        $tres = $tstmt->get_result();
        if ($tr = $tres->fetch_assoc()) {
            $team_name = $tr['team_name'];
        }
        $tstmt->close();
    }
}

 $positions = [];
 $sql = "SELECT p.id, p.title, COALESCE(p.department,'') AS department, COALESCE(p.team,'') AS team, p.status_id, COALESCE(s.status_name,'') AS status_name
     FROM positions p
     LEFT JOIN positions_status s ON p.status_id = s.status_id
     WHERE COALESCE(p.team,'') = ? ORDER BY p.id DESC";

 $pstmt = $conn->prepare($sql);
 if ($pstmt) {
     $pstmt->bind_param('s', $team_name);
     $pstmt->execute();
     $pres = $pstmt->get_result();
     while ($row = $pres->fetch_assoc()) {
         $positions[] = $row;
     }
     $pstmt->close();
 }

echo json_encode($positions);
exit;

?>
