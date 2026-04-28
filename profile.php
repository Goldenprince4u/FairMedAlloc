<?php
/**
 * Student Profile
 * Manage personal and medical information.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';
require_once 'includes/UrgencyScoreService.php';

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'student') { 
    header("Location: login.php"); 
    exit(); 
}

$user_id = $_SESSION['user_id'];
$msg = '';
$msg_type = '';

function getStoredMedicalSnapshot(mysqli $conn, int $user_id): ?array {
    $stmt = $conn->prepare("SELECT condition_category, mobility_status, severity_level, urgency_score FROM medical_records WHERE student_id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * PHP fallback for cases where the XGBoost model cannot score a record.
 */
function calculateUrgencyScore(string $condition, string $mobility, string $severity_str, int $has_special_needs): float {
    return UrgencyScoreService::calculateFallbackScore([
        'condition' => $condition,
        'mobility' => $mobility,
        'severity' => $severity_str,
        'has_special_needs' => $has_special_needs,
    ]);
}

// ── ACTION: Remove profile photo ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['remove_photo'])) {
    check_csrf();

    $del_stmt = $conn->prepare("SELECT profile_pic FROM users WHERE user_id = ?");
    $del_stmt->bind_param("i", $user_id);
    $del_stmt->execute();
    $current_pic = $del_stmt->get_result()->fetch_assoc()['profile_pic'] ?? '';

    // Delete file from disk (basename prevents path traversal)
    if ($current_pic && $current_pic !== 'default.png') {
        $file_path = __DIR__ . '/uploads/profile_pics/' . basename($current_pic);
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }

    // Clear in database and session
    $clr = $conn->prepare("UPDATE users SET profile_pic = NULL WHERE user_id = ?");
    $clr->bind_param("i", $user_id);
    $clr->execute();
    $_SESSION['profile_pic'] = null;

    $msg      = "Profile photo removed successfully.";
    $msg_type = "success";
}

// ── ACTION: Full profile update ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['remove_photo'])) {
    check_csrf();

    // Upload new profile photo
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === 0) {
        $allowed_exts  = ['jpg', 'jpeg', 'png', 'gif'];
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif'];
        $max_size_bytes = 2 * 1024 * 1024; // 2 MB limit

        $ext      = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
        $filesize = $_FILES['profile_pic']['size'];

        // Validate MIME type via finfo OOP API (PHP 8.5+ compatible — auto-closes on scope exit)
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($_FILES['profile_pic']['tmp_name']);

        if (!in_array($ext, $allowed_exts) || !in_array($mime, $allowed_mimes)) {
            $msg      = "Invalid file type. Only JPG, PNG, and GIF images are allowed.";
            $msg_type = "error";
        } elseif ($filesize > $max_size_bytes) {
            $msg      = "Image is too large. Maximum allowed size is 2 MB.";
            $msg_type = "error";
        } else {
            $upload_dir = __DIR__ . "/uploads/profile_pics/";
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);

            $new_name = "u{$user_id}_" . time() . "." . $ext;

            if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $upload_dir . $new_name)) {
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
            }
        }
    }

    // Academic & Medical Data
    $name    = sanitize_input($_POST['full_name'] ?? '');
    $lvl     = (int)($_POST['level'] ?? 0);
    $dept_id = (int)($_POST['department'] ?? 0);
    $needs   = isset($_POST['has_special_needs']) ? 1 : 0;
    $medicalSnapshot = getStoredMedicalSnapshot($conn, $user_id);
    $cond    = $medicalSnapshot['condition_category'] ?? 'None / Healthy';
    $mob     = (string)($medicalSnapshot['mobility_status'] ?? 0);
    $sev     = $medicalSnapshot['severity_level'] ?? 'Low';

    $stmt = $conn->prepare("UPDATE student_profiles SET level=?, department_id=?, has_special_needs=? WHERE user_id=?");
    $stmt->bind_param("iiii", $lvl, $dept_id, $needs, $user_id);

    if ($stmt->execute()) {
        $stmt_u = $conn->prepare("UPDATE users SET full_name=? WHERE user_id=?");
        $stmt_u->bind_param("si", $name, $user_id);
        $stmt_u->execute();

        $scorePayload = [
            'id' => $user_id,
            'condition' => $cond,
            'mobility' => $mob,
            'severity' => $sev,
            'academic_level' => $lvl,
            'has_special_needs' => $needs,
            'is_requested' => $needs,
        ];

        try {
            $scoreService = new UrgencyScoreService();
            $scoreResult = $scoreService->scoreStudent($scorePayload);
            $score = (float)$scoreResult['score'];
        } catch (Exception $e) {
            error_log('[FairMedAlloc] Profile scoring fell back to PHP rules: ' . $e->getMessage());
            $score = calculateUrgencyScore($cond, $mob, $sev, $needs);
        }

        if ($medicalSnapshot !== null) {
            $m_stmt = $conn->prepare("UPDATE medical_records SET condition_category=?, mobility_status=?, severity_level=?, urgency_score=? WHERE student_id=?");
            $m_stmt->bind_param("sssdi", $cond, $mob, $sev, $score, $user_id);
            $m_stmt->execute();
        }

        $msg      = "Profile updated successfully.";
        $msg_type = "success";
    } else {
        $msg      = "Error updating profile details.";
        $msg_type = "error";
    }
}

