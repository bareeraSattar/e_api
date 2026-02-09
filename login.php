<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');           // ← add this (same as signup)
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';

// Optional: keep for local debugging, comment out on production
// ini_set('display_errors', 0);
// error_reporting(0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON: ' . json_last_error_msg()
    ]);
    exit;
}

$identifier = trim($data['identifier'] ?? '');
$password   = trim($data['password'] ?? '');

if (empty($identifier) || empty($password)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Identifier (roll number or email) and password are required'
    ]);
    exit;
}

// ────────────────────────────────────────────────
// Query using correct columns
// ────────────────────────────────────────────────
$query = "
    SELECT
        id,
        roll_number,
        email,
        full_name,
        is_admin
    FROM users
    WHERE (roll_number = ? OR email = ?)
      AND password = ?
    LIMIT 1
";

$stmt = $conn->prepare($query);
if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $conn->error
    ]);
    exit;
}

$stmt->bind_param("sss", $identifier, $identifier, $password);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'user' => [
            'id'          => (int)$row['id'],
            'roll_number' => $row['roll_number'],
            'email'       => $row['email'],
            'full_name'   => $row['full_name'],
            'is_admin'    => (int)$row['is_admin']
        ]
    ]);
} else {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid roll number/email or password'
    ]);
}

$stmt->close();
$conn->close();