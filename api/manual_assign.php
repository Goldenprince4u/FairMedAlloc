<?php
/**
 * Manual Allocation API
 */
session_start();
require_once '../db_config.php';
require_once '../includes/security_helper.php';

if (($_SESSION['role'] ?? '') !== 'admin') { die("Unauthorized"); }

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $student_id = (int) $_POST['student_id'];
    $room_id = (int) $_POST['room_id']; // This receives actual room_id now

    if ($room_id > 0 && $student_id > 0) {
        // Check for existing allocation and decrement occupancy
        $check = $conn->query("SELECT room_id FROM allocations WHERE student_id = $student_id");
        if ($check->num_rows > 0) {
            $old_room_id = $check->fetch_assoc()['room_id'];
            $conn->query("UPDATE rooms SET occupied_count = GREATEST(0, occupied_count - 1) WHERE room_id = $old_room_id");
            $conn->query("DELETE FROM allocations WHERE student_id = $student_id");
        }
        
        // Insert new
        $stmt = $conn->prepare("INSERT INTO allocations (student_id, room_id, allocation_method) VALUES (?, ?, 'manual')");
        $stmt->bind_param("ii", $student_id, $room_id);
        
        if ($stmt->execute()) {
             // Update occupancy
             $conn->query("UPDATE rooms SET occupied_count = occupied_count + 1 WHERE room_id = $room_id");
             echo json_encode(['status'=>'success']);
        } else {
             echo json_encode(['status'=>'error', 'message'=>'Database Error']);
        }
    } else {
        echo json_encode(['status'=>'error', 'message'=>'Invalid Data']);
    }
}
?>
