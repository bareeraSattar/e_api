<?php
require_once 'config.php';
header('Content-Type: application/json');

// Force logging (just in case)
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/submit_assessment_error.log');
error_reporting(E_ALL);

// Debug file - will be created/updated on every submission
$debugLog = __DIR__ . '/notify_force.log';

// Write start proof
file_put_contents($debugLog, "\n[" . date('Y-m-d H:i:s') . "] SCRIPT STARTED - Submission attempt\n", FILE_APPEND);

/* ---------- Read JSON input ---------- */
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !is_array($input)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid or missing JSON input'
    ]);
    file_put_contents($debugLog, "[" . date('Y-m-d H:i:s') . "] EXIT: Invalid JSON\n", FILE_APPEND);
    exit;
}

/* ---------- Required fields ---------- */
if (
    (empty($input['assessor_id']) && empty($input['assessor_roll_number'])) ||
    (empty($input['student_id']) && empty($input['student_roll_number'])) ||
    empty($input['subject_id']) ||
    empty($input['assessment_date'])
) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing required fields'
    ]);
    file_put_contents($debugLog, "[" . date('Y-m-d H:i:s') . "] EXIT: Missing fields\n", FILE_APPEND);
    exit;
}

$assessor_id = intval($input['assessor_id'] ?? 0);
$student_id = intval($input['student_id'] ?? 0);
$subject_id = intval($input['subject_id']);
$assessment_date = $conn->real_escape_string($input['assessment_date']);
$criteria_scores = $input['criteria_scores'] ?? [];

/* ---------- Lookup assessor_id if roll provided ---------- */
if ($assessor_id == 0 && !empty($input['assessor_roll_number'])) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE roll_number = ?");
    $stmt->bind_param("s", $input['assessor_roll_number']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $assessor_id = intval($row['id']);
    }
    $stmt->close();
}

/* ---------- Lookup student_id if roll provided ---------- */
if ($student_id == 0 && !empty($input['student_roll_number'])) {
    $stmt = $conn->prepare("SELECT id FROM students WHERE roll_no = ?");
    $stmt->bind_param("s", $input['student_roll_number']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $student_id = intval($row['id']);
    }
    $stmt->close();
}

/* ---------- Validate IDs exist ---------- */
if ($assessor_id == 0) {
    echo json_encode(['status' => 'error', 'message' => 'Assessor not found']);
    exit;
}
if ($student_id == 0) {
    echo json_encode(['status' => 'error', 'message' => 'Student not found']);
    exit;
}

/* ---------- Prevent self-assessment ---------- */
if ($assessor_id == $student_id) {
    echo json_encode(['status' => 'error', 'message' => 'You cannot assess yourself']);
    exit;
}

/* ---------- Validate criteria ---------- */
if (!is_array($criteria_scores) || empty($criteria_scores)) {
    echo json_encode(['status' => 'error', 'message' => 'No criteria scores provided']);
    exit;
}

/* ---------- Calculate totals ---------- */
$total_marks = 0.0;
$max_possible = 0.0;
foreach ($criteria_scores as $score) {
    $criteria_id = intval($score['criteria_id'] ?? 0);
    $marks_awarded = floatval($score['marks_awarded'] ?? $score['score'] ?? 0);
    if ($criteria_id <= 0 || $marks_awarded < 0) {
        continue;
    }
    $stmt = $conn->prepare("SELECT max_marks FROM criteria WHERE id = ? AND subject_id = ?");
    $stmt->bind_param("ii", $criteria_id, $subject_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $max_marks = floatval($row['max_marks']);
        $total_marks += $marks_awarded;
        $max_possible += $max_marks;
    }
    $stmt->close();
}
$percentage = ($max_possible > 0) ? round(($total_marks / $max_possible) * 100, 2) : 0.0;

/* ---------- Transaction ---------- */
$conn->begin_transaction();
try {
    // Check for existing assessment by unique assessor-student-subject
    $existing_assessment_id = null;
    $check_stmt = $conn->prepare(
        "SELECT id FROM assessments
         WHERE student_id = ?
           AND subject_id = ?
           AND assessor_id = ?
         LIMIT 1"
    );
    $check_stmt->bind_param("iii", $student_id, $subject_id, $assessor_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    if ($check_row = $check_result->fetch_assoc()) {
        $existing_assessment_id = $check_row['id'];
    }
    $check_stmt->close();

    if ($existing_assessment_id) {
        error_log("[ASSESSMENT] Updating existing ID $existing_assessment_id for student $student_id");
        $update_stmt = $conn->prepare(
            "UPDATE assessments SET
                total_marks = ?,
                max_possible_marks = ?,
                percentage = ?,
                assessment_date = ?,
                created_at = NOW()
             WHERE id = ?"
        );
        $update_stmt->bind_param("dddsi", $total_marks, $max_possible, $percentage, $assessment_date, $existing_assessment_id);
        $update_stmt->execute();
        $update_stmt->close();

        $delete_stmt = $conn->prepare("DELETE FROM assessment_scores WHERE assessment_id = ?");
        $delete_stmt->bind_param("i", $existing_assessment_id);
        $delete_stmt->execute();
        $delete_stmt->close();

        $assessment_id = $existing_assessment_id;
    } else {
        error_log("[ASSESSMENT] Creating new for student $student_id");
        $insert_stmt = $conn->prepare(
            "INSERT INTO assessments (
                student_id, assessor_id, subject_id, total_marks, max_possible_marks, percentage, assessment_date, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $insert_stmt->bind_param("iiiddds", $student_id, $assessor_id, $subject_id, $total_marks, $max_possible, $percentage, $assessment_date);
        $insert_stmt->execute();
        $assessment_id = $insert_stmt->insert_id;
        $insert_stmt->close();
    }

    // Insert (or re-insert) scores
    foreach ($criteria_scores as $score) {
        $criteria_id = intval($score['criteria_id'] ?? 0);
        $marks_awarded = floatval($score['marks_awarded'] ?? $score['score'] ?? 0);
        if ($criteria_id <= 0 && !empty($score['criteria_name'])) {
            $name = $conn->real_escape_string(trim($score['criteria_name']));
            $q = $conn->prepare("SELECT id FROM criteria WHERE name = ? AND subject_id = ? LIMIT 1");
            $q->bind_param("si", $name, $subject_id);
            $q->execute();
            $res = $q->get_result();
            if ($row = $res->fetch_assoc()) {
                $criteria_id = intval($row['id']);
            }
            $q->close();
        }
        if ($criteria_id <= 0) {
            throw new Exception("Invalid criteria: " . ($score['criteria_name'] ?? 'unknown'));
        }
        $stmt = $conn->prepare("INSERT INTO assessment_scores (assessment_id, criteria_id, marks_awarded) VALUES (?, ?, ?)");
        $stmt->bind_param("iid", $assessment_id, $criteria_id, $marks_awarded);
        $stmt->execute();
        $stmt->close();
    }

    /* Insert feedback if provided */
    if (!empty($input['feedback_text'])) {
        $feedback_text = $conn->real_escape_string(trim($input['feedback_text']));
        $assessor_roll = $input['assessor_roll_number'] ?? '';
        $stmt = $conn->prepare("INSERT INTO feedback (assessment_id, feedback_text, feedback_by) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $assessment_id, $feedback_text, $assessor_roll ?: 'Anonymous');
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();

    // ────────────────────────────────────────────────────────────────
    // FORCED PROOF AFTER COMMIT (this MUST appear if commit works)
    // ────────────────────────────────────────────────────────────────
    $commitProof = "[" . date('Y-m-d H:i:s') . "] COMMIT SUCCESSFULLY REACHED - Assessment ID: $assessment_id | Student ID: $student_id\n";
    error_log("[FORCE-PROOF] $commitProof");
    file_put_contents($debugFile, $commitProof, FILE_APPEND);

    // ────────────────────────────────────────────────────────────────
    // FORCED TEST NOTIFICATION - you WILL receive this if we reach here
    // REMOVE AFTER TEST
    // ────────────────────────────────────────────────────────────────
    $forceToken = 'cniMOln5SKySxOOPC3e0wL:APA91bEhygWrc_B0RCEAVTpAZt2EVxn3jVuILhRikGdIUBDFvh6HaXmrsN1AnfENhyhS32hzBeOSQmPzDe3n7_Ge0vASFPbaAG19bfKz0RcP3BIX-0BkH5Q'; // Your working token
    require_once __DIR__ . '/send_notification.php';
    $forceTitle = "FORCED TEST AFTER COMMIT";
    $forceBody = "Submission committed successfully - Assessment ID $assessment_id - This is FORCED proof that push code is reached";
    $forceData = ['test' => 'forced', 'assessment_id' => $assessment_id];
    $forceSent = sendFcmNotification($forceToken, $forceTitle, $forceBody, $forceData);
    $forceResult = "[" . date('Y-m-d H:i:s') . "] FORCED PUSH SENT - Result: " . ($forceSent ? 'SUCCESS' : 'FAILED') . "\n";
    error_log("[FORCE-NOTIFY] $forceResult");
    file_put_contents($debugFile, $forceResult, FILE_APPEND);

    // ────────────────────────────────────────────────────────────────
    // Your original push notification block (unchanged)
    // ────────────────────────────────────────────────────────────────
    error_log("[NOTIFY-TRACE] === START OF NOTIFICATION BLOCK ===");
    error_log("[NOTIFY-TRACE] Submission successful - assessment ID: $assessment_id");
    error_log("[NOTIFY-TRACE] Student ID: $student_id");

    if ($student_id > 0) {
        $roll_stmt = $conn->prepare("SELECT roll_no FROM students WHERE id = ? LIMIT 1");
        $roll_stmt->bind_param("i", $student_id);
        $roll_stmt->execute();
        $roll_result = $roll_stmt->get_result();
        $student_roll = null;
        if ($r = $roll_result->fetch_assoc()) {
            $student_roll = $r['roll_no'];
            error_log("[NOTIFY-TRACE] Student roll found: $student_roll");
        } else {
            error_log("[NOTIFY-TRACE] NO roll_no found for student_id $student_id");
        }
        $roll_stmt->close();

        if ($student_roll) {
            $user_stmt = $conn->prepare("SELECT id FROM users WHERE roll_number = ? LIMIT 1");
            $user_stmt->bind_param("s", $student_roll);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $target_user_id = null;
            if ($u = $user_result->fetch_assoc()) {
                $target_user_id = $u['id'];
                error_log("[NOTIFY-TRACE] Target user_id found: $target_user_id");
            } else {
                error_log("[NOTIFY-TRACE] NO matching user found for roll $student_roll");
            }
            $user_stmt->close();

            if ($target_user_id) {
                error_log("[NOTIFY-TRACE] Looking up token for user_id $target_user_id");
                $stmt_token = $conn->prepare("SELECT token FROM fcm_tokens WHERE user_id = ? LIMIT 1");
                $stmt_token->bind_param("i", $target_user_id);
                $stmt_token->execute();
                $result_token = $stmt_token->get_result();
                if ($row_token = $result_token->fetch_assoc()) {
                    $deviceToken = trim($row_token['token'] ?? '');
                    error_log("[NOTIFY-TRACE] Token found - length = " . strlen($deviceToken));
                    if (strlen($deviceToken) > 30) {
                        error_log("[NOTIFY-TRACE] Calling sendFcmNotification()");
                        require_once __DIR__ . '/send_notification.php';
                        $push_title = "Assessment Submitted";
                        $push_body = "Your assessment for subject ID $subject_id has been received. View your results now!";
                        $push_data = [
                            'assessment_id' => (string)$assessment_id,
                            'student_id' => (string)$student_id,
                            'type' => 'assessment_done'
                        ];
                        $sent = sendFcmNotification($deviceToken, $push_title, $push_body, $push_data);
                        error_log("[NOTIFY-TRACE] send result: " . ($sent ? 'SUCCESS' : 'FAILED'));
                    } else {
                        error_log("[NOTIFY-TRACE] Token too short or empty");
                    }
                } else {
                    error_log("[NOTIFY-TRACE] NO token found for user_id $target_user_id");
                }
                $stmt_token->close();
            }
        }
    } else {
        error_log("[NOTIFY-TRACE] student_id invalid - push skipped");
    }

    error_log("[NOTIFY-TRACE] === END OF NOTIFICATION BLOCK ===");

    echo json_encode([
        'status' => 'success',
        'message' => 'Assessment submitted successfully',
        'assessment_id' => $assessment_id,
        'total_marks' => $total_marks,
        'percentage' => $percentage
    ]);

    file_put_contents($debugFile, "RESPONSE SENT - " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
} catch (Exception $e) {
    $conn->rollback();
    $errMsg = "[FATAL-ERROR] Submission failed: " . $e->getMessage() . " at line " . $e->getLine();
    error_log($errMsg);
    file_put_contents($debugFile, $errMsg . "\n", FILE_APPEND);
    echo json_encode([
        'status' => 'error',
        'message' => 'Submission failed: ' . $e->getMessage()
    ]);
}

$conn->close();

// Final proof
file_put_contents($debugFile, "SCRIPT FULLY ENDED - " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
?>