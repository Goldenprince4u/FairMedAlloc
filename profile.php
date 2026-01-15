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

// Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Profile Pic
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $ext = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $upload_dir = __DIR__ . "/uploads/profile_pics/";
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $new_name = "u{$user_id}_" . time() . ".$ext";
            
            if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $upload_dir . $new_name)) {
                $conn->query("UPDATE users SET profile_pic='$new_name' WHERE user_id=$user_id");
            }
        }
    }

    // 2. Data
    // Check if fields are set to avoid notices
    $name = sanitize_input($_POST['full_name'] ?? '');
    $lvl  = (int) ($_POST['level'] ?? 0);
    $faculty = sanitize_input($_POST['faculty'] ?? ''); 
    $dept = sanitize_input($_POST['department'] ?? '');
    $cond = sanitize_input($_POST['medical_condition'] ?? 'None');
    $mob  = sanitize_input($_POST['mobility_status'] ?? 'Normal Mobility');

    $stmt = $conn->prepare("UPDATE student_profiles SET full_name=?, level=?, faculty=?, department=? WHERE user_id=?");
    $stmt->bind_param("sisss", $name, $lvl, $faculty, $dept, $user_id);
    
    if ($stmt->execute()) {
        // Update Medical
        $score = 10;
        if ($cond !== 'None') $score += 50;
        if ($mob !== 'Normal Mobility') $score += 30;

        $check = $conn->query("SELECT record_id FROM medical_records WHERE student_id=$user_id");
        if ($check->num_rows > 0) {
            $m_stmt = $conn->prepare("UPDATE medical_records SET condition_category=?, mobility_status=?, urgency_score=? WHERE student_id=?");
            $m_stmt->bind_param("ssii", $cond, $mob, $score, $user_id);
        } else {
            $m_stmt = $conn->prepare("INSERT INTO medical_records (student_id, condition_category, mobility_status, urgency_score) VALUES (?, ?, ?, ?)");
            $m_stmt->bind_param("issi", $user_id, $cond, $mob, $score);
        }
        $m_stmt->execute();
        
        $msg = "Profile updated successfully.";
        $msg_type = "success";
    } else {
        $msg = "Error updating profile details.";
        $msg_type = "error";
    }
}

