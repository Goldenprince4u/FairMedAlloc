<?php
/**
 * Signup Page
 * New student registration.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

$msg = "";
$msg_type = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    check_csrf(); // Security Gate

    $matric = sanitize_input($_POST['matric_no']);
    $email  = sanitize_input($_POST['email']);
    $name   = sanitize_input($_POST['full_name']);
    $pass   = $_POST['password'];
    $level  = (int)($_POST['level'] ?? 100);
    $role   = 'student'; 

    // Auto-role detection for admin (Development only)
    if (stripos($matric, 'ADMIN') !== false) {
        $role = 'admin';
    }

    // Check Existence
    $check = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
    $check->bind_param("s", $matric);
    $check->execute();
    
    if ($check->get_result()->num_rows > 0) {
        $msg = "Matric number already exists.";
        $msg_type = "error";
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        
        // 1. Create User
        $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $matric, $hash, $role);
        
        if ($stmt->execute()) {
            $new_id = $conn->insert_id;
            
            // 2. Create Profile
            if ($role === 'student') {
                $fac = sanitize_input($_POST['faculty']);
                $dept = sanitize_input($_POST['department']);
                $gen = 'Male'; // Default
                
                $stmt2 = $conn->prepare("INSERT INTO student_profiles (user_id, matric_no, full_name, level, faculty, department, gender) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt2->bind_param("ississs", $new_id, $matric, $name, $level, $fac, $dept, $gen);
                $stmt2->execute();
            }

            // Auto Login
            $_SESSION['logged_in'] = true;
            $_SESSION['user_id'] = $new_id;
            $_SESSION['role'] = $role;
            $_SESSION['username'] = $matric;
            $_SESSION['profile_pic'] = 'default.png';

            header("Location: " . ($role === 'admin' ? 'admin_dashboard.php' : 'student_dashboard.php'));
            exit();
        } else {
            $msg = "Error creating account. Please try again.";
            $msg_type = "error";
        }
    }
}

$page_title = "Create Account | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="auth-container">
    <!-- Left: Brand -->
    <div class="auth-left text-center">
        <!-- Blobs for animation (Keeping subtle background) -->
        <div class="hero-blob" style="width: 500px; height: 500px; top: -150px; left: -150px; opacity: 0.2; background: var(--c-accent);"></div>
        
        <div class="brand-content relative z-10 animate-fade-in text-left pl-12">
            <h1 class="serif mb-2" style="font-size: 3rem; line-height: 1.1; color: white; font-weight: 700;">Student<br>Registration</h1>
            <p class="mb-12 text-sm text-gray-300 tracking-widest uppercase font-semibold">Join the Fair Allocation System</p>
            
            <div style="border-left: 4px solid var(--c-accent); padding-left: 1.5rem;">
                <ul class="text-white text-lg font-light space-y-4" style="list-style: none;">
                    <li class="flex items-center gap-3"><i class="fa-solid fa-check text-accent"></i> Secure Data Handling</li>
                    <li class="flex items-center gap-3"><i class="fa-solid fa-check text-accent"></i> Automated Medical Scoring</li>
                    <li class="flex items-center gap-3"><i class="fa-solid fa-check text-accent"></i> Fair Hostel Placement</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Right: Form -->
    <div class="auth-right" style="background: white;">
        <div class="auth-box animate-fade-in" style="max-width: 600px;">
            <div class="mb-8">
                <span class="badge badge-success mb-4" style="background: #ecfdf5; color: #047857; border-radius: 4px; padding: 4px 8px; font-size: 0.75rem; font-weight: 700;">NEW ACCOUNT</span>
                <h2 class="mb-2 text-primary serif" style="font-size: 2rem;">Create Student Profile</h2>
                <p class="text-muted text-lg">Fill in your academic details to get started.</p>
            </div>

            <?php if($msg): ?>
                <div class="alert <?php echo $msg_type == 'error' ? 'alert-danger' : 'alert-success'; ?>">
                    <?php echo $msg; ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php csrf_field(); ?>

                <div class="form-group mb-4">
                    <label class="font-bold text-slate-700 text-sm mb-2">Full Name</label>
                    <input type="text" name="full_name" placeholder="e.g. John Doe" required style="background: #eff6ff; border-color: transparent; padding: 0.8rem;">
                </div>

                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                       <label class="font-bold text-slate-700 text-sm mb-2">Matric Number</label>
                       <input type="text" name="matric_no" placeholder="RUN/CMP/22/..." required style="background: #eff6ff; border-color: transparent; padding: 0.8rem;">
                    </div>
                    <div class="form-group">
                        <label class="font-bold text-slate-700 text-sm mb-2">Level</label>
                        <select name="level" style="background: #eff6ff; border-color: transparent; padding: 0.8rem;">
                            <option value="100">100 Level</option>
                            <option value="200">200 Level</option>
                            <option value="300">300 Level</option>
                            <option value="400">400 Level</option>
                            <option value="500">500 Level</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label class="font-bold text-slate-700 text-sm mb-2">Faculty</label>
                        <select name="faculty" id="facultySelect" required onchange="updateDepartments()" style="background: #eff6ff; border-color: transparent; padding: 0.8rem;">
                            <option value="">Select...</option>
                            <option value="Faculty of Computing and Digital Technologies">Faculty of Computing and Digital Technologies</option>
                            <option value="Natural Sciences">Natural Sciences</option>
                            <option value="Basic Medical Sciences">Basic Medical Sciences</option>
                            <option value="Management Sciences">Management Sciences</option>
                            <option value="Engineering">Engineering</option>
                            <option value="Humanities">Humanities</option>
                            <option value="Law">Law</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="font-bold text-slate-700 text-sm mb-2">Department</label>
                        <select name="department" id="deptSelect" required style="background: #eff6ff; border-color: transparent; padding: 0.8rem;">
                            <option value="">Select Faculty First</option>
                        </select>
                    </div>
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
                    deptSelect.innerHTML = '<option value="">Select Department</option>';
                    
                    if(faculty && departments[faculty]) {
                        departments[faculty].forEach(dept => {
                            const option = document.createElement("option");
                            option.value = dept;
                            option.text = dept;
                            deptSelect.appendChild(option);
                        });
                    }
                }
                </script>

                <div class="form-group mb-4">
                    <label class="font-bold text-slate-700 text-sm mb-2">Email Address</label>
                    <input type="email" name="email" required style="background: #eff6ff; border-color: transparent; padding: 0.8rem;">
                </div>

                <div class="form-group mb-8">
                    <label class="font-bold text-slate-700 text-sm mb-2">Password</label>
                    <input type="password" name="password" placeholder="Create a strong password" required style="background: #eff6ff; border-color: transparent; padding: 0.8rem;">
                </div>

                <button class="btn btn-primary w-full mb-4" style="width: 100%;">Register & Proceed</button>
                
                <div class="text-center" style="font-size: 0.875rem;">
                    Already have an account? <a href="login.php" style="font-weight: 600;">Sign In</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>