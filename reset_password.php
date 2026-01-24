<?php
/**
 * Reset Password
 * Final step of account recovery.
 */
session_start();
require_once 'db_config.php';

$message = '';
$msg_type = '';
$token = $_GET['token'] ?? '';
$valid_token = false;

// 1. Validate Token
$token_hash = hash('sha256', $token);
$stmt = $conn->prepare("SELECT * FROM password_resets WHERE token_hash = ? AND expires_at > NOW()");
$stmt->bind_param("s", $token_hash);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows > 0) {
    $valid_token = true;
    $reset_row = $res->fetch_assoc();
} else {
    $message = "Invalid or expired reset token. Please request a new one.";
    $msg_type = "error";
}

// 2. Handle Password Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token) {
    $new_pass = $_POST['password'];
    $confirm_pass = $_POST['confirm_password'];
    
    if (strlen($new_pass) < 6) {
        $message = "Password must be at least 6 characters.";
        $msg_type = "error";
    } elseif ($new_pass !== $confirm_pass) {
        $message = "Passwords do not match.";
        $msg_type = "error";
    } else {
        // Update User
        $user_id = $reset_row['user_id'];
        $pass_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        
        $stmt_upd = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
        $stmt_upd->bind_param("si", $pass_hash, $user_id);
        
        if ($stmt_upd->execute()) {
            // Delete Token
            $conn->query("DELETE FROM password_resets WHERE user_id = $user_id");
            
            $message = "Password reset successfully! <a href='login.php' class='underline font-bold'>Login Now</a>";
            $msg_type = "success";
            $valid_token = false; // Hide form
        } else {
            $message = "Database error. Please try again.";
            $msg_type = "error";
        }
    }
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
        <div class="auth-box glass-card" style="background: white; border: none; box-shadow: none;">
            
            <h2 class="mb-2" style="font-size: 2rem; color: var(--c-primary);">Reset Password</h2>
            
            <?php if($message): ?>
                <div class="alert alert-<?php echo ($msg_type == 'error' ? 'danger' : 'success'); ?> mb-4 text-center">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if ($valid_token): ?>
                <p class="text-muted mb-6">Create a new password for your account.</p>
                <form method="post">
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
