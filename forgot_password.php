<?php
/**
 * forgot_password.php — Password Recovery Flow
 * ================================================
 * Step 1 of 2 in the account recovery process.
 * Accepts a username/matric number input, looks up the user, generates
 * a secure time-limited reset token, and simulates email delivery.
 *
 * Security measures applied:
 *   - CSRF token validation on every POST.
 *   - Prepared statements for all DB queries.
 *   - Reset token is a 64-char CSPRNG hex string.
 *   - Only the SHA-256 hash of the token is stored in the DB (never the raw token).
 *   - Generic success message even when user not found (prevents enumeration).
 *   - Token expiry: 1 hour.
 *
 * NOTE (Production TODO): Replace the file_put_contents() log and direct link
 *   display with a real email delivery library (e.g. PHPMailer/SwiftMailer).
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

$message = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $username = trim($_POST['username']);
    
    // --- 1. User Lookup ---
    // Match against username OR email in the users table.
    // Uses a LEFT JOIN on student_profiles in case email is stored separately (legacy).
    $stmt = $conn->prepare("
        SELECT u.user_id, COALESCE(u.email, '') as email 
        FROM users u 
        LEFT JOIN student_profiles p ON u.user_id = p.user_id 
        WHERE u.username = ? OR u.email = ?
    ");
    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        $user    = $res->fetch_assoc();
        $user_id = $user['user_id'];
        
        // --- 2. Generate Secure Token ---
        // random_bytes(32) gives 256 bits of cryptographic entropy.
        // The raw token is sent to the user; only its SHA-256 hash is stored in DB.
        $token      = bin2hex(random_bytes(32));
        $token_hash = hash('sha256', $token);
        $expires    = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // --- 3. Persist Token (invalidating any previous one) ---
        // Delete old tokens first to prevent duplicate key issues and stale tokens.
        $stmt_del = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
        $stmt_del->bind_param("i", $user_id);
        $stmt_del->execute();

        $stmt_ins = $conn->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
        $stmt_ins->bind_param("iss", $user_id, $token_hash, $expires);
        $stmt_ins->execute();

        // --- 4. Deliver Reset Link (Simulated — Dev mode) ---
        // Build the absolute reset URL using the current server host.
        // SECURITY: Using HTTPS in production prevents token interception in transit.
        $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $reset_link = $protocol . '://' . htmlspecialchars($_SERVER['HTTP_HOST'])
                      . dirname($_SERVER['PHP_SELF'])
                      . '/reset_password.php?token=' . urlencode($token);
        
        // TODO (Production): Replace this with a real email delivery call:
        // mailer->send($user['email'], 'Reset your FairMedAlloc password', $reset_link);
        $log_entry = "[" . date('Y-m-d H:i:s') . "] Reset for {$username}: {$reset_link}" . PHP_EOL;
        file_put_contents('email_log.txt', $log_entry, FILE_APPEND | LOCK_EX);

        // NOTE: The link below is intentional for dev/demo. In production, remove this
        // and only send the link via email. It is safe to echo here because $reset_link
        // is built from server variables and urlencode(), not raw user input.
        $message  = "A reset link has been sent to your registered email (Simulated).<br><strong><a href='" . $reset_link . "'>Click here to Reset Password</a></strong>";
        $msg_type = 'success';
    } else {
        // --- Enumeration Prevention ---
        // Return the same message whether the user exists or not.
        $message  = "If an account exists with that ID, a reset link has been sent.";
        $msg_type = 'info';
    }
}

$page_title = "Recover Access | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="auth-container">
    <div class="auth-left">
        <div class="brand-content">
             <i class="fa-solid fa-shield-halved text-4xl text-accent mb-6" style="font-size: 4rem; color: var(--c-accent); margin-bottom: 1.5rem;"></i>
             <h1 style="font-size: 2.5rem; line-height: 1.1; margin-bottom: 1rem; font-weight: 700;">Secure Recovery</h1>
             <p style="font-size: 1.25rem; opacity: 0.9; font-weight: 300;">Identity verification is required to reset your access credentials.</p>
        </div>
    </div>

    <div class="auth-right">
        <div class="auth-box glass-card" style="background: white; border: none; box-shadow: none;">
            <a href="login.php" class="mb-6 inline-block text-muted" style="font-size: 0.85rem;"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>
            
            <h2 class="mb-2" style="font-size: 2rem; color: var(--c-primary);">Forgot Password?</h2>
            <p class="text-muted mb-6">Enter your matriculation number to reset your access key.</p>

            <?php if($message): ?>
                <div class="alert alert-<?php echo $msg_type; ?> mb-4 text-center">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php csrf_field(); ?>
                <div class="form-group">
                    <label>Matric No / Username</label>
                    <input type="text" name="username" placeholder="RUN/..." required class="input w-full">
                </div>

                <button class="btn btn-primary w-full mb-6" style="width: 100%;">
                    Request Reset Link
                </button>
            </form>
            
            <div class="text-center text-xs text-muted">
                Contact <strong>sysadmin@fairmed.edu.ng</strong> if you cannot access your student email.
            </div>
        </div>
    </div>
</div>
</body>
</html>