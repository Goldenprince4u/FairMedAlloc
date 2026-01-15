<?php
header('Content-Type: application/json');
require_once '../db_config.php';

$hostel_id = (int) $_GET['hostel_id'];
if (!$hostel_id) { echo json_encode([]); exit; }

$sql = "SELECT room_id, room_number, floor_level FROM rooms 
        WHERE hostel_id = $hostel_id AND occupied_count < capacity 
        ORDER BY floor_level ASC, room_number ASC";

$res = $conn->query($sql);
$rooms = [];
while($row = $res->fetch_assoc()) {
    $rooms[] = $row;
}

echo json_encode($rooms);
?>
