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

// Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    // 1. Profile Pic — with size and MIME-type validation
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

    // 2. Academic & Medical Data
    $name    = sanitize_input($_POST['full_name']        ?? '');
    $lvl     = (int)($_POST['level']                    ?? 0);
    $dept_id = (int)($_POST['department']               ?? 0);
    $cond    = sanitize_input($_POST['medical_condition'] ?? 'None');
    $mob     = sanitize_input($_POST['mobility_status']  ?? 'Normal Mobility');
    $needs   = isset($_POST['has_special_needs']) ? 1 : 0;
    $sev     = sanitize_input($_POST['severity_level']   ?? 'Low');

    $stmt = $conn->prepare("UPDATE student_profiles SET level=?, department_id=?, has_special_needs=? WHERE user_id=?");
    $stmt->bind_param("iiii", $lvl, $dept_id, $needs, $user_id);

    if ($stmt->execute()) {
        $stmt_u = $conn->prepare("UPDATE users SET full_name=? WHERE user_id=?");
        $stmt_u->bind_param("si", $name, $user_id);
        $stmt_u->execute();

        // FIX: Compute urgency score using the same weighted logic as predict.py fallback
        // (previously used a naive formula: 10 + 50 + 30 which ignored condition severity)
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
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="serif mb-1 text-3xl">Student Profile</h1>
                <p class="text-muted">Manage your personal and medical information.</p>
            </div>
            <a href="student_dashboard.php" class="btn btn-outline text-primary ">
                <i class="fa-solid fa-arrow-left mr-2"></i> Dashboard
            </a>
        </div>

        <?php if($msg): ?>
            <div class="mb-6 p-4 rounded-lg flex items-center gap-3 <?php echo $msg_type == 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
                 <i class="fa-solid <?php echo $msg_type == 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?>"></i>
                 <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <div class="glass-card max-w-4xl mx-auto p-0 mb-8">
             
             <!-- Header Banner -->
             <div class="p-8 relative overflow-hidden bg-gradient-brand text-white rounded-t-lg">
                  
                  <div class="relative z-10 flex items-center gap-6">
                       <div class="relative group">
                           <img src="uploads/profile_pics/<?php echo $student['profile_pic'] ?: 'default.png'; ?>" 
                                class="avatar w-[100px] h-[100px] border-4 border-white">
                           <label class="absolute bottom-0 right-0 bg-white text-primary p-2 rounded-full cursor-pointer shadow-md hover:bg-gray-100 transition-colors w-8 h-8 flex items-center justify-center">
                               <i class="fa-solid fa-camera"></i>
                               <input type="file" name="profile_pic" class="hidden" onchange="this.form.submit()" form="profileForm">
                           </label>
                       </div>
                       <div>
                           <h2 class="serif text-3xl text-white mb-2"><?php echo htmlspecialchars($student['full_name']); ?></h2>
                           <div class="opacity-90 text-sm mt-1 flex gap-4">
                               <span><i class="fa-solid fa-id-card mr-1"></i> <?php echo htmlspecialchars($student['matric_no']); ?></span>
                               <span><i class="fa-solid fa-layer-group mr-1"></i> <?php echo htmlspecialchars($student['level']); ?> Lvl</span>
                           </div>
                       </div>
                  </div>
             </div>

            <form method="post" enctype="multipart/form-data" id="profileForm" class="p-8">
                <?php csrf_field(); ?>
                
                <!-- Academic Section -->
                <div class="mb-10">
                    <h3 class="flex items-center gap-3 text-lg font-bold mb-6 pb-2 text-head border-b border-border">
                        <span class="inline-flex w-8 h-8 bg-blue-50 text-primary rounded items-center justify-center"><i class="fa-solid fa-graduation-cap"></i></span>
                        Academic Information
                    </h3>

                    <div class="grid grid-cols-2">
                        <div class="form-group">
                            <label>Full Name</label>
                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($student['full_name']); ?>">
                        </div>

                        <div class="form-group">
                            <label>Gender</label>
                            <input type="text" value="<?php echo htmlspecialchars($student['gender']); ?>" disabled class="bg-gray-100 cursor-not-allowed">
                            <div class="text-xs text-muted mt-1">Locked for allocation purposes.</div>
                        </div>

                        <div class="form-group">
                            <label>Level</label>
                            <select name="level">
                                <option value="100" <?php if($student['level']==100) echo 'selected'; ?>>100 Level</option>
                                <option value="200" <?php if($student['level']==200) echo 'selected'; ?>>200 Level</option>
                                <option value="300" <?php if($student['level']==300) echo 'selected'; ?>>300 Level</option>
                                <option value="400" <?php if($student['level']==400) echo 'selected'; ?>>400 Level</option>
                                <option value="500" <?php if($student['level']==500) echo 'selected'; ?>>500 Level</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Faculty</label>
                            <select name="faculty" id="facultySelect" onchange="updateDepartments()">
                                <option value="">Select...</option>
                                <?php
                                $fac_query = $conn->query("SELECT faculty_id, name FROM faculties ORDER BY name ASC");
                                while($f = $fac_query->fetch_assoc()) {
                                    $sel = ($student['faculty_id'] == $f['faculty_id']) ? 'selected' : '';
                                    echo '<option value="'.$f['faculty_id'].'" '.$sel.'>'.htmlspecialchars($f['name']).'</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Department</label>
                            <select name="department" id="deptSelect" data-current="<?php echo htmlspecialchars($student['department_id']); ?>">
                                <option value="<?php echo htmlspecialchars($student['department_id']); ?>"><?php echo htmlspecialchars($student['department_name'] ?: 'Select Faculty First'); ?></option>
                            </select>
                        </div>
                        <div class="form-group flex items-end ml-4">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="has_special_needs" value="1" <?php if(!empty($student['has_special_needs'])) echo 'checked'; ?> class="w-5 h-5 cursor-pointer text-primary border-gray-300 rounded">
                                <span class="text-sm font-medium">I have documented Special Needs</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Medical Section -->
                <div class="mb-8">
                    <h3 class="flex items-center gap-3 text-lg font-bold mb-6 pb-2 text-danger border-b border-red-100">
                        <span class="inline-flex w-8 h-8 bg-red-50 text-danger rounded items-center justify-center"><i class="fa-solid fa-heart-pulse"></i></span>
                        Medical & Health Status
                    </h3>

                    <div class="alert alert-danger mb-6">
                        <p class="text-sm">
                            <strong>Note:</strong> Information provided here directly impacts your room allocation priority. False claims will be verified by the University Health Center.
                        </p>
                    </div>

                    <div class="grid grid-cols-2">
                        <div class="form-group">
                            <label>Medical Condition</label>
                            <select name="medical_condition">
                                <option value="None" <?php if(($student['condition_category']??'')=='None') echo 'selected'; ?>>None / Healthy</option>
                                <option value="Asthma" <?php if(($student['condition_category']??'')=='Asthma') echo 'selected'; ?>>Asthma</option>
                                <option value="Epilepsy" <?php if(($student['condition_category']??'')=='Epilepsy') echo 'selected'; ?>>Epilepsy</option>
                                <option value="Ulcer" <?php if(($student['condition_category']??'')=='Ulcer') echo 'selected'; ?>>Ulcer</option>
                                <option value="Sickle Cell" <?php if(($student['condition_category']??'')=='Sickle Cell') echo 'selected'; ?>>Sickle Cell Disease</option>
                                <option value="Visual Impairment" <?php if(($student['condition_category']??'')=='Visual Impairment') echo 'selected'; ?>>Visual Impairment</option>
                                <option value="Physical Disability" <?php if(($student['condition_category']??'')=='Physical Disability') echo 'selected'; ?>>Physical Disability</option>
                                <option value="Cardiovascular" <?php if(($student['condition_category']??'')=='Cardiovascular') echo 'selected'; ?>>Cardiovascular</option>
                                <option value="Neurological" <?php if(($student['condition_category']??'')=='Neurological') echo 'selected'; ?>>Neurological</option>
                                <option value="Respiratory" <?php if(($student['condition_category']??'')=='Respiratory') echo 'selected'; ?>>Respiratory</option>
                                <option value="Mobility" <?php if(($student['condition_category']??'')=='Mobility') echo 'selected'; ?>>Mobility</option>
                                <option value="Other" <?php if(($student['condition_category']??'')=='Other') echo 'selected'; ?>>Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Mobility Status</label>
                            <select name="mobility_status">
                                <option value="Normal Mobility" <?php if(($student['mobility_status']??'')=='Normal Mobility') echo 'selected'; ?>>Normal Mobility</option>
                                <option value="Wheelchair User" <?php if(($student['mobility_status']??'')=='Wheelchair User') echo 'selected'; ?>>Wheelchair User</option>
                                <option value="Crutches/Walker" <?php if(($student['mobility_status']??'')=='Crutches/Walker') echo 'selected'; ?>>Use of Crutches/Walker</option>
                                <option value="Artificial Limb" <?php if(($student['mobility_status']??'')=='Artificial Limb') echo 'selected'; ?>>Artificial Limb</option>
                            </select>
                        </div>
                        
                        <div class="form-group mt-4">
                            <label>Condition Severity Level</label>
                            <select name="severity_level">
                                <option value="High" <?php if(($student['severity_level']??'')=='High') echo 'selected'; ?>>High</option>
                                <option value="Medium" <?php if(($student['severity_level']??'')=='Medium') echo 'selected'; ?>>Medium</option>
                                <option value="Low" <?php if(($student['severity_level']??'')=='Low') echo 'selected'; ?>>Low</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="text-right mt-6">
                    <button class="btn btn-primary">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </main>
</div>

<script src="js/departments.js"></script>
</body>
</html>
