<?php
/**
 * Admin API Controller
 * ====================
 * Unified endpoint for administrative actions.
 * Usage: ?action=run_algorithm | manual_assign | get_rooms | analytics
 */
session_start();
require_once '../db_config.php';
header('Content-Type: application/json');

// 1. Security Check
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'run_algorithm':
        handleRunAlgorithm($conn);
        break;

    case 'manual_assign':
        handleManualAssign($conn);
        break;

    case 'get_rooms':
        handleGetRooms($conn);
        break;

    case 'analytics':
        handleAnalytics($conn);
        break;

    default:
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}

// --------------------------------------------------------------------------
// Handlers
// --------------------------------------------------------------------------

function handleRunAlgorithm($conn) {
    require_once '../includes/AllocationEngine.php';
    try {
        $engine = new AllocationEngine($conn);
        $result = $engine->run();
        echo json_encode($result);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

function handleManualAssign($conn) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        echo json_encode(['status' => 'error', 'message' => 'POST required']);
        return;
    }

    $student_id = (int) $_POST['student_id'];
    $room_id = (int) $_POST['room_id'];

    if ($room_id > 0 && $student_id > 0) {
        // Clear old allocation if exists
        $check = $conn->query("SELECT room_id FROM allocations WHERE student_id = $student_id");
        if ($check->num_rows > 0) {
            $old_room_id = $check->fetch_assoc()['room_id'];
            $conn->query("UPDATE rooms SET occupied_count = GREATEST(0, occupied_count - 1) WHERE room_id = $old_room_id");
            $conn->query("DELETE FROM allocations WHERE student_id = $student_id");
        }

        // Assign new
        $stmt = $conn->prepare("INSERT INTO allocations (student_id, room_id, allocation_method) VALUES (?, ?, 'manual')");
        $stmt->bind_param("ii", $student_id, $room_id);

        if ($stmt->execute()) {
            $conn->query("UPDATE rooms SET occupied_count = occupied_count + 1 WHERE room_id = $room_id");
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database Error']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Data']);
    }
}

function handleGetRooms($conn) {
    $hostel_id = (int) ($_GET['hostel_id'] ?? 0);
    if (!$hostel_id) { 
        echo json_encode([]); 
        return; 
    }

    $sql = "SELECT room_id, room_number, floor_level FROM rooms 
            WHERE hostel_id = $hostel_id AND occupied_count < capacity 
            ORDER BY floor_level ASC, room_number ASC";

    $res = $conn->query($sql);
    $rooms = [];
    while ($row = $res->fetch_assoc()) {
        $rooms[] = $row;
    }
    echo json_encode($rooms);
}

function handleAnalytics($conn) {
    // 1. Allocation Status
    $stats_alloc = $conn->query("
        SELECT 
            COUNT(CASE WHEN allocation_id IS NOT NULL THEN 1 END) as allocated,
            COUNT(CASE WHEN allocation_id IS NULL THEN 1 END) as pending
        FROM student_profiles p
        LEFT JOIN allocations a ON p.user_id = a.student_id
    ")->fetch_assoc();

    // 2. Medical Conditions
    $stats_medical = $conn->query("
        SELECT condition_category, COUNT(*) as count 
        FROM medical_records 
        GROUP BY condition_category
    ")->fetch_all(MYSQLI_ASSOC);

    // 3. Payment Status
    $stats_payment = $conn->query("
        SELECT status, COUNT(*) as count 
        FROM payments 
        GROUP BY status
    ")->fetch_all(MYSQLI_ASSOC);

    echo json_encode([
        'status' => 'success',
        'allocation' => $stats_alloc,
        'medical' => $stats_medical,
        'payments' => $stats_payment
    ]);
}
?>
