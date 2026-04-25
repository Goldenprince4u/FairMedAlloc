<?php
/**
 * Student Profile
 * Manage personal and medical information.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'student') { 
    header("Location: login.php"); 
    exit(); 
}

$user_id = $_SESSION['user_id'];
$msg = '';
$msg_type = '';

/**
 * Calculate urgency score using the same weighted logic as ml_models/predict.py fallback.
 * This ensures profile updates stay consistent with the allocation engine's scoring.
 */
function calculateUrgencyScore(string $condition, string $mobility, string $severity_str, int $has_special_needs): float {
    $score = 10.0;

    // Severity multiplier
    $sev_map = ['Low' => 1, 'Medium' => 2, 'High' => 3];
    $severity = $sev_map[$severity_str] ?? 1;

    // Condition weights — must mirror predict.py weights dict exactly
    $weights = [
        'Sickle Cell'        => 90.0,
        'Epilepsy'           => 90.0,
        'Diabetes'           => 90.0,
        'Cardiac'            => 90.0,
        'Cardiovascular'     => 90.0,
        'Neurological'       => 70.0,
        'Orthopaedic'        => 65.0,
        'Physical Disability'=> 65.0,
        'Visual Impairment'  => 60.0,
        'Asthma'             => 50.0,
        'Respiratory'        => 50.0,
        'Ulcer'              => 30.0,
        'Other'              => 20.0,
        'Mobility'           => 0.0,
    ];

    $score += $weights[$condition] ?? 0.0;

    // Mobility boost (takes precedence via max() to prevent double-counting)
    $mobility_score = 0.0;
    if (in_array($mobility, ['Wheelchair User', 'Crutches/Walker', 'Artificial Limb'])) {
        $mobility_score = $has_special_needs ? 90.0 : 75.0;
    }
    $score = max($score, $mobility_score);

    // Severity bump (mirrors predict.py: severity * 5)
    $score += ($severity * 5.0);

    return min((float)$score, 100.0);
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
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

            $new_name = "u{$user_id}_" . time() . "." . $ext;

            if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $upload_dir . $new_name)) {
                $pic_stmt = $conn->prepare("UPDATE users SET profile_pic = ? WHERE user_id = ?");
                $pic_stmt->bind_param("si", $new_name, $user_id);
                $pic_stmt->execute();
                $_SESSION['profile_pic'] = $new_name;
            }
        }
    }

    // Academic & Medical Data
    $name    = sanitize_input($_POST['full_name']         ?? '');
    $lvl     = (int)($_POST['level']                     ?? 0);
    $dept_id = (int)($_POST['department']                ?? 0);
    $cond    = sanitize_input($_POST['medical_condition'] ?? 'None');
    $mob     = sanitize_input($_POST['mobility_status']   ?? 'Normal Mobility');
    $needs   = isset($_POST['has_special_needs']) ? 1 : 0;
    $sev     = sanitize_input($_POST['severity_level']    ?? 'Low');

    $stmt = $conn->prepare("UPDATE student_profiles SET level=?, department_id=?, has_special_needs=? WHERE user_id=?");
    $stmt->bind_param("iiii", $lvl, $dept_id, $needs, $user_id);

    if ($stmt->execute()) {
        $stmt_u = $conn->prepare("UPDATE users SET full_name=? WHERE user_id=?");
        $stmt_u->bind_param("si", $name, $user_id);
        $stmt_u->execute();

        // Compute urgency score using the same weighted logic as predict.py fallback
        $score = calculateUrgencyScore($cond, $mob, $sev, $needs);

        $check_stmt = $conn->prepare("SELECT record_id FROM medical_records WHERE student_id = ?");
        $check_stmt->bind_param("i", $user_id);
        $check_stmt->execute();
        $check = $check_stmt->get_result();

        if ($check->num_rows > 0) {
            $m_stmt = $conn->prepare("UPDATE medical_records SET condition_category=?, mobility_status=?, severity_level=?, urgency_score=? WHERE student_id=?");
            $m_stmt->bind_param("sssdi", $cond, $mob, $sev, $score, $user_id);
        } else {
            $m_stmt = $conn->prepare("INSERT INTO medical_records (student_id, condition_category, mobility_status, severity_level, urgency_score) VALUES (?, ?, ?, ?, ?)");
            $m_stmt->bind_param("isssd", $user_id, $cond, $mob, $sev, $score);
        }
        $m_stmt->execute();

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
                                     onchange="this.form.submit()" form="profileForm">
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

                        <div style="display:flex;align-items:flex-end;padding-bottom:2px;">
                            <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;font-weight:500;margin-bottom:0;">
                                <input type="checkbox" id="profile-special-needs"
                                       name="has_special_needs" value="1"
                                       <?php if(!empty($student['has_special_needs'])) echo 'checked'; ?>
                                       style="width:16px;height:16px;cursor:pointer;flex-shrink:0;accent-color:var(--c-primary);">
                                <span style="font-size:0.85rem;color:var(--c-text-body);">Documented Special Needs</span>
                            </label>
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
                            <label>Medical Condition</label>
                            <select name="medical_condition" id="profile-condition">
                                <option value="None"                <?php if(($student['condition_category']??'')=='None')               echo 'selected'; ?>>None / Healthy</option>
                                <option value="Asthma"              <?php if(($student['condition_category']??'')=='Asthma')             echo 'selected'; ?>>Asthma</option>
                                <option value="Epilepsy"            <?php if(($student['condition_category']??'')=='Epilepsy')           echo 'selected'; ?>>Epilepsy</option>
                                <option value="Ulcer"               <?php if(($student['condition_category']??'')=='Ulcer')              echo 'selected'; ?>>Ulcer</option>
                                <option value="Sickle Cell"         <?php if(($student['condition_category']??'')=='Sickle Cell')        echo 'selected'; ?>>Sickle Cell Disease</option>
                                <option value="Visual Impairment"   <?php if(($student['condition_category']??'')=='Visual Impairment')  echo 'selected'; ?>>Visual Impairment</option>
                                <option value="Physical Disability"  <?php if(($student['condition_category']??'')=='Physical Disability') echo 'selected'; ?>>Physical Disability</option>
                                <option value="Cardiovascular"      <?php if(($student['condition_category']??'')=='Cardiovascular')     echo 'selected'; ?>>Cardiovascular</option>
                                <option value="Neurological"        <?php if(($student['condition_category']??'')=='Neurological')       echo 'selected'; ?>>Neurological</option>
                                <option value="Respiratory"         <?php if(($student['condition_category']??'')=='Respiratory')        echo 'selected'; ?>>Respiratory</option>
                                <option value="Mobility"            <?php if(($student['condition_category']??'')=='Mobility')           echo 'selected'; ?>>Mobility</option>
                                <option value="Other"               <?php if(($student['condition_category']??'')=='Other')              echo 'selected'; ?>>Other</option>
                            </select>
                        </div>

                        <div class="form-group" style="margin-bottom:0;">
                            <label>Mobility Status</label>
                            <select name="mobility_status" id="profile-mobility">
                                <option value="Normal Mobility"  <?php if(($student['mobility_status']??'')=='Normal Mobility')  echo 'selected'; ?>>Normal Mobility</option>
                                <option value="Wheelchair User"  <?php if(($student['mobility_status']??'')=='Wheelchair User')  echo 'selected'; ?>>Wheelchair User</option>
                                <option value="Crutches/Walker"  <?php if(($student['mobility_status']??'')=='Crutches/Walker')  echo 'selected'; ?>>Crutches / Walker</option>
                                <option value="Artificial Limb"  <?php if(($student['mobility_status']??'')=='Artificial Limb')  echo 'selected'; ?>>Artificial Limb</option>
                            </select>
                        </div>

                        <div class="form-group" style="margin-bottom:0;">
                            <label>Severity Level</label>
                            <select name="severity_level" id="profile-severity">
                                <option value="High"   <?php if(($student['severity_level']??'')=='High')   echo 'selected'; ?>>High</option>
                                <option value="Medium" <?php if(($student['severity_level']??'')=='Medium') echo 'selected'; ?>>Medium</option>
                                <option value="Low"    <?php if(($student['severity_level']??'')=='Low')    echo 'selected'; ?>>Low</option>
                            </select>
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
</script>
</body>
</html>
