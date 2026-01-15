<?php
/**
 * Login Page
 * Handles user authentication.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

$error = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    check_csrf();

    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    // Auth Logic
    $stmt = $conn->prepare("SELECT user_id, username, password_hash, role FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res->num_rows === 1) {
        $user = $res->fetch_assoc();
        if (password_verify($password, $user['password_hash'])) {
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
        }
    }
    $error = "Invalid credentials provided.";
}

$page_title = "Login | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="auth-container">
    <!-- Left: Brand (Blue Gradient) -->
    <div class="auth-left text-center">
        <!-- Blobs for animation (Keeping subtle background) -->
        <div class="hero-blob" style="width: 400px; height: 400px; top: -100px; left: -100px; opacity: 0.2;"></div>
        
        <div class="brand-content relative z-10 animate-fade-in text-left pl-12">
            <h1 class="serif mb-2" style="font-size: 3.5rem; line-height: 1.1; color: white; font-weight: 700;">FairMedAlloc<br>System</h1>
            <p class="mb-12 text-sm text-gray-300 tracking-widest uppercase font-semibold">Redeemer's University</p>
            
            <div style="border-left: 4px solid var(--c-accent); padding-left: 1.5rem;">
                <p class="text-white text-lg font-light leading-relaxed">Prioritizing Student Health & Safety through Algorithmic Fairness.<br>A Final Year Computer Science Research Project.</p>
            </div>
        </div>
    </div>

    <div class="auth-right" style="background: white;">
        <div class="auth-box animate-fade-in">
            <div class="mb-8">
                <span class="badge badge-info mb-4" style="background: #eff6ff; color: #3b82f6; border-radius: 4px; padding: 4px 8px; font-size: 0.75rem; font-weight: 700;">UNIFIED PORTAL</span>
                <h2 class="mb-2 text-primary serif" style="font-size: 2.25rem;">Welcome Back</h2>
                <p class="text-muted text-lg">Enter your credentials to access the system.</p>
            </div>

            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="post">
                <?php csrf_field(); ?>
                
                <div class="form-group">
                    <label class="text-sm font-bold text-slate-700 mb-2">User ID (Matric No or Username)</label>
                    <input type="text" name="username" placeholder="admin" required style="background: #eff6ff; border-color: transparent; padding: 1rem;">
                </div>
                
                <div class="form-group">
                    <label class="text-sm font-bold text-slate-700 mb-2">Password</label>
                    <input type="password" name="password" placeholder="••••••••" required style="background: #eff6ff; border-color: transparent; padding: 1rem;">
                </div>
                
                <button class="btn btn-primary w-full mb-4">Sign In</button>
                
                <div class="flex justify-between text-sm mt-4">
                    <a href="signup.php" style="color: var(--c-primary); font-weight: 600;">New Student? Create Account</a>
                    <a href="forgot_password.php" class="text-muted">Forgot Password?</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>