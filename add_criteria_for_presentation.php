<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header("Cache-Control: no-cache, must-revalidate");

include_once 'config.php';

$logFile = __DIR__ . '/add_criteria_log.txt';

function logToFile($message) {
    global $logFile;
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - $message\n", FILE_APPEND);
}

logToFile("Request started. Raw input: " . file_get_contents("php://input"));

$data = json_decode(file_get_contents("php://input"));

if (!empty($data->subject_id) && !empty($data->name) && !empty($data->max_marks)) {
   
    logToFile("Data received: " . json_encode($data));

    $assessor_user_id = isset($data->assessor_user_id) ? intval($data->assessor_user_id) : null;
    $assessor_roll    = $data->assessor_roll_number ?? '';
    $is_admin_sent    = isset($data->is_admin) && $data->is_admin == 1;
    $assessor_id      = null;

    // Verify admin by user_id (preferred for admins without roll)
    if ($assessor_user_id) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND is_admin = 1");
        if ($stmt) {
            $stmt->bind_param("i", $assessor_user_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $assessor_id = $assessor_user_id;
                logToFile("Admin verified by user_id: $assessor_id");
            }
            $stmt->close();
        }
    }

    // Fallback: verify by roll_number (for teachers)
    if ($assessor_id === null && !empty($assessor_roll)) {
        $stmt = $conn->prepare("SELECT id, is_admin FROM users WHERE roll_number = ?");
        if ($stmt) {
            $stmt->bind_param("s", $assessor_roll);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc() && $row['is_admin'] == 1) {
                $assessor_id = $row['id'];
                logToFile("Teacher verified by roll: $assessor_roll, ID: $assessor_id");
            }
            $stmt->close();
        }
    }

    if ($assessor_id === null) {
        logToFile("Unauthorized: No valid admin/teacher found");
        echo json_encode(["success" => false, "message" => "Only teachers/admins can add criteria. Please log in as admin."]);
        exit;
    }

    // ──────────────────────────────────────────────
    // NEW: Prevent duplicate criteria (same name + subject + presentation)
    // ──────────────────────────────────────────────
    $name_trimmed = trim($data->name);
    $name_lower   = strtolower($name_trimmed);

    $check_sql = "
        SELECT id 
        FROM criteria 
        WHERE subject_id = ? 
          AND LOWER(TRIM(name)) = ? 
          AND is_presentation = 1 
        LIMIT 1
    ";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("is", $data->subject_id, $name_lower);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        logToFile("Duplicate prevented: Criterion '$name_trimmed' already exists for subject {$data->subject_id} (presentation)");
        echo json_encode([
            "success" => false,
            "message" => "This criterion name already exists for presentations in this subject. Please choose a different name."
        ]);
        $check_stmt->close();
        $conn->close();
        exit;
    }
    $check_stmt->close();
    // ──────────────────────────────────────────────

    // INSERT – proceed only if no duplicate
    $stmt = $conn->prepare("
        INSERT INTO criteria
        (subject_id, name, max_marks, weightage, is_presentation)
        VALUES (?, ?, ?, ?, 1)
    ");

    if (!$stmt) {
        logToFile("Prepare INSERT failed: " . $conn->error);
        echo json_encode(["success" => false, "message" => "Database prepare error: " . $conn->error]);
        exit;
    }

    $weightage = isset($data->weightage) && is_numeric($data->weightage) ? floatval($data->weightage) : 1.00;
    $max_marks = is_numeric($data->max_marks) ? floatval($data->max_marks) : 0.0;
    $subject_id = intval($data->subject_id);
    $name = $name_trimmed;  // use trimmed version

    logToFile("Bound values: subject_id=$subject_id, name='$name', max_marks=$max_marks, weightage=$weightage");

    $stmt->bind_param("isdd", $subject_id, $name, $max_marks, $weightage);

    if ($stmt->execute()) {
        $affected = $stmt->affected_rows;
        $newId = $conn->insert_id;
        logToFile("Execute success. Affected rows: $affected. New ID: $newId");
        echo json_encode([
            "success" => true,
            "message" => "Criteria added successfully.",
            "id" => $newId
        ]);
    } else {
        logToFile("Execute failed: " . $stmt->error);
        echo json_encode([
            "success" => false,
            "message" => "Failed to add criteria: " . $stmt->error
        ]);
    }

    $stmt->close();

} else {
    logToFile("Incomplete data");
    echo json_encode(["success" => false, "message" => "Incomplete data. Required: subject_id, name, max_marks"]);
}

$conn->close();
logToFile("Request ended\n---");
?>