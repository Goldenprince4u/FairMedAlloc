<?php
session_start();
require_once '../db_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
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
    echo json_encode(['status' => 'success', 'message' => 'Payment successful']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
?>
