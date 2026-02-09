<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

include_once 'config.php';

// Optional: add basic error reporting during testing
// error_reporting(E_ALL); ini_set('display_errors', 1);  // ← uncomment only for debugging

// Get JSON input
$data = json_decode(file_get_contents("php://input"), true);  // ← add true for assoc array (safer)

$password = $data['password'] ?? null;

if (empty($password)) {
    http_response_code(400);
    echo json_encode(["valid" => false, "message" => "Password is required"]);
    exit;
}

// Fetch hashed password (id=1)
$stmt = $conn->prepare("SELECT assessment_password FROM settings WHERE id = 1");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["valid" => false, "message" => "Database error"]);
    exit;
}

$stmt->execute();
$result = $stmt->get_result();
$setting = $result->fetch_assoc();

if (!$setting || empty($setting['assessment_password'])) {
    echo json_encode(["valid" => false]);  // No password set → treat as invalid / open
    exit;
}

// Verify
$valid = password_verify($password, $setting['assessment_password']);

echo json_encode(["valid" => $valid]);

// Optional: clean up
$stmt->close();
?>