// Fetch Data
$stmt = $conn->prepare("SELECT p.*, m.condition_category, m.mobility_status, m.severity_level, u.profile_pic, u.full_name, u.email,
                                     u.username AS matric_no,
                                     d.name as department_name, d.faculty_id
                              FROM student_profiles p 
                              JOIN users u ON p.user_id = u.user_id 
                              JOIN departments d ON p.department_id = d.department_id
                              LEFT JOIN medical_records m ON p.user_id = m.student_id 
                              WHERE p.user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$display_condition = $student['condition_category'] ?? 'None / Healthy';
$display_mobility_map = [
    0 => 'Normal Mobility',
    1 => 'Artificial Limb',
    2 => 'Crutches / Walker',
    3 => 'Wheelchair User',
];
$display_mobility = $display_mobility_map[(int)($student['mobility_status'] ?? 0)] ?? 'Normal Mobility';

$page_title = "My Profile | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-info">
                <h1>My Profile</h1>
                <p class="text-muted">Manage your personal and medical information.</p>
            </div>
            <a href="student_dashboard.php" class="btn btn-outline" id="profile-back-btn">
                <i class="fa-solid fa-arrow-left"></i> Dashboard
            </a>
        </div>

        <?php if($msg): ?>
            <div class="alert <?php echo $msg_type == 'success' ? 'alert-success' : 'alert-danger'; ?> mb-6">
                 <i class="fa-solid <?php echo $msg_type == 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?>"></i>
                 <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <div class="card p-0 mb-8" style="max-width:900px;">

             <!-- Profile Banner: Solid Navy -->
             <div class="profile-banner">
                  <div class="relative" style="display:inline-block;">
                      <img src="uploads/profile_pics/<?php echo htmlspecialchars($student['profile_pic'] ?: 'default.png'); ?>"
                           class="profile-banner-avatar"
                           id="profile-pic-preview"
                           alt="Profile photo"
                           onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 80 80%27%3E%3Ccircle cx=%2740%27 cy=%2740%27 r=%2740%27 fill=%27rgba(255,255,255,0.15)%27/%3E%3Ccircle cx=%2740%27 cy=%2732%27 r=%2714%27 fill=%27rgba(255,255,255,0.5)%27/%3E%3Cellipse cx=%2740%27 cy=%2768%27 rx=%2722%27 ry=%2716%27 fill=%27rgba(255,255,255,0.5)%27/%3E%3C/svg%3E'">

                      <!-- Camera trigger button -->
                      <button type="button" id="photo-menu-trigger"
                              style="position:absolute;bottom:0;right:0;width:28px;height:28px;background:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;border:2px solid rgba(255,255,255,0.5);padding:0;box-shadow:0 1px 4px rgba(0,0,0,0.25);"
                              title="Change photo"
                              onclick="togglePhotoMenu(event)">
                          <i class="fa-solid fa-camera" style="color:var(--c-primary);font-size:0.65rem;"></i>
                      </button>

                      <!-- Photo action dropdown -->
                      <div id="photo-action-menu"
                           style="display:none;position:absolute;bottom:36px;right:-8px;background:#fff;border:1px solid var(--c-border);border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,0.18);min-width:150px;z-index:50;overflow:hidden;">
                          <!-- Change photo -->
                          <label for="profile-pic-upload"
                                 style="display:flex;align-items:center;gap:0.625rem;padding:0.625rem 0.875rem;font-size:0.82rem;font-weight:600;color:var(--c-text-head);cursor:pointer;transition:background 0.15s;"
                                 onmouseover="this.style.background='var(--c-bg-surface-2)'"
                                 onmouseout="this.style.background=''"
                                 onclick="document.getElementById('photo-action-menu').style.display='none'">
                              <i class="fa-solid fa-camera" style="color:var(--c-primary);width:14px;"></i>
                              Change Photo
                              <input type="file" id="profile-pic-upload" name="profile_pic"
                                     class="hidden" accept="image/jpeg,image/png,image/gif"
                                     onchange="previewAndSubmit(this)" form="profileForm">
                          </label>

                          <?php if (!empty($student['profile_pic']) && $student['profile_pic'] !== 'default.png'): ?>
                          <!-- Divider -->
                          <div style="height:1px;background:var(--c-border);margin:0;"></div>
                          <!-- Remove photo -->
                          <button type="button"
                                  style="display:flex;align-items:center;gap:0.625rem;width:100%;padding:0.625rem 0.875rem;font-size:0.82rem;font-weight:600;color:var(--c-danger);background:none;border:none;cursor:pointer;font-family:inherit;transition:background 0.15s;"
                                  onmouseover="this.style.background='rgba(220,38,38,0.06)'"
                                  onmouseout="this.style.background=''"
                                  onclick="if(confirm('Remove your profile photo? This cannot be undone.')) document.getElementById('remove-photo-form').submit();">
                              <i class="fa-solid fa-trash-can" style="width:14px;"></i>
                              Remove Photo
                          </button>
                          <?php endif; ?>
                      </div>

                      <!-- Hidden remove-photo form -->
                      <?php if (!empty($student['profile_pic']) && $student['profile_pic'] !== 'default.png'): ?>
                      <form method="post" id="remove-photo-form" style="display:none;">
                          <?php csrf_field(); ?>
                          <input type="hidden" name="remove_photo" value="1">
                      </form>
                      <?php endif; ?>
                  </div>
                  <div class="profile-banner-info">
                      <h2><?php echo htmlspecialchars($student['full_name']); ?></h2>
                      <div class="profile-banner-meta">
                          <span><i class="fa-solid fa-id-card"></i> <?php echo htmlspecialchars($student['matric_no']); ?></span>
                          <span><i class="fa-solid fa-layer-group"></i> <?php echo htmlspecialchars($student['level']); ?> Level</span>
                          <span><i class="fa-solid fa-venus-mars"></i> <?php echo htmlspecialchars($student['gender']); ?></span>
                      </div>
                  </div>
             </div>

            <form method="post" enctype="multipart/form-data" id="profileForm" style="padding:1.5rem 2rem;">
                <?php csrf_field(); ?>

                <!-- ── ACADEMIC INFORMATION ── -->
                <div style="margin-bottom:1.5rem;">
                    <div class="form-section-title" style="margin-bottom:1rem;padding-bottom:0.625rem;">
                        <span class="form-section-icon" style="background:rgba(37,99,235,0.08);color:var(--c-info);"><i class="fa-solid fa-graduation-cap"></i></span>
                        Academic Information
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.875rem;">

                        <div class="form-group" style="margin-bottom:0;">
                            <label>Full Name</label>
                            <input type="text" name="full_name" id="profile-full-name"
                                   value="<?php echo htmlspecialchars($student['full_name']); ?>">
                        </div>

                        <div class="form-group" style="margin-bottom:0;">
                            <label>Gender <span style="font-size:0.72rem;color:var(--c-text-muted);font-weight:400;">(locked)</span></label>
                            <input type="text"
                                   value="<?php echo htmlspecialchars($student['gender']); ?>"
                                   disabled
                                   style="opacity:0.6;cursor:not-allowed;background:var(--c-bg-surface-2);">
                        </div>

                        <div class="form-group" style="margin-bottom:0;">
                            <label>Level</label>
                            <select name="level" id="profile-level">
                                <option value="100" <?php if($student['level']==100) echo 'selected'; ?>>100 Level</option>
                                <option value="200" <?php if($student['level']==200) echo 'selected'; ?>>200 Level</option>
                                <option value="300" <?php if($student['level']==300) echo 'selected'; ?>>300 Level</option>
                                <option value="400" <?php if($student['level']==400) echo 'selected'; ?>>400 Level</option>
                                <option value="500" <?php if($student['level']==500) echo 'selected'; ?>>500 Level</option>
                            </select>
                        </div>

                        <div class="form-group" style="margin-bottom:0;">
                            <label>Faculty</label>
                            <select name="faculty" id="facultySelect" onchange="updateDepartments()">
                                <option value="">Select faculty…</option>
                                <?php
                                $fac_query = $conn->query("SELECT faculty_id, name FROM faculties ORDER BY name ASC");
                                while($f = $fac_query->fetch_assoc()) {
                                    $sel = ($student['faculty_id'] == $f['faculty_id']) ? 'selected' : '';
                                    echo '<option value="'.$f['faculty_id'].'" '.$sel.'>'.htmlspecialchars($f['name']).'</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <div class="form-group" style="margin-bottom:0;">
                            <label>Department</label>
                            <select name="department" id="deptSelect"
                                    data-current="<?php echo htmlspecialchars($student['department_id']); ?>">
                                <option value="<?php echo htmlspecialchars($student['department_id']); ?>">
                                    <?php echo htmlspecialchars($student['department_name'] ?: 'Select faculty first'); ?>
                                </option>
                            </select>
                        </div>



                    </div>
                </div>

                <!-- ── MEDICAL & HEALTH STATUS ── -->
                <div style="margin-bottom:1.25rem;">
                    <div class="form-section-title" style="margin-bottom:1rem;padding-bottom:0.625rem;color:var(--c-danger);border-bottom-color:rgba(220,38,38,0.15);">
                        <span class="form-section-icon" style="background:rgba(220,38,38,0.08);color:var(--c-danger);"><i class="fa-solid fa-heart-pulse"></i></span>
                        Medical &amp; Health Status
                    </div>

                    <div class="alert alert-warning" style="margin-bottom:0.875rem;font-size:0.82rem;padding:0.625rem 0.875rem;">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span>Medical data directly affects your allocation priority. False declarations will be cross-verified with the University Health Centre.</span>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.875rem;">

                        <div class="form-group" style="margin-bottom:0;">
                            <label>Medical Condition <span style="font-size:0.72rem;color:var(--c-text-muted);font-weight:400;">(locked)</span></label>
                            <input type="text"
                                   id="profile-condition"
                                   value="<?php echo htmlspecialchars($display_condition); ?>"
                                   disabled
                                   style="opacity:0.6;cursor:not-allowed;background:var(--c-bg-surface-2);">
                        </div>

                        <div class="form-group" style="margin-bottom:0;">
                            <label>Mobility Status <span style="font-size:0.72rem;color:var(--c-text-muted);font-weight:400;">(locked)</span></label>
                            <input type="text"
                                   id="profile-mobility"
                                   value="<?php echo htmlspecialchars($display_mobility); ?>"
                                   disabled
                                   style="opacity:0.6;cursor:not-allowed;background:var(--c-bg-surface-2);">
                        </div>

                        <div class="form-group" style="margin-bottom:0;grid-column:1 / -1;">
                            <div class="text-xs text-muted" style="padding-top:0.25rem;">
                                Medical condition and mobility status are locked after submission. Contact Student Affairs or the medical team if a verified update is required.
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ── SAVE FOOTER ── -->
                <div style="display:flex;justify-content:flex-end;padding-top:1rem;border-top:1px solid var(--c-border);">
                    <button type="submit" class="btn btn-primary" id="profile-save-btn">
                        <i class="fa-solid fa-floppy-disk"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </main>
</div>

<script src="js/departments.js"></script>
<script>
// Photo action menu toggle (social media style)
function togglePhotoMenu(e) {
    e.stopPropagation();
    var menu = document.getElementById('photo-action-menu');
    if (!menu) return;
    var isOpen = menu.style.display === 'flex' || menu.style.display === 'block';
    menu.style.display = isOpen ? 'none' : 'block';
}

// Close menu when clicking anywhere else on the page
document.addEventListener('click', function(e) {
    var menu    = document.getElementById('photo-action-menu');
    var trigger = document.getElementById('photo-menu-trigger');
    if (!menu || !trigger) return;
    if (!menu.contains(e.target) && e.target !== trigger && !trigger.contains(e.target)) {
        menu.style.display = 'none';
    }
});

// Close menu on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var menu = document.getElementById('photo-action-menu');
        if (menu) menu.style.display = 'none';
    }
});
// Instantly preview uploaded photo in both the banner and the nav sidebar,
// then auto-submit so the server persists the new image.
function previewAndSubmit(input) {
    if (!input.files || !input.files[0]) return;
    var reader = new FileReader();
    reader.onload = function(e) {
        // Update banner preview
        var banner = document.getElementById('profile-pic-preview');
        if (banner) banner.src = e.target.result;

        // Update nav sidebar avatar (photo variant)
        var navImg = document.getElementById('nav-avatar-img');
        if (navImg) {
            navImg.src = e.target.result;
        } else {
            // No photo element yet (first upload) — swap initials for img
            var initials = document.getElementById('nav-avatar-initials');
            if (initials) {
                var img = document.createElement('img');
                img.id  = 'nav-avatar-img';
                img.alt = 'Profile photo';
                img.src = e.target.result;
                img.style.cssText = 'width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid rgba(255,255,255,0.25);';
                initials.parentNode.replaceChild(img, initials);
            }
        }

        // Submit the form so PHP saves the file
        input.form.submit();
    };
    reader.readAsDataURL(input.files[0]);
}
</script>
</body>
</html>
