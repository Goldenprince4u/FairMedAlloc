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
        $sql = FAIRMED_SUPPORTS_MUST_CHANGE_PASSWORD
            ? "INSERT INTO users (username, full_name, email, password_hash, must_change_password, role) VALUES (?, ?, ?, ?, 1, ?)"
            : "INSERT INTO users (username, full_name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssss", $username, $name, $email, $hash, $role);
        
        if ($stmt->execute()) {
            // FIX: Previously this code replaced the creating admin's session with the
            // new account's session — a silent session-hijack bug. Now the creator stays
            // logged in and receives a clear confirmation message instead.
            $msg      = "Admin account '{$username}' created successfully. They can now log in at admin_login.php and will be required to change the temporary password after sign-in.";
            $msg_type = "success";
        } else {
            // Generic error — do not expose DB error details to the client.
            $msg      = "Error creating account. Please try again.";
            $msg_type = "error";
        }
    }
}

$page_title = "Create Admin | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div class="page-header-info">
                <h1>Create Admin</h1>
                <p class="text-muted">Create additional administrator accounts from an active admin session.</p>
            </div>
            <a href="admin_dashboard.php" class="btn btn-outline">
                <i class="fa-solid fa-arrow-left"></i> Dashboard
            </a>
        </div>

        <?php if($msg): ?>
            <div class="alert alert-<?php echo $msg_type === 'error' ? 'danger' : 'success'; ?> mb-6">
                <i class="fa-solid <?php echo $msg_type === 'error' ? 'fa-circle-exclamation' : 'fa-check-circle'; ?>"></i>
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <div class="grid" style="grid-template-columns:minmax(0, 560px) minmax(260px, 320px);gap:1.5rem;align-items:start;">
            <div class="card" style="padding:2rem;">
                <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.5rem;">
                    <span class="badge badge-warning"><i class="fa-solid fa-user-shield"></i> INTERNAL ACCESS</span>
                </div>

                <form method="post">
                    <?php csrf_field(); ?>

                    <div class="form-group mb-4">
                        <label for="admin-full-name">Full Name</label>
                        <input type="text" id="admin-full-name" name="full_name" placeholder="John Doe" required class="input-auth">
                    </div>

                    <div class="form-group mb-4">
                        <label for="admin-username">Username</label>
                        <input type="text" id="admin-username" name="username" placeholder="admin_johndoe" required class="input-auth">
                    </div>

                    <div class="form-group mb-4">
                        <label for="admin-email">Email Address</label>
                        <input type="email" id="admin-email" name="email" placeholder="johndoe@fairmed.edu.ng" required class="input-auth">
                    </div>

                    <div class="form-group mb-6">
                        <label for="admin-signup-password">Temporary Password</label>
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
                        <div class="text-xs text-muted mt-2">Share this password securely and ask the new admin to change it after first sign-in.</div>
                    </div>

                    <div style="display:flex;justify-content:flex-end;">
                        <button class="btn btn-primary">
                            <i class="fa-solid fa-user-plus"></i> Create Admin Account
                        </button>
                    </div>
                </form>
            </div>

            <div class="card" style="padding:1.75rem;">
                <h3 style="font-size:1rem;margin-bottom:1rem;">Access Notes</h3>
                <div style="display:flex;flex-direction:column;gap:0.875rem;">
                    <div class="text-sm text-body">
                        <strong class="text-head">Session-only action.</strong><br>
                        This page is available only to a logged-in admin.
                    </div>
                    <div class="text-sm text-body">
                        <strong class="text-head">No session switch.</strong><br>
                        Creating a new admin does not log out the current admin or replace the active session.
                    </div>
                    <div class="text-sm text-body">
                        <strong class="text-head">Shared responsibility.</strong><br>
                        New admins can access allocation, reports, imports, and settings immediately after signing in.
                    </div>
                </div>
            </div>
        </div>
    </main>
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
