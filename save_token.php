<?php
include 'config.php'; // your DB connection

$data = json_decode(file_get_contents("php://input"), true);
$user_id = $data['user_id'] ?? null;
$token = $data['token'] ?? null;

if (!$user_id || !$token) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing user_id or token"]);
    exit;
}

$stmt = $conn->prepare("INSERT INTO fcm_tokens (user_id, token) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE token = VALUES(token), updated_at = CURRENT_TIMESTAMP");
$stmt->bind_param("is", $user_id, $token);
if ($stmt->execute()) {
    echo json_encode(["status" => "success"]);
} else {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $stmt->error]);
}
?>
