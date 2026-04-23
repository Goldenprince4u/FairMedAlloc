<?php
/**
 * admin_login.php â€” Administrator Login Portal
 * ==============================================
 * Handles admin authentication with the following security features:
 *   - CSRF token validation on every POST request.
 *   - Prepared statements (no SQL injection risk).
 *   - Brute-force protection: account locked for 15 min after 5 failed attempts.
 *   - Lockout events are audit-logged for security review.
 *   - Role guard: only 'admin' role can log in here.
 *   - last_login timestamp updated on success.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

$error = '';
$timeout_notice = '';

// Session idle-timeout notice
if (isset($_GET['timeout']) && $_GET['timeout'] === '1') {
    $timeout_notice = "Your session expired after 30 minutes of inactivity. Please log in again.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate CSRF token before any processing
    check_csrf();

    // Use raw trimmed values â€” NOT htmlspecialchars â€” for DB comparison
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    // Fetch user record including lockout state
    $stmt = $conn->prepare("SELECT user_id, username, password_hash, role, login_attempts, lock_until, profile_pic, full_name FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 1) {
        $user = $res->fetch_assoc();

        // --- 1. Brute-Force Lockout Check ---
        // Block login if a temporary lockout is currently active.
        if ($user['lock_until'] && strtotime($user['lock_until']) > time()) {
            $remaining = ceil((strtotime($user['lock_until']) - time()) / 60);
            $error = "Account locked due to too many failed attempts. Try again in {$remaining} minutes.";
        } else {
            // --- 2. Password Verification ---
            if (password_verify($password, $user['password_hash'])) {
                // Success: reset lockout counter and record login timestamp
                $stmt_reset = $conn->prepare("UPDATE users SET login_attempts = 0, lock_until = NULL, last_login = NOW() WHERE user_id = ?");
                $stmt_reset->bind_param("i", $user['user_id']);
                $stmt_reset->execute();

                // --- 3. Role Guard ---
                // This portal is exclusively for admin/medical_officer roles.
                if ($user['role'] !== 'admin') {
                    $error = "Access Denied: Admin privileges required.";
                } else {
                    // Populate session to establish the authenticated admin state.
                    $_SESSION['logged_in'] = true;
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['profile_pic'] = $user['profile_pic'] ?? 'default.png';
                    $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];

                    // Audit log: successful admin logins are always recorded
                    log_admin_action($conn, $user['user_id'], 'Successful Admin Login');

                    header("Location: admin_dashboard.php");
                    exit();
                }
            } else {
                // --- 4. Failed Attempt Tracking ---
                $attempts = $user['login_attempts'] + 1;

                if ($attempts >= 5) {
                    // Lock the account for 15 minutes
                    $stmt_lock = $conn->prepare("UPDATE users SET login_attempts = ?, lock_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE user_id = ?");
                    $stmt_lock->bind_param("ii", $attempts, $user['user_id']);
                    $stmt_lock->execute();
                    $error = "Too many failed attempts. Account locked for 15 minutes.";

                    // Extra audit log for admin lockouts (security signal)
                    if ($user['role'] === 'admin') {
                        log_admin_action($conn, $user['user_id'], 'Admin Account Locked Out (5 Failed Attempts)');
                    }
                } else {
                    $stmt_inc = $conn->prepare("UPDATE users SET login_attempts = ? WHERE user_id = ?");
                    $stmt_inc->bind_param("ii", $attempts, $user['user_id']);
                    $stmt_inc->execute();
                    $error = "Invalid credentials provided. ({$attempts}/5 attempts)";
                }
            }
        }
    } else {
        // Generic message to prevent username enumeration
        $error = "Invalid credentials provided.";
    }
}

$page_title = "Admin Login | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="auth-container">
    <!-- Left: Brand Panel -->
    <div class="auth-left">
        <div class="brand-content">
            <img src="assets/logo.jpeg"
                 alt="Redeemer's University"
                 style="width:80px;height:80px;border-radius:50%;object-fit:cover;margin-bottom:1.2rem;border:3px solid rgba(255,255,255,0.3);box-shadow:0 0 30px rgba(255,255,255,0.15);">
            <h1 class="auth-headline">System<br>Administration</h1>
            <p class="auth-subtitle">Redeemer's University</p>

            <div class="brand-border">
                <p>Secure access to the FairMedAlloc Management Console. Strictly for authorized personnel.</p>
            </div>
        </div>
    </div>

    <div class="auth-right">
        <div class="auth-box animate-fade-in">
            <div class="mb-8">
                <span class="badge badge-warning mb-4"><i class="fa-solid fa-lock mr-2"></i>ADMIN PORTAL</span>
                <h2 class="mb-2 text-primary serif text-4xl">Admin Login</h2>
                <p class="text-muted text-lg">Enter your administrative credentials.</p>
            </div>

            <?php if ($timeout_notice): ?>
                <div class="alert" style="background:rgba(245,158,11,0.12);border-left:3px solid var(--c-warning);color:var(--c-warning);padding:.9rem 1rem;border-radius:var(--radius);margin-bottom:1rem;font-size:.9rem;">
                    <i class="fa-solid fa-clock mr-2"></i><?php echo htmlspecialchars($timeout_notice); ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <!-- SECURITY: htmlspecialchars() prevents XSS from any user-controlled data in $error -->
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="post">
                <?php csrf_field(); ?>

                <div class="form-group">
                    <label class="text-sm fw-700 mb-2">Username</label>
                    <input type="text" name="username" placeholder="admin" required class="input-auth">
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

                <button class="btn btn-warning w-full mb-4" style="color: var(--c-primary-dark);"><i class="fa-solid fa-right-to-bracket mr-2"></i> Sign in</button>

                <div class="text-center text-muted text-sm mb-6">
                    <a href="forgot_password.php" class="text-muted">Forgot Password?</a>
                </div>

                <div class="text-center text-sm pt-4 text-muted" style="border-top: 1px solid var(--c-border);">
                    Student Portal? <a href="login.php" class="text-primary fw-700">Student Login</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body>

</html>
