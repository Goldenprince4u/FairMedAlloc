<?php
function fairmed_load_env(string $path): array {
    if (!file_exists($path) || !is_readable($path)) {
        return [];
    }

    $parsed = @parse_ini_file($path, false, INI_SCANNER_RAW);
    if (is_array($parsed)) {
        return $parsed;
    }

    $values = [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, ';')) {
            continue;
        }

        $parts = explode('=', $trimmed, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);
        $value = trim($value, "\"'");
        if ($key !== '') {
            $values[$key] = $value;
        }
    }

    return $values;
}

$env = fairmed_load_env(__DIR__ . '/.env');

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
define('ML_SERVICE_TIMEOUT', (float)(getenv('ML_SERVICE_TIMEOUT') ?: ($env['ML_SERVICE_TIMEOUT'] ?? 120)));

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

    // API routes must always receive JSON so the frontend poll loop never crashes
    // on an unexpected HTML token ('<') in JSON.parse().
    // Detect API context by script path (/api/ prefix or X-Requested-With header).
    $scriptName  = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
    $isApiRoute  = strpos($scriptName, '/api/') !== false;
    $isXhrOrJson = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
                || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

    if ($isApiRoute || $isXhrOrJson) {
        http_response_code(503);
        header('Content-Type: application/json');
        die(json_encode([
            'status'  => 'error',
            'message' => 'The database is temporarily unavailable. Please try again shortly.',
        ]));
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

// ── Schema migration guard ────────────────────────────────────────────────────
// Migration work (SHOW COLUMNS / ALTER TABLE / alignMedicalSchema) is expensive
// and can cause lock contention under live traffic. We gate it behind a single
// settings-table marker ('schema_version' = 'v1') so the inspection and any
// structural changes only happen ONCE per deployment, not on every request.
//
// Flow:
//   marker present  → define constant immediately, skip all migration work
//   marker absent   → run SHOW COLUMNS / ALTER TABLE, write marker on success
//
// If the settings table itself is missing (very first run), fall back gracefully.
$_fairmed_schema_version_needed = 'v1';
$_fairmed_schema_current        = null;

$_sv_res = @$conn->query(
    "SELECT setting_value FROM settings WHERE setting_key = 'schema_version' LIMIT 1"
);
if ($_sv_res instanceof mysqli_result) {
    $_sv_row = $_sv_res->fetch_assoc();
    $_fairmed_schema_current = $_sv_row['setting_value'] ?? null;
    $_sv_res->free();
}

if ($_fairmed_schema_current !== $_fairmed_schema_version_needed) {
    // ── Run migrations ────────────────────────────────────────────────────────
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
            error_log('[FairMedAlloc] Unable to add users.must_change_password automatically: ' . $conn->error);
        }
    }

    DbHelper::alignMedicalSchema($conn);

    // Write version marker so subsequent requests skip all of the above
    if ($supportsMustChangePassword) {
        @$conn->query(
            "INSERT INTO settings (setting_key, setting_value)
                  VALUES ('schema_version', '{$_fairmed_schema_version_needed}')
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
    }
} else {
    // Marker present — schema is already up to date
    $supportsMustChangePassword = true;
}

define('FAIRMED_SUPPORTS_MUST_CHANGE_PASSWORD', $supportsMustChangePassword);

?>
