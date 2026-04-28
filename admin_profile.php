<?php
/**
 * admin_profile.php — Administrator Profile & Security Settings
 * ==============================================================
 * Allows the currently logged-in admin to:
 *   1. Update their display name (how they appear in the UI).
 *   2. Update their profile picture (type + MIME validated).
 *   3. Remove their profile picture.
 *   4. Change their password (requires current password + complexity check).
 *
 * Security measures applied:
 *   - Session-based admin auth guard.
 *   - CSRF token validation on every POST.
 *   - File type validated by extension AND MIME.
 *   - Password change requires proof of current password.
 *   - New password enforces complexity: 8+ chars, upper, lower, digit.
 *   - All output escaped with htmlspecialchars().
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

// --- Auth Guard: Admin Only ---
if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: admin_login.php"); exit();
}
$user_id = $_SESSION['user_id'];

$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    // === ACTION: Remove profile photo ===
    if (!empty($_POST['remove_photo'])) {
        $del = $conn->prepare("SELECT profile_pic FROM users WHERE user_id = ?");
        $del->bind_param("i", $user_id);
        $del->execute();
        $current_pic = $del->get_result()->fetch_assoc()['profile_pic'] ?? '';

        if ($current_pic && $current_pic !== 'default.png') {
            $file_path = __DIR__ . '/uploads/profile_pics/' . basename($current_pic);
            if (file_exists($file_path)) unlink($file_path);
        }

        $clr = $conn->prepare("UPDATE users SET profile_pic = NULL WHERE user_id = ?");
        $clr->bind_param("i", $user_id);
        $clr->execute();
        $_SESSION['profile_pic'] = null;

        $msg      = "Profile photo removed successfully.";
        $msg_type = "success";

    // === ACTION: Upload new profile picture ===
    } elseif (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === 0) {
        $allowed_ext   = ['jpg', 'jpeg', 'png', 'gif'];
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif'];
        $ext  = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_ext)) {
            $msg      = "Invalid file type. Only JPG, PNG, GIF allowed.";
            $msg_type = "error";
        } elseif ($_FILES['profile_pic']['size'] > 2 * 1024 * 1024) {
            $msg      = "Image too large. Maximum file size is 2 MB.";
            $msg_type = "error";
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($_FILES['profile_pic']['tmp_name']);

            if (!in_array($mime, $allowed_mimes)) {
                $msg      = "Invalid image content. Allowed: JPEG, PNG, GIF.";
                $msg_type = "error";
            } else {
                $upload_dir = __DIR__ . '/uploads/profile_pics/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                $new_name = "admin_{$user_id}_" . time() . ".$ext";
                $dest     = $upload_dir . $new_name;

                if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $dest)) {
                    // Fetch and delete the old profile picture from disk
                    $old_pic_stmt = $conn->prepare("SELECT profile_pic FROM users WHERE user_id = ?");
                    $old_pic_stmt->bind_param("i", $user_id);
                    $old_pic_stmt->execute();
                    $old_pic = $old_pic_stmt->get_result()->fetch_assoc()['profile_pic'] ?? '';
                    
                    if ($old_pic && $old_pic !== 'default.png') {
                        $old_file_path = $upload_dir . basename($old_pic);
                        if (file_exists($old_file_path)) {
                            unlink($old_file_path);
                        }
                    }

                    $pic_stmt = $conn->prepare("UPDATE users SET profile_pic = ? WHERE user_id = ?");
                    $pic_stmt->bind_param("si", $new_name, $user_id);
                    $pic_stmt->execute();
                    $_SESSION['profile_pic'] = $new_name;
                    $msg      = "Profile photo updated successfully.";
                    $msg_type = "success";
                } else {
                    $msg      = "Upload failed: could not move the file.";
                    $msg_type = "error";
                }
            }
        }

    // === ACTION: Update display name ===
    } elseif (!empty($_POST['display_name'])) {
        $display_name = trim(sanitize_input($_POST['display_name']));
        if (strlen($display_name) < 2) {
            $msg      = "Display name must be at least 2 characters.";
            $msg_type = "error";
        } else {
            $dn = $conn->prepare("UPDATE users SET full_name = ? WHERE user_id = ?");
            $dn->bind_param("si", $display_name, $user_id);
            if ($dn->execute()) {
                // Update the session key the nav/dashboard reads from
                $_SESSION['full_name'] = $display_name;
                $msg      = "Display name updated successfully.";
                $msg_type = "success";
            } else {
                $msg      = "Failed to update display name.";
                $msg_type = "error";
            }
        }

    // === ACTION: Password Update ===
    } elseif (!empty($_POST['new_pass'])) {
        $current = $_POST['current_pass'];
        $new     = $_POST['new_pass'];
        $confirm = $_POST['confirm_pass'];

        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();

        if (!password_verify($current, $res['password_hash'])) {
            $msg      = "Current password is incorrect.";
            $msg_type = "error";
        } elseif ($new !== $confirm) {
            $msg      = "New passwords do not match.";
            $msg_type = "error";
        } elseif (
            strlen($new) < 8 ||
            !preg_match('/[A-Z]/', $new) ||
            !preg_match('/[a-z]/', $new) ||
            !preg_match('/[0-9]/', $new)
        ) {
            $msg      = "Password must be 8+ characters and include uppercase, lowercase, and a number.";
            $msg_type = "error";
        } else {
            $new_hash = password_hash($new, PASSWORD_DEFAULT);
            $sql = FAIRMED_SUPPORTS_MUST_CHANGE_PASSWORD
                ? "UPDATE users SET password_hash = ?, must_change_password = 0 WHERE user_id = ?"
                : "UPDATE users SET password_hash = ? WHERE user_id = ?";
            $update = $conn->prepare($sql);
            $update->bind_param("si", $new_hash, $user_id);
            if ($update->execute()) {
                $_SESSION['must_change_password'] = false;
                $msg      = "Password updated successfully.";
                $msg_type = "success";
            } else {
                $msg      = "Database error updating password.";
                $msg_type = "error";
            }
        }
    }
}

// Fetch current admin data
$admin_data = $conn->prepare("SELECT full_name, profile_pic FROM users WHERE user_id = ?");
$admin_data->bind_param("i", $user_id);
$admin_data->execute();
$admin = $admin_data->get_result()->fetch_assoc();

$display_name = $admin['full_name'] ?? $_SESSION['username'];
$profile_pic  = $admin['profile_pic'] ?? $_SESSION['profile_pic'] ?? null;

$page_title = "Admin Profile | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-info">
                <h1>Admin Profile</h1>
                <p class="text-muted">Manage your display name, photo, and security credentials.</p>
            </div>
        </div>

        <?php if($msg): ?>
            <div class="alert <?php echo $msg_type === 'success' ? 'alert-success' : 'alert-danger'; ?> mb-6">
                <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?>"></i>
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <div class="grid-profile">

            <!-- ── LEFT: Profile Card ─────────────────────────────── -->
            <div class="card text-center" style="padding:2rem 1.5rem;">

                <!-- Avatar + camera menu -->
                <div style="position:relative;display:inline-block;margin-bottom:1.25rem;">
                    <img src="uploads/profile_pics/<?php echo htmlspecialchars(basename($profile_pic ?: 'default.png')); ?>"
                         class="avatar-lg"
                         id="admin-pic-preview"
                         alt="Admin photo">

                    <!-- Camera trigger -->
                    <button type="button" id="admin-photo-trigger"
                            style="position:absolute;bottom:6px;right:6px;width:34px;height:34px;background:var(--c-primary);border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;border:3px solid #fff;padding:0;box-shadow:0 2px 6px rgba(0,0,0,0.25);"
                            title="Change photo"
                            onclick="toggleAdminPhotoMenu(event)">
                        <i class="fa-solid fa-camera" style="color:white;font-size:0.72rem;"></i>
                    </button>

                    <!-- Photo action dropdown -->
                    <div id="admin-photo-menu"
                         style="display:none;position:absolute;bottom:48px;right:-8px;background:#fff;border:1px solid var(--c-border);border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,0.18);min-width:155px;z-index:50;overflow:hidden;text-align:left;">

                        <!-- Change photo -->
                        <form method="post" enctype="multipart/form-data" id="admin-pic-form">
                            <?php csrf_field(); ?>
                            <label for="admin-pic-upload"
                                   style="display:flex;align-items:center;gap:0.625rem;padding:0.625rem 0.875rem;font-size:0.82rem;font-weight:600;color:var(--c-text-head);cursor:pointer;transition:background 0.15s;"
                                   onmouseover="this.style.background='var(--c-bg-surface-2)'"
                                   onmouseout="this.style.background=''"
                                   onclick="document.getElementById('admin-photo-menu').style.display='none'">
                                <i class="fa-solid fa-camera" style="color:var(--c-primary);width:14px;"></i>
                                Change Photo
                                <input type="file" id="admin-pic-upload" name="profile_pic"
                                       class="hidden" accept="image/jpeg,image/png,image/gif"
                                       onchange="document.getElementById('admin-pic-form').submit()">
                            </label>
                        </form>

                        <?php if (!empty($profile_pic) && $profile_pic !== 'default.png'): ?>
                        <div style="height:1px;background:var(--c-border);"></div>
                        <!-- Remove photo -->
                        <button type="button"
                                style="display:flex;align-items:center;gap:0.625rem;width:100%;padding:0.625rem 0.875rem;font-size:0.82rem;font-weight:600;color:var(--c-danger);background:none;border:none;cursor:pointer;font-family:inherit;transition:background 0.15s;"
                                onmouseover="this.style.background='rgba(220,38,38,0.06)'"
                                onmouseout="this.style.background=''"
                                onclick="if(confirm('Remove your profile photo? This cannot be undone.')) document.getElementById('admin-remove-form').submit();">
                            <i class="fa-solid fa-trash-can" style="width:14px;"></i>
                            Remove Photo
                        </button>
                        <?php endif; ?>
                    </div>

                    <!-- Hidden remove form -->
                    <?php if (!empty($profile_pic) && $profile_pic !== 'default.png'): ?>
                    <form method="post" id="admin-remove-form" style="display:none;">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="remove_photo" value="1">
                    </form>
                    <?php endif; ?>
                </div>
                <?php if (!empty($profile_pic) && $profile_pic !== 'default.png'): ?>
                <div style="margin-top:0.5rem;">
                    <button type="button"
                            class="btn btn-outline btn-sm"
                            style="border-color:rgba(220,38,38,0.25);color:var(--c-danger);"
                            onclick="if(confirm('Remove your profile photo? This cannot be undone.')) document.getElementById('admin-remove-form').submit();">
                        <i class="fa-solid fa-trash-can"></i> Remove Photo
                    </button>
                </div>
                <?php endif; ?>

                <!-- Display name -->
                <h2 style="font-size:1.2rem;margin-bottom:0.25rem;"><?php echo htmlspecialchars($display_name); ?></h2>
                <div class="badge badge-primary mb-4" style="background:rgba(0,33,71,0.08);color:var(--c-primary);">Administrator</div>

                <!-- Role capabilities -->
                <div style="text-align:left;border-top:1px solid var(--c-border);padding-top:1.25rem;margin-top:0.75rem;">
                    <div style="font-size:0.7rem;color:var(--c-text-muted);text-transform:uppercase;letter-spacing:0.08em;font-weight:700;margin-bottom:0.75rem;">Role Capabilities</div>
                    <ul style="list-style:none;display:flex;flex-direction:column;gap:0.5rem;">
                        <li style="display:flex;align-items:center;gap:0.5rem;font-size:0.85rem;"><i class="fa-solid fa-check" style="color:var(--c-success);width:14px;"></i> Full System Access</li>
                        <li style="display:flex;align-items:center;gap:0.5rem;font-size:0.85rem;"><i class="fa-solid fa-check" style="color:var(--c-success);width:14px;"></i> User Management</li>
                        <li style="display:flex;align-items:center;gap:0.5rem;font-size:0.85rem;"><i class="fa-solid fa-check" style="color:var(--c-success);width:14px;"></i> Allocation Control</li>
                    </ul>
                </div>
            </div>

            <!-- ── RIGHT: Settings Panel ──────────────────────────── -->
            <div class="card" style="padding:2rem;">

                <!-- Display Name -->
                <div class="form-section-title" style="margin-bottom:1.25rem;">
                    <span class="form-section-icon" style="background:rgba(0,33,71,0.08);color:var(--c-primary);"><i class="fa-solid fa-user-pen"></i></span>
                    Display Name
                </div>
                <form method="post" id="admin-name-form" style="margin-bottom:2rem;padding-bottom:2rem;border-bottom:1px solid var(--c-border);">
                    <?php csrf_field(); ?>
                    <div class="form-group">
                        <label for="admin-display-name">How you want to appear in the system</label>
                        <input type="text" id="admin-display-name" name="display_name"
                               value="<?php echo htmlspecialchars($display_name); ?>"
                               placeholder="e.g. Dr. Adeyemi or Systems Admin"
                               maxlength="80">
                    </div>
                    <div style="display:flex;justify-content:flex-end;">
                        <button type="submit" class="btn btn-secondary" id="admin-save-name-btn">
                            <i class="fa-solid fa-floppy-disk"></i> Save Name
                        </button>
                    </div>
                </form>

                <!-- Security / Password -->
                <div class="form-section-title" style="margin-bottom:1.25rem;">
                    <span class="form-section-icon" style="background:rgba(0,33,71,0.08);color:var(--c-primary);"><i class="fa-solid fa-shield-halved"></i></span>
                    Security Settings
                </div>
                <form method="post" id="admin-pw-form">
                    <?php csrf_field(); ?>
                    <div class="form-group">
                        <label for="admin-current-pass">Current Password</label>
                        <div class="input-group" style="position:relative;">
                            <span class="input-icon"><i class="fa-solid fa-lock"></i></span>
                            <input type="password" id="admin-current-pass" name="current_pass"
                                   placeholder="Enter current password"
                                   style="padding-left:2.5rem;padding-right:2.75rem;">
                            <i class="fa-solid fa-eye"
                               id="toggleCurrentPass"
                               style="position:absolute;right:14px;top:50%;transform:translateY(-50%);cursor:pointer;font-size:0.85rem;color:var(--c-text-muted);"
                               onclick="toggleAdminPw('admin-current-pass','toggleCurrentPass')"
                               title="Toggle password visibility"></i>
                        </div>
                    </div>

                    <div class="grid" style="grid-template-columns:1fr 1fr;gap:1rem;">
                        <div class="form-group">
                            <label for="admin-new-pass">New Password</label>
                            <div style="position:relative;">
                                <input type="password" id="admin-new-pass" name="new_pass" placeholder="New password" style="padding-right:2.75rem;">
                                <i class="fa-solid fa-eye"
                                   id="toggleNewPass"
                                   style="position:absolute;right:14px;top:50%;transform:translateY(-50%);cursor:pointer;font-size:0.85rem;color:var(--c-text-muted);"
                                   onclick="toggleAdminPw('admin-new-pass','toggleNewPass')"
                                   title="Toggle password visibility"></i>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="admin-confirm-pass">Confirm Password</label>
                            <div style="position:relative;">
                                <input type="password" id="admin-confirm-pass" name="confirm_pass" placeholder="Confirm new password" style="padding-right:2.75rem;">
                                <i class="fa-solid fa-eye"
                                   id="toggleConfirmPass"
                                   style="position:absolute;right:14px;top:50%;transform:translateY(-50%);cursor:pointer;font-size:0.85rem;color:var(--c-text-muted);"
                                   onclick="toggleAdminPw('admin-confirm-pass','toggleConfirmPass')"
                                   title="Toggle password visibility"></i>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info" style="font-size:0.82rem;padding:0.625rem 0.875rem;">
                        <i class="fa-solid fa-circle-info"></i>
                        <span>8+ characters with at least one uppercase letter, lowercase letter, and digit.</span>
                    </div>

                    <div style="display:flex;justify-content:flex-end;margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid var(--c-border);">
                        <button type="submit" class="btn btn-primary" id="admin-save-pw-btn">
                            <i class="fa-solid fa-floppy-disk"></i> Update Password
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </main>
</div>

<script>
function toggleAdminPhotoMenu(e) {
    e.stopPropagation();
    var menu = document.getElementById('admin-photo-menu');
    if (!menu) return;
    menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
}

document.addEventListener('click', function(e) {
    var menu    = document.getElementById('admin-photo-menu');
    var trigger = document.getElementById('admin-photo-trigger');
    if (!menu || !trigger) return;
    if (!menu.contains(e.target) && e.target !== trigger && !trigger.contains(e.target)) {
        menu.style.display = 'none';
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var menu = document.getElementById('admin-photo-menu');
        if (menu) menu.style.display = 'none';
    }
});

// Password visibility toggle (shared helper)
function toggleAdminPw(inputId, iconId) {
    var input = document.getElementById(inputId);
    var icon  = document.getElementById(iconId);
    if (!input) return;
    var isHidden = input.type === 'password';
    input.type   = isHidden ? 'text' : 'password';
    if (icon) {
        icon.classList.toggle('fa-eye',      !isHidden);
        icon.classList.toggle('fa-eye-slash', isHidden);
    }
}
</script>
</body>
</html>
