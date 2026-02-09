<?php
require_once 'config.php';

$assessment_id = intval($_GET['assessment_id'] ?? 0);

if ($assessment_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid assessment ID']);
    exit;
}

$stmt = $conn->prepare("SELECT criteria_id, marks_awarded FROM assessment_scores WHERE assessment_id = ?");
$stmt->bind_param("i", $assessment_id);
$stmt->execute();
$result = $stmt->get_result();

$scores = [];
while ($row = $result->fetch_assoc()) {
    $scores[] = [
        'criteria_id' => (int)$row['criteria_id'],
        'marks_awarded' => (float)$row['marks_awarded'],
    ];
}

echo json_encode([
    'status' => 'success',
    'scores' => $scores
]);

$stmt->close();
$conn->close();
?>