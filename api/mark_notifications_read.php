<?php
session_start();
require_once '../db_config.php';
require_once '../includes/security_helper.php';
require_once '../includes/NotificationManager.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'student' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST required']);
    exit;
}

check_csrf();

$notifier = new NotificationManager($conn);
$ok = $notifier->markAllRead((int)$_SESSION['user_id']);

echo json_encode([
    'status' => $ok ? 'success' : 'error'
]);
