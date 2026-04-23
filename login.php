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
$timeout_notice = '';

// Pre-fill an error message if redirected here due to an incomplete profile
if (isset($_GET['error']) && $_GET['error'] === 'profile_missing') {
    $error = "Profile data incomplete. Please log in again to sync.";
}

// Session idle-timeout notice
if (isset($_GET['timeout']) && $_GET['timeout'] === '1') {
    $timeout_notice = "Your session expired after 30 minutes of inactivity. Please log in again.";
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
            <img src="assets/logo.jpeg"
                 alt="Redeemer's University"
                 style="width:80px;height:80px;border-radius:50%;object-fit:cover;margin-bottom:1.2rem;border:3px solid rgba(255,255,255,0.3);box-shadow:0 0 30px rgba(255,255,255,0.15);">
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

                <?php if ($timeout_notice): ?>
                    <div class="alert" style="background:rgba(245,158,11,0.12);border-left:3px solid var(--c-warning);color:var(--c-warning);padding:.9rem 1rem;border-radius:var(--radius);margin-bottom:1rem;font-size:.9rem;">
                        <i class="fa-solid fa-clock mr-2"></i><?php echo htmlspecialchars($timeout_notice); ?>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>

                    <!-- SECURITY: htmlspecialchars() prevents any user-controlled content in $error from being rendered as HTML/JS -->
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <form method="post">
                    <?php csrf_field(); ?>

                    <div class="form-group">
                        <label class="text-sm fw-700 mb-2">Matric No</label>
                        <input type="text" name="username" placeholder="run/..." required class="input-auth">
                    </div>

                    <div class="form-group">
                        <label class="text-sm fw-700 mb-2">Password</label>
                        <div style="position: relative;">
                            <input type="password" id="passwordInput" name="password" placeholder="••••••••" required class="input-auth" style="padding-right: 40px;">
                            <i class="fa-solid fa-eye text-muted" id="togglePassword"
                               style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); cursor: pointer;"
                               onclick="togglePasswordVisibility()"></i>
                        </div>
                    </div>

                    <script>
                    function togglePasswordVisibility() {
                        const pw   = document.getElementById('passwordInput');
                        const icon = document.getElementById('togglePassword');
                        const isHidden = pw.type === 'password';
                        pw.type = isHidden ? 'text' : 'password';
                        icon.classList.toggle('fa-eye',       !isHidden);
                        icon.classList.toggle('fa-eye-slash',  isHidden);
                    }
                    </script>

                    <button class="btn btn-primary w-full mb-4">Sign In</button>

                    <div class="flex justify-between text-sm mt-4">
                        <a href="signup.php" class="text-primary fw-700">New Student? Create Account</a>
                        <a href="forgot_password.php" class="text-muted">Forgot Password?</a>
                    </div>
                    <div class="text-center text-sm mt-6 pt-4 text-muted" style="border-top: 1px solid var(--c-border);">
                        Administrator? <a href="admin_login.php" class="text-primary fw-700">Admin Login</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    </div>
</body>
</html>