// Fetch Data
$stmt = $conn->prepare("SELECT p.*, m.condition_category, m.mobility_status, u.profile_pic FROM student_profiles p JOIN users u ON p.user_id = u.user_id LEFT JOIN medical_records m ON p.user_id = m.student_id WHERE p.user_id = ?");
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
            <a href="student_dashboard.php" class="btn btn-outline text-primary border-slate-300 hover:bg-slate-50">
                <i class="fa-solid fa-arrow-left mr-2"></i> Dashboard
            </a>
        </div>

        <?php if($msg): ?>
            <div class="mb-6 p-4 rounded-lg flex items-center gap-3 <?php echo $msg_type == 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
                 <i class="fa-solid <?php echo $msg_type == 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?>"></i>
                 <?php echo $msg; ?>
            </div>
        <?php endif; ?>

        <div class="glass-card max-w-4xl mx-auto p-0 mb-8">
             
             <!-- Header Banner -->
             <div class="p-8 relative overflow-hidden" style="background: linear-gradient(135deg, var(--c-primary) 0%, var(--c-primary-dark) 100%); color: white; border-radius: var(--radius-md) var(--radius-md) 0 0;">
                  
                  <div class="relative z-10 flex items-center gap-6">
                       <div class="relative group">
                           <img src="uploads/profile_pics/<?php echo $student['profile_pic'] ?: 'default.png'; ?>" 
                                class="avatar" style="width: 100px; height: 100px; border: 4px solid white;">
                           <label class="absolute bottom-0 right-0 bg-white text-primary p-2 rounded-full cursor-pointer shadow-md hover:bg-gray-100 transition-colors" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">
                               <i class="fa-solid fa-camera"></i>
                               <input type="file" name="profile_pic" class="hidden" style="display:none;" onchange="this.form.submit()" form="profileForm">
                           </label>
                       </div>
                       <div>
                           <h2 class="serializer" style="font-size: 1.75rem; color: white; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($student['full_name']); ?></h2>
                           <div class="opacity-90 text-sm mt-1 flex gap-4">
                               <span><i class="fa-solid fa-id-card mr-1"></i> <?php echo htmlspecialchars($student['matric_no']); ?></span>
                               <span><i class="fa-solid fa-layer-group mr-1"></i> <?php echo htmlspecialchars($student['level']); ?> Lvl</span>
                           </div>
                       </div>
                  </div>
             </div>

            <form method="post" enctype="multipart/form-data" id="profileForm" class="p-8">
                
                <!-- Academic Section -->
                <div class="mb-10">
                    <h3 class="flex items-center gap-3 text-lg font-bold mb-6 pb-2" style="color: var(--c-text-head); border-bottom: 1px solid var(--c-border);">
                        <span style="display:inline-flex; width: 32px; height: 32px; background: #eff6ff; color: var(--c-primary); border-radius: 4px; align-items: center; justify-content: center;"><i class="fa-solid fa-graduation-cap"></i></span>
                        Academic Information
                    </h3>

                    <div class="grid grid-cols-2">
                        <div class="form-group">
                            <label>Full Name</label>
                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($student['full_name']); ?>">
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
                                <option value="Faculty of Computing and Digital Technologies" <?php if($student['faculty'] == 'Faculty of Computing and Digital Technologies') echo 'selected'; ?>>Faculty of Computing and Digital Technologies</option>
                                <option value="Natural Sciences" <?php if($student['faculty'] == 'Natural Sciences') echo 'selected'; ?>>Natural Sciences</option>
                                <option value="Basic Medical Sciences" <?php if($student['faculty'] == 'Basic Medical Sciences') echo 'selected'; ?>>Basic Medical Sciences</option>
                                <option value="Management Sciences" <?php if($student['faculty'] == 'Management Sciences') echo 'selected'; ?>>Management Sciences</option>
                                <option value="Engineering" <?php if($student['faculty'] == 'Engineering') echo 'selected'; ?>>Engineering</option>
                                <option value="Humanities" <?php if($student['faculty'] == 'Humanities') echo 'selected'; ?>>Humanities</option>
                                <option value="Law" <?php if($student['faculty'] == 'Law') echo 'selected'; ?>>Law</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Department</label>
                            <select name="department" id="deptSelect">
                                <option value="<?php echo htmlspecialchars($student['department']); ?>"><?php echo htmlspecialchars($student['department'] ?: 'Select Faculty First'); ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Medical Section -->
                <div class="mb-8">
                    <h3 class="flex items-center gap-3 text-lg font-bold mb-6 pb-2" style="color: #991b1b; border-bottom: 1px solid #fee2e2;">
                        <span style="display:inline-flex; width: 32px; height: 32px; background: #fef2f2; color: #dc2626; border-radius: 4px; align-items: center; justify-content: center;"><i class="fa-solid fa-heart-pulse"></i></span>
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
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Mobility Status</label>
                            <select name="mobility_status">
                                <option value="Normal Mobility" <?php if(($student['mobility_status']??'')=='Normal Mobility') echo 'selected'; ?>>Normal Mobility</option>
                                <option value="Wheelchair User" <?php if(($student['mobility_status']??'')=='Wheelchair User') echo 'selected'; ?>>Wheelchair User</option>
                                <option value="Crutches/Walker" <?php if(($student['mobility_status']??'')=='Crutches/Walker') echo 'selected'; ?>>Use of Crutches/Walker</option>
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

<script>
const departments = {
    "Faculty of Computing and Digital Technologies": ["Computer Science", "Information Technology", "Cybersecurity"],
    "Natural Sciences": ["Biochemistry", "Industrial Mathematics", "Microbiology", "Physics", "Chemistry"],
    "Basic Medical Sciences": ["Nursing Science", "Physiology", "Anatomy", "Medical Laboratory Science"],
    "Management Sciences": ["Accounting", "Business Administration", "Economics", "Transport Management"],
    "Engineering": ["Civil Engineering", "Mechanical Engineering", "Electrical Engineering"],
    "Humanities": ["English", "History", "Theatre Arts"],
    "Law": ["Law"]
};

function updateDepartments() {
    const faculty = document.getElementById("facultySelect").value;
    const deptSelect = document.getElementById("deptSelect");
    const currentDept = "<?php echo $student['department']; ?>";
    
    deptSelect.innerHTML = '<option value="">Select Department</option>';
    
    if(faculty && departments[faculty]) {
        departments[faculty].forEach(dept => {
            const option = document.createElement("option");
            option.value = dept;
            option.text = dept;
            if (dept === currentDept) {
                option.selected = true;
            }
            deptSelect.appendChild(option);
        });
    }
}

// Run on load to set initial state
document.addEventListener('DOMContentLoaded', function() {
    updateDepartments();
});
</script>
</body>
</html>
