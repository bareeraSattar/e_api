<?php
require_once 'config.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['assessment_id']) || !isset($input['criteria_scores'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing data']);
    exit;
}

$assessment_id = intval($input['assessment_id']);
$criteria_scores = $input['criteria_scores'];

$conn->begin_transaction();

try {
    $total_marks = 0.0;
    $max_possible = 0.0;

    foreach ($criteria_scores as $score) {
        $criteria_id = intval($score['criteria_id']);
        $marks = floatval($score['marks_awarded']);

        $sql_max = "SELECT max_marks FROM criteria WHERE id = ?";
        $stmt_max = $conn->prepare($sql_max);
        $stmt_max->bind_param("i", $criteria_id);
        $stmt_max->execute();
        $res_max = $stmt_max->get_result();
        if ($row_max = $res_max->fetch_assoc()) {
            $max_possible += floatval($row_max['max_marks']);
        }
        $total_marks += $marks;

        // Update or insert score
        $sql = "INSERT INTO assessment_scores (assessment_id, criteria_id, marks_awarded) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE marks_awarded = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iidd", $assessment_id, $criteria_id, $marks, $marks);
        $stmt->execute();
    }

    $percentage = $max_possible > 0 ? round(($total_marks / $max_possible) * 100, 2) : 0.0;

    $sql_update = "UPDATE assessments SET 
                    total_marks = ?, 
                    max_possible_marks = ?, 
                    percentage = ?, 
                    assessment_date = CURDATE() 
                   WHERE id = ?";
    $stmt_update = $conn->prepare($sql_update);
    $stmt_update->bind_param("dddi", $total_marks, $max_possible, $percentage, $assessment_id);
    $stmt_update->execute();

    $conn->commit();
    echo json_encode(['status' => 'success', 'message' => 'Updated successfully']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
$conn->close();
?>