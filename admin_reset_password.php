<?php
session_start();

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

require_once 'db_config.php';
require_once 'includes/security_helper.php';

$msg = '';
$msg_type = '';
$issued_password = '';
$target_account = '';

function generate_temporary_password(int $length = 12): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $max_index = strlen($alphabet) - 1;
    $password = '';

    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max_index)];
    }

    return $password;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $username = sanitize_input($_POST['username'] ?? '');
    $provided_password = trim($_POST['temporary_password'] ?? '');

    if ($username === '') {
        $msg = 'Enter the account username or matric number.';
        $msg_type = 'error';
    } else {
        $lookup = DbHelper::prepare(
            $conn,
            "SELECT user_id, username, full_name, role FROM users WHERE username = ? LIMIT 1",
            'admin reset lookup'
        );

        if (!$lookup) {
            $msg = 'Password reset is temporarily unavailable. Please try again shortly.';
            $msg_type = 'error';
        } else {
            $lookup->bind_param("s", $username);
            $lookup->execute();
            $user = $lookup->get_result()->fetch_assoc();
            $lookup->close();

            if (!$user) {
                $msg = 'No account matched that username or matric number.';
                $msg_type = 'error';
            } else {
                $issued_password = $provided_password !== '' ? $provided_password : generate_temporary_password();

                if (strlen($issued_password) < 8) {
                    $msg = 'Temporary password must be at least 8 characters long.';
                    $msg_type = 'error';
                } else {
                    $password_hash = password_hash($issued_password, PASSWORD_DEFAULT);
                    $target_account = $user['username'];
                    $sql = FAIRMED_SUPPORTS_MUST_CHANGE_PASSWORD
                        ? "UPDATE users SET password_hash = ?, must_change_password = 1, login_attempts = 0, lock_until = NULL WHERE user_id = ?"
                        : "UPDATE users SET password_hash = ?, login_attempts = 0, lock_until = NULL WHERE user_id = ?";

                    $conn->begin_transaction();

                    try {
                        $reset_stmt = DbHelper::prepare($conn, $sql, 'admin reset update');
                        if (!$reset_stmt) {
                            throw new RuntimeException('Unable to prepare the password reset.');
                        }
                        $reset_stmt->bind_param("si", $password_hash, $user['user_id']);
                        $reset_stmt->execute();
                        $reset_stmt->close();

                        $delete_tokens = DbHelper::prepare($conn, "DELETE FROM password_resets WHERE user_id = ?", 'admin reset delete tokens');
                        if (!$delete_tokens) {
                            throw new RuntimeException('Unable to clear existing reset tokens.');
                        }
                        $delete_tokens->bind_param("i", $user['user_id']);
                        $delete_tokens->execute();
                        $delete_tokens->close();

                        log_admin_action(
                            $conn,
                            (int)$_SESSION['user_id'],
                            "Issued temporary password reset for {$user['role']} account {$user['username']}"
                        );

                        $conn->commit();

                        $msg = "Temporary password issued for {$user['full_name']} ({$user['username']}). Share it securely; the user will be required to change it after signing in.";
                        $msg_type = 'success';
                    } catch (Throwable $e) {
                        $conn->rollback();
                        error_log('[FairMedAlloc] Admin reset password failed: ' . $e->getMessage());
                        $issued_password = '';
                        $target_account = '';
                        $msg = 'Unable to reset that account right now. Please try again.';
                        $msg_type = 'error';
                    }
                }
            }
        }
    }
}

$page_title = "Reset User Password | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div class="page-header-info">
                <h1>Reset User Password</h1>
                <p class="text-muted">Temporary admin-issued reset for student or admin accounts.</p>
            </div>
            <a href="admin_dashboard.php" class="btn btn-outline">
                <i class="fa-solid fa-arrow-left"></i> Dashboard
            </a>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-<?php echo $msg_type === 'error' ? 'danger' : 'success'; ?> mb-6">
                <i class="fa-solid <?php echo $msg_type === 'error' ? 'fa-circle-exclamation' : 'fa-key'; ?>"></i>
                <?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <div class="mobile-form-grid">
            <div class="card mobile-form-card">
                <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.5rem;">
                    <span class="badge badge-warning"><i class="fa-solid fa-key"></i> ADMIN RESET</span>
                </div>

                <form method="post">
                    <?php csrf_field(); ?>

                    <div class="form-group mb-4">
                        <label for="reset-username">Username / Matric Number</label>
                        <input
                            type="text"
                            id="reset-username"
                            name="username"
                            class="input-auth"
                            placeholder="RUN/CMP/22/001 or admin_username"
                            value="<?php echo htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            required
                        >
                    </div>

                    <div class="form-group mb-6">
                        <label for="reset-temp-password">Temporary Password</label>
                        <input
                            type="text"
                            id="reset-temp-password"
                            name="temporary_password"
                            class="input-auth"
                            placeholder="Leave blank to auto-generate"
                            value=""
                        >
                        <div class="text-xs text-muted mt-2">Leave blank to generate a secure temporary password automatically.</div>
                    </div>

                    <div style="display:flex;justify-content:flex-end;">
                        <button class="btn btn-primary">
                            <i class="fa-solid fa-key"></i> Issue Temporary Password
                        </button>
                    </div>
                </form>
            </div>

            <div style="display:flex;flex-direction:column;gap:1rem;">
                <div class="card mobile-side-card">
                    <h3 style="font-size:1rem;margin-bottom:1rem;">How this works</h3>
                    <div style="display:flex;flex-direction:column;gap:0.875rem;">
                        <div class="text-sm text-body">
                            <strong class="text-head">Temporary replacement.</strong><br>
                            This page stands in for the unfinished email-reset flow.
                        </div>
                        <div class="text-sm text-body">
                            <strong class="text-head">Safer recovery.</strong><br>
                            Lockout counters are cleared and any old reset tokens are removed.
                        </div>
                        <div class="text-sm text-body">
                            <strong class="text-head">Share once.</strong><br>
                            Give the password directly to the account owner and ask them to change it after sign-in.
                        </div>
                    </div>
                </div>

                <?php if ($issued_password && $target_account): ?>
                    <div class="card mobile-side-card" style="border:1px solid rgba(37,99,235,0.2);">
                        <h3 style="font-size:1rem;margin-bottom:0.75rem;">Issued Password</h3>
                        <div class="text-xs text-muted mb-2">Account</div>
                        <div class="fw-700 text-head mb-4"><?php echo htmlspecialchars($target_account, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="text-xs text-muted mb-2">Temporary Password</div>
                        <div style="font-family:var(--font-mono, monospace);font-size:1.05rem;font-weight:700;padding:0.875rem 1rem;border-radius:10px;background:var(--c-bg-subtle);border:1px dashed var(--c-border);">
                            <?php echo htmlspecialchars($issued_password, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

</body>
</html>
