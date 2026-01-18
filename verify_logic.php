<?php
/**
 * Verification Script
 * Tests the allocation rules logic.
 * Usage: php verify_logic.php
 */
require_once 'db_config.php';
require_once 'includes/AllocationEngine.php';

// CLI Output Helper
function log_msg($msg, $type='INFO') {
    echo "[$type] $msg\n";
}

log_msg("Starting Verification...");

// 1. Reset & Seed Hostels (Mini-Seed)
$conn->query("SET FOREIGN_KEY_CHECKS=0");
$conn->query("TRUNCATE TABLE allocations");
$conn->query("TRUNCATE TABLE rooms");
$conn->query("TRUNCATE TABLE hostels");
$conn->query("TRUNCATE TABLE student_profiles");
$conn->query("TRUNCATE TABLE users");
$conn->query("TRUNCATE TABLE medical_records");
$conn->query("SET FOREIGN_KEY_CHECKS=1");

// Seed Hostels (Exact List)
$hostels = [
    ['Prophet Moses Hall', 'Main Block', 'Male', 1, 100, 'General'],
    ['Prophet Moses Engineering Hall', 'Main Block', 'Male', 0, 100, 'Engineering'],
    ['Queen Esther Extension Hall', 'Main Block', 'Female', 1, 100, 'General'],
    ['Queen Esther Engineering Hall', 'Main Block', 'Female', 0, 100, 'Engineering'],
    ['Queen Esther Main Hall', 'Main Block', 'Female', 0, 100, 'General'], // Added Main Hall
    ['Guest House', 'Main Block', 'Female', 0, 100, 'General']
];
$stmt_h = $conn->prepare("INSERT INTO hostels (name, block_name, gender_allowed, is_proximal, total_capacity, proximal_faculty) VALUES (?, ?, ?, ?, ?, ?)");
foreach($hostels as $h) {
    $stmt_h->bind_param("sssiis", $h[0], $h[1], $h[2], $h[3], $h[4], $h[5]);
    $stmt_h->execute();
    $hid = $conn->insert_id;
    // Add 1 room
    $conn->query("INSERT INTO rooms (hostel_id, room_number, floor_level, capacity) VALUES ($hid, '101', 1, 10)");
}

// 2. Identify Hostel IDs
$map = [];
$res = $conn->query("SELECT hostel_id, name FROM hostels");
while($row = $res->fetch_assoc()) $map[$row['name']] = $row['hostel_id'];

// 3. Create Test Cases
$test_cases = [
    ['name' => 'Male High Priority', 'gender' => 'Male', 'score' => 85, 'faculty' => 'Arts', 'expected' => 'Prophet Moses Hall'],
    ['name' => 'Female High Priority', 'gender' => 'Female', 'score' => 85, 'faculty' => 'Arts', 'expected' => 'Queen Esther Extension Hall'],
    ['name' => 'Male Engineering', 'gender' => 'Male', 'score' => 20, 'faculty' => 'Engineering', 'expected' => 'Prophet Moses Engineering Hall'],
    ['name' => 'Female Basic Med', 'gender' => 'Female', 'score' => 20, 'faculty' => 'Basic Medical Sciences', 'expected' => 'Queen Esther Engineering Hall'],
    ['name' => 'Female General', 'gender' => 'Female', 'score' => 10, 'faculty' => 'Arts', 'expected' => 'Queen Esther Main Hall'] // Updated expectation
];

// Insert Students
foreach($test_cases as $i => $tc) {
    // User
    $matric = "TEST/00$i";
    $conn->query("INSERT INTO users (username, password_hash, role) VALUES ('$matric', 'hash', 'student')");
    $uid = $conn->insert_id;
    $test_cases[$i]['uid'] = $uid;
    
    // Profile
    $stmt_p = $conn->prepare("INSERT INTO student_profiles (user_id, matric_no, full_name, gender, faculty, level) VALUES (?, ?, ?, ?, ?, 100)");
    $stmt_p->bind_param("issss", $uid, $matric, $tc['name'], $tc['gender'], $tc['faculty']);
    $stmt_p->execute();
    
    // Medical
    $stmt_m = $conn->prepare("INSERT INTO medical_records (student_id, urgency_score) VALUES (?, ?)");
    $stmt_m->bind_param("id", $uid, $tc['score']);
    $stmt_m->execute();
}

// 4. Run Engine
$engine = new AllocationEngine($conn);
$res = $engine->run();
log_msg("Engine Run: " . $res['status'] . " (Allocated: " . $res['allocated'] . ")");

// 5. Verify
$fails = 0;
foreach($test_cases as $tc) {
    $uid = $tc['uid'];
    $sql = "SELECT h.name FROM allocations a 
            JOIN rooms r ON a.room_id = r.room_id 
            JOIN hostels h ON r.hostel_id = h.hostel_id 
            WHERE a.student_id = $uid";
    $res = $conn->query($sql);
    if($res && $res->num_rows > 0) {
        $actual = $res->fetch_assoc()['name'];
        if($actual === $tc['expected']) {
            log_msg("PASS: {$tc['name']} -> $actual", 'SUCCESS');
        } else {
            log_msg("FAIL: {$tc['name']} -> Got '$actual', Expected '{$tc['expected']}'", 'ERROR');
            $fails++;
        }
    } else {
        log_msg("FAIL: {$tc['name']} -> Not Allocated", 'ERROR');
        $fails++;
    }
}

if($fails === 0) {
    log_msg("ALL TESTS PASSED", 'SUCCESS');
} else {
    log_msg("$fails TESTS FAILED", 'ERROR');
}
?>
