<?php
require 'db_config.php';
$conn->query("UPDATE notifications SET message = REPLACE(message, 'Prophet Moses Engineering Hall', 'Joshua Hall')");
echo "PMH notifications updated: " . $conn->affected_rows . "\n";
$conn->query("UPDATE notifications SET message = REPLACE(message, 'Queen Esther Engineering Hall', 'Deborah Hall')");
echo "QEH notifications updated: " . $conn->affected_rows . "\n";
?>
