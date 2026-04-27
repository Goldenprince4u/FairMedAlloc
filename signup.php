<?php
/**
 * signup.php -- Student Registration
 * ==================================
 * Handles new student account creation:
 *   1. Validates & sanitizes all form inputs.
 *   2. Checks for duplicate matric numbers.
 *   3. Inserts into: users, student_profiles, medical_records (if applicable).
 *   4. Auto-logs the student in on success and redirects to dashboard.
 *
 * Security measures applied:
 *   - CSRF token validation on every POST.
 *   - Prepared statements for all DB queries (prevents SQL injection).
 *   - Password hashing with PASSWORD_DEFAULT (bcrypt).
 *   - Server-side email format validation.
 *   - Minimum password length enforced server-side.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';
require_once 'includes/UrgencyScoreService.php';

$msg = "";
$msg_type = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // --- Security Gate: Validate CSRF Token ---
    // Prevents Cross-Site Request Forgery attacks.
    check_csrf();

    // --- Sanitize All Inputs Before Processing ---
    // sanitize_input() trims, strips slashes, and encodes HTML special chars.
    $matric = sanitize_input($_POST['matric_no']);
    $email  = sanitize_input($_POST['email']);
    $name   = sanitize_input($_POST['full_name']);
    $pass   = $_POST['password'];           // Not sanitized â€” password_hash() handles raw value.
    $level  = (int)($_POST['level'] ?? 100);
    $role   = 'student';                    // Role is always 'student' on this page; never trust user input for role.

    // --- Server-side Email Format Validation ---
    // The HTML `type="email"` is client-only and can be bypassed. Always re-check on the server.
    if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        $msg = "Please provide a valid email address.";
        $msg_type = "error";
    }
    // --- Duplicate Matric Number Check ---
    // Matric is the unique student identifier; reject if already registered.
    elseif (($check = $conn->prepare("SELECT user_id FROM users WHERE username = ?")) &&
            $check->bind_param("s", $matric) &&
            $check->execute() &&
            $check->get_result()->num_rows > 0) {
        $msg = "Matric number already exists.";
        $msg_type = "error";
    } elseif (strlen($_POST['password']) < 8) {
        // --- Password Length Check (Server-side Enforcement) ---
        // The client-side minlength attribute is cosmetic only; enforce again here.
        $msg = "Password must be at least 8 characters long.";
        $msg_type = "error";
    } else {
        // --- Hash the Password (bcrypt via PASSWORD_DEFAULT) ---
        // Never store plain-text passwords. password_hash generates a secure salted hash.
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        
        // --- 1. Create Core User Account ---
        // Insert standard authentication credentials into the main users table.
        $stmt = $conn->prepare("INSERT INTO users (username, full_name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $matric, $name, $email, $hash, $role);
        
        if ($stmt->execute()) {
            // Capture the new user_id for use in related profile/medical tables.
            $new_id = $conn->insert_id;
            
            // --- 2. Create Student Academic Profile ---
            // Links the new user to their faculty, department, and level of study.
            $dept_id = (int)$_POST['department'];
            $gen = sanitize_input($_POST['gender']);
            
            $stmt2 = $conn->prepare("INSERT INTO student_profiles (user_id, level, department_id, gender) VALUES (?, ?, ?, ?)");
            $stmt2->bind_param("iiis", $new_id, $level, $dept_id, $gen);
            $stmt2->execute();

            // --- 3. Process Medical Record (conditional) ---
            // If the student reports a medical condition, store it as a pending record.
            // This data is later used by the XGBoost model to calculate priority scores.
            $condition = trim($_POST['medical_condition']);
            if ($condition && $condition !== 'None / Healthy') {
                $mobility = (int)($_POST['mobility'] ?? 0);
                $details  = "$condition (Self-Reported)";

                $severity = 'Low';
                if (in_array($condition, ['Sickle Cell Disease', 'Epilepsy', 'Cardiovascular', 'Asthma'])) {
                    $severity = 'High';
                } elseif (in_array($condition, ['Visual Impairment', 'Physical Disability'])) {
                    $severity = 'Medium';
                }

                if ($mobility === 3) {
                    $severity = 'High';
                } elseif ($mobility === 1 || $mobility === 2) {
                    if ($severity !== 'High') $severity = 'Medium';
                }

                $scorePayload = [
                    'id' => $new_id,
                    'condition' => $condition,
                    'mobility' => $mobility,
                    'severity' => $severity,
                    'academic_level' => $level,
                    'has_special_needs' => (int)($mobility > 0),
                    'is_requested' => (int)($mobility > 0),
                ];

                try {
                    $scoreService = new UrgencyScoreService();
                    $scoreResult = $scoreService->scoreStudent($scorePayload);
                    $score = (float)$scoreResult['score'];
                } catch (Exception $e) {
                    error_log('[FairMedAlloc] Signup scoring fell back to PHP rules: ' . $e->getMessage());
                    $score = UrgencyScoreService::calculateFallbackScore($scorePayload);
                }

                $stmt_med = $conn->prepare("INSERT INTO medical_records (student_id, condition_category, condition_details, severity_level, urgency_score, mobility_status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt_med->bind_param("isssdi", $new_id, $condition, $details, $severity, $score, $mobility);
                $stmt_med->execute();
            }

            // --- 4. Auto-Login After Registration ---
            // Immediately populate the session so the student lands on their dashboard.
            $_SESSION['logged_in']   = true;
            $_SESSION['user_id']     = $new_id;
            $_SESSION['role']        = $role;
            $_SESSION['username']    = $matric;
            $_SESSION['full_name']   = $name;
            $_SESSION['profile_pic'] = 'default.png';
            $_SESSION['must_change_password'] = false;

            // Redirect to the student dashboard after successful registration.
            header("Location: student_dashboard.php");
            exit();
        } else {
            // Database insertion failure â€” surface a generic error (do not expose DB details).
            $msg = "Error creating account. Please try again.";
            $msg_type = "error";
        }
    }
}

$page_title = "Create Account | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="auth-container">
    <!-- Left: Brand Panel -->
    <div class="auth-left">
        <div class="brand-content">
            <h1 class="auth-headline">Student<br>Registration</h1>
            <p class="auth-subtitle">Redeemer's University</p>

            <div class="brand-border">
                <p>Join the unified portal for fair and transparent hostel allocation.</p>
                <ul class="auth-feature-list">
                    <li><i class="fa-solid fa-check"></i> Secure Data Handling</li>
                    <li><i class="fa-solid fa-check"></i> Automated Medical Scoring</li>
                    <li><i class="fa-solid fa-check"></i> Fair Hostel Placement</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Right: Form -->
    <div class="auth-right">
        <div class="auth-box animate-fade-in">
            <div class="mb-8">
                <span class="badge badge-primary mb-4" style="font-size:0.68rem;letter-spacing:0.1em;">NEW ACCOUNT</span>
                <h2 style="font-size:1.75rem;margin-bottom:0.35rem;color:var(--c-text-head);">Create Profile</h2>
                <p class="text-muted" style="font-size:0.9rem;">Fill in your academic details to get started.</p>
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
                    <input type="text" name="full_name" placeholder="Surname Firstname Middle" required class="input-auth">
                </div>

                <div class="form-group mb-4">
                    <label class="text-sm fw-700 mb-2">Matric Number</label>
                    <input type="text" name="matric_no" placeholder="RUN/CMP/22/..." required class="input-auth">
                </div>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label class="text-sm fw-700 mb-2">Level</label>
                        <select name="level" class="input-auth">
                            <option value="100">100 Level</option>
                            <option value="200">200 Level</option>
                            <option value="300">300 Level</option>
                            <option value="400">400 Level</option>
                            <option value="500">500 Level</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="text-sm fw-700 mb-2">Gender</label>
                        <select name="gender" class="input-auth" required>
                            <option value="">Select...</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label class="text-sm fw-700 mb-2">Faculty</label>
                        <select name="faculty" id="facultySelect" required onchange="updateDepartments()" class="input-auth">
                            <option value="">Select...</option>
                            <?php
                            $fac_query = $conn->query("SELECT faculty_id, name FROM faculties ORDER BY name ASC");
                            while($f = $fac_query->fetch_assoc()) {
                                echo '<option value="'.$f['faculty_id'].'">'.htmlspecialchars($f['name']).'</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="text-sm fw-700 mb-2">Department</label>
                        <select name="department" id="deptSelect" required class="input-auth">
                            <option value="">Select Faculty First</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 mb-4">                    <div class="form-group">
                        <label class="text-sm fw-700 mb-2">Medical Condition</label>
                        <select name="medical_condition" id="medCondition" required class="input-auth" onchange="toggleMobility()">
                            <option value="">Select Status...</option>
                            <option value="None / Healthy">None / Healthy</option>
                            <option value="Asthma">Asthma</option>
                            <option value="Epilepsy">Epilepsy</option>
                            <option value="Ulcer">Ulcer</option>
                            <option value="Sickle Cell Disease">Sickle Cell Disease</option>
                            <option value="Cardiovascular">Cardiovascular</option>
                            <option value="Visual Impairment">Visual Impairment</option>
                            <option value="Physical Disability">Physical Disability</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                        <div class="form-group" id="mobilityGroup" style="display:none;">
                            <label class="text-sm fw-700 mb-2">Mobility Status</label>
                            <select name="mobility" id="mobilityInput" class="input-auth">
                                <option value="0">Normal Mobility</option>
                                <option value="1">Artificial Limb</option>
                                <option value="2">Crutches/Walker</option>
                                <option value="3">Wheelchair User</option>
                            </select>
                        </div>
                </div>

                <script>
                function toggleMobility() {
                    const cond = document.getElementById('medCondition').value;
                    const mobGroup = document.getElementById('mobilityGroup');
                    if (cond && cond !== 'None / Healthy') {
                        mobGroup.style.display = 'block';
                    } else {
                        mobGroup.style.display = 'none';
                        document.getElementById('mobilityInput').value = '0';
                    }
                }
                </script>

                <div class="form-group mb-4">
                    <label class="text-sm fw-700 mb-2">Email Address</label>
                    <input type="email" name="email" required class="input-auth">
                </div>

                <div class="form-group mb-8">
                    <label class="text-sm fw-700 mb-2">Password</label>
                    <div class="input-group" style="position:relative;">
                        <input type="password" id="signup-password" name="password"
                               placeholder="Create a strong password" required class="input-auth"
                               minlength="8" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}"
                               title="Must contain at least one number and one uppercase and lowercase letter, and at least 8 or more characters"
                               style="padding-right:2.75rem;">
                        <i class="fa-solid fa-eye"
                           id="toggleSignupPassword"
                           style="position:absolute;right:14px;top:50%;transform:translateY(-50%);cursor:pointer;font-size:0.85rem;color:var(--c-text-muted);"
                           onclick="toggleSignupPw()"
                           title="Toggle password visibility"></i>
                    </div>
                    <div class="text-xs text-muted mt-2">For security, please ensure your password is at least 8 characters long and includes a mix of letters and numbers.</div>
                </div>

                <div style="margin-top:1.5rem;">
                    <button type="submit" class="btn btn-primary w-full" id="signup-submit-btn" style="padding:0.8rem;">
                        <i class="fa-solid fa-user-plus"></i> Create Account
                    </button>
                </div>
                
                <div class="text-center mt-4" style="font-size:0.84rem;">
                    Already have an account? <a href="login.php" class="text-primary fw-700">Sign In &rarr;</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="js/departments.js"></script>
<script>
function toggleSignupPw() {
    var input = document.getElementById('signup-password');
    var icon  = document.getElementById('toggleSignupPassword');
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
