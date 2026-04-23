<?php
require 'db_config.php';
$conn->query("DELETE FROM users WHERE role = 'student'");
echo "Students deleted: " . $conn->affected_rows . "\n";
?>
