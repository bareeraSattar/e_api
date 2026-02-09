<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

include_once 'config.php';

$data = json_decode(file_get_contents("php://input"));

if (!empty($data->subject_id) && !empty($data->participant_rolls) && !empty($data->assessor_roll_number) && !empty($data->presentation_date)) {
    // Lookup assessor_id
    $stmt = $conn->prepare("SELECT id, is_admin FROM users WHERE roll_number = ?");
    $stmt->bind_param("s", $data->assessor_roll_number);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $assessor_id = $row['id'];
        $is_admin = $row['is_admin'];
    } else {
        echo json_encode(["message" => "Invalid assessor roll number."]);
        exit;
    }

    // Students can add if they are in the participants or if admin
    $rolls_array = array_map('trim', explode(',', $data->participant_rolls));
    $rolls_array = array_unique($rolls_array);  // Ensure unique
    if (count($rolls_array) == 0) {
        echo json_encode(["message" => "At least one participant required."]);
        exit;
    }

    // Validate each roll exists in students
    foreach ($rolls_array as $roll) {
        $stmt = $conn->prepare("SELECT id FROM students WHERE roll_no = ?");
        $stmt->bind_param("s", $roll);
        $stmt->execute();
        if ($stmt->get_result()->num_rows == 0) {
            echo json_encode(["message" => "Invalid roll number: $roll."]);
            exit;
        }
    }

    // If not admin, ensure assessor is in participants (for students adding own)
    if ($is_admin == 0 && !in_array($data->assessor_roll_number, $rolls_array)) {
        echo json_encode(["message" => "Students can only add presentations including themselves."]);
        exit;
    }

    // Set type
    $type = (count($rolls_array) > 1) ? 'group' : 'individual';

    // Insert
    $participant_rolls_str = implode(',', $rolls_array);
    $stmt = $conn->prepare("INSERT INTO presentations (subject_id, participant_rolls, assessor_id, presentation_date, type, notes) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isisss", $data->subject_id, $participant_rolls_str, $assessor_id, $data->presentation_date, $type, $data->notes);
    
    if ($stmt->execute()) {
        echo json_encode(["message" => "Presentation scheduled successfully."]);
    } else {
        echo json_encode(["message" => "Failed to schedule."]);
    }
} else {
    echo json_encode(["message" => "Incomplete data."]);
}
?>