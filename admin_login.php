<?php
/**
 * admin_login.php — Administrator Login Portal
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

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate CSRF token before any processing
    check_csrf();

    // Use raw trimmed values — NOT htmlspecialchars — for DB comparison
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $generic_login_error = "Unable to sign in right now. Check your credentials or try again later.";
    $must_change_select = FAIRMED_SUPPORTS_MUST_CHANGE_PASSWORD ? ', must_change_password' : ', 0 AS must_change_password';

    // Fetch user record including lockout state
    $stmt = $conn->prepare("SELECT user_id, username, password_hash, role, login_attempts, lock_until, profile_pic, full_name{$must_change_select} FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 1) {
        $user = $res->fetch_assoc();

        // --- 1. Brute-Force Lockout Check ---
        // Block login if a temporary lockout is currently active.
        if ($user['lock_until'] && strtotime($user['lock_until']) > time()) {
            $error = $generic_login_error;
        } else {
            // --- 2. Password Verification ---
            if (password_verify($password, $user['password_hash'])) {
                // --- 3. Role Guard ---
                // This portal is exclusively for admin/medical_officer roles.
                if ($user['role'] !== 'admin') {
                    $error = $generic_login_error;
                } else {
                    // Success: reset lockout counter and record login timestamp
                    $stmt_reset = $conn->prepare("UPDATE users SET login_attempts = 0, lock_until = NULL, last_login = NOW() WHERE user_id = ?");
                    $stmt_reset->bind_param("i", $user['user_id']);
                    $stmt_reset->execute();

                    // Populate session to establish the authenticated admin state.
                    session_regenerate_id(true);
                    $_SESSION['logged_in'] = true;
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['profile_pic'] = $user['profile_pic'] ?? 'default.png';
                    $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
                    $_SESSION['must_change_password'] = !empty($user['must_change_password']);

                    // Audit log: successful admin logins are always recorded
                    log_admin_action($conn, $user['user_id'], 'Successful Admin Login');

                    $target = $_SESSION['must_change_password'] ? 'change_password.php?required=1' : 'admin_dashboard.php';
                    header("Location: {$target}");
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
                    $error = $generic_login_error;

                    // Extra audit log for admin lockouts (security signal)
                    if ($user['role'] === 'admin') {
                        log_admin_action($conn, $user['user_id'], 'Admin Account Locked Out (5 Failed Attempts)');
                    }
                } else {
                    $stmt_inc = $conn->prepare("UPDATE users SET login_attempts = ? WHERE user_id = ?");
                    $stmt_inc->bind_param("ii", $attempts, $user['user_id']);
                    $stmt_inc->execute();
                    $error = $generic_login_error;
                }
            }
        }
    } else {
        // Generic message to prevent username enumeration
        $error = $generic_login_error;
    }
}

$page_title = "Admin Login | FairMedAlloc";
require_once 'includes/header.php';
?>


<div class="auth-container">

    <!-- ── Left: Brand Panel ── -->
    <div class="auth-left">
        <div class="brand-content">

            <img src="assets/logo.jpeg"
                 alt="Redeemer's University Logo"
                 style="width:72px;height:72px;border-radius:50%;object-fit:cover;margin-bottom:1.5rem;border:3px solid rgba(201,168,76,0.5);">

            <h1 class="auth-headline">System<br>Administration</h1>
            <p class="auth-subtitle">Redeemer's University</p>

            <div class="brand-border">
                <p>Secure access to the FairMedAlloc Management Console. Strictly for authorized personnel.</p>
                <ul class="auth-feature-list">
                    <li><i class="fa-solid fa-check"></i> System Management</li>
                    <li><i class="fa-solid fa-check"></i> Allocation Control</li>
                    <li><i class="fa-solid fa-check"></i> Reports &amp; Analytics</li>
                    <li><i class="fa-solid fa-check"></i> Data Import &amp; Settings</li>
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
                <span class="badge badge-primary mb-4" style="font-size:0.68rem;letter-spacing:0.1em;background:rgba(0,33,71,0.08);color:var(--c-primary);">
                    <i class="fa-solid fa-lock" style="font-size:0.6rem;"></i> ADMINISTRATOR PORTAL
                </span>
                <h2 style="font-size:1.75rem;margin-bottom:0.35rem;color:var(--c-text-head);">Admin Login</h2>
                <p class="text-muted" style="font-size:0.9rem;">Enter your administrative credentials to proceed.</p>
            </div>

            <!-- Error -->
            <?php if ($error): ?>
                <div class="alert alert-danger mb-4">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Admin Login Form -->
            <form method="post" id="admin-login-form">
                <?php csrf_field(); ?>

                <div class="form-group">
                    <label for="admin-username">Username</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fa-solid fa-user-shield"></i></span>
                        <input type="text"
                               id="admin-username"
                               name="username"
                               placeholder="admin"
                               required
                               class="input-auth"
                               style="padding-left:2.5rem;"
                               autocomplete="username">
                    </div>
                </div>

                <div class="form-group">
                    <label for="admin-password">Password</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fa-solid fa-lock"></i></span>
                        <input type="password"
                               id="admin-password"
                               name="password"
                               placeholder="Enter your password"
                               required
                               class="input-auth"
                               style="padding-left:2.5rem;padding-right:2.75rem;"
                               autocomplete="current-password">
                        <i class="fa-solid fa-eye text-muted"
                           id="toggleAdminPassword"
                           style="position:absolute;right:14px;top:50%;transform:translateY(-50%);cursor:pointer;font-size:0.85rem;"
                           onclick="toggleAdminPw()"
                           title="Toggle password visibility"></i>
                    </div>
                </div>

                <script>
                function toggleAdminPw() {
                    const pw   = document.getElementById('admin-password');
                    const icon = document.getElementById('toggleAdminPassword');
                    const isHidden = pw.type === 'password';
                    pw.type = isHidden ? 'text' : 'password';
                    icon.classList.toggle('fa-eye',       !isHidden);
                    icon.classList.toggle('fa-eye-slash',  isHidden);
                }
                </script>

                <button type="submit" class="btn btn-primary w-full mt-2" id="admin-login-submit" style="padding:0.8rem;">
                    <i class="fa-solid fa-right-to-bracket"></i> Sign In
                </button>

                <div class="text-center mt-4" style="font-size:0.84rem;">
                    <a href="forgot_password.php" class="text-muted">Forgot Password?</a>
                </div>

                <div class="text-center mt-6 pt-4 text-muted" style="border-top:1px solid var(--c-border);font-size:0.84rem;">
                    Student portal? <a href="login.php" class="text-primary fw-700">Student Login &rarr;</a>
                </div>
            </form>

        </div>
    </div>
</div>

</body>
</html>

