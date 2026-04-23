<?php
require 'db_config.php';
$res = $conn->query("SHOW CREATE TABLE hostels");
echo $res->fetch_row()[1] . "\n\n";
$res = $conn->query("SHOW CREATE TABLE rooms");
echo $res->fetch_row()[1] . "\n\n";

$res = $conn->query("SELECT * FROM hostels LIMIT 5");
while($row = $res->fetch_assoc()) {
    print_r($row);
}
?>
