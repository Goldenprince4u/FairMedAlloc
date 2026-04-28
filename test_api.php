<?php
chdir(__DIR__);
session_start();
$_SESSION['logged_in'] = true;
$_SESSION['role'] = 'admin';
$_SESSION['user_id'] = 1;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['csrf_token'] = 'fake_token';
$_POST['csrf_token'] = 'fake_token';

// we just include security helper normally
require_once "includes/security_helper.php";

ob_start();
require_once "api/admin_api.php";
$out = ob_get_clean();

echo "Length: " . strlen($out) . "\n";
echo "Content: \n" . $out . "\n";
?>
