<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_GET['is_admin']) || $_GET['is_admin'] != 1) {
    echo json_encode(['status' => 'error', 'message' => 'Admin access required']);
    exit;
}

$sql = "SELECT COUNT(*) as total FROM users WHERE is_admin = 0";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
echo json_encode([
    'status' => 'success',
    'total_students' => (int)$row['total']
]);
$conn->close();
?>