<?php
/**
 * Database Seeder
 * Reset and populate database.
 */

session_start();
require_once 'db_config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Database Seeder | FairMedAlloc</title>
    <!-- Use Main CSS for consistent font -->
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Space Mono', monospace; background: #0f172a; color: #38bdf8; padding: 2rem; line-height: 1.6; }
        .log-entry { margin-bottom: 0.5rem; border-left: 2px solid #334155; padding-left: 1rem; }
        .success { color: #4ade80; }
        .error { color: #f87171; }
        h1 { color: white; border-bottom: 1px solid #334155; padding-bottom: 1rem; margin-bottom: 2rem; }
        .btn { display: inline-block; background: #38bdf8; color: #0f172a; padding: 10px 20px; text-decoration: none; font-weight: bold; border-radius: 4px; margin-top: 2rem; }
        .btn:hover { background: #7dd3fc; }
    </style>
</head>
<body>

<h1>FairMedAlloc // Data Seeder</h1>

<?php
// 1. Reset Database
$conn->query("SET FOREIGN_KEY_CHECKS = 0");
$tables = ['allocations', 'medical_records', 'student_profiles', 'users', 'rooms', 'hostels', 'faqs'];
foreach ($tables as $t) {
    if ($conn->query("TRUNCATE TABLE $t")) {
        echo "<div class='log-entry'>[RESET] Table <span style='color:white'>$t</span> cleared.</div>";
    } else {
        echo "<div class='log-entry error'>[ERROR] Failed to clear $t: " . $conn->error . "</div>";
    }
}
$conn->query("SET FOREIGN_KEY_CHECKS = 1");
echo "<br>";

// 2. Seed Hostels
$hostels = [
    // [Name, Block, Gender, Proximal?, Capacity]
    ['Prophet Moses Hall', 'Block A', 'Male', 0, 500],
    ['Prophet Moses Hall', 'Block B (Science)', 'Male', 0, 300],
    ['Daniel Hall', 'Main Block', 'Male', 1, 150], // Proximal
    ['Queen Esther Hall', 'Block A', 'Female', 0, 600],
    ['Queen Esther Hall', 'Block C (Science)', 'Female', 0, 400],
    ['Mary Hall', 'Main Block', 'Female', 1, 200]  // Proximal
];

$stmt = $conn->prepare("INSERT INTO hostels (name, block_name, gender_allowed, is_proximal, total_capacity, proximal_faculty) VALUES (?, ?, ?, ?, ?, ?)");

foreach ($hostels as $h) {
    $fac = (strpos($h[1], 'Science') !== false) ? 'Science' : 'General';
    $stmt->bind_param("sssiis", $h[0], $h[1], $h[2], $h[3], $h[4], $fac);
    $stmt->execute();
    $hid = $conn->insert_id;
    
    // Seed Rooms (Demo: 20 per hostel)
    $rooms_to_seed = 20;
    
    $stmt_r = $conn->prepare("INSERT INTO rooms (hostel_id, room_number, floor_level, capacity) VALUES (?, ?, ?, ?)");
    for ($r=1; $r<=$rooms_to_seed; $r++) {
        $fl = ($r <= 5) ? 0 : 1;
        $num = (($fl==0)?'G':'1') . str_pad($r, 2, '0', STR_PAD_LEFT);
        $cap = 4;
        $stmt_r->bind_param("isii", $hid, $num, $fl, $cap);
        $stmt_r->execute();
    }
}
echo "<div class='log-entry success'>[SEED] Hostels and Rooms created successfully.</div>";

// 3. Seed Students
$conditions = ['None', 'None', 'None', 'Asthma', 'Sickle Cell', 'Visual Impairment', 'Orthopaedic'];
$mobilities = ['None', 'None', 'None', 'None', 'None', 'Wheelchair', 'Crutches'];
$gen_pass = password_hash('password', PASSWORD_DEFAULT);

$stmt_u = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'student')");
$stmt_p = $conn->prepare("INSERT INTO student_profiles (user_id, matric_no, full_name, gender, level, faculty) VALUES (?, ?, ?, ?, ?, ?)");
$stmt_m = $conn->prepare("INSERT INTO medical_records (student_id, condition_category, condition_details, severity_level, urgency_score) VALUES (?, ?, ?, ?, ?)");

for ($i = 1; $i <= 30; $i++) {
    $matric = "RUN/CMP/22/" . str_pad($i, 4, '0', STR_PAD_LEFT);
    $name = "Student User " . $i;
    $gender = ($i % 2 == 0) ? 'Female' : 'Male';
    $level = rand(1, 4) * 100;
    $faculty = "Science";
    
    // Create User
    $stmt_u->bind_param("ss", $matric, $gen_pass);
    if (!$stmt_u->execute()) continue; 
    $uid = $conn->insert_id;
    
    // Create Profile
    $stmt_p->bind_param("isssis", $uid, $matric, $name, $gender, $level, $faculty);
    $stmt_p->execute();
    
    // Create Medical
    $cat = $conditions[array_rand($conditions)];
    $mob = $mobilities[array_rand($mobilities)];
    
    if ($mob !== 'None') $cat = 'Mobility';
    
    $sev = ($cat == 'None') ? 0 : rand(3, 9);
    
    // Score Logic
    $score = 0;
    if ($cat !== 'None') {
        $score += 40;
        $score += ($sev * 5);
    }
    if ($mob == 'Wheelchair') $score = max($score, 85);
    if ($mob == 'Crutches') $score = max($score, 75);
    $score = min($score, 100);
    
    $details = "$cat - $mob";
    $stmt_m->bind_param("issid", $uid, $cat, $details, $sev, $score);
    $stmt_m->execute();
}
echo "<div class='log-entry success'>[SEED] 30 Student Profiles generated.</div>";

// 4. Ensure Admin
$admin_user = 'AbdulQuadri';
$admin_pass = password_hash('fairmed2026', PASSWORD_DEFAULT);
$conn->query("INSERT IGNORE INTO users (username, password_hash, role) VALUES ('$admin_user', '$admin_pass', 'admin')");
echo "<div class='log-entry success'>[SEED] Admin account '$admin_user' verified.</div>";

// 5. Seed FAQs (Updated: Removed array structure inside loop for clarity)
$conn->query("TRUNCATE TABLE faqs"); // Ensure clean slate
$stmt_faq = $conn->prepare("INSERT INTO faqs (question, answer) VALUES (?, ?)");
$faqs = [
    'How is the "Urgency Score" calculated?' => 'The system uses a Machine Learning algorithm (XGBoost) trained on historical medical data given by the school clinic. It considers your reported medical conditions, mobility status, and severity level to assign a priority score (0-100).',
    'What if my allocation is pending?' => 'Allocations are done in batches. If your status is "Pending", the admin has likely not run the final allocation for the session yet. Ensure your profile is up to date.',
    'How do I correct a wrong medical entry?' => 'You can edit your profile via the "Student Dashboard > Edit Profile" link. However, false claims are subject to physical verification at the University Health Center.'
];

foreach ($faqs as $q => $a) {
    $stmt_faq->bind_param("ss", $q, $a);
    $stmt_faq->execute();
}
echo "<div class='log-entry success'>[SEED] FAQ content populated.</div>";

?>

<a href="login.php" class="btn">Return to Login</a>
</body>
</html>
