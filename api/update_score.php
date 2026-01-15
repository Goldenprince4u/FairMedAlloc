<?php
/**
 * FairMedAlloc - ML Score Update API
 * ==================================
 * Receives JSON data from the Python ML Model.
 * Payload: { "matric": "RUN/2026/001", "score": 85.5 }
 */

header("Content-Type: application/json");
require_once '../db_config.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit();
}

// Read JSON Input
$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['matric']) || !isset($input['score'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing 'matric' or 'score'"]);
    exit();
}

$matric = $input['matric'];
$score  = floatval($input['score']);

// Validate
if ($score < 0 || $score > 100) {
    echo json_encode(["status" => "error", "message" => "Score must be 0-100"]);
    exit();
}

// Update Database
// Update Database
// 1. Get User ID from Matric
$stmtp = $conn->prepare("SELECT user_id FROM student_profiles WHERE matric_no = ?");
$stmtp->bind_param("s", $matric);
$stmtp->execute();
$res = $stmtp->get_result();

if ($res->num_rows === 0) {
    echo json_encode(["status" => "warning", "message" => "Matric not found"]);
    exit();
}

$uid = $res->fetch_assoc()['user_id'];

// 2. Update Medical Record
// Upsert score (Insert if not exists, though seed_data ensures it usually does)
$stmt = $conn->prepare("INSERT INTO medical_records (student_id, urgency_score) VALUES (?, ?) ON DUPLICATE KEY UPDATE urgency_score = VALUES(urgency_score)");
// Wait, medical_records has other fields. We should just update if exists.
// Logic: If profile exists, medical record might not.
// Safe bet: UPDATE using student_id. If 0 rows affected, INSERT default?
// For simpler demo, let's just UPDATE.
$stmt = $conn->prepare("UPDATE medical_records SET urgency_score = ? WHERE student_id = ?");
$stmt->bind_param("di", $score, $uid);

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
