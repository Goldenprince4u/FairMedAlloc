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
$env = parse_ini_file(__DIR__ . '/.env') ?: [];
if (empty($env)) {
    error_log('[FairMedAlloc] WARNING: .env file missing or unreadable — using built-in defaults. Check file path and permissions.');
}

if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
}

// Define connection constants from the environment.
define('DB_HOST', $env['DB_HOST'] ?? '127.0.0.1');
define('DB_PORT', (int)($env['DB_PORT'] ?? 3307));
define('DB_USER', $env['DB_USER'] ?? 'root');
define('DB_PASS', $env['DB_PASS'] ?? '');
define('DB_NAME', $env['DB_NAME'] ?? 'fairmedalloc');
define('ML_SERVICE_URL', rtrim($env['ML_SERVICE_URL'] ?? 'http://127.0.0.1:5051', '/'));
define('ML_SERVICE_TIMEOUT', (float)($env['ML_SERVICE_TIMEOUT'] ?? 5));
$pythonBin = trim((string)($env['PYTHON_BIN'] ?? ($env['FAIRMED_PYTHON_BIN'] ?? '')));
define('PYTHON_BIN', $pythonBin);
define('FAIRMED_PYTHON_BIN', $pythonBin);

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
        <p><small>If you are a developer: check your database server is running, the .env file credentials are correct, and the database exists.</small></p>
    ");
}

/**
 * Lightweight runtime schema alignment for account-security improvements.
 * This keeps older local databases compatible without requiring a manual
 * migration before the app can boot.
 */
$supportsMustChangePassword = false;
$mustChangeColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'must_change_password'");
if ($mustChangeColumn instanceof mysqli_result) {
    $supportsMustChangePassword = $mustChangeColumn->num_rows > 0;
    $mustChangeColumn->free();
}

if (!$supportsMustChangePassword) {
    if ($conn->query("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash")) {
        $supportsMustChangePassword = true;
    } else {
        error_log("[FairMedAlloc] Unable to add users.must_change_password automatically: " . $conn->error);
    }
}

define('FAIRMED_SUPPORTS_MUST_CHANGE_PASSWORD', $supportsMustChangePassword);
?>
