<?php
/**
 * FairMedAlloc - Payment Simulation API
 * =====================================
 * This endpoint simulates the process of a student paying school fees on the portal.
 * In the project, this mirrors the university portal flow through a pay simulator.
 */
session_start();
require_once '../db_config.php';

header('Content-Type: application/json');
ini_set('display_errors', '0');

// --- 1. Authentication Check ---
// Require that the user making the payment is a logged-in student
if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Ensure submission is via POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

require_once '../includes/security_helper.php';

// Verify JS-sent CSRF token (found in headers or body) to prevent Cross-Site Request Forgery
$input = json_decode(file_get_contents('php://input'), true);
$csrf_token = $input['csrf_token'] ?? $_POST['csrf_token'] ?? '';

if (!verify_csrf_token($csrf_token)) {
     echo json_encode(['status' => 'error', 'message' => 'Security Error: Invalid Token']);
     exit;
}

// Define mock variables for the simulated transaction
$user_id = $_SESSION['user_id'];
$amount = 50000.00; // Simulated School Fee amount

$conn->begin_transaction();

// --- 2. Deduplication Check ---
// Check if the student has already paid to prevent double processing
$stmt = $conn->prepare("SELECT payment_id FROM payments WHERE student_id = ? AND status = 'paid' FOR UPDATE");
$stmt->bind_param("i", $user_id);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    $conn->commit();
    require_once '../includes/Student.php';
    $student = new Student($conn, $user_id);
    $allocation = $student->getAllocation();
    $message = $allocation
        ? 'Your portal payment was already confirmed and your allocation is active.'
        : 'Your portal payment was already confirmed. Your allocation is still pending.';
    echo json_encode(['status' => 'success', 'message' => $message]);
    exit;
}

// --- 3. Process Transaction ---
// Insert a finalized 'paid' record corresponding to this student
$ref = 'REF-' . strtoupper(bin2hex(random_bytes(8))); // Generate dummy transaction reference
$stmt = $conn->prepare("INSERT INTO payments (student_id, amount, reference_no, status) VALUES (?, ?, ?, 'paid')");
$stmt->bind_param("ids", $user_id, $amount, $ref);

if ($stmt->execute()) {
    $paid_stmt = $conn->prepare("UPDATE student_profiles SET is_paid = 1 WHERE user_id = ?");
    $paid_stmt->bind_param("i", $user_id);
    $paid_stmt->execute();
    
    $conn->commit();

    $message = 'Portal payment of &#8358;50,000 confirmed. You are now eligible for hostel allocation.';
    require_once '../includes/AllocationEngine.php';
    try {
        $engine = new AllocationEngine($conn);
        $result = $engine->run($user_id);
        if (($result['status'] ?? '') === 'success' && (int)($result['allocated'] ?? 0) > 0) {
            $message = 'Portal payment of &#8358;50,000 confirmed. Your room has been allocated automatically.';
        } elseif (($result['status'] ?? '') !== 'success') {
            $message = 'Portal payment of &#8358;50,000 confirmed, but auto-allocation could not complete immediately. Your payment is safe and you remain eligible.';
            error_log('Allocation engine returned an error during pay simulation: ' . ($result['message'] ?? 'Unknown error'));
        } else {
            $message = 'Portal payment of &#8358;50,000 confirmed. Your payment is recorded, but no suitable room was available immediately.';
        }
    } catch (Throwable $e) {
        error_log('Allocation engine error during pay simulation: ' . $e->getMessage());
    }
    echo json_encode([
        'status'  => 'success',
        'message' => $message
    ]);
} else {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => 'Database error. Please try again.']);
}
?>
