<?php
require_once 'config.php';
header('Content-Type: application/json');

// Log incoming params (for debugging - keep this!)
error_log("get_assessments.php called with: " . json_encode($_GET));

$user_roll_number = isset($_GET['user_roll_number']) ? trim($_GET['user_roll_number']) : '';
$is_admin = isset($_GET['is_admin']) && intval($_GET['is_admin']) === 1;
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$type = isset($_GET['type']) ? strtolower($_GET['type']) : null; // 'assessment' or 'presentation'

$user_id = 0;
$my_student_id = 0;

if (!empty($user_roll_number)) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE roll_number = ?");
    $stmt->bind_param("s", $user_roll_number);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $user_id = intval($row['id']);
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT id FROM students WHERE roll_no = ?");
    $stmt->bind_param("s", $user_roll_number);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $my_student_id = intval($row['id']);
    }
    $stmt->close();
}

// ──────────────────────────────────────────────
// SINGLE STUDENT LATEST ASSESSMENT MODE (for loading sliders)
// ──────────────────────────────────────────────
$single_student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
if ($single_student_id > 0 && $subject_id > 0) {
    $sql = "
        SELECT
            a.id, a.student_id, a.assessor_id, a.subject_id,
            a.total_marks, a.max_possible_marks, a.percentage,
            a.assessment_date, a.created_at,
            stu.name AS student_name,
            sub.name AS subject_name,
            u.full_name AS assessor_name
        FROM assessments a
        LEFT JOIN students stu ON a.student_id = stu.id
        LEFT JOIN subjects sub ON a.subject_id = sub.id
        LEFT JOIN users u ON a.assessor_id = u.id
        WHERE a.student_id = ? 
          AND a.subject_id = ?
          AND a.assessor_id = ?
    ";
    if ($type === 'assessment') {
        $sql .= " AND a.presentation_id IS NULL";
    } elseif ($type === 'presentation') {
        $sql .= " AND a.presentation_id IS NOT NULL";
    }
    $sql .= " ORDER BY a.assessment_date DESC, a.created_at DESC LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $single_student_id, $subject_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $assessment = $row;
        $assessmentsTemp = [$assessment];
        enrichWithCriteriaScores($assessmentsTemp, $conn);
        $assessment = $assessmentsTemp[0] ?? $assessment;

        echo json_encode([
            'status' => 'success',
            'has_existing' => true,
            'assessment' => $assessment
        ]);
    } else {
        echo json_encode([
            'status' => 'success',
            'has_existing' => false,
            'assessment' => null
        ]);
    }
    $stmt->close();
    $conn->close();
    exit;
}

// ──────────────────────────────────────────────
// Original multi-assessment logic (unchanged)
// ──────────────────────────────────────────────
$given = [];
$received = [];
$all_assessments = [];
$average_percentage = null;

if ($subject_id == 0) {
    echo json_encode(['status' => 'error', 'message' => 'subject_id is required']);
    exit;
}

function enrichWithCriteriaScores(&$assessmentsArray, $conn) {
    foreach ($assessmentsArray as &$ass) {
        $ass_id = $ass['id'];
        $crit_sql = "
            SELECT
                c.name AS criteria_name,
                s.marks_awarded AS score,
                c.max_marks
            FROM assessment_scores s
            JOIN criteria c ON s.criteria_id = c.id
            WHERE s.assessment_id = ?
            ORDER BY c.id
        ";
        $cstmt = $conn->prepare($crit_sql);
        $cstmt->bind_param("i", $ass_id);
        $cstmt->execute();
        $ass['criteria_scores'] = $cstmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $cstmt->close();

        $ass['total_marks'] = (float) $ass['total_marks'];
        $ass['max_possible_marks'] = (float) $ass['max_possible_marks'];
        $ass['percentage'] = (float) $ass['percentage'];
        $ass['id'] = (int) $ass['id'];
        $ass['student_id'] = (int) $ass['student_id'];
        $ass['assessor_id'] = (int) $ass['assessor_id'];
        $ass['subject_id'] = (int) $ass['subject_id'];
    }
}

