<?php
require_once 'config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Only allow admins
if (!isset($_GET['is_admin']) || $_GET['is_admin'] != '1') {
    echo json_encode(['status' => 'error', 'message' => 'Admin access required']);
    exit;
}

$sql = "SELECT id, roll_number, full_name, email, is_admin, created_at 
        FROM users 
        ORDER BY id DESC";

$result = $conn->query($sql);

if ($result === false) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Query failed: ' . $conn->error
    ]);
} else {
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $row['is_admin'] = (int)$row['is_admin']; // Make sure it's 0 or 1
        $users[] = $row;
    }

    echo json_encode([
        'status' => 'success',
        'users'  => $users
    ]);
}

$conn->close();
?>