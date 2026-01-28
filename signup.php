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
                $gen = sanitize_input($_POST['gender']);
                
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
    <!-- Left: Brand (Blue Gradient) -->
    <!-- Left: Brand (Blue Gradient) -->
    <div class="auth-left text-center">
        <!-- Blobs for animation -->
        <div class="hero-blob opacity-20 w-[400px] h-[400px] -top-24 -left-24 absolute rounded-full bg-white blur-3xl"></div>
        
        <div class="brand-content relative z-10 animate-fade-in text-left pl-12">
            <h1 class="serif mb-2 text-white font-bold leading-none text-6xl">Student<br>Registration</h1>
            <p class="mb-12 text-sm text-gray-300 tracking-widest uppercase font-semibold">Redeemer's University</p>
            
            <div class="brand-border">
                <p class="text-white text-lg font-light leading-relaxed mb-6">Join the unified portal for fair and transparent hostel allocation.</p>
                <ul class="text-white text-sm font-light space-y-4 list-none">
                    <li class="flex items-center gap-3"><i class="fa-solid fa-check text-accent"></i> Secure Data Handling</li>
                    <li class="flex items-center gap-3"><i class="fa-solid fa-check text-accent"></i> Automated Medical Scoring</li>
                    <li class="flex items-center gap-3"><i class="fa-solid fa-check text-accent"></i> Fair Hostel Placement</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Right: Form -->
    <div class="auth-right">
        <div class="auth-box animate-fade-in max-w-[600px]">
            <div class="mb-8">
                <span class="badge badge-info mb-4">NEW ACCOUNT</span>
                <h2 class="mb-2 text-primary serif text-4xl">Create Profile</h2>
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
                    <label class="text-sm font-bold text-slate-700 mb-2">Full Name</label>
                    <input type="text" name="full_name" placeholder="Surname Firstname Middle" required class="input-auth">
                </div>

                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                       <label class="text-sm font-bold text-slate-700 mb-2">Matric Number</label>
                       <input type="text" name="matric_no" placeholder="RUN/CMP/22/..." required class="input-auth">
                    </div>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label class="text-sm font-bold text-slate-700 mb-2">Level</label>
                        <select name="level" class="input-auth">
                            <option value="100">100 Level</option>
                            <option value="200">200 Level</option>
                            <option value="300">300 Level</option>
                            <option value="400">400 Level</option>
                            <option value="500">500 Level</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="text-sm font-bold text-slate-700 mb-2">Gender</label>
                        <select name="gender" class="input-auth" required>
                            <option value="">Select...</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label class="text-sm font-bold text-slate-700 mb-2">Faculty</label>
                        <select name="faculty" id="facultySelect" required onchange="updateDepartments()" class="input-auth">
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
                        <label class="text-sm font-bold text-slate-700 mb-2">Department</label>
                        <select name="department" id="deptSelect" required class="input-auth">
                            <option value="">Select Faculty First</option>
                        </select>
                    </div>
                </div>

<script src="js/departments.js"></script>

                <div class="form-group mb-4">
                    <label class="text-sm font-bold text-slate-700 mb-2">Email Address</label>
                    <input type="email" name="email" required class="input-auth">
                </div>

                <div class="form-group mb-8">
                    <label class="text-sm font-bold text-slate-700 mb-2">Password</label>
                    <input type="password" name="password" placeholder="Create a strong password" required class="input-auth" minlength="8" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" title="Must contain at least one number and one uppercase and lowercase letter, and at least 8 or more characters">
                    <div class="text-xs text-muted mt-2">For security, please ensure your password is at least 8 characters long and includes a mix of letters and numbers.</div>
                </div>

                <button class="btn btn-primary w-full mb-4">Register Account</button>
                
                <div class="text-center text-sm">
                    Already have an account? <a href="login.php" class="text-primary fw-700">Sign In</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>