// ADMIN MODE
if ($is_admin) {
    $sql = "
        SELECT
            a.id, a.student_id, a.assessor_id, a.subject_id,
            a.total_marks, a.max_possible_marks, a.percentage,
            a.assessment_date, a.created_at,
            stu.name AS student_name,
            sub.name AS subject_name,
            u.full_name AS assessor_name
        FROM assessments a
        LEFT JOIN students stu ON a.student_id = stu.id
        LEFT JOIN subjects sub ON a.subject_id = sub.id
        LEFT JOIN users u ON a.assessor_id = u.id
        WHERE a.subject_id = ?
    ";
    if ($type === 'assessment') {
        $sql .= " AND a.presentation_id IS NULL";
    } elseif ($type === 'presentation') {
        $sql .= " AND a.presentation_id IS NOT NULL";
    }
    $sql .= " ORDER BY a.assessment_date DESC, a.created_at DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    if ($stmt->error) {
        error_log("Admin query error: " . $stmt->error);
        echo json_encode(['status' => 'error', 'message' => 'Admin query failed']);
        exit;
    }
    $all_assessments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    enrichWithCriteriaScores($all_assessments, $conn);

    $given = array_filter($all_assessments, function($a) use ($user_id) {
        return $user_id > 0 ? $a['assessor_id'] == $user_id : true;
    });

    error_log("Admin fetched " . count($all_assessments) . " assessments for subject $subject_id");

    echo json_encode([
        'status' => 'success',
        'given' => array_values($given),
        'received' => [],
        'average_percentage' => null,
        'all_assessments' => array_values($all_assessments)
    ]);
}
// STUDENT MODE
else {
    $given = [];
    $received = [];
    $average_percentage = null;

    if ($user_id > 0 || $my_student_id > 0) {
        if ($user_id > 0) {
            $given_sql = "
                SELECT
                    a.id, a.student_id, a.assessor_id, a.subject_id,
                    a.total_marks, a.max_possible_marks, a.percentage,
                    a.assessment_date, a.created_at,
                    s.name AS student_name, sub.name AS subject_name,
                    u.full_name AS assessor_name
                FROM assessments a
                JOIN students s ON a.student_id = s.id
                JOIN subjects sub ON a.subject_id = sub.id
                JOIN users u ON a.assessor_id = u.id
                WHERE a.assessor_id = ? AND a.subject_id = ?
            ";
            if ($type === 'assessment') {
                $given_sql .= " AND a.presentation_id IS NULL";
            } elseif ($type === 'presentation') {
                $given_sql .= " AND a.presentation_id IS NOT NULL";
            }
            $given_sql .= " ORDER BY a.assessment_date DESC, a.created_at DESC";

            $stmt = $conn->prepare($given_sql);
            $stmt->bind_param("ii", $user_id, $subject_id);
            $stmt->execute();
            if ($stmt->error) {
                error_log("Given query error: " . $stmt->error);
            } else {
                $given = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                enrichWithCriteriaScores($given, $conn);
            }
            $stmt->close();
        }

        if ($my_student_id > 0) {
            $received_sql = "
                SELECT
                    a.id, a.student_id, a.assessor_id, a.subject_id,
                    a.total_marks, a.max_possible_marks, a.percentage,
                    a.assessment_date, a.created_at,
                    sub.name AS subject_name,
                    'Anonymous' AS assessor_name
                FROM assessments a
                JOIN subjects sub ON a.subject_id = sub.id
                WHERE a.student_id = ? AND a.subject_id = ?
            ";
            if ($type === 'assessment') {
                $received_sql .= " AND a.presentation_id IS NULL";
            } elseif ($type === 'presentation') {
                $received_sql .= " AND a.presentation_id IS NOT NULL";
            }
            $received_sql .= " ORDER BY a.assessment_date DESC, a.created_at DESC";

            $stmt = $conn->prepare($received_sql);
            $stmt->bind_param("ii", $my_student_id, $subject_id);
            $stmt->execute();
            if ($stmt->error) {
                error_log("Received query error: " . $stmt->error);
            } else {
                $received = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                enrichWithCriteriaScores($received, $conn);
            }
            $stmt->close();

            $avg_sql = "SELECT AVG(percentage) AS avg FROM assessments WHERE student_id = ? AND subject_id = ?";
            if ($type === 'assessment') {
                $avg_sql .= " AND presentation_id IS NULL";
            } elseif ($type === 'presentation') {
                $avg_sql .= " AND presentation_id IS NOT NULL";
            }
            $avg_stmt = $conn->prepare($avg_sql);
            $avg_stmt->bind_param("ii", $my_student_id, $subject_id);
            $avg_stmt->execute();
            $avg_row = $avg_stmt->get_result()->fetch_assoc();
            $average_percentage = $avg_row['avg'] ? round((float)$avg_row['avg'], 1) : null;
            $avg_stmt->close();
        }
    }

    echo json_encode([
        'status' => 'success',
        'given' => $given,
        'received' => $received,
        'average_percentage' => $average_percentage,
        'all_assessments' => []
    ]);
}

$conn->close();
?>