<?php
/**
 * FairMedAlloc - Database Configuration
 * =====================================
 * This file handles the connection to the MySQL Database.
 */

// 1. Connection Parameters
// Note: 'localhost' usually works, but if you get "Connection Refused", 
// try using '127.0.0.1' instead.
$env = parse_ini_file(__DIR__ . '/.env');

define('DB_HOST', $env['DB_HOST'] ?? '127.0.0.1');
define('DB_USER', $env['DB_USER'] ?? 'root');
define('DB_PASS', $env['DB_PASS'] ?? '');
define('DB_NAME', $env['DB_NAME'] ?? 'fairmedalloc');

// 2. Establish Connection
// We use the 'mysqli' library (standard for PHP)
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// 3. Check for Errors
if ($conn->connect_error) {
    // Explanation for common errors
    $err = $conn->connect_error;
    die("<h3>Database Connection Failed</h3>
         <p>Error: $err</p>
         <ul>
           <li>Is XAMPP/WAMP running?</li>
           <li>Is the 'MySQL' module turned on (Green)?</li>
           <li>Did you run 'install.php' to create the database?</li>
         </ul>");
}
?>
