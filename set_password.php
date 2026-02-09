<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

include_once 'config.php';

// Optional debug: error_reporting(E_ALL); ini_set('display_errors', 1);

$data = json_decode(file_get_contents("php://input"), true);

$user_id  = $data['user_id']  ?? null;
$password = $data['password'] ?? null;

if (empty($user_id) || !is_numeric($user_id)) {
    http_response_code(400);
    echo json_encode(["message" => "Invalid or missing user_id"]);
    exit;
}

if ($password === null) {   // Allow empty string = remove/disable password
    $password = '';
}

// Check if user is admin
$stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(["message" => "User not found"]);
    exit;
}

$user = $result->fetch_assoc();
$stmt->close();

if ($user['is_admin'] != 1) {
    http_response_code(403);
    echo json_encode(["message" => "Unauthorized - Only admin can set password"]);
    exit;
}

// Hash (even if empty — password_hash handles empty string fine)
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Update (will work even if no row exists yet, but we assume id=1 row is pre-inserted)
$stmt = $conn->prepare("UPDATE settings SET assessment_password = ? WHERE id = 1");
$stmt->bind_param("s", $hashed_password);

if ($stmt->execute()) {
    $affected = $conn->affected_rows;
    if ($affected > 0) {
        $msg = empty($password) ? "Password removed successfully" : "Password set successfully";
        echo json_encode(["message" => $msg, "success" => true]);
    } else {
        // Maybe row doesn't exist → try INSERT instead
        $stmt->close();
        $stmt = $conn->prepare("INSERT INTO settings (id, assessment_password) VALUES (1, ?)");
        $stmt->bind_param("s", $hashed_password);
        $stmt->execute();
        echo json_encode(["message" => empty($password) ? "Password removed" : "Password set successfully", "success" => true]);
    }
} else {
    http_response_code(500);
    echo json_encode(["message" => "Database error: " . $conn->error]);
}

$stmt->close();
?>