<?php
session_start();
require_once '../db_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

// Enforce POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

require_once '../includes/security_helper.php';

// Verify JS-sent CSRF token (headers or body)
// Since we will send it as JSON or Form data, let's check input
$input = json_decode(file_get_contents('php://input'), true);
$csrf_token = $input['csrf_token'] ?? $_POST['csrf_token'] ?? '';

if (!verify_csrf_token($csrf_token)) {
     echo json_encode(['status' => 'error', 'message' => 'Security Error: Invalid Token']);
     exit;
}

$user_id = $_SESSION['user_id'];
$amount = 50000.00; // Example School Fee
$ref = 'REF-' . strtoupper(uniqid());

// Check if already paid
$stmt = $conn->prepare("SELECT payment_id FROM payments WHERE student_id = ? AND status = 'paid'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    echo json_encode(['status' => 'success', 'message' => 'Already paid']);
    exit;
}

$stmt = $conn->prepare("INSERT INTO payments (student_id, amount, reference_no, status) VALUES (?, ?, ?, 'paid')");
$stmt->bind_param("ids", $user_id, $amount, $ref);

if ($stmt->execute()) {
    // TRIGGER ALLOCATION INSTANTLY
    require_once '../includes/AllocationEngine.php';
    
    try {
        $engine = new AllocationEngine($conn);
        $alloc_result = $engine->run();
        
        $msg = 'Payment successful. ';
        if (($alloc_result['allocated'] ?? 0) > 0) {
            $msg .= 'Room allocated successfully!';
        } else {
            $msg .= 'Payment received, but you occupy a waitlist position (No room currently available).';
        }

        echo json_encode(['status' => 'success', 'message' => $msg, 'debug' => $alloc_result]);
    } catch (Exception $e) {
        // Payment worked, but allocation failed. Don't fail the payment.
        echo json_encode(['status' => 'success', 'message' => 'Payment successful, but allocation trigger failed: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
?>
