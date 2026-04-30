<?php
/**
 * Full batch timing test — runs the allocation engine on ALL eligible students
 * and reports timing, urgency band distribution, and allocation outcome.
 * Usage: php scratch/test_full_batch.php
 */
define('TESTING_CLI', true);
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../includes/AllocationEngine.php';

echo "\n=== FairMedAlloc — Full Batch Timing Test ===\n\n";

// Pre-flight summary
$eligible = $conn->query("SELECT COUNT(*) FROM student_profiles WHERE allocation_status='Unallocated' AND is_paid=1")->fetch_row()[0];
$rooms    = $conn->query("SELECT COUNT(*), SUM(capacity-occupied_count) FROM rooms r JOIN hostels h ON r.hostel_id=h.hostel_id WHERE r.occupied_count<r.capacity AND h.is_postgrad=0 AND h.is_foundation=0")->fetch_row();

echo "  Eligible students : $eligible\n";
echo "  Available rooms   : {$rooms[0]} rooms / {$rooms[1]} beds\n";
echo "  Solver backend    : " . ($conn->query("SELECT setting_value FROM settings WHERE setting_key='allocation_solver_backend'")->fetch_row()[0] ?? 'php') . "\n\n";

if ($eligible == 0) {
    echo "  ERROR: No eligible students. Set is_paid=1 for some students first.\n\n";
    exit(1);
}

echo "--- Starting AllocationEngine::run() for ALL $eligible students ---\n\n";

$start  = microtime(true);
$engine = new AllocationEngine($conn);
$result = $engine->run();
$total  = round(microtime(true) - $start, 2);

echo "  Status          : " . ($result['status'] ?? 'unknown') . "\n";
if (($result['status'] ?? '') === 'error') {
    echo "  Error           : " . ($result['message'] ?? '') . "\n\n";
    exit(1);
}
echo "  Prediction mode : " . ($result['prediction_mode'] ?? 'N/A') . "\n";
echo "  Solver mode     : " . ($result['solver_mode'] ?? 'N/A') . "\n";
echo "  Optimal         : " . (($result['optimal'] ?? false) ? 'YES' : 'NO (FEASIBLE)') . "\n";
echo "  Allocated       : " . ($result['allocated'] ?? 0) . " of " . ($result['total'] ?? 0) . " students\n";
echo "  Total time      : {$total}s\n\n";

// Urgency band breakdown of what was allocated
$breakdown = $conn->query("
    SELECT 
        CASE
            WHEN COALESCE(m.urgency_score,0) >= 75 THEN 'High'
            WHEN COALESCE(m.urgency_score,0) >= 40 THEN 'Medium'
            ELSE 'Low'
        END as band,
        COUNT(*) as cnt
    FROM allocations a
    JOIN student_profiles p ON a.student_id = p.user_id
    LEFT JOIN medical_records m ON m.student_id = p.user_id
    GROUP BY band ORDER BY cnt DESC
");
echo "  Urgency band breakdown of allocated students:\n";
while ($row = $breakdown->fetch_assoc()) {
    printf("    %-10s %d students\n", $row['band'] . ':', $row['cnt']);
}

// Sample of actual allocations (first 5)
echo "\n  Sample allocations:\n";
$sample = $conn->query("
    SELECT a.student_id, h.name as hostel, h.block_name, a.bed_space, a.bed_label,
           COALESCE(m.urgency_score,0) as score
    FROM allocations a
    JOIN rooms r ON a.room_id = r.room_id
    JOIN hostels h ON r.hostel_id = h.hostel_id
    LEFT JOIN medical_records m ON m.student_id = a.student_id
    ORDER BY score DESC LIMIT 5
");
while ($row = $sample->fetch_assoc()) {
    printf("    Student %-6s → %-35s Blk %-4s Bed %s (%s)  score=%.1f\n",
        $row['student_id'], $row['hostel'], $row['block_name'],
        $row['bed_space'], $row['bed_label'], $row['score']);
}

echo "\n--- Cleaning up test allocations ---\n";
$conn->query("DELETE FROM allocations");
$conn->query("UPDATE student_profiles SET allocation_status='Unallocated'");
$conn->query("UPDATE rooms SET occupied_count=0");
$conn->query("DELETE FROM algorithm_audit_logs");
$conn->query("DELETE FROM notifications WHERE message LIKE 'Congratulations%' OR message LIKE 'Update: You have%'");
echo "  Done — database reset to clean state.\n\n";
echo "=== Test Complete ===\n\n";
