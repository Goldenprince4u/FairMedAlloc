<?php
/**
 * Security Helper Functions
 * =========================
 * Provides essential security utilities including CSRF protection 
 * and input sanitization to prevent common web vulnerabilities.
 * 
 * @package Core
 * @subpackage Security
 * @author FairMedAlloc Team
 * @version 1.0.0
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_secure'   => 1,        // HTTPS only
        'cookie_httponly' => 1,        // no JS access
        'cookie_samesite' => 'Strict', // CSRF mitigation
        'use_strict_mode' => 1,        // reject unrecognised session IDs
    ]);
}

/**
 * Session Idle Timeout
 * ====================
 * Logs out users who have been idle longer than SESSION_TIMEOUT_SECONDS.
 * Fires on every protected page load — not while the user is actively
 * navigating, only after they walk away.
 *
 * Standard procedure:
 *  1. Record 'last_activity' in the session on each request.
 *  2. On the next request, compare elapsed time to the threshold.
 *  3. If exceeded → wipe session → destroy → redirect to login.
 */
defined('SESSION_TIMEOUT_SECONDS') || define('SESSION_TIMEOUT_SECONDS', 900); // 15-minute idle window

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $now = time();
    if (isset($_SESSION['last_activity'])) {
        $idle = $now - (int)$_SESSION['last_activity'];
        if ($idle > SESSION_TIMEOUT_SECONDS) {
            // Clean logout
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                          $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
            $is_admin = strpos($_SERVER['PHP_SELF'] ?? '', 'admin') !== false;
            $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
            $target = $is_admin ? 'admin_login.php' : 'login.php';
            header('Location: ' . $base . '/' . $target);
            exit();
        }
    }
    // Refresh timestamp on every active request
    $_SESSION['last_activity'] = $now;

    $current_script = basename($_SERVER['PHP_SELF'] ?? '');
    $password_change_allowed = ['change_password.php', 'logout.php'];
    if (!empty($_SESSION['must_change_password']) && !in_array($current_script, $password_change_allowed, true)) {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        header('Location: ' . $base . '/change_password.php?required=1');
        exit();
    }
}

/**
 * Generate CSRF Token
 *
 * Creates a cryptographically secure token if one does not already exist
 * in the session. If a previous POST set the rotation flag, the old token
 * is cleared here so a fresh one is issued for the new page render.
 *
 * @return string The valid CSRF token.
 */
function generate_csrf_token(): string {
    // Consume the rotation flag set by check_csrf() after a successful POST.
    // AJAX handlers never call this function, so they are unaffected and can
    // continue using the same token within the session.
    if (!empty($_SESSION['csrf_rotate_pending'])) {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_rotate_pending']);
    }

    if (empty($_SESSION['csrf_token'])) {
        try {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
        }
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF Token
 *
 * Compares the provided token against the session token to ensure
 * the request originated from a trusted source.
 *
 * @param string $token The token submitted via the form.
 * @return bool True if valid, False otherwise.
 */
function verify_csrf_token(string $token): bool {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Check CSRF (Strict Enforcement)
 *
 * Validates the CSRF token for all POST requests. If validation fails,
 * the script execution is terminated with a 403 Forbidden response.
 * On success, a rotation flag is set so the next page render issues
 * a fresh token, preventing replay attacks.
 *
 * @return void
 */
function check_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!verify_csrf_token($token)) {
            header('HTTP/1.1 403 Forbidden');
            $url = htmlspecialchars($_SERVER['PHP_SELF']);
            die("<h1>403 Forbidden</h1>
                 <p>Security Token Mismatch (Session Expired).</p>
                 <p><a href='$url'>Click here to reload the page safely</a></p>");
        }
        // Schedule deferred rotation — fires on the next page render, not here.
        $_SESSION['csrf_rotate_pending'] = true;
    }
}

/**
 * Output CSRF Field
 *
 * Helper to echo the hidden input field containing the CSRF token.
 * Should be used inside all <form> tags.
 *
 * @return void
 */
function csrf_field(): void {
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generate_csrf_token()) . '">';
}

/**
 * Sanitize Input
 *
 * Trims whitespace from user input before it is stored or processed.
 * This function intentionally does NOT apply htmlspecialchars() — that is
 * an output-encoding operation and is applied at render time in templates
 * (e.g. echo htmlspecialchars($var, ENT_QUOTES, 'UTF-8')). Encoding here
 * would cause double-escaping (e.g. O'Brien → O&#039;Brien in the DB, then
 * O&amp;#039;Brien on screen) and would corrupt data in reports and exports.
 *
 * All callers pass the result directly into prepared statements, so no
 * additional escaping is needed for SQL safety.
 *
 * @param mixed $data The raw input data (string or array).
 * @return mixed The trimmed data, safe for storage.
 */
function sanitize_input($data) {
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    // trim() removes leading/trailing whitespace.
    // No htmlspecialchars() here — apply that only when echoing to HTML.
    return trim((string)$data);
}

/**
 * Log Admin Action
 * 
 * Records an administrative action in the database for auditing purposes.
 * 
 * @param mysqli $conn The database connection
 * @param int $admin_id The user ID of the admin
 * @param string $action_description Description of the action performed
 * @return void
 */
function log_admin_action($conn, int $admin_id, string $action_description): void {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    try {
        $stmt = $conn->prepare("INSERT INTO admin_audit_logs (admin_id, action_description, ip_address) VALUES (?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("iss", $admin_id, $action_description, $ip_address);
            $stmt->execute();
            $stmt->close();
        }
    } catch (\Throwable $e) {
        // Silently fail — audit logging must never block authentication
    }
}
?>
