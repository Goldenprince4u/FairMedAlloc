<?php
/**
 * FairMedAlloc - ML Score Update API
 * ==================================
 * Secondary Webhook Endpoint: Receives JSON data updates directly from the Background Python ML process.
 * Used internally for syncing scores across services.
 * Payload example: { "matric": "RUN/2026/001", "score": 85.5 }
 */

header("Content-Type: application/json");
require_once '../db_config.php';

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
$stmtp = $conn->prepare("SELECT user_id FROM student_profiles WHERE matric_no = ?");
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
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
}
?>
