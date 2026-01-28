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
    session_start();
}

/**
 * Generate CSRF Token
 * 
 * Creates a cryptographically secure token if one does not already exist 
 * in the session. This token is used to validate POST requests.
 * 
 * @return string The valid CSRF token.
 */
function generate_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        try {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            // Fallback for older systems (unlikely in modern PHP)
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
 * recursively sanitizes user input to prevent XSS attacks.
 * 
 * @param mixed $data The raw input data (string or array).
 * @return mixed The sanitized data.
 */
function sanitize_input($data) {
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    $data = trim((string)$data);
    $data = stripslashes($data);
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}
?>
