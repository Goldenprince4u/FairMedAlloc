<?php
/**
 * admin_profile.php â€” Administrator Profile & Security Settings
 * ===============================================================
 * Allows the currently logged-in admin to:
 *   1. Update their profile picture (validated for type and MIME, not just extension).
 *   2. Change their password (requires current password verification + complexity check).
 *
 * Security measures applied:
 *   - Session-based admin auth guard.
 *   - CSRF token validation on every POST.
 *   - File type validated by extension AND MIME (prevents disguised executable uploads).
 *   - Password change requires proof of current password.
 *   - New password enforces complexity: 8+ chars, upper, lower, digit.
 *   - All output escaped with htmlspecialchars().
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

// --- Auth Guard: Admin Only ---
if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') { header("Location: admin_login.php"); exit(); }
$user_id = $_SESSION['user_id'];

$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    // === SECTION 1: Profile Picture Upload ===
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === 0) {
        $allowed_ext   = ['jpg', 'jpeg', 'png', 'gif'];
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif'];
        $ext  = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_ext)) {
            $msg      = "Invalid file type. Only JPG, PNG, GIF allowed.";
            $msg_type = "error";
        } elseif ($_FILES['profile_pic']['size'] > 2 * 1024 * 1024) {
            // Cap at 2MB â€” prevents storing oversized images in the uploads directory
            $msg      = "Image too large. Maximum file size is 2MB.";
            $msg_type = "error";
        } else {
            // Validate actual MIME type, not just the extension.
            // Uses OOP finfo class (PHP 8.5+): auto-closes on scope exit â€” no finfo_close() needed.
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($_FILES['profile_pic']['tmp_name']);

            if (!in_array($mime, $allowed_mimes)) {
                $msg      = "Invalid image content. Allowed: JPEG, PNG, GIF.";
                $msg_type = "error";
            } else {
                // Create upload directory if it doesn't exist
                $upload_dir = __DIR__ . '/uploads/profile_pics/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                // Unique filename prevents overwriting and path collisions
                $new_name = "admin_{$user_id}_" . time() . ".$ext";
                $dest     = $upload_dir . $new_name;

                if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $dest)) {
                    $pic_stmt = $conn->prepare("UPDATE users SET profile_pic = ? WHERE user_id = ?");
                    $pic_stmt->bind_param("si", $new_name, $user_id);
                    $pic_stmt->execute();
                    // Update session immediately so the navbar avatar reflects the change
                    $_SESSION['profile_pic'] = $new_name;
                    $msg      = "Profile photo updated successfully.";
                    $msg_type = "success";
                } else {
                    $msg      = "Upload failed: could not move the file to the destination.";
                    $msg_type = "error";
                }
            }
        }
    }

    // === SECTION 2: Password Update ===
    if (!empty($_POST['new_pass'])) {
        $current = $_POST['current_pass'];
        $new     = $_POST['new_pass'];
        $confirm = $_POST['confirm_pass'];

        // Fetch the current hash to verify the old password
        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();

        if (!password_verify($current, $res['password_hash'])) {
            $msg = "Current password is incorrect.";
            $msg_type = "error";
        } elseif ($new !== $confirm) {
            $msg = "New passwords do not match.";
            $msg_type = "error";
        } elseif (
            strlen($new) < 8 ||
            !preg_match('/[A-Z]/', $new) ||
            !preg_match('/[a-z]/', $new) ||
            !preg_match('/[0-9]/', $new)
        ) {
            // Enforce complexity: 8+ chars, at least one uppercase, lowercase, and digit
            $msg = "New password must be at least 8 characters and include uppercase, lowercase, and numbers.";
            $msg_type = "error";
        } else {
            $new_hash = password_hash($new, PASSWORD_DEFAULT);
            $update   = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
            $update->bind_param("si", $new_hash, $user_id);
            if ($update->execute()) {
                $msg = "Password updated successfully.";
                $msg_type = "success";
            } else {
                $msg = "Database error updating password.";
                $msg_type = "error";
            }
        }
    }
}

$page_title = "Admin Profile | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="serif mb-1 text-3xl">Admin Profile</h1>
                <p class="text-muted">Manage your administrator credentials and security.</p>
            </div>
        </div>

        <?php if($msg): ?>
            <!-- SECURITY: htmlspecialchars() prevents XSS in $msg -->
            <div class="mb-6 p-4 rounded <?php echo $msg_type == 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <div class="grid-profile">
            
            <!-- Profile Card -->
            <div class="card text-center p-8">
                <div class="relative inline-block mb-6 group">
                    <img src="uploads/profile_pics/<?php echo htmlspecialchars(basename($_SESSION['profile_pic'] ?? 'default.png')); ?>" 
                         class="avatar-lg bg-white">
                    
                    <form method="post" enctype="multipart/form-data" id="picForm">
                        <?php csrf_field(); ?>
                        <label class="absolute bottom-0 right-0 bg-primary text-white p-2 rounded-full cursor-pointer hover:bg-blue-800 transition-colors shadow-md w-9 h-9 flex items-center justify-center" title="Change Photo">
                            <i class="fa-solid fa-camera"></i>
                            <input type="file" name="profile_pic" class="hidden" onchange="document.getElementById('picForm').submit()">
                        </label>
                    </form>
                </div>
                
                <h2 class="serif text-2xl mb-2"><?php echo htmlspecialchars($_SESSION['username']); ?></h2>
                <div class="badge badge-info mb-6">Administrator</div>
                
                <div class="text-left border-t border-gray-100 pt-6">
                    <div class="text-sm text-muted mb-2 uppercase tracking-wider font-bold">Role Capabilities</div>
                    <ul class="text-sm" style="list-style: none;">
                        <li class="flex items-center gap-2 mb-2"><i class="fa-solid fa-check text-green-500" style="color: var(--c-success);"></i> Full System Access</li>
                        <li class="flex items-center gap-2 mb-2"><i class="fa-solid fa-check text-green-500" style="color: var(--c-success);"></i> User Management</li>
                        <li class="flex items-center gap-2"><i class="fa-solid fa-check text-green-500" style="color: var(--c-success);"></i> Allocation Control</li>
                    </ul>
                </div>
            </div>

            <!-- Security Settings -->
            <div class="card p-8">
                <div class="flex items-center gap-4 mb-8 pb-4 border-b border-gray-100">
                    <div class="w-12 h-12 rounded-full flex items-center justify-center text-primary text-xl bg-blue-50">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div>
                        <h3 class="serif text-xl mb-1">Security Settings</h3>
                        <p class="text-sm text-muted">Update your password to keep the account secure.</p>
                    </div>
                </div>

                <form method="post" class="max-w-[600px]">
                    <?php csrf_field(); ?>
                    <div class="form-group mb-6">
                        <label class="block mb-2 font-bold">Current Password</label>
                        <div class="relative">
                            <span class="absolute left-3 top-3 text-gray-400 opacity-50"><i class="fa-solid fa-lock"></i></span>
                            <input type="password" name="current_pass" placeholder="Enter current password" class="pl-10">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 mb-6">
                        <div class="form-group">
                            <label class="block mb-2 font-bold">New Password</label>
                            <input type="password" name="new_pass" placeholder="New password">
                        </div>
                        <div class="form-group">
                            <label class="block mb-2 font-bold">Confirm Password</label>
                            <input type="password" name="confirm_pass" placeholder="Confirm new password">
                        </div>
                    </div>
                    
                    <div class="alert alert-info mb-6 flex gap-3">
                         <i class="fa-solid fa-circle-info mt-1"></i>
                         <p>For security, please ensure your password is at least 8 characters long and includes a mix of letters and numbers.</p>
                    </div>

                    <div class="text-right">
                        <button type="submit" class="btn btn-primary">
                            Update Security Credentials
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </main>
</div>
</body>
</html>
