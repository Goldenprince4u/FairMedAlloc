<?php
/**
 * create_admin.php
 * =================
 * Local-only bootstrap page for creating the very first administrator account.
 * This route is intentionally disabled once any admin already exists.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

$allowed_ips = ['127.0.0.1', '::1'];
$remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';

if (!in_array($remote_ip, $allowed_ips, true)) {
    http_response_code(403);
    die('Forbidden');
}

$admin_count_res = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'admin'");
$admin_count = (int)($admin_count_res->fetch_assoc()['total'] ?? 0);

if ($admin_count > 0) {
    header('Location: admin_login.php');
    exit();
}

$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($username === '' || $full_name === '' || $email === '') {
        $msg = 'All fields are required.';
        $msg_type = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = 'Please provide a valid email address.';
        $msg_type = 'error';
    } elseif ($password !== $confirm) {
        $msg = 'Passwords do not match.';
        $msg_type = 'error';
    } elseif (
        strlen($password) < 8 ||
        !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[a-z]/', $password) ||
        !preg_match('/[0-9]/', $password)
    ) {
        $msg = 'Password must be 8+ characters and include uppercase, lowercase, and a number.';
        $msg_type = 'error';
    } else {
        $check = $conn->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
        $check->bind_param("s", $username);
        $check->execute();

        if ($check->get_result()->num_rows > 0) {
            $msg = 'That username is already in use.';
            $msg_type = 'error';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, full_name, email, password_hash, role) VALUES (?, ?, ?, ?, 'admin')");
            $stmt->bind_param("ssss", $username, $full_name, $email, $hash);

            if ($stmt->execute()) {
                $msg = 'Administrator account created successfully. You can now sign in.';
                $msg_type = 'success';
            } else {
                $msg = 'Unable to create the administrator account right now.';
                $msg_type = 'error';
            }
        }
    }
}

$page_title = "Create Initial Admin | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="auth-container">
    <div class="auth-left">
        <div class="brand-content">
            <h1 class="auth-headline">Initial<br>Administrator</h1>
            <p class="auth-subtitle">Local Bootstrap Only</p>

            <div class="brand-border">
                <p>This page is available only on the local machine and only until the first admin account has been created.</p>
                <ul class="auth-feature-list">
                    <li><i class="fa-solid fa-check"></i> Loopback-only access</li>
                    <li><i class="fa-solid fa-check"></i> Single-use bootstrap</li>
                    <li><i class="fa-solid fa-check"></i> Password complexity enforced</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="auth-right">
        <div class="auth-box animate-fade-in">
            <div class="mb-8">
                <span class="badge badge-primary mb-4" style="font-size:0.68rem;letter-spacing:0.1em;">BOOTSTRAP</span>
                <h2 style="font-size:1.75rem;margin-bottom:0.35rem;color:var(--c-text-head);">Create First Admin</h2>
                <p class="text-muted" style="font-size:0.9rem;">Set up the first administrator account for this installation.</p>
            </div>

            <?php if ($msg): ?>
                <div class="alert <?php echo $msg_type === 'success' ? 'alert-success' : 'alert-danger'; ?> mb-4">
                    <?php echo htmlspecialchars($msg); ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php csrf_field(); ?>

                <div class="form-group mb-4">
                    <label>Full Name</label>
                    <input type="text" name="full_name" required class="input-auth">
                </div>

                <div class="form-group mb-4">
                    <label>Username</label>
                    <input type="text" name="username" required class="input-auth" autocomplete="username">
                </div>

                <div class="form-group mb-4">
                    <label>Email Address</label>
                    <input type="email" name="email" required class="input-auth" autocomplete="email">
                </div>

                <div class="form-group mb-4">
                    <label>Password</label>
                    <input type="password" name="password" required class="input-auth" autocomplete="new-password">
                </div>

                <div class="form-group mb-6">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" required class="input-auth" autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-primary w-full">
                    <i class="fa-solid fa-user-shield"></i> Create Administrator
                </button>

                <?php if ($msg_type === 'success'): ?>
                    <div class="text-center mt-4" style="font-size:0.84rem;">
                        <a href="admin_login.php" class="text-primary fw-700">Continue to Admin Login &rarr;</a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>

</body>
</html>
