<?php
/**
 * FairMedAlloc - ML Score Update API
 * ==================================
 * INTERNAL WEBHOOK (loopback only — restricted to 127.0.0.1).
 * Receives JSON { "matric": "RUN/2026/001", "score": 85.5 } and updates
 * urgency_score in medical_records.
 *
 * NOTE: As of current architecture, the PHP allocation engine calls Python via
 * shell_exec() and reads results from stdout — it does NOT POST to this endpoint.
 * This endpoint exists as forward-compatible scaffolding for a future architecture
 * where the Python process runs as a long-lived service and pushes scores via HTTP.
 * It is safe to keep but is currently NOT called by any part of the live system.
 */

header("Content-Type: application/json");
require_once '../db_config.php';

// --- Security: Restrict to loopback only ---
// This endpoint is an INTERNAL webhook called by the Python ML process.
// It should never be reachable from the public internet.
$caller_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$allowed   = ['127.0.0.1', '::1'];
if (!in_array($caller_ip, $allowed)) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Forbidden"]);
    exit();
}

// Only accept POST requests for state mutations
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit();
}

// Read the incoming raw JSON payload (sent from Python script via HTTP POST)
$input = json_decode(file_get_contents("php://input"), true);

// Validate minimum required fields
if (!isset($input['matric']) || !isset($input['score'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing 'matric' or 'score'"]);
    exit();
}

$matric = $input['matric'];
$score  = floatval($input['score']);

// Ensure the ML model hasn't sent an out-of-bounds score error
if ($score < 0 || $score > 100) {
    echo json_encode(["status" => "error", "message" => "Score must be 0-100"]);
    exit();
}

// --- Sync Mechanism ---
// 1. Get corresponding User ID assigned to that Matric Number
// matric_no is now stored as users.username (single source of truth)
$stmtp = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND role = 'student'");
$stmtp->bind_param("s", $matric);
$stmtp->execute();
$res = $stmtp->get_result();

if ($res->num_rows === 0) {
    echo json_encode(["status" => "warning", "message" => "Matric not found"]);
    exit();
}

$uid = $res->fetch_assoc()['user_id'];

// 2. Update Medical Record explicitly assigning the new python-calculated Urgency Score
$stmt = $conn->prepare("UPDATE medical_records SET urgency_score = ? WHERE student_id = ?");
$stmt->bind_param("di", $score, $uid);

// Process the execution and report success/failure back to Python script calling the webhook
if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode(["status" => "success", "message" => "Updated score for $matric"]);
    } else {
        echo json_encode(["status" => "warning", "message" => "Matric not found or score unchanged"]);
    }
} else {
    // FIX: Do not expose $conn->error (MySQL internals) in the response.
    // Log the error server-side only.
    error_log("[update_score] DB error for matric={$matric}: " . $conn->error);
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database update failed."]);
}
?>
