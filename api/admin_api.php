<?php
/**
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
require_once '../includes/security_helper.php';
require_once '../includes/DbHelper.php';

// All responses from this file will be JSON-formatted
header('Content-Type: application/json');

const MANUAL_ALLOCATION_VERSION = 'manual_override_v1';

// --- 1. Security Check ---
// Ensure the request is coming from an authenticated administrator
if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
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

    case 'rescore_all':
        handleRescoreAll($conn);
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

    case 'hostel_stats':
        handleHostelStats($conn);
        break;

    case 'ml_status':
        handleMlStatus();
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
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'POST required']);
        return;
    }

    check_csrf();

    require_once '../includes/AllocationEngine.php';
    if (!acquireProcessingLock($conn, 'admin_processing_lock')) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'Another admin processing job is already running.']);
        return;
    }

    try {
        $engine = new AllocationEngine($conn);
        $result = $engine->run();
        if (($result['status'] ?? '') === 'success') {
            log_admin_action($conn, (int)$_SESSION['user_id'], 'Triggered allocation engine');
        }
        echo json_encode($result);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    } finally {
        releaseProcessingLock($conn, 'admin_processing_lock');
    }
}

function handleRescoreAll($conn) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'POST required']);
        return;
    }

    check_csrf();

    require_once '../includes/AllocationEngine.php';
    if (!acquireProcessingLock($conn, 'admin_processing_lock')) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'Another admin processing job is already running.']);
        return;
    }

    try {
        $engine = new AllocationEngine($conn);
        $result = $engine->rescoreAllMedicalRecords();
        if (($result['status'] ?? '') === 'success') {
            log_admin_action($conn, (int)$_SESSION['user_id'], 'Recomputed all XGBoost urgency scores');
        }
        echo json_encode($result);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    } finally {
        releaseProcessingLock($conn, 'admin_processing_lock');
    }
}

function handleMlStatus() {
    require_once '../includes/MlServiceClient.php';

    try {
        $client = new MlServiceClient();
        $result = $client->health();
        echo json_encode($result);
    } catch (Exception $e) {
        http_response_code(503);
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
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

    // Validate CSRF token to prevent cross-site request forgery
    check_csrf();

    $student_id = (int)($_POST['student_id'] ?? 0);
    $room_id    = (int)($_POST['room_id'] ?? 0);

    if ($room_id <= 0 || $student_id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid Data']);
        return;
    }

    // Fetch current academic session
    $sess_res = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'current_session'");
    $current_session = $sess_res ? ($sess_res->fetch_assoc()['setting_value'] ?? '2025/2026') : '2025/2026';

    try {
        $conn->begin_transaction();

        $student = fetchStudentForManualAllocation($conn, $student_id);
        if (!$student) {
            throw new Exception('Student record not found.');
        }

        $target_room = fetchTargetRoomForManualAllocation($conn, $room_id);
        if (!$target_room) {
            throw new Exception('Selected room was not found.');
        }
        if ((int)$target_room['is_postgrad'] === 1 || (int)$target_room['is_foundation'] === 1) {
            throw new Exception('That room is not available for undergraduate allocation.');
        }
        if (($student['gender'] ?? '') !== ($target_room['gender_allowed'] ?? '')) {
            throw new Exception('Student gender does not match the selected hostel.');
        }

        $existing = fetchExistingAllocationForManualAllocation($conn, $student_id);
        if ($existing && (int)$existing['room_id'] === $room_id) {
            throw new Exception('Student is already assigned to that room.');
        }

        if ($existing) {
            $old_room_id = (int)$existing['room_id'];

            $del_stmt = $conn->prepare("DELETE FROM allocations WHERE student_id = ?");
            $del_stmt->bind_param("i", $student_id);
            $del_stmt->execute();

            $dec_stmt = $conn->prepare("UPDATE rooms SET occupied_count = GREATEST(0, occupied_count - 1) WHERE room_id = ?");
            $dec_stmt->bind_param("i", $old_room_id);
            $dec_stmt->execute();
        }

        $bed = determineAvailableBedForManualAllocation(
            $conn,
            $room_id,
            (int)$target_room['capacity'],
            $target_room['bed_config'] ?? ''
        );
        if ($bed === null) {
            throw new Exception('The selected room is already full.');
        }

        if (DbHelper::supportsAlgorithmVersion($conn)) {
            $algorithm_version = MANUAL_ALLOCATION_VERSION;
            $ins_stmt = $conn->prepare("INSERT INTO allocations (student_id, room_id, bed_space, bed_label, academic_session, allocation_method, algorithm_version) VALUES (?, ?, ?, ?, ?, 'manual', ?)");
            $ins_stmt->bind_param("iissss", $student_id, $room_id, $bed['bed_space'], $bed['bed_label'], $current_session, $algorithm_version);
        } else {
            $ins_stmt = $conn->prepare("INSERT INTO allocations (student_id, room_id, bed_space, bed_label, academic_session, allocation_method) VALUES (?, ?, ?, ?, ?, 'manual')");
            $ins_stmt->bind_param("iisss", $student_id, $room_id, $bed['bed_space'], $bed['bed_label'], $current_session);
        }
        if (!$ins_stmt->execute()) {
            throw new Exception('Unable to save the new room allocation.');
        }

        $inc_stmt = $conn->prepare("UPDATE rooms SET occupied_count = occupied_count + 1 WHERE room_id = ? AND occupied_count < capacity");
        $inc_stmt->bind_param("i", $room_id);
        $inc_stmt->execute();
        if ($inc_stmt->affected_rows !== 1) {
            throw new Exception('The selected room became unavailable. Please try again.');
        }

        $upd_stmt = $conn->prepare("UPDATE student_profiles SET allocation_status = 'Allocated' WHERE user_id = ?");
        $upd_stmt->bind_param("i", $student_id);
        $upd_stmt->execute();

        log_admin_action(
            $conn,
            (int)$_SESSION['user_id'],
            "Manually assigned student {$student_id} to room {$room_id}"
        );

        $conn->commit();
        echo json_encode([
            'status' => 'success',
            'bed_space' => $bed['bed_space'],
            'bed_label' => $bed['bed_label']
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

/**
 * Fetches available (unfilled) rooms for a specific hostel.
 * Joined against hostels to block postgrad and foundation rooms from the dropdown.
 */
