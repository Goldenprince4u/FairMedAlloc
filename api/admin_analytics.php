<?php
/**
 * Admin Analytics API
 * Returns JSON data for dashboards.
 */
session_start();
require_once '../db_config.php';
header('Content-Type: application/json');

if (($_SESSION['role'] ?? '') !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// 1. Allocation Status (Pie Chart)
$stats_alloc = $conn->query("
    SELECT 
        COUNT(CASE WHEN allocation_id IS NOT NULL THEN 1 END) as allocated,
        COUNT(CASE WHEN allocation_id IS NULL THEN 1 END) as pending
    FROM student_profiles p
    LEFT JOIN allocations a ON p.user_id = a.student_id
")->fetch_assoc();

// 2. Medical Conditions (Bar Chart)
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
?>
