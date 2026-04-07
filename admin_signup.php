<?php
/**
 * Admin Signup Page
 * New administrator registration.
 */
session_start();

// Auth Guard - Only admins can create new admins!
if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') { 
    header("Location: admin_login.php"); 
    exit(); 
}

require_once 'db_config.php';
require_once 'includes/security_helper.php';

$msg = "";
$msg_type = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    check_csrf(); // Security Gate

    $username = sanitize_input($_POST['username']);
    $email    = sanitize_input($_POST['email']);
    $name     = sanitize_input($_POST['full_name']);
    $pass     = $_POST['password'];
    $role     = 'admin';

    // Check Existence
    $check = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
    $check->bind_param("s", $username);
    $check->execute();
    
    if ($check->get_result()->num_rows > 0) {
        $msg = "Username already exists.";
        $msg_type = "error";
    } elseif (strlen($pass) < 8) {
        $msg = "Password must be at least 8 characters long.";
        $msg_type = "error";
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        
        // 1. Create User
        $stmt = $conn->prepare("INSERT INTO users (username, full_name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $username, $name, $email, $hash, $role);
        
        if ($stmt->execute()) {
            $new_id = $conn->insert_id;
            
            // Auto Login
            $_SESSION['logged_in'] = true;
            $_SESSION['user_id'] = $new_id;
            $_SESSION['role'] = $role;
            $_SESSION['username'] = $username;
            $_SESSION['profile_pic'] = 'default.png';

            header("Location: admin_dashboard.php");
            exit();
        } else {
            $msg = "Error creating account. Please try again.";
            $msg_type = "error";
        }
    }
}

$page_title = "Admin Registration | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="auth-container">
    <!-- Left: Brand (Blue Gradient) -->
    <div class="auth-left text-center">
        <!-- Blobs for animation -->
        <div class="hero-blob opacity-20 w-[400px] h-[400px] -top-24 -left-24 absolute rounded-full bg-white blur-3xl"></div>
        
        <div class="brand-content relative z-10 animate-fade-in text-left pl-12">
            <h1 class="serif mb-2 text-white font-bold leading-none text-6xl">Staff<br>Onboarding</h1>
            <p class="mb-12 text-sm text-gray-300 tracking-widest uppercase font-semibold">Redeemer's University</p>
            
            <div class="brand-border">
                <p class="text-white text-lg font-light leading-relaxed mb-6">Create an administrative account to access the FairMedAlloc Management Console.</p>
                <ul class="text-white text-sm font-light space-y-4 list-none">
                    <li class="flex items-center gap-3"><i class="fa-solid fa-check text-accent"></i> System Configuration</li>
                    <li class="flex items-center gap-3"><i class="fa-solid fa-check text-accent"></i> Trigger Allocation AI</li>
                    <li class="flex items-center gap-3"><i class="fa-solid fa-check text-accent"></i> Reporting & Analytics</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Right: Form -->
    <div class="auth-right">
        <div class="auth-box animate-fade-in max-w-[500px]">
            <div class="mb-8">
                <span class="badge badge-warning mb-4"><i class="fa-solid fa-user-tie mr-2"></i>STAFF ACCOUNT</span>
                <h2 class="mb-2 text-primary serif text-4xl">Admin Setup</h2>
                <p class="text-muted text-lg">Enter your details to register as an administrator.</p>
            </div>

            <?php if($msg): ?>
                <div class="alert <?php echo $msg_type == 'error' ? 'alert-danger' : 'alert-success'; ?>">
                    <?php echo $msg; ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php csrf_field(); ?>

                <div class="form-group mb-4">
                    <label class="text-sm font-bold text-slate-700 mb-2">Full Name</label>
                    <input type="text" name="full_name" placeholder="John Doe" required class="input-auth">
                </div>

                <div class="form-group mb-4">
                    <label class="text-sm font-bold text-slate-700 mb-2">Username</label>
                    <input type="text" name="username" placeholder="admin_johndoe" required class="input-auth">
                </div>

                <div class="form-group mb-4">
                    <label class="text-sm font-bold text-slate-700 mb-2">Email Address</label>
                    <input type="email" name="email" required class="input-auth">
                </div>

                <div class="form-group mb-8">
                    <label class="text-sm font-bold text-slate-700 mb-2">Password</label>
                    <input type="password" name="password" placeholder="Create a strong password" required class="input-auth" minlength="8" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" title="Must contain at least one number and one uppercase and lowercase letter, and at least 8 or more characters">
                </div>

                <div class="text-center">
                    <button class="btn btn-warning text-slate-800 w-full mb-4">Create Admin Account</button>
                </div>
                
                <div class="text-center text-sm pt-4 border-t border-slate-200 text-muted">
                    Already an admin? <a href="admin_login.php" class="text-primary fw-700">Admin Login</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
