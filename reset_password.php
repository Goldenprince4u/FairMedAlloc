<?php
/**
 * reset_password.php — Password Reset (Token Redemption)
 * ========================================================
 * Final step of the account recovery flow.
 * This page is reached via a tokenised link from forgot_password.php.
 *
 * Security measures applied:
 *   - Token is stored as a SHA-256 hash (never in plain text).
 *   - Token has a 1-hour expiry enforced at the DB level.
 *   - CSRF token validation on the POST (password update) form.
 *   - Used token is deleted immediately after a successful reset.
 *   - Minimum password length enforced server-side (8 chars).
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

$message  = '';
$msg_type = '';
$msg_html = false; // True only when $message contains intentional safe HTML (e.g., login link)
$token = $_GET['token'] ?? '';
$valid_token = false;

// --- 1. Validate Token ---
// The raw token from the URL is hashed before DB lookup.
// This means even if the password_resets table were breached,
// the plain tokens (which are the actual secret) would not be exposed.
$token_hash = hash('sha256', $token);
$stmt = $conn->prepare("SELECT id, user_id, expires_at FROM password_resets WHERE token_hash = ? AND expires_at > NOW()");
$stmt->bind_param("s", $token_hash);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows > 0) {
    $valid_token = true;
    $reset_row = $res->fetch_assoc();
} else {
    // Token is either invalid or expired — show a generic error message.
    $message = "Invalid or expired reset token. Please request a new one.";
    $msg_type = "error";
}

// --- 2. Handle Password Update (POST only, and only if token is valid) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token) {
    // Verify CSRF token to prevent cross-site request forgery on the reset form.
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf)) {
        $message  = "Security token invalid. Please reload the page and try again.";
        $msg_type = "error";
    } else {
        $new_pass     = $_POST['password'];
        $confirm_pass = $_POST['confirm_password'];
    
        // Enforce minimum length server-side (8 to match signup policy)
        if (strlen($new_pass) < 8) {
            $message = "Password must be at least 8 characters.";
            $msg_type = "error";
        } elseif ($new_pass !== $confirm_pass) {
            $message = "Passwords do not match.";
            $msg_type = "error";
        } else {
            // Hash the new password securely before updating.
            $user_id   = $reset_row['user_id'];
            $pass_hash = password_hash($new_pass, PASSWORD_DEFAULT);
            
            $stmt_upd = $conn->prepare("UPDATE users SET password_hash = ?, login_attempts = 0, lock_until = NULL WHERE user_id = ?");
            $stmt_upd->bind_param("si", $pass_hash, $user_id);
            
            if ($stmt_upd->execute()) {
                // --- Invalidate Token ---
                // Delete immediately after use so the link can never be reused,
                // even if it has not yet expired.
                $stmt_del = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
                $stmt_del->bind_param("i", $user_id);
                $stmt_del->execute();

                // The link below uses a hardcoded href (not user input), so it is safe to echo as HTML.
                // $msg_html = true signals the template to skip htmlspecialchars() for this message only.
                $message  = "Password reset successfully! <a href='login.php' style='font-weight:700; text-decoration:underline;'>Login Now</a>";
                $msg_html = true;
                $msg_type = "success";
                $valid_token = false; // Hide the form after success
            } else {
                // Generic error — do not expose DB error details.
                $message = "Database error. Please try again.";
                $msg_type = "error";
            }
        }
    } // end CSRF check
}

$page_title = "Set New Password | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="auth-container">
    <div class="auth-left">
        <div class="brand-content">
             <i class="fa-solid fa-key text-4xl text-accent mb-6" style="font-size: 4rem; color: var(--c-accent); margin-bottom: 1.5rem;"></i>
             <h1 style="font-size: 2.5rem; line-height: 1.1; margin-bottom: 1rem; font-weight: 700;">New Credentials</h1>
             <p style="font-size: 1.25rem; opacity: 0.9; font-weight: 300;">Secure your account with a strong password.</p>
        </div>
    </div>

    <div class="auth-right">
        <div class="auth-box glass-card">
            
            <h2 class="mb-2" style="font-size: 2rem; color: var(--c-primary);">Reset Password</h2>
            
            <?php if($message): ?>
                <div class="alert alert-<?php echo ($msg_type == 'error' ? 'danger' : 'success'); ?> mb-4 text-center">
                    <?php echo $msg_html ? $message : htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if ($valid_token): ?>
                <p class="text-muted mb-6">Create a new password for your account.</p>
                <form method="post">
                    <?php csrf_field(); ?>
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="password" placeholder="******" required class="input w-full">
                    </div>
                    
                    <div class="form-group mb-6">
                        <label>Confirm Password</label>
                        <input type="password" name="confirm_password" placeholder="******" required class="input w-full">
                    </div>

                    <button class="btn btn-primary w-full" style="width: 100%;">
                        Update Password
                    </button>
                </form>
            <?php elseif (!$valid_token && $msg_type == 'success'): ?>
                <!-- Success State handled above -->
            <?php else: ?>
                <div class="mt-6 text-center">
                    <a href="forgot_password.php" class="btn btn-outline">Request New Link</a>
                </div>
            <?php endif; ?>
            
        </div>
    </div>
</div>
</body>
</html>
