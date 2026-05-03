<?php
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'student';
$is_required = isset($_GET['required']) && $_GET['required'] === '1';

if ($role !== 'admin' && !$is_required) {
    header("Location: student_dashboard.php");
    exit();
}

$message = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $current_pass = $_POST['current_pass'] ?? '';
    $new_pass = $_POST['new_pass'] ?? '';
    $confirm_pass = $_POST['confirm_pass'] ?? '';

    $stmt = DbHelper::prepare($conn, "SELECT password_hash FROM users WHERE user_id = ?", 'change password lookup');
    if (!$stmt) {
        $message = "Password update is temporarily unavailable. Please try again shortly.";
        $msg_type = 'error';
    } else {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user_row || !password_verify($current_pass, $user_row['password_hash'])) {
            $message = "Current password is incorrect.";
            $msg_type = 'error';
        } elseif ($new_pass !== $confirm_pass) {
            $message = "New passwords do not match.";
            $msg_type = 'error';
        } elseif (
            strlen($new_pass) < 8 ||
            !preg_match('/[A-Z]/', $new_pass) ||
            !preg_match('/[a-z]/', $new_pass) ||
            !preg_match('/[0-9]/', $new_pass)
        ) {
            $message = "Password must be 8+ characters and include uppercase, lowercase, and a number.";
            $msg_type = 'error';
        } else {
            $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $sql = FAIRMED_SUPPORTS_MUST_CHANGE_PASSWORD
                ? "UPDATE users SET password_hash = ?, must_change_password = 0, login_attempts = 0, lock_until = NULL WHERE user_id = ?"
                : "UPDATE users SET password_hash = ?, login_attempts = 0, lock_until = NULL WHERE user_id = ?";
            $update = DbHelper::prepare($conn, $sql, 'change password update');

            if (!$update) {
                $message = "Password update is temporarily unavailable. Please try again shortly.";
                $msg_type = 'error';
            } else {
                $update->bind_param("si", $new_hash, $user_id);

                if ($update->execute()) {
                    $update->close();
                    $_SESSION['must_change_password'] = false;

                    $delete_tokens = DbHelper::prepare($conn, "DELETE FROM password_resets WHERE user_id = ?", 'change password delete tokens');
                    if ($delete_tokens) {
                        $delete_tokens->bind_param("i", $user_id);
                        $delete_tokens->execute();
                        $delete_tokens->close();
                    }

                    $target = $role === 'admin' ? 'admin_dashboard.php' : 'student_dashboard.php';
                    header("Location: {$target}?password_changed=1");
                    exit();
                }

                $update->close();
                $message = "Unable to update password right now. Please try again.";
                $msg_type = 'error';
            }
        }
    }
}

$page_title = "Change Password | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="auth-container">
    <div class="auth-left">
        <div class="brand-content">
            <i class="fa-solid fa-key text-4xl text-accent mb-6" style="font-size: 4rem; color: var(--c-accent); margin-bottom: 1.5rem;"></i>
            <h1 style="font-size: 2.5rem; line-height: 1.1; margin-bottom: 1rem; font-weight: 700;">Update Password</h1>
            <p style="font-size: 1.1rem; opacity: 0.9; font-weight: 400;">Use a strong password you will remember and keep private.</p>
        </div>
    </div>

    <div class="auth-right">
        <div class="auth-box">
            <h2 class="mb-2" style="font-size: 2rem; color: var(--c-primary);">Change Password</h2>
            <p class="text-muted mb-6">
                <?php echo $is_required ? 'Your current password is temporary. You must change it before continuing.' : 'Update your account password below.'; ?>
            </p>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $msg_type === 'error' ? 'danger' : 'success'; ?> mb-4 text-center">
                    <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <?php if ($is_required): ?>
                <div class="alert alert-warning mb-4">
                    <i class="fa-solid fa-shield-halved"></i>
                    Temporary credentials are active on this account. Choose a new password to continue.
                </div>
            <?php endif; ?>

            <form method="post">
                <?php csrf_field(); ?>

                <div class="form-group">
                    <label for="change-current-pass">Current Password</label>
                    <input type="password" id="change-current-pass" name="current_pass" required class="input w-full" autocomplete="current-password">
                </div>

                <div class="form-group">
                    <label for="change-new-pass">New Password</label>
                    <input type="password" id="change-new-pass" name="new_pass" required class="input w-full" autocomplete="new-password">
                </div>

                <div class="form-group mb-4">
                    <label for="change-confirm-pass">Confirm New Password</label>
                    <input type="password" id="change-confirm-pass" name="confirm_pass" required class="input w-full" autocomplete="new-password">
                </div>

                <div class="alert alert-info mb-6">
                    <i class="fa-solid fa-circle-info"></i>
                    8+ characters, including at least one uppercase letter, one lowercase letter, and one number.
                </div>

                <button class="btn btn-primary w-full" style="width: 100%;">
                    <i class="fa-solid fa-floppy-disk"></i> Save New Password
                </button>
            </form>

            <?php if (!$is_required): ?>
                <div class="mt-6 text-center">
                    <a href="<?php echo $role === 'admin' ? 'admin_dashboard.php' : 'student_dashboard.php'; ?>" class="text-muted">Back to Dashboard</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
