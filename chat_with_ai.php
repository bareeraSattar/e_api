<?php
require_once 'config.php';

// Enable error logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/chat_ai_errors.log');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !is_array($input)) {
    error_log("[" . date('Y-m-d H:i:s') . "] Invalid input received");
    echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
    exit;
}

$message = trim($input['message'] ?? '');
$history = $input['history'] ?? [];

if (empty($message)) {
    error_log("[" . date('Y-m-d H:i:s') . "] No message provided");
    echo json_encode(['status' => 'error', 'message' => 'No message provided']);
    exit;
}

// Log incoming data
error_log("[" . date('Y-m-d H:i:s') . "] New request - Message: " . substr($message, 0, 100));
error_log("History count: " . count($history));

// Build contents
$contents = [];
foreach ($history as $msg) {
    if (!isset($msg['role']) || !isset($msg['content'])) continue;
    $role = $msg['role'] === 'user' ? 'user' : 'model';
    $contents[] = [
        'role' => $role,
        'parts' => [['text' => trim($msg['content'])]]
    ];
}
$contents[] = [
    'role' => 'user',
    'parts' => [['text' => $message]]
];

$data = [
    'contents' => $contents,
    'generationConfig' => [
        'temperature' => 0.9,
        'maxOutputTokens' => 1024,
    ],
    'safetySettings' => [
        ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
        ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE'],
        ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
    ]
];

// FIXED MODEL NAME HERE ↓↓↓
// Change this line to:
$api_url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $gemini_api_key;

$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // temporary for testing

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Log full info
error_log("[" . date('Y-m-d H:i:s') . "] HTTP Code: $httpCode | cURL Error: " . ($curlError ?: 'None'));
error_log("Response (first 500 chars): " . substr($response, 0, 500));

if ($httpCode !== 200 || $curlError) {
    $errMsg = $curlError ?: "HTTP $httpCode - " . substr($response, 0, 200);
    error_log("Gemini failed: $errMsg");
    echo json_encode([
        'status' => 'error',
        'message' => 'AI service error: ' . $errMsg
    ]);
    exit;
}

$json = json_decode($response, true);
$reply = $json['candidates'][0]['content']['parts'][0]['text'] ?? "Sorry, I couldn't respond. Try again!";

echo json_encode([
    'status' => 'success',
    'reply' => $reply
]);
?>