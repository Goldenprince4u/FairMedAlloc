<?php
$file = 'includes/AllocationEngine.php';
$content = file_get_contents($file);

$start_marker = "// 7. Process Allocations into Database";
$end_marker = "// Only lock the session if we actually allocated at least one student.";

$start_pos = strpos($content, $start_marker);
$end_pos = strpos($content, $end_marker, $start_pos);

if ($start_pos === false || $end_pos === false) {
    die("Markers not found");
}

$payload = file_get_contents('scratch/bulk_assign_payload.php');
$payload = preg_replace('/<\?php\n/', '', $payload, 1);

$new_content = substr($content, 0, $start_pos) . $payload . "\n            " . substr($content, $end_pos);

file_put_contents($file, $new_content);
echo "Spliced perfectly!";
?>
