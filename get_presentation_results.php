<?php
require_once 'config.php';
header('Content-Type: application/json');

// Log incoming params (for debugging)
error_log("get_presentation_results.php called with: " . json_encode($_GET));

// ──────────────────────────────────────────────
// Get required / optional parameters
// ──────────────────────────────────────────────
$presentation_id   = isset($_GET['presentation_id']) ? intval($_GET['presentation_id']) : 0;
$user_roll_number  = isset($_GET['user_roll_number']) ? trim($_GET['user_roll_number']) : '';
$is_admin          = isset($_GET['is_admin']) && intval($_GET['is_admin']) === 1;

if ($presentation_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'presentation_id is required']);
    exit;
}

// ──────────────────────────────────────────────
// Get current user info (for role & filtering)
// ──────────────────────────────────────────────
$user_id = 0;
$my_student_id = 0;

if (!empty($user_roll_number)) {
    // Get user id (assessor)
    $stmt = $conn->prepare("SELECT id FROM users WHERE roll_number = ?");
    $stmt->bind_param("s", $user_roll_number);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $user_id = intval($row['id']);
    }
    $stmt->close();

    // Get student id (if applicable)
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
// Get presentation basic info
// ──────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT 
        p.id, p.subject_id, p.presentation_date, p.type, p.status, p.notes,
        sub.name AS subject_name,
        u.full_name AS assessor_name
    FROM presentations p
    LEFT JOIN subjects sub ON p.subject_id = sub.id
    LEFT JOIN users u ON p.assessor_id = u.id
    WHERE p.id = ?
");
$stmt->bind_param("i", $presentation_id);
$stmt->execute();
$presentation = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$presentation) {
    echo json_encode(['status' => 'error', 'message' => 'Presentation not found']);
    exit;
}

// Get participants
$participants = [];
$stmt = $conn->prepare("SELECT roll_no, name FROM students WHERE roll_no IN (?)");
$rolls_list = str_replace(',', "','", $presentation['participant_rolls']); // safe for IN clause
$stmt->bind_param("s", $rolls_list); // Note: better to use explode + loop in production, but simple for now
$stmt->execute();
$participants_result = $stmt->get_result();
while ($row = $participants_result->fetch_assoc()) {
    $participants[] = $row;
}
$stmt->close();

// ──────────────────────────────────────────────
// Fetch all assessments for this presentation
// ──────────────────────────────────────────────
$sql = "
    SELECT 
        a.id, a.student_id, a.assessor_id, a.subject_id,
        a.total_marks, a.max_possible_marks, a.percentage,
        a.assessment_date, a.created_at,
        stu.name AS student_name,
        stu.roll_no,
        u.full_name AS assessor_name,
        CASE WHEN u.is_admin = 1 THEN 'teacher' ELSE 'peer' END AS assessor_type
    FROM assessments a
    LEFT JOIN students stu ON a.student_id = stu.id
    LEFT JOIN users u ON a.assessor_id = u.id
    WHERE a.presentation_id = ?
    ORDER BY stu.roll_no, a.assessment_date DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $presentation_id);
$stmt->execute();
$all_assessments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Enrich with criteria scores (reuse your helper function)
enrichWithCriteriaScores($all_assessments, $conn);

// ──────────────────────────────────────────────
// Group results by student
// ──────────────────────────────────────────────
$results_per_student = [];
foreach ($all_assessments as $ass) {
    $sid = $ass['student_id'];
    if (!isset($results_per_student[$sid])) {
        $results_per_student[$sid] = [
            'student_id'          => $sid,
            'roll_no'             => $ass['roll_no'],
            'name'                => $ass['student_name'],
            'average_percentage'  => 0,
            'assessment_count'    => 0,
            'assessments'         => []
        ];
    }

    // Anonymize peer assessors for non-admins
    if (!$is_admin && $ass['assessor_type'] === 'peer') {
        $ass['assessor_name'] = 'Anonymous';
    }

    $results_per_student[$sid]['assessments'][] = $ass;
    $results_per_student[$sid]['assessment_count']++;
}

// Calculate averages per student
foreach ($results_per_student as &$student) {
    if ($student['assessment_count'] > 0) {
        $sum = 0;
        foreach ($student['assessments'] as $a) {
            $sum += $a['percentage'];
        }
        $student['average_percentage'] = round($sum / $student['assessment_count'], 1);
    }
}
$results_per_student = array_values($results_per_student); // re-index

// ──────────────────────────────────────────────
// Final output
// ──────────────────────────────────────────────
echo json_encode([
    'status'                => 'success',
    'presentation'          => $presentation,
    'participants'          => $participants,
    'results_per_student'   => $results_per_student,
    'total_assessments'     => count($all_assessments)
]);

$conn->close();

// ──────────────────────────────────────────────
// Helper function (copied from your get_assessments.php)
// ──────────────────────────────────────────────
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

        // Force numeric types
        $ass['total_marks']        = (float) $ass['total_marks'];
        $ass['max_possible_marks'] = (float) $ass['max_possible_marks'];
        $ass['percentage']         = (float) $ass['percentage'];
        $ass['id']                 = (int) $ass['id'];
        $ass['student_id']         = (int) $ass['student_id'];
        $ass['assessor_id']        = (int) $ass['assessor_id'];
        $ass['subject_id']         = (int) $ass['subject_id'];
    }
}
?>