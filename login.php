<?php
/**
 * Login Page
 * Handles user authentication.
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
    
    // Auth Logic
    $stmt = $conn->prepare("SELECT user_id, username, password_hash, role, login_attempts, lock_until FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res->num_rows === 1) {
        $user = $res->fetch_assoc();

        // 1. Check Lockout
        if ($user['lock_until'] && strtotime($user['lock_until']) > time()) {
            $remaining = ceil((strtotime($user['lock_until']) - time()) / 60);
            $error = "Account locked due to too many failed attempts. Try again in $remaining minutes.";
        } else {
            // 2. Verify Password
            if (password_verify($password, $user['password_hash'])) {
                // Success: Reset Attempts
                $conn->query("UPDATE users SET login_attempts = 0, lock_until = NULL WHERE user_id = " . $user['user_id']);

                $_SESSION['logged_in'] = true;
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['username'] = $user['username'];
                
                // Profile Pic
                $pid = $user['user_id'];
                $pic = $conn->query("SELECT profile_pic FROM users WHERE user_id=$pid")->fetch_assoc();
                $_SESSION['profile_pic'] = $pic['profile_pic'] ?? 'default.png';

                header("Location: " . ($user['role'] === 'admin' ? 'admin_dashboard.php' : 'student_dashboard.php'));
                exit();
            } else {
                // Failure: Increment Attempts
                $attempts = $user['login_attempts'] + 1;
                
                if ($attempts >= 5) {
                    $conn->query("UPDATE users SET login_attempts = $attempts, lock_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE user_id = " . $user['user_id']);
                    $error = "Too many failed attempts. Account locked for 15 minutes.";
                } else {
                    $conn->query("UPDATE users SET login_attempts = $attempts WHERE user_id = " . $user['user_id']);
                    $error = "Invalid credentials provided. ($attempts/5 attempts)";
                }
            }
        }
    } else {
        $error = "Invalid credentials provided.";
    }
}

$page_title = "Login | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="auth-container">
    <!-- Left: Brand (Blue Gradient) -->
    <div class="auth-left text-center">
        <!-- Blobs for animation -->
        <div class="hero-blob opacity-20 w-[400px] h-[400px] -top-24 -left-24 absolute rounded-full bg-white blur-3xl"></div>
        
        <div class="brand-content relative z-10 animate-fade-in text-left pl-12">
            <h1 class="serif mb-2 text-white font-bold leading-none text-6xl">FairMedAlloc<br>System</h1>
            <p class="mb-12 text-sm text-gray-300 tracking-widest uppercase font-semibold">Redeemer's University</p>
            
            <div class="brand-border">
                <p class="text-white text-lg font-light leading-relaxed">Prioritizing Student Health & Safety through Algorithmic Fairness.<br>A Final Year Computer Science Research Project.</p>
            </div>
        </div>
    </div>

    <div class="auth-right">
        <div class="auth-box animate-fade-in">
            <div class="mb-8">
                <span class="badge badge-info mb-4">UNIFIED PORTAL</span>
                <h2 class="mb-2 text-primary serif text-4xl">Welcome Back</h2>
                <p class="text-muted text-lg">Enter your credentials to access the system.</p>
            </div>

            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="post">
                <?php csrf_field(); ?>
                
                <div class="form-group">
                    <label class="text-sm font-bold text-slate-700 mb-2">User ID (Matric No or Username)</label>
                    <input type="text" name="username" placeholder="admin" required class="input-auth">
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
            </form>
        </div>
    </div>
</div>
</body>
</html>