<?php
/**
 * login.php — Student Login Portal
 * Security: CSRF, bcrypt, brute-force lockout, role guard.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

$error = '';
if (isset($_GET['error']) && $_GET['error'] === 'profile_missing') {
    $error = "Profile data incomplete. Please log in again to sync.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    check_csrf();
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $generic_login_error = "Unable to sign in right now. Check your credentials or try again later.";

    $stmt = $conn->prepare("SELECT user_id, username, password_hash, role, login_attempts, lock_until, profile_pic, full_name FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 1) {
        $user = $res->fetch_assoc();
        if ($user['lock_until'] && strtotime($user['lock_until']) > time()) {
            $error = $generic_login_error;
        } else {
            if (password_verify($password, $user['password_hash'])) {
                if ($user['role'] !== 'student') {
                    $error = $generic_login_error;
                } else {
                    $reset_stmt = $conn->prepare("UPDATE users SET login_attempts = 0, lock_until = NULL, last_login = NOW() WHERE user_id = ?");
                    $reset_stmt->bind_param("i", $user['user_id']);
                    $reset_stmt->execute();
                    session_regenerate_id(true);
                    $_SESSION['logged_in']   = true;
                    $_SESSION['user_id']     = $user['user_id'];
                    $_SESSION['role']        = $user['role'];
                    $_SESSION['username']    = $user['username'];
                    $_SESSION['profile_pic'] = $user['profile_pic'] ?? 'default.png';
                    $_SESSION['full_name']   = $user['full_name'] ?? $user['username'];
                    header("Location: student_dashboard.php");
                    exit();
                }
            } else {
                $attempts = $user['login_attempts'] + 1;
                if ($attempts >= 5) {
                    $lock_stmt = $conn->prepare("UPDATE users SET login_attempts = ?, lock_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE user_id = ?");
                    $lock_stmt->bind_param("ii", $attempts, $user['user_id']);
                    $lock_stmt->execute();
                    $error = $generic_login_error;
                } else {
                    $inc_stmt = $conn->prepare("UPDATE users SET login_attempts = ? WHERE user_id = ?");
                    $inc_stmt->bind_param("ii", $attempts, $user['user_id']);
                    $inc_stmt->execute();
                    $error = $generic_login_error;
                }
            }
        }
    } else {
        $error = $generic_login_error;
    }
}

$page_title = "Student Login | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="auth-container">

    <!-- ── Left: Brand Panel ── -->
    <div class="auth-left">
        <div class="brand-content">

            <img src="assets/logo.jpeg"
                 alt="Redeemer's University Logo"
                 style="width:72px;height:72px;border-radius:50%;object-fit:cover;margin-bottom:1.5rem;border:3px solid rgba(201,168,76,0.5);">

            <h1 class="auth-headline">FairMedAlloc<br>System</h1>
            <p class="auth-subtitle">Redeemer's University</p>

            <div class="brand-border">
                <p>Prioritizing Student Health &amp; Safety through Algorithmic Fairness.</p>
                <ul class="auth-feature-list">
                    <li><i class="fa-solid fa-check"></i> Medical Priority Scoring</li>
                    <li><i class="fa-solid fa-check"></i> Fair Room Allocation</li>
                    <li><i class="fa-solid fa-check"></i> Transparent Process</li>
                    <li><i class="fa-solid fa-check"></i> Secure &amp; CSRF-Protected</li>
                </ul>
            </div>

            <p style="margin-top:3rem;font-size:0.72rem;color:rgba(255,255,255,0.3);letter-spacing:0.05em;">
                A Final Year Computer Science Research Project
            </p>
        </div>
    </div>

    <!-- ── Right: Form Panel ── -->
    <div class="auth-right">
        <div class="auth-box animate-fade-in">

            <!-- Header -->
            <div class="mb-8">
                <span class="badge badge-primary mb-4" style="font-size:0.68rem;letter-spacing:0.1em;">STUDENT PORTAL</span>
                <h2 style="font-size:1.75rem;margin-bottom:0.35rem;color:var(--c-text-head);">Welcome Back</h2>
                <p class="text-muted" style="font-size:0.9rem;">Enter your credentials to access the system.</p>
            </div>



            <!-- Error -->
            <?php if ($error): ?>
                <div class="alert alert-danger mb-4">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="post" id="login-form">
                <?php csrf_field(); ?>

                <div class="form-group">
                    <label for="login-matric">Matric Number</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fa-solid fa-id-card"></i></span>
                        <input type="text"
                               id="login-matric"
                               name="username"
                               placeholder="e.g. RUN/CMP/22/001"
                               required
                               class="input-auth"
                               style="padding-left:2.5rem;">
                    </div>
                </div>

                <div class="form-group">
                    <label for="login-password">Password</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fa-solid fa-lock"></i></span>
                        <input type="password"
                               id="login-password"
                               name="password"
                               placeholder="••••••••"
                               required
                               class="input-auth"
                               style="padding-left:2.5rem;padding-right:2.75rem;">
                        <i class="fa-solid fa-eye text-muted"
                           id="togglePassword"
                           style="position:absolute;right:14px;top:50%;transform:translateY(-50%);cursor:pointer;font-size:0.85rem;"
                           onclick="togglePasswordVisibility()"
                           title="Toggle password visibility"></i>
                    </div>
                </div>

                <script>
                function togglePasswordVisibility() {
                    const pw   = document.getElementById('login-password');
                    const icon = document.getElementById('togglePassword');
                    const isHidden = pw.type === 'password';
                    pw.type = isHidden ? 'text' : 'password';
                    icon.classList.toggle('fa-eye',      !isHidden);
                    icon.classList.toggle('fa-eye-slash', isHidden);
                }
                </script>

                <button type="submit" class="btn btn-primary w-full mt-2" id="login-submit-btn" style="padding:0.8rem;">
                    Sign In <i class="fa-solid fa-arrow-right" style="margin-left:6px;"></i>
                </button>

                <div class="flex justify-between mt-4" style="font-size:0.84rem;">
                    <a href="signup.php" class="text-primary fw-700">New Student? Register</a>
                    <a href="forgot_password.php" class="text-muted">Forgot Password?</a>
                </div>

                <div class="text-center mt-6 pt-4 text-muted" style="border-top:1px solid var(--c-border);font-size:0.84rem;">
                    Administrator? <a href="admin_login.php" class="text-primary fw-700">Admin Login &rarr;</a>
                </div>
            </form>

        </div>
    </div>
</div>

</body>
</html>
