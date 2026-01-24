<?php
/**
 * CSRF Protection Class
 */
class Csrf {
    
    /**
     * Generate a token and store in session
     */
    public static function getToken() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Generate hidden input field
     */
    public static function field() {
        $token = self::getToken();
        echo '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }

    /**
     * Verify token from POST
     */
    public static function verify() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        
        if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token'])) {
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
    }
}

/**
 * Helper function for views
 */
function csrf_field() {
    Csrf::field();
}
?>