function handleGetRooms($conn) {
    $hostel_id = (int)($_GET['hostel_id'] ?? 0);
    if (!$hostel_id) {
        echo json_encode([]);
        return;
    }

    // Only return rooms with capacity AND belonging to a non-restricted hostel.
    $stmt = $conn->prepare("
        SELECT r.room_id, r.room_number, r.floor_level, r.capacity, r.occupied_count
        FROM rooms r
        JOIN hostels h ON r.hostel_id = h.hostel_id
        WHERE r.hostel_id = ?
          AND r.occupied_count < r.capacity
          AND h.is_postgrad   = 0
          AND h.is_foundation = 0
        ORDER BY CAST(r.room_number AS UNSIGNED) ASC
    ");
    $stmt->bind_param("i", $hostel_id);
    $stmt->execute();
    $res   = $stmt->get_result();
    $rooms = [];
    while ($row = $res->fetch_assoc()) {
        $row['available'] = (int)$row['capacity'] - (int)$row['occupied_count'];
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
        'status'   => 'success',
        'allocation' => $stats_alloc,
        'medical'    => $stats_medical,
        'payments'   => $stats_payment
    ]);
}

/**
 * Returns per-hostel occupancy totals (capacity, occupied, gender, name).
 * Used by the hostel occupancy table on the reports page.
 */
function handleHostelStats($conn) {
    $res = $conn->query("
        SELECT
            h.hostel_id,
            h.name,
            h.block_name,
            h.gender_allowed   AS gender,
            SUM(r.capacity)          AS capacity,
            SUM(r.occupied_count)    AS occupied
        FROM hostels h
        JOIN rooms r ON r.hostel_id = h.hostel_id
        GROUP BY h.hostel_id
        ORDER BY h.name ASC, h.block_name ASC
    ");
    $rows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['capacity'] = (int)$row['capacity'];
            $row['occupied'] = (int)$row['occupied'];
            $rows[] = $row;
        }
    }
    echo json_encode($rows);
}

function fetchStudentForManualAllocation($conn, int $student_id): ?array {
    $stmt = $conn->prepare("SELECT p.user_id, p.gender FROM student_profiles p WHERE p.user_id = ? LIMIT 1");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function fetchTargetRoomForManualAllocation($conn, int $room_id): ?array {
    $stmt = $conn->prepare("
        SELECT r.room_id, r.capacity, r.occupied_count, r.bed_config,
               h.gender_allowed, h.is_postgrad, h.is_foundation
        FROM rooms r
        JOIN hostels h ON r.hostel_id = h.hostel_id
        WHERE r.room_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $room_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function fetchExistingAllocationForManualAllocation($conn, int $student_id): ?array {
    $stmt = $conn->prepare("SELECT allocation_id, room_id FROM allocations WHERE student_id = ? LIMIT 1");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function determineAvailableBedForManualAllocation($conn, int $room_id, int $capacity, string $bed_config): ?array {
    $config_arr = [];
    if ($bed_config !== '') {
        $config_arr = array_map('trim', explode(',', $bed_config));
    }
    if (count($config_arr) < $capacity) {
        $config_arr = array_pad($config_arr, $capacity, 'LB');
    }

    $stmt = $conn->prepare("SELECT bed_space FROM allocations WHERE room_id = ?");
    $stmt->bind_param("i", $room_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $occupied_indices = [];
    while ($row = $res->fetch_assoc()) {
        $bed_space = $row['bed_space'] ?? '';
        if ($bed_space !== '' && strlen($bed_space) === 1) {
            $ord = ord($bed_space);
            if ($ord >= 65 && $ord <= 90) {
                $occupied_indices[] = $ord - 65;
            }
        }
    }

    for ($i = 0; $i < $capacity; $i++) {
        if (!in_array($i, $occupied_indices, true)) {
            return [
                'bed_space' => chr(65 + $i),
                'bed_label' => $config_arr[$i] ?? 'LB'
            ];
        }
    }

    return null;
}

function acquireProcessingLock($conn, string $lock_key): bool {
    $seed_stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, '0') ON DUPLICATE KEY UPDATE setting_key = setting_key");
    if (!$seed_stmt) {
        return false;
    }

    $seed_stmt->bind_param("s", $lock_key);
    $seed_stmt->execute();
    $seed_stmt->close();

    $lock_stmt = $conn->prepare("UPDATE settings SET setting_value = '1' WHERE setting_key = ? AND setting_value = '0'");
    if (!$lock_stmt) {
        return false;
    }

    $lock_stmt->bind_param("s", $lock_key);
    $lock_stmt->execute();
    $acquired = $lock_stmt->affected_rows === 1;
    $lock_stmt->close();

    return $acquired;
}

function releaseProcessingLock($conn, string $lock_key): void {
    $unlock_stmt = $conn->prepare("UPDATE settings SET setting_value = '0' WHERE setting_key = ?");
    if (!$unlock_stmt) {
        return;
    }

    $unlock_stmt->bind_param("s", $lock_key);
    $unlock_stmt->execute();
    $unlock_stmt->close();
}

// allocationsSupportAlgorithmVersion() is no longer defined here.
// Use DbHelper::supportsAlgorithmVersion($conn) — the single shared implementation.
?>
