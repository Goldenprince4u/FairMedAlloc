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
$ref = 'REF-' . strtoupper(uniqid()); // Generate dummy transaction reference

// --- 2. Deduplication Check ---
// Check if the student has already paid to prevent double processing
$stmt = $conn->prepare("SELECT payment_id FROM payments WHERE student_id = ? AND status = 'paid'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    // Escape early if already paid
    echo json_encode(['status' => 'success', 'message' => 'Already paid']);
    exit;
}

// --- 3. Process Transaction ---
// Insert a finalized 'paid' record corresponding to this student
$stmt = $conn->prepare("INSERT INTO payments (student_id, amount, reference_no, status) VALUES (?, ?, ?, 'paid')");
$stmt->bind_param("ids", $user_id, $amount, $ref);

if ($stmt->execute()) {
    // FIX: Previously, this called AllocationEngine::run() synchronously here,
    // which could block for up to 60 seconds (OR-Tools solver timeout) — exceeding
    // PHP's default max_execution_time (30s) and silently killing the request.
    //
    // The payment is now confirmed immediately. The student's profile remains
    // 'Unallocated' and will be processed during the next admin-triggered
    // batch allocation cycle (via run_allocation.php). The notification system
    // will inform the student when a room is assigned.
    echo json_encode([
        'status'  => 'success',
        'message' => 'Portal payment of &#8358;50,000 confirmed through the pay simulator. Your room will be considered in the next admin batch, and you will be notified when a room is assigned.'
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Database error. Please try again.']);
}
?>
