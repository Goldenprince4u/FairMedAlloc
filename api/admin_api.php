<?php
/**
 * Admin API Controller
 * ====================
 * Unified endpoint for administrative API actions.
 * Acts as a router directing AJAX requests to specific handler functions.
 * Expected Usage via GET parameter: ?action=run_algorithm | manual_assign | get_rooms | analytics
 */
session_start();
require_once '../db_config.php';

// All responses from this file will be JSON-formatted
header('Content-Type: application/json');

// --- 1. Security Check ---
// Ensure the request is coming from an authenticated administrator
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

// Retrieve the requested action 
$action = $_GET['action'] ?? '';

// --- 2. Action Router ---
// Direct the request to the appropriate function block below
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
        // Reject unknown or missing actions
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}

// --------------------------------------------------------------------------
// Handlers
// --------------------------------------------------------------------------

/**
 * Invokes the core Allocation Engine to process mathematical hostel placements.
 */
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

/**
 * Allows administrators to manually override the algorithm and assign a specific student to a specific room.
 */
function handleManualAssign($conn) {
    // Only accept form-submissions (POST) for data mutations
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        echo json_encode(['status' => 'error', 'message' => 'POST required']);
        return;
    }

    $student_id = (int) $_POST['student_id'];
    $room_id = (int) $_POST['room_id'];

    if ($room_id > 0 && $student_id > 0) {
        // Step 1: Clear old allocation if exists to prevent duplicate assignments
        $check = $conn->query("SELECT room_id FROM allocations WHERE student_id = $student_id");
        if ($check->num_rows > 0) {
            $old_room_id = $check->fetch_assoc()['room_id'];
            // Reduce the occupied count of the old room
            $conn->query("UPDATE rooms SET occupied_count = GREATEST(0, occupied_count - 1) WHERE room_id = $old_room_id");
            // Remove the old allocation record
            $conn->query("DELETE FROM allocations WHERE student_id = $student_id");
        }

        // Step 2: Assign new room
        $stmt = $conn->prepare("INSERT INTO allocations (student_id, room_id, allocation_method) VALUES (?, ?, 'manual')");
        $stmt->bind_param("ii", $student_id, $room_id);

        if ($stmt->execute()) {
            // Step 3: Increase occupied count of the newly assigned room
            $conn->query("UPDATE rooms SET occupied_count = occupied_count + 1 WHERE room_id = $room_id");
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database Error']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid Data']);
    }
}

/**
 * Fetches available (unfilled) rooms dynamically for a specific hostel.
 * Used often for dynamic dropdowns on the admin panel.
 */
function handleGetRooms($conn) {
    $hostel_id = (int) ($_GET['hostel_id'] ?? 0);
    if (!$hostel_id) { 
        echo json_encode([]); 
        return; 
    }

    // Only select rooms that haven't reached maximum capacity yet
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

/**
 * Aggregates various statistics to feed the Admin Dashboard charts and metrics.
 */
function handleAnalytics($conn) {
    // 1. Allocation Status Check (How many allocated vs pending)
    $stats_alloc = $conn->query("
        SELECT 
            COUNT(CASE WHEN allocation_id IS NOT NULL THEN 1 END) as allocated,
            COUNT(CASE WHEN allocation_id IS NULL THEN 1 END) as pending
        FROM student_profiles p
        LEFT JOIN allocations a ON p.user_id = a.student_id
    ")->fetch_assoc();

    // 2. Aggregate counts grouping by Medical Conditions
    $stats_medical = $conn->query("
        SELECT condition_category, COUNT(*) as count 
        FROM medical_records 
        GROUP BY condition_category
    ")->fetch_all(MYSQLI_ASSOC);

    // 3. Overview of pending vs finalized Payments
    $stats_payment = $conn->query("
        SELECT status, COUNT(*) as count 
        FROM payments 
        GROUP BY status
    ")->fetch_all(MYSQLI_ASSOC);

    // Return the aggregated metrics back to the Admin JS frontend
    echo json_encode([
        'status' => 'success',
        'allocation' => $stats_alloc,
        'medical' => $stats_medical,
        'payments' => $stats_payment
    ]);
}
?>
