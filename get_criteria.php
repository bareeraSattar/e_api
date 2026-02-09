<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_GET['subject_id']) || !is_numeric($_GET['subject_id'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Valid Subject ID is required'
    ]);
    exit;
}

$subject_id = intval($_GET['subject_id']);
$type = isset($_GET['type']) ? strtolower($_GET['type']) : null; // 'assessment' or 'presentation'

$sql = "SELECT id, name, max_marks, weightage, is_presentation
        FROM criteria
        WHERE subject_id = ?";
$params_types = "i";
$params = [$subject_id];

if ($type === 'assessment') {
    $sql .= " AND is_presentation = 0";
} elseif ($type === 'presentation') {
    $sql .= " AND is_presentation = 1";
}

$sql .= " ORDER BY id ASC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database prepare error: ' . $conn->error
    ]);
    $conn->close();
    exit;
}

$stmt->bind_param($params_types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$criteria = [];
while ($row = $result->fetch_assoc()) {
    $row['max_marks'] = floatval($row['max_marks']);
    $row['weightage'] = floatval($row['weightage'] ?? 1.00);
    $row['is_presentation'] = (int)$row['is_presentation'];
    $criteria[] = $row;
}

echo json_encode([
    'status' => 'success',
    'criteria' => $criteria
]);

$stmt->close();
$conn->close();
?>