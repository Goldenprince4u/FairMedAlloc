<?php
header('Content-Type: application/json');
session_start();
require_once '../db_config.php';
require_once '../includes/AllocationEngine.php';

// Security Check
if (($_SESSION['role'] ?? '') !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

try {
    $engine = new AllocationEngine($conn);
    $result = $engine->run();
    echo json_encode($result);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
