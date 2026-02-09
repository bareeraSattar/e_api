<?php
header('Content-Type: application/json');
require_once 'config.php';

try {
    $sql = "
        SELECT
            id,                     -- ← IMPORTANT: Add this (primary key)
            roll_no AS roll_number,
            name AS full_name,
            class AS class_name
        FROM students
        ORDER BY roll_no
    ";

    $result = $conn->query($sql);
    if (!$result) {
        throw new Exception($conn->error);
    }

    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = [
            'id'           => (int)$row['id'],          // ← Now included
            'roll_number'  => $row['roll_number'],
            'full_name'    => $row['full_name'],
            'class_name'   => $row['class_name']
        ];
    }

    echo json_encode([
        'status'   => 'success',
        'students' => $students
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

$conn->close();
?>