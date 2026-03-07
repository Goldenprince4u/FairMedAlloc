<?php
include 'db_config.php';

try {
    $sql1 = "ALTER TABLE allocations ADD COLUMN IF NOT EXISTS academic_session VARCHAR(20) DEFAULT '2025/2026'";
    if ($conn->query($sql1) === TRUE) {
        echo "Successfully added 'academic_session' column.\n";
    } else {
        echo "Error updating record: " . $conn->error . "\n";
    }
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage();
}
?>
