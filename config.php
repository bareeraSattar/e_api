<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

$host = 'localhost';
$user = 'bareeszl_student_assessment_db';
$pass = 'F;a5!)bf}bM~';
$db   = 'bareeszl_student_assessment_db';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die(json_encode(['status' => 'error', 'message' => 'Database connection failed']));
}

// ─── Add this line ───────────────────────────────────────────────────────────
$gemini_api_key = 'AIzaSyCb8xC7ZRzG6KZ6NorZxmTapzrcVMYQV-0';  // ← use this name // ← PASTE YOUR REAL GEMINI API KEY HERE

?>