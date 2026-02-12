<?php
include 'db_config.php';

try {
    $sql = "ALTER TABLE medical_records ADD COLUMN IF NOT EXISTS is_requested_mobility BOOLEAN DEFAULT FALSE";
    if ($conn->query($sql) === TRUE) {
        echo "Successfully added 'is_requested_mobility' column.";
    } else {
        echo "Error updating record: " . $conn->error;
    }
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage();
}
?>
