<?php
/**
 * CLI test harness — run with:
 *   php scratch/test_engine.php
 */
define('TESTING_CLI', true);
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../includes/AllocationEngine.php';

echo "\n=== FairMedAlloc Engine Test ===\n\n";

// 1. Pre-flight DB checks
$checks = [
    ['Eligible students (unallocated + paid)',
        "SELECT COUNT(*) as n FROM student_profiles WHERE allocation_status='Unallocated' AND is_paid=1"],
    ['Available rooms',
        "SELECT COUNT(*) as n FROM rooms r JOIN hostels h ON r.hostel_id=h.hostel_id WHERE r.occupied_count < r.capacity AND h.is_postgrad=0 AND h.is_foundation=0"],
    ['Medical records with urgency scores',
        "SELECT COUNT(*) as n FROM medical_records WHERE urgency_score > 0"],
    ['Solver backend setting',
        "SELECT COALESCE(MAX(setting_value),'php (default)') as n FROM settings WHERE setting_key='allocation_solver_backend'"],
    ['Proximal threshold',
        "SELECT COALESCE(MAX(setting_value),'75') as n FROM settings WHERE setting_key='urgency_threshold_proximal'"],
    ['Medium threshold',
        "SELECT COALESCE(MAX(setting_value),'40') as n FROM settings WHERE setting_key='urgency_threshold_medium'"],
];

foreach ($checks as [$label, $sql]) {
    $res = $conn->query($sql);
    $val = $res ? $res->fetch_row()[0] : 'ERROR';
    printf("  %-50s %s\n", $label . ':', $val);
}

echo "\n--- Running AllocationEngine::run() (LIMIT 20 students for speed) ---\n\n";

// Run on a small sample: fetch first 20 eligible student IDs
$sample_res = $conn->query(
    "SELECT user_id FROM student_profiles WHERE allocation_status='Unallocated' AND is_paid=1 LIMIT 20"
);
$sample_ids = [];
while ($row = $sample_res->fetch_assoc()) {
    $sample_ids[] = (int)$row['user_id'];
}

if (empty($sample_ids)) {
    echo "  ERROR: Still no eligible students — check is_paid flag.\n";
    exit(1);
}

echo "  Sample student IDs: " . implode(', ', $sample_ids) . "\n\n";

// Temporarily patch the SQL to only run on our sample (use single student mode for first ID)
$start = microtime(true);
$engine = new AllocationEngine($conn);

// Test with a single student first (fastest proof of life)
$result = $engine->run($sample_ids[0]);
$elapsed = round(microtime(true) - $start, 2);

echo "  Result for student {$sample_ids[0]}:\n";
foreach ($result as $k => $v) {
    printf("    %-20s %s\n", $k . ':', var_export($v, true));
}
echo "\n  Time: {$elapsed}s\n";

// Check what was actually written
$alloc_check = $conn->query(
    "SELECT a.student_id, h.name as hostel, a.bed_space, a.bed_label 
     FROM allocations a JOIN rooms r ON a.room_id=r.room_id JOIN hostels h ON r.hostel_id=h.hostel_id
     WHERE a.student_id = {$sample_ids[0]}"
);
$alloc_row = $alloc_check ? $alloc_check->fetch_assoc() : null;

if ($alloc_row) {
    echo "\n  ✓ Allocation written to DB:\n";
    printf("    Student %-6s → %s  Bed: %s (%s)\n",
        $alloc_row['student_id'], $alloc_row['hostel'], $alloc_row['bed_space'], $alloc_row['bed_label']);
} else {
    echo "\n  ⚠ No allocation row found for student {$sample_ids[0]} (may be on waiting list)\n";
}

echo "\n=== Test Complete ===\n\n";
