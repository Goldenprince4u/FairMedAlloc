<?php
/**
 * logout.php — Session Termination
 * ==================================
 * Performs a complete and secure logout in three mandatory steps:
 *
 *   1. Clear all session variables (wipe in-memory data).
 *   2. Expire the session cookie on the client browser.
 *   3. Destroy the server-side session record.
 *
 * Skipping any of these steps would leave the session partially alive.
 * All three together ensure the session cannot be re-used even if the
 * browser keeps a stale cookie.
 */
session_start();

// Step 1: Wipe all session variables from memory
$_SESSION = [];

// Step 2: Expire the session cookie on the client side
// Without this, the browser still holds the session ID and could reuse it
// if the server session were somehow not destroyed.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',                     // Empty value
        time() - 42000,         // Expiry in the past forces immediate deletion
        $params['path'],
        $params['domain'],
        $params['secure'],      // HTTPS-only if configured
        $params['httponly']     // Prevents JavaScript access to cookie
    );
}

// Step 3: Destroy the server-side session record
session_destroy();

// Redirect to login page after logout
header("Location: login.php");
exit();
?>