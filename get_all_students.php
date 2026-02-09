<?php
require_once 'config.php';
header('Content-Type: application/json');

try {
    $sql = "SELECT id, name, roll_no FROM students ORDER BY name ASC";
    $result = $conn->query($sql);

    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }

    echo json_encode([
        'status' => 'success',
        'students' => $students
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>