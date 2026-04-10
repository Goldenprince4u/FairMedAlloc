<?php
/**
 * login.php — Student Login Portal
 * ==================================
 * Handles student authentication with the following security features:
 *   - CSRF token validation on every POST request.
 *   - Prepared statements (no SQL injection risk).
 *   - Brute-force protection: account locked for 15 min after 5 failed attempts.
 *   - Password verification via password_verify() (bcrypt-aware).
 *   - Strict role check: only 'student' role can log in here.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

$error = '';

// Pre-fill an error message if redirected here due to an incomplete profile
if (isset($_GET['error']) && $_GET['error'] === 'profile_missing') {
    $error = "Profile data incomplete. Please log in again to sync.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate CSRF token before processing any login logic
    check_csrf();

    // Trim inputs but do NOT sanitize username/password with htmlspecialchars—
    // they need to be used verbatim for comparison against the DB.
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    // --- Authentication Logic Flow ---
    // 1. Fetch user credentials and current lockout status by username.
    $stmt = $conn->prepare("SELECT user_id, username, password_hash, role, login_attempts, lock_until, profile_pic, full_name FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 1) {
        $user = $res->fetch_assoc();

        // --- 1. Security Check: Brute Force Lockout ---
        // Verify if the user is currently serving a temporary ban due to too many failed attempts.
        if ($user['lock_until'] && strtotime($user['lock_until']) > time()) {
            $remaining = ceil((strtotime($user['lock_until']) - time()) / 60);
            $error = "Account locked due to too many failed attempts. Try again in {$remaining} minutes.";
        } else {
            // --- 2. Password Verification ---
            // Compare the raw submitted password against the stored bcrypt hash.
            if (password_verify($password, $user['password_hash'])) {
                // Success: Reset the failed login attempts counter and record login timestamp.
                $reset_stmt = $conn->prepare("UPDATE users SET login_attempts = 0, lock_until = NULL, last_login = NOW() WHERE user_id = ?");
                $reset_stmt->bind_param("i", $user['user_id']);
                $reset_stmt->execute();

                // --- 3. Role Guard ---
                // This login page is strictly for students. Admins have a separate portal.
                if ($user['role'] !== 'student') {
                    $error = "Invalid portal for your role. Please use the Administrator Login.";
                } else {
                    // Populate session variables to establish the authenticated state.
                    $_SESSION['logged_in'] = true;
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['profile_pic'] = $user['profile_pic'] ?? 'default.png';
                    $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];

                    header("Location: student_dashboard.php");
                    exit();
                }
            } else {
                // --- 4. Failed Attempt Tracking ---
                // Increment the counter and lock after 5 failures.
                $attempts = $user['login_attempts'] + 1;

                if ($attempts >= 5) {
                    // Lock account for 15 minutes
                    $lock_stmt = $conn->prepare("UPDATE users SET login_attempts = ?, lock_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE user_id = ?");
                    $lock_stmt->bind_param("ii", $attempts, $user['user_id']);
                    $lock_stmt->execute();
                    $error = "Too many failed attempts. Account locked for 15 minutes.";
                } else {
                    $inc_stmt = $conn->prepare("UPDATE users SET login_attempts = ? WHERE user_id = ?");
                    $inc_stmt->bind_param("ii", $attempts, $user['user_id']);
                    $inc_stmt->execute();
                    $error = "Invalid credentials provided. ({$attempts}/5 attempts)";
                }
            }
        }
    } else {
        // Username not found — use the same generic message to avoid username enumeration.
        $error = "Invalid credentials provided.";
    }
}

$page_title = "Login | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="auth-container">
    <!-- Left: Brand Panel -->
    <div class="auth-left">
        <div class="brand-content">
            <h1 class="auth-headline">FairMedAlloc<br>System</h1>
            <p class="auth-subtitle">Redeemer's University</p>

            <div class="brand-border">
                <p>Prioritizing Student Health &amp; Safety through Algorithmic Fairness. A Final Year Computer Science
                    Research Project.</p>
            </div>
        </div>
    </div>

    <div class="auth-right">
        <div class="auth-box animate-fade-in">
            <div class="mb-8">
                <div class="mb-8">
                    <span class="badge badge-info mb-4">STUDENT PORTAL</span>
                    <h2 class="mb-2 text-primary serif text-4xl">Welcome Back</h2>
                    <p class="text-muted text-lg">Enter your credentials to access the system.</p>
                </div>

                <?php if ($error): ?>
                    <!-- SECURITY: htmlspecialchars() prevents any user-controlled content in $error from being rendered as HTML/JS -->
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <form method="post">
                    <?php csrf_field(); ?>

                    <div class="form-group">
                        <label class="text-sm font-bold text-slate-700 mb-2">Matric No</label>
                        <input type="text" name="username" placeholder="run/..." required class="input-auth">
                    </div>

                    <div class="form-group">
                        <label class="text-sm font-bold text-slate-700 mb-2">Password</label>
                        <input type="password" name="password" placeholder="••••••••" required class="input-auth">
                    </div>

                    <button class="btn btn-primary w-full mb-4">Sign In</button>

                    <div class="flex justify-between text-sm mt-4">
                        <a href="signup.php" class="text-primary fw-700">New Student? Create Account</a>
                        <a href="forgot_password.php" class="text-muted">Forgot Password?</a>
                    </div>
                    <div class="text-center text-sm mt-6 pt-4 border-t border-slate-200 text-muted">
                        Administrator? <a href="admin_login.php" class="text-primary fw-700">Admin Login</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    </body>

    </html>