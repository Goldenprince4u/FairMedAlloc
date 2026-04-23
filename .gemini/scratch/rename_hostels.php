<?php
require 'db_config.php';
$conn->query("UPDATE hostels SET name = 'Joshua Hall' WHERE name = 'Prophet Moses Engineering Hall'");
echo "Joshua Hall updated: " . $conn->affected_rows . "\n";
$conn->query("UPDATE hostels SET name = 'Deborah Hall' WHERE name = 'Queen Esther Engineering Hall'");
echo "Deborah Hall updated: " . $conn->affected_rows . "\n";
?>
