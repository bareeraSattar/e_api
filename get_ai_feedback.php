<?php
require_once 'config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !is_array($input)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input']);
    exit;
}

$user_roll_number = isset($input['user_roll_number']) ? trim($input['user_roll_number']) : '';
$subject_id = isset($input['subject_id']) ? intval($input['subject_id']) : 0;
$subject_name = isset($input['subject_name']) ? trim($input['subject_name']) : 'this subject';
$performance_summary = isset($input['performance_summary']) ? trim($input['performance_summary']) : '';

// Build summary from DB if not provided
if (empty($performance_summary) && $subject_id > 0 && !empty($user_roll_number)) {
    $stmt = $conn->prepare("SELECT id FROM students WHERE roll_no = ?");
    $stmt->bind_param("s", $user_roll_number);
    $stmt->execute();
    $result = $stmt->get_result();
    $student_id = 0;
    if ($row = $result->fetch_assoc()) {
        $student_id = (int)$row['id'];
    }
    $stmt->close();
    if ($student_id > 0) {
        $avg_sql = "SELECT AVG(percentage) AS avg_perc FROM assessments WHERE student_id = ? AND subject_id = ?";
        $avg_stmt = $conn->prepare($avg_sql);
        $avg_stmt->bind_param("ii", $student_id, $subject_id);
        $avg_stmt->execute();
        $avg_row = $avg_stmt->get_result()->fetch_assoc();
        $avg_perc = $avg_row['avg_perc'] ? round((float)$avg_row['avg_perc'], 1) : null;
        $avg_stmt->close();
        $weak_sql = "
            SELECT c.name AS criteria_name,
                   AVG(s.marks_awarded / c.max_marks * 100) AS avg_score_percent
            FROM assessment_scores s
            JOIN criteria c ON s.criteria_id = c.id
            JOIN assessments a ON s.assessment_id = a.id
            WHERE a.student_id = ? AND a.subject_id = ?
            GROUP BY c.id, c.name
            HAVING avg_score_percent < 70
            ORDER BY avg_score_percent ASC
            LIMIT 3
        ";
        $weak_stmt = $conn->prepare($weak_sql);
        $weak_stmt->bind_param("ii", $student_id, $subject_id);
        $weak_stmt->execute();
        $weak_result = $weak_stmt->get_result();
        $weak_areas = [];
        while ($w = $weak_result->fetch_assoc()) {
            $weak_areas[] = $w['criteria_name'] . " (" . round($w['avg_score_percent'], 1) . "%)";
        }
        $weak_stmt->close();
        $performance_summary = "Average score: " . ($avg_perc ? $avg_perc . '%' : 'unknown') . ". ";
        if (!empty($weak_areas)) {
            $performance_summary .= "Needs improvement especially in: " . implode(", ", $weak_areas) . ".";
        } else {
            $performance_summary .= "Performance looks solid overall (no major weak areas below 70%).";
        }
    }
}
if (empty($performance_summary)) {
    error_log("No performance summary generated for roll: $user_roll_number, subject: $subject_id");
    echo json_encode([
        'status' => 'error',
        'message' => 'Cannot generate feedback: missing performance data and could not fetch from database.'
    ]);
    exit;
}
$prompt = "You are a supportive and kind teacher helping a student improve.
Subject: $subject_name
Performance summary: $performance_summary
Give feedback in this exact markdown format:
- **Areas to improve** — list 1–3 specific topics/skills (use criteria names when possible)
- **Why it matters** — short reason for each area
- **How to improve** — clear, practical tips (study techniques, daily practice, free resources like YouTube/Khan Academy, etc.)
Tone: positive, motivating, easy to understand (for students 12–18 years old).
Length: 150–300 words. Do not add extra headings or conclusions.";

$data = [
    'contents' => [
        ['parts' => [['text' => $prompt]]]
    ],
    'generationConfig' => [
        'temperature' => 0.75,
        'maxOutputTokens' => 450,
    ],
    'safetySettings' => [
        ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
        ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE'],
        ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
    ]
];

// FIXED: Use the model from your ListModels output
$ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $gemini_api_key);

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_VERBOSE, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

error_log("--- Gemini Request Debug ---");
error_log("Roll: $user_roll_number | Subject ID: $subject_id | Name: $subject_name");
error_log("Summary used: $performance_summary");
error_log("HTTP Code: $httpCode");
if ($curlError) error_log("cURL error: $curlError");
error_log("Raw Gemini response: " . substr($response, 0, 1000));

if ($httpCode !== 200 || $curlError) {
    echo json_encode([
        'status' => 'error',
        'message' => 'AI service unavailable. HTTP: ' . $httpCode . ($curlError ? ' - cURL: ' . $curlError : '')
    ]);
    exit;
}

$json = json_decode($response, true);
$feedback = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
if (empty($feedback)) {
    $feedback = "We're having trouble generating detailed feedback right now.\nTry focusing on topics where you scored lowest in recent assessments.";
}
echo json_encode([
    'status' => 'success',
    'feedback' => $feedback,
    'summary' => $performance_summary
]);
$conn->close();
?>