<?php
require_once "db_config.php";
require_once "includes/AllocationEngine.php";
$engine = new AllocationEngine($conn);
$result = $engine->run(1); // Test for student 1
print_r($result);
?>
