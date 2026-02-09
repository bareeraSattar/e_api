<?php
require_once 'config.php';

$sql = "SELECT * FROM subjects ORDER BY name";
$result = $conn->query($sql);

$subjects = [];
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $subjects[] = $row;
    }
}

echo json_encode(['status' => 'success', 'subjects' => $subjects]);
$conn->close();
?>