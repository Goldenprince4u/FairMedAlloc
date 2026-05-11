<?php
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $generic_login_error = 'Unable to sign in right now. Check your credentials or try again later.';
    $must_change_select = FAIRMED_SUPPORTS_MUST_CHANGE_PASSWORD ? ', must_change_password' : ', 0 AS must_change_password';

    $lookupSql = "SELECT user_id, username, password_hash, role, login_attempts, lock_until, profile_pic, full_name{$must_change_select} FROM users WHERE username = ?";
    $stmt = DbHelper::prepare($conn, $lookupSql, 'admin login lookup');

    if (!$stmt) {
        $error = 'Sign-in is temporarily unavailable. Please try again shortly.';
    } else {
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 1) {
            $user = $res->fetch_assoc();

            if ($user['lock_until'] && strtotime($user['lock_until']) > time()) {
                $error = $generic_login_error;
            } elseif (!password_verify($password, $user['password_hash'])) {
                $attempts = (int)$user['login_attempts'] + 1;

                if ($attempts >= 5) {
                    $lockStmt = DbHelper::prepare(
                        $conn,
                        'UPDATE users SET login_attempts = ?, lock_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE user_id = ?',
                        'admin login lockout'
                    );
                    if ($lockStmt) {
                        $lockStmt->bind_param('ii', $attempts, $user['user_id']);
                        $lockStmt->execute();
                        $lockStmt->close();
                    }

                    if (($user['role'] ?? '') === 'admin') {
                        log_admin_action($conn, (int)$user['user_id'], 'Admin Account Locked Out (5 Failed Attempts)');
                    }
                } else {
                    $incrementStmt = DbHelper::prepare(
                        $conn,
                        'UPDATE users SET login_attempts = ? WHERE user_id = ?',
                        'admin login attempt increment'
                    );
                    if ($incrementStmt) {
                        $incrementStmt->bind_param('ii', $attempts, $user['user_id']);
                        $incrementStmt->execute();
                        $incrementStmt->close();
                    }
                }

                $error = $generic_login_error;
            } elseif (($user['role'] ?? '') !== 'admin') {
                $error = $generic_login_error;
            } else {
                $resetStmt = DbHelper::prepare(
                    $conn,
                    'UPDATE users SET login_attempts = 0, lock_until = NULL, last_login = NOW() WHERE user_id = ?',
                    'admin login success reset'
                );
                if ($resetStmt) {
                    $resetStmt->bind_param('i', $user['user_id']);
                    $resetStmt->execute();
                    $resetStmt->close();
                }

                session_regenerate_id(true);
                $_SESSION['logged_in'] = true;
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['profile_pic'] = $user['profile_pic'] ?? 'default.png';
                $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
                $_SESSION['must_change_password'] = !empty($user['must_change_password']);

                log_admin_action($conn, (int)$user['user_id'], 'Successful Admin Login');

                $target = $_SESSION['must_change_password'] ? 'change_password.php?required=1' : 'admin_dashboard.php';
                header("Location: {$target}");
                exit();
            }
        } else {
            $error = $generic_login_error;
        }

        $stmt->close();
    }
}

$page_title = 'Admin Login | FairMedAlloc';
require_once 'includes/header.php';
?>
<style>
    /* Mobile-first responsive overrides for admin login */
    @media (max-width: 768px) {
        .input-auth {
            font-size: 1rem !important;
            min-height: 48px;
        }
        .input-group {
            position: relative;
        }
        .input-icon {
            font-size: 1rem;
            left: 12px;
        }
        #togglePassword {
            right: 12px !important;
            font-size: 1rem !important;
        }
        .auth-headline {
            font-size: clamp(1.25rem, 5vw, 1.75rem) !important;
        }
        .auth-subtitle {
            font-size: 0.9rem !important;
        }
    }
</style>

<div class="auth-container">
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

    <div class="auth-right">
        <div class="auth-box animate-fade-in">
            <div class="mb-8">
                <span class="badge badge-primary mb-4" style="font-size:0.68rem;letter-spacing:0.1em;background:rgba(0,33,71,0.08);color:var(--c-primary);">
                    <i class="fa-solid fa-lock" style="font-size:0.6rem;"></i> ADMINISTRATOR PORTAL
                </span>
                <h2 style="font-size:1.75rem;margin-bottom:0.35rem;color:var(--c-text-head);">Admin Login</h2>
                <p class="text-muted" style="font-size:0.9rem;">Enter your administrative credentials to proceed.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger mb-4">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

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
                    const pw = document.getElementById('admin-password');
                    const icon = document.getElementById('toggleAdminPassword');
                    const isHidden = pw.type === 'password';

                    pw.type = isHidden ? 'text' : 'password';
                    icon.classList.toggle('fa-eye', !isHidden);
                    icon.classList.toggle('fa-eye-slash', isHidden);
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
