<?php
require_once 'config.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['assessment_id']) || !isset($input['feedback_text'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
    exit;
}

$assessment_id = intval($input['assessment_id']);
$feedback_text = trim($conn->real_escape_string($input['feedback_text']));
$feedback_by = trim($conn->real_escape_string($input['feedback_by'] ?? 'Teacher'));

$sql = "INSERT INTO feedback (assessment_id, feedback_text, feedback_by) VALUES (?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iss", $assessment_id, $feedback_text, $feedback_by);

if ($stmt->execute()) {
    echo json_encode(['status' => 'success', 'message' => 'Feedback submitted']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to submit feedback']);
}

$stmt->close();
$conn->close();
?>