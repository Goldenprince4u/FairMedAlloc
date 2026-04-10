<?php
/**
 * db_config.php — Database Configuration
 * =========================================
 * Establishes the mysqli connection used across the entire application.
 * Credentials are loaded from the .env file to keep them out of version control.
 *
 * SECURITY NOTES:
 *   - The .env file is listed in .gitignore and must never be committed.
 *   - DB errors are logged server-side and a generic message is shown to the user
 *     to prevent credential/schema disclosure.
 */

// Load environment variables from the .env file in the project root.
// Use defaults only as a last resort (localhost dev fallback).
$env = parse_ini_file(__DIR__ . '/.env');

// Define connection constants from the environment.
define('DB_HOST', $env['DB_HOST'] ?? '127.0.0.1');
define('DB_PORT', (int)($env['DB_PORT'] ?? 3307));
define('DB_USER', $env['DB_USER'] ?? 'root');
define('DB_PASS', $env['DB_PASS'] ?? '');
define('DB_NAME', $env['DB_NAME'] ?? 'fairmedalloc');

// Establish the connection using the mysqli extension (standard for PHP/XAMPP stacks).
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

// Check for connection errors. If one is found:
//   - Log the actual error to PHP's error log (visible in XAMPP error_log, not the browser).
//   - Display a generic user-facing message to avoid disclosing sensitive DB details.
if ($conn->connect_error) {
    error_log("[FairMedAlloc] DB Connection Failed: " . $conn->connect_error);
    die("
        <h3>Service Temporarily Unavailable</h3>
        <p>The application is unable to connect to the database. Please try again shortly, or contact the system administrator.</p>
        <p><small>If you are a developer: check that XAMPP's MySQL module is running, the .env file is correct, and the database has been created.</small></p>
    ");
}
?>
