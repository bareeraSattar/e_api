<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Change to your Flutter app domain later for security
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';

/* ---------- Only allow POST requests ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

/* ---------- Read JSON body from Flutter ---------- */
$data = json_decode(file_get_contents('php://input'), true);

$roll_number = trim($data['roll_number'] ?? '');
$email       = trim($data['email'] ?? '');
$password    = trim($data['password'] ?? '');

if ($roll_number === '' || $email === '' || $password === '') {
    echo json_encode([
        'success' => false,
        'message' => 'roll_number, email and password are required'
    ]);
    exit;
}

/* ---------- 1. Check if roll number exists in students table ---------- */
$stmt = $conn->prepare("SELECT name FROM students WHERE roll_no = ?");
$stmt->bind_param("s", $roll_number);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    $stmt->close();
    echo json_encode([
        'success' => false,
        'message' => 'Roll number not found in class list'
    ]);
    exit;
}

$stmt->bind_result($student_name);
$stmt->fetch();
$stmt->close();

/* ---------- 2. Check for duplicate roll_number or email in users ---------- */
$stmt = $conn->prepare("SELECT id FROM users WHERE roll_number = ? OR email = ?");
$stmt->bind_param("ss", $roll_number, $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $stmt->close();
    echo json_encode([
        'success' => false,
        'message' => 'This roll number or email is already registered'
    ]);
    exit;
}
$stmt->close();

/* ---------- 3. Create new student user ---------- */
$stmt = $conn->prepare(
    "INSERT INTO users 
     (roll_number, full_name, email, password, is_admin, created_at)
     VALUES (?, ?, ?, ?, 0, NOW())"
);

$stmt->bind_param("ssss", $roll_number, $student_name, $email, $password);

if ($stmt->execute()) {
    $new_user_id = $conn->insert_id;  // Get the auto-generated user ID

    echo json_encode([
        'success' => true,
        'message' => 'Account created successfully',
        'user' => [
            'id'          => $new_user_id,          // Important for Flutter persistent login
            'roll_number' => $roll_number,
            'full_name'   => $student_name,
            'email'       => $email,
            'is_admin'    => 0
        ]
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to create account: ' . $stmt->error
    ]);
}

$stmt->close();
$conn->close();