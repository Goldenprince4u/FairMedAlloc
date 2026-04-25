<?php
/**
 * admin_signup.php — Administrator Registration
 * ================================================
 * Allows an existing admin to create a new admin account.
 * This page is PROTECTED: only a currently logged-in admin can access it.
 *
 * Security measures applied:
 *   - Session-based auth guard: redirects non-admins immediately.
 *   - CSRF token validation on every POST.
 *   - Prepared statements for all DB queries (prevents SQL injection).
 *   - Password hashing with PASSWORD_DEFAULT (bcrypt).
 *   - Server-side email format validation.
 *   - Minimum password length enforced server-side.
 */
session_start();

// --- Auth Guard ---
// Only an active admin session may create new admin accounts.
// This prevents unauthorized self-registration as an admin.
if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') { 
    header("Location: admin_login.php"); 
    exit(); 
}

require_once 'db_config.php';
require_once 'includes/security_helper.php';

$msg = "";
$msg_type = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // --- Security Gate: Validate CSRF Token ---
    check_csrf();

    // Sanitize all text inputs to prevent XSS.
    $username = sanitize_input($_POST['username']);
    $email    = sanitize_input($_POST['email']);
    $name     = sanitize_input($_POST['full_name']);
    $pass     = $_POST['password']; // Raw — will be hashed. Do not sanitize.
    $role     = 'admin';            // Role is hardcoded; never trust user input for role assignment.

    // --- Server-side Email Format Validation ---
    if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        $msg = "Please provide a valid email address.";
        $msg_type = "error";
    }
    // --- Duplicate Username Check ---
    elseif (($check = $conn->prepare("SELECT user_id FROM users WHERE username = ?")) &&
            $check->bind_param("s", $username) &&
            $check->execute() &&
            $check->get_result()->num_rows > 0) {
        $msg = "Username already exists.";
        $msg_type = "error";
    } elseif (strlen($pass) < 8) {
        // Server-side password length guard (client-side minlength is not sufficient).
        $msg = "Password must be at least 8 characters long.";
        $msg_type = "error";
    } else {
        // Hash the password securely before storing.
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        
        // --- 1. Create Admin User ---
        $stmt = $conn->prepare("INSERT INTO users (username, full_name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $username, $name, $email, $hash, $role);
        
        if ($stmt->execute()) {
            // FIX: Previously this code replaced the creating admin's session with the
            // new account's session — a silent session-hijack bug. Now the creator stays
            // logged in and receives a clear confirmation message instead.
            $msg      = "Admin account '{$username}' created successfully. They can now log in at admin_login.php.";
            $msg_type = "success";
        } else {
            // Generic error — do not expose DB error details to the client.
            $msg      = "Error creating account. Please try again.";
            $msg_type = "error";
        }
    }
}

$page_title = "Admin Registration | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="auth-container">
    <!-- Left: Brand Panel -->
    <div class="auth-left">
        <div class="brand-content">
            <h1 class="auth-headline">Staff<br>Onboarding</h1>
            <p class="auth-subtitle">Redeemer's University</p>

            <div class="brand-border">
                <p>Create an administrative account to access the FairMedAlloc Management Console.</p>
                <ul class="auth-feature-list">
                    <li><i class="fa-solid fa-check"></i> System Configuration</li>
                    <li><i class="fa-solid fa-check"></i> Trigger Allocation AI</li>
                    <li><i class="fa-solid fa-check"></i> Reporting &amp; Analytics</li>
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
                    <?php echo htmlspecialchars($msg); ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php csrf_field(); ?>

                <div class="form-group mb-4">
                    <label class="text-sm fw-700 mb-2">Full Name</label>
                    <input type="text" name="full_name" placeholder="John Doe" required class="input-auth">
                </div>

                <div class="form-group mb-4">
                    <label class="text-sm fw-700 mb-2">Username</label>
                    <input type="text" name="username" placeholder="admin_johndoe" required class="input-auth">
                </div>

                <div class="form-group mb-4">
                    <label class="text-sm fw-700 mb-2">Email Address</label>
                    <input type="email" name="email" required class="input-auth">
                </div>

                <div class="form-group mb-8">
                    <label class="text-sm fw-700 mb-2">Password</label>
                    <div class="input-group" style="position:relative;">
                        <input type="password" id="admin-signup-password" name="password"
                               placeholder="Create a strong password" required class="input-auth"
                               minlength="8" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}"
                               title="Must contain at least one number and one uppercase and lowercase letter, and at least 8 or more characters"
                               style="padding-right:2.75rem;">
                        <i class="fa-solid fa-eye"
                           id="toggleAdminSignupPassword"
                           style="position:absolute;right:14px;top:50%;transform:translateY(-50%);cursor:pointer;font-size:0.85rem;color:var(--c-text-muted);"
                           onclick="toggleAdminSignupPw()"
                           title="Toggle password visibility"></i>
                    </div>
                </div>

                <div class="text-center">
                    <button class="btn btn-warning text-dark w-full mb-4" style="color: var(--c-primary-dark);">Create Admin Account</button>
                </div>
                
                <div class="text-center text-sm pt-4 text-muted" style="border-top: 1px solid var(--c-border);">
                    Already an admin? <a href="admin_login.php" class="text-primary fw-700">Admin Login</a>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function toggleAdminSignupPw() {
    var input = document.getElementById('admin-signup-password');
    var icon  = document.getElementById('toggleAdminSignupPassword');
    if (!input) return;
    var isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    if (icon) {
        icon.classList.toggle('fa-eye',       !isHidden);
        icon.classList.toggle('fa-eye-slash',  isHidden);
    }
}
</script>
</body>
</html>
