<?php
$env = file_exists(__DIR__ . '/.env') ? parse_ini_file(__DIR__ . '/.env') : [];

if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
}

define('DB_HOST', getenv('DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'));
define('DB_PORT', (int)(getenv('DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
define('DB_USER', getenv('DB_USER') ?: ($env['DB_USER'] ?? 'root'));
define('DB_PASS', getenv('DB_PASS') ?: ($env['DB_PASS'] ?? ''));
define('DB_NAME', getenv('DB_NAME') ?: ($env['DB_NAME'] ?? 'fairmedalloc'));
define('ML_SERVICE_URL', rtrim(getenv('ML_SERVICE_URL') ?: ($env['ML_SERVICE_URL'] ?? 'http://127.0.0.1:5051'), '/'));
define('ML_SERVICE_TIMEOUT', (float)(getenv('ML_SERVICE_TIMEOUT') ?: ($env['ML_SERVICE_TIMEOUT'] ?? 5)));

$pythonBin = trim((string)(getenv('PYTHON_BIN') ?: ($env['PYTHON_BIN'] ?? ($env['FAIRMED_PYTHON_BIN'] ?? ''))));
define('PYTHON_BIN', $pythonBin);
define('FAIRMED_PYTHON_BIN', $pythonBin);

$renderDbUnavailable = static function (): void {
    if (php_sapi_name() === 'cli') {
        // Running as a background worker — write to stderr and exit with failure code
        fwrite(STDERR, "[FairMedAlloc] FATAL: Unable to connect to the database. " .
            "Check DB_HOST, DB_USER, DB_PASS, DB_NAME, and DB_PORT in your .env file.\n");
        exit(1);
    }
    die("
        <h3>Service Temporarily Unavailable</h3>
        <p>The application is unable to connect to the database. Please try again shortly, or contact the system administrator.</p>
        <p><small>If you are a developer: check your database server is running, the .env file credentials are correct, and the database exists.</small></p>
    ");
};

mysqli_report(MYSQLI_REPORT_OFF);

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
} catch (mysqli_sql_exception $e) {
    error_log('[FairMedAlloc] DB Connection Failed: ' . $e->getMessage());
    $renderDbUnavailable();
}

if ($conn->connect_errno) {
    error_log('[FairMedAlloc] DB Connection Failed: ' . $conn->connect_error);
    $renderDbUnavailable();
}

require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/DbHelper.php';

$supportsMustChangePassword = false;
$mustChangeColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'must_change_password'");
if ($mustChangeColumn instanceof mysqli_result) {
    $supportsMustChangePassword = $mustChangeColumn->num_rows > 0;
    $mustChangeColumn->free();
}

// Older local databases may be missing the forced-password-change flag.
if (!$supportsMustChangePassword) {
    if ($conn->query("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash")) {
        $supportsMustChangePassword = true;
    } else {
        error_log('[FairMedAlloc] Unable to add users.must_change_password automatically: ' . $conn->error);
    }
}

define('FAIRMED_SUPPORTS_MUST_CHANGE_PASSWORD', $supportsMustChangePassword);

DbHelper::alignMedicalSchema($conn);
?>
