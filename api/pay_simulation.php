<?php
/**
 * FairMedAlloc - Payment Simulation API
 * =====================================
 * This endpoint simulates the process of a student paying their school fees.
 * Real production would integrate Paystack or Flutterwave here.
 */
session_start();
require_once '../db_config.php';

header('Content-Type: application/json');

// --- 1. Authenticaton Check ---
// Require that the user making the payment is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
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
    
    // --- 4. Post-Payment Triggers ---
    // TRIGGER ALLOCATION INSTANTLY: Immediately check if there is an open bed for the student since they met the fee requirement
    require_once '../includes/AllocationEngine.php';
    
    try {
        $engine = new AllocationEngine($conn);
        $alloc_result = $engine->run();
        
        $msg = 'Payment successful. ';
        // Check if the engine was successfully able to find them a bed right away
        if (($alloc_result['allocated'] ?? 0) > 0) {
            $msg .= 'Room allocated successfully!';
        } else {
            $msg .= 'Payment received, but you occupy a waitlist position (No room currently available).';
        }

        echo json_encode(['status' => 'success', 'message' => $msg, 'debug' => $alloc_result]);
    } catch (Exception $e) {
        // Warning fallback: If allocation failed temporarily, acknowledge payment still succeeded
        echo json_encode(['status' => 'success', 'message' => 'Payment successful, but allocation trigger failed: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
?>
