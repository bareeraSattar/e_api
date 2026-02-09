<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
include_once 'config.php';

$subject_id = $_GET['subject_id'] ?? '';
$include_completed = isset($_GET['include_completed']) && $_GET['include_completed'] === '1';

if (empty($subject_id)) {
    echo json_encode(["message" => "Subject ID required."]);
    exit;
}

$where_status = $include_completed ? "AND p.status IN ('scheduled', 'completed')" : "AND p.status = 'scheduled'";
$where_date   = $include_completed ? "" : "AND p.presentation_date > NOW()";

$query = "
    SELECT
        p.id,
        p.subject_id,
        p.presentation_date,
        p.type,
        p.participant_rolls,
        p.assessor_id,
        p.notes,
        p.status,
        u.full_name AS assessor_name
    FROM presentations p
    LEFT JOIN users u ON p.assessor_id = u.id
    WHERE p.subject_id = ?
      $where_date
      $where_status
    ORDER BY 
        CASE WHEN p.status = 'scheduled' THEN 0 ELSE 1 END,
        p.presentation_date DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $subject_id);
$stmt->execute();
$result = $stmt->get_result();
$presentations = [];

while ($row = $result->fetch_assoc()) {
    $rolls = explode(',', $row['participant_rolls']);
    $names = [];
    foreach ($rolls as $roll) {
        $roll = trim($roll);
        if (empty($roll)) continue;
        $name_stmt = $conn->prepare("SELECT name FROM students WHERE roll_no = ?");
        $name_stmt->bind_param("s", $roll);
        $name_stmt->execute();
        $name_result = $name_stmt->get_result();
        if ($name_row = $name_result->fetch_assoc()) {
            $names[] = $name_row['name'] . ' (' . $roll . ')';
        } else {
            $names[] = $roll;
        }
        $name_stmt->close();
    }
    $row['participant_names'] = implode(', ', $names);
    $presentations[] = $row;
}

echo json_encode($presentations);
$stmt->close();
$conn->close();
?>