<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
include_once 'config.php';

// Simple log function
function logToFile($msg) {
    file_put_contents(__DIR__ . '/assessment_debug.log', date('Y-m-d H:i:s') . " - $msg\n", FILE_APPEND);
}

$data = json_decode(file_get_contents("php://input"));

logToFile("Request received. Raw input: " . json_encode($data));

if (
    !empty($data->presentation_id) &&
    !empty($data->student_roll) &&
    !empty($data->scores) &&
    !empty($data->subject_id)
) {
    $student_roll = trim($data->student_roll ?? '');
    $assessor_roll = trim($data->assessor_roll_number ?? '');
    $assessor_user_id = intval($data->assessor_user_id ?? 0);
    $is_admin_sent = intval($data->is_admin ?? 0);

    logToFile("Normalized: student_roll='$student_roll', assessor_roll='$assessor_roll', user_id=$assessor_user_id, is_admin=$is_admin_sent");

    $assessor_id = null;
    $is_teacher = false;

    // 1. Prefer user_id + is_admin flag (for admins without roll)
    if ($assessor_user_id > 0 && $is_admin_sent === 1) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND is_admin = 1");
        if ($stmt) {
            $stmt->bind_param("i", $assessor_user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $assessor_id = $assessor_user_id;
                $is_teacher = true;
                logToFile("Admin verified by user_id: $assessor_id");
            } else {
                logToFile("Admin check failed - user_id $assessor_user_id not found or not admin");
            }
            $stmt->close();
        } else {
            logToFile("Prepare admin check failed: " . $conn->error);
        }
    }

    // 2. Fallback: check by roll_number (for teachers/students)
    if ($assessor_id === null && !empty($assessor_roll)) {
        $stmt = $conn->prepare("SELECT id, is_admin FROM users WHERE roll_number = ?");
        if ($stmt) {
            $stmt->bind_param("s", $assessor_roll);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $assessor_id = $row['id'];
                $is_teacher = ($row['is_admin'] == 1);
                logToFile("User found by roll: '$assessor_roll', ID: $assessor_id, is_teacher: " . ($is_teacher ? 'yes' : 'no'));
            } else {
                logToFile("No user found for roll: '$assessor_roll'");
            }
            $stmt->close();
        } else {
            logToFile("Prepare roll check failed: " . $conn->error);
        }
    }

    // Final check - no valid assessor
    if ($assessor_id === null) {
        logToFile("No valid assessor found - rejecting submission");
        echo json_encode(["success" => false, "message" => "User roll number not found or not authorized. Please log in again."]);
        exit;
    }

    // 3. Presentation validation
    $stmt = $conn->prepare("SELECT subject_id, participant_rolls FROM presentations WHERE id = ?");
    $stmt->bind_param("i", $data->presentation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$pres_row = $result->fetch_assoc()) {
        echo json_encode(["message" => "Invalid presentation ID."]);
        exit;
    }
    if ($pres_row['subject_id'] != $data->subject_id) {
        echo json_encode(["message" => "Subject mismatch."]);
        exit;
    }

    $participant_rolls = explode(',', $pres_row['participant_rolls']);
    $participant_rolls = array_map('trim', $participant_rolls);
    $student_roll_upper = strtoupper($student_roll);
    $participant_upper = array_map('strtoupper', $participant_rolls);

    if (!in_array($student_roll_upper, $participant_upper)) {
        echo json_encode(["message" => "Selected student is not part of this presentation."]);
        exit;
    }

    if (!$is_teacher && strtoupper($assessor_roll) === $student_roll_upper) {
        echo json_encode(["message" => "You cannot assess yourself."]);
        exit;
    }

    // 4. Student lookup (case/space proof)
    $stmt = $conn->prepare("SELECT id FROM students WHERE UPPER(TRIM(roll_no)) = ?");
    $stmt->bind_param("s", $student_roll_upper);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$student_row = $result->fetch_assoc()) {
        logToFile("Student not found: '$student_roll' (upper: '$student_roll_upper')");
        echo json_encode(["message" => "Student roll number not found in database."]);
        exit;
    }
    $student_id = $student_row['id'];
    logToFile("Student found: roll='$student_roll', id=$student_id");

    // 5. Calculate totals
    $total_marks = 0;
    $max_possible_marks = 0;
    foreach ($data->scores as $score) {
        if (!isset($score->criteria_id) || !isset($score->marks_awarded)) {
            echo json_encode(["message" => "Invalid score format."]);
            exit;
        }
        $total_marks += floatval($score->marks_awarded);
        $max_stmt = $conn->prepare("SELECT max_marks FROM criteria WHERE id = ? AND subject_id = ?");
        $max_stmt->bind_param("ii", $score->criteria_id, $data->subject_id);
        $max_stmt->execute();
        $max_res = $max_stmt->get_result();
        if ($max_row = $max_res->fetch_assoc()) {
            $max_possible_marks += floatval($max_row['max_marks']);
        } else {
            echo json_encode(["message" => "Invalid criteria ID for this subject."]);
            exit;
        }
        $max_stmt->close();
    }
    $percentage = ($max_possible_marks > 0) ? ($total_marks / $max_possible_marks) * 100 : 0;

    // 6. Insert assessment
    $insert_stmt = $conn->prepare("
        INSERT INTO assessments
        (student_id, assessor_id, subject_id, total_marks, max_possible_marks, percentage, assessment_date, presentation_id)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
    ");
    $insert_stmt->bind_param("iiiddii",
        $student_id,
        $assessor_id,
        $data->subject_id,
        $total_marks,
        $max_possible_marks,
        $percentage,
        $data->presentation_id
    );
    if (!$insert_stmt->execute()) {
        logToFile("Assessment insert failed: " . $conn->error);
        echo json_encode(["message" => "Failed to save assessment: " . $conn->error]);
        exit;
    }
    $assessment_id = $insert_stmt->insert_id;

    // 7. Insert per-criteria scores
    foreach ($data->scores as $score) {
        $score_stmt = $conn->prepare("
            INSERT INTO assessment_scores
            (assessment_id, criteria_id, marks_awarded, comments)
            VALUES (?, ?, ?, ?)
        ");
        $comments = $score->comments ?? null;
        $score_stmt->bind_param("iids",
            $assessment_id,
            $score->criteria_id,
            $score->marks_awarded,
            $comments
        );
        $score_stmt->execute();
        $score_stmt->close();
    }

    // 8. Mark presentation completed if teacher/admin
    if ($is_teacher) {
        $update_stmt = $conn->prepare("UPDATE presentations SET status = 'completed' WHERE id = ?");
        $update_stmt->bind_param("i", $data->presentation_id);
        $update_stmt->execute();
        $update_stmt->close();
    }

    logToFile("Success: Assessment $assessment_id submitted by assessor $assessor_id for student $student_id");
    echo json_encode([
        "success" => true,
        "message" => "Assessment submitted successfully.",
        "assessment_id" => $assessment_id
    ]);
} else {
    logToFile("Incomplete data received");
    echo json_encode(["success" => false, "message" => "Incomplete data."]);
}
?>