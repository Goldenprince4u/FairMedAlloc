<?php
/**
 * FairMedAlloc - Database Configuration
 * =====================================
 * This file handles the connection to the MySQL Database.
 */

// 1. Connection Parameters
// Note: 'localhost' usually works, but if you get "Connection Refused", 
// try using '127.0.0.1' instead.
define('DB_HOST', 'localhost');
define('DB_USER', 'root');      // Default XAMPP user
define('DB_PASS', '');          // Default XAMPP password (empty)
define('DB_NAME', 'fairmedalloc'); // Database Name

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
