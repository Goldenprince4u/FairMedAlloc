<?php
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';
require_once 'includes/UrgencyScoreService.php';

$msg = "";
$msg_type = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    check_csrf();

    $matric = sanitize_input($_POST['matric_no'] ?? '');
    $email  = sanitize_input($_POST['email'] ?? '');
    $name   = sanitize_input($_POST['full_name'] ?? '');
    $pass   = $_POST['password'] ?? '';
    $level  = (int)($_POST['level'] ?? 100);
    $role   = 'student';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = "Please provide a valid email address.";
        $msg_type = "error";
    } elseif (strlen($pass) < 8) {
        $msg = "Password must be at least 8 characters long.";
        $msg_type = "error";
    } else {
        $check = DbHelper::prepare($conn, "SELECT user_id FROM users WHERE username = ?", 'student signup duplicate check');

        if (!$check) {
            $msg = "Account creation is temporarily unavailable. Please try again shortly.";
            $msg_type = "error";
        } else {
            $check->bind_param("s", $matric);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;
            $check->close();

            if ($exists) {
                $msg = "Matric number already exists.";
                $msg_type = "error";
            } else {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $dept_id = (int)($_POST['department'] ?? 0);
                $gen = sanitize_input($_POST['gender'] ?? '');
                $new_id = 0;

                $conn->begin_transaction();

                try {
                    $userStmt = DbHelper::prepare(
                        $conn,
                        "INSERT INTO users (username, full_name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)",
                        'student signup user insert'
                    );
                    if (!$userStmt) {
                        throw new RuntimeException('Unable to create the student account.');
                    }
                    $userStmt->bind_param("sssss", $matric, $name, $email, $hash, $role);
                    if (!$userStmt->execute()) {
                        throw new RuntimeException('Unable to create the student account.');
                    }
                    $new_id = (int)$conn->insert_id;
                    $userStmt->close();

                    $specialNeedsFlag = 0;
                    $profileStmt = DbHelper::prepare(
                        $conn,
                        "INSERT INTO student_profiles (user_id, level, department_id, gender, has_special_needs) VALUES (?, ?, ?, ?, ?)",
                        'student signup profile insert'
                    );
                    if (!$profileStmt) {
                        throw new RuntimeException('Unable to save the student profile.');
                    }
                    $profileStmt->bind_param("iiisi", $new_id, $level, $dept_id, $gen, $specialNeedsFlag);
                    if (!$profileStmt->execute()) {
                        throw new RuntimeException('Unable to save the student profile.');
                    }
                    $profileStmt->close();

                    $condition = trim($_POST['medical_condition'] ?? '');
                    $mobility  = UrgencyScoreService::normalizeMobility((string)($_POST['mobility'] ?? '0'));
                    $severityInput = trim((string)($_POST['severity_level'] ?? 'Low'));
                    $has_condition = ($condition && $condition !== 'None / Healthy');
                    $has_mobility  = ($mobility !== 'Normal Mobility');

                    if ($has_condition || $has_mobility) {
                        // Mobility-only students: condition is 'None' — their need is captured by mobility_status alone.
                        $record_condition = $has_condition ? $condition : 'None';
                        $details = $has_condition
                            ? "$condition (Self-Reported)"
                            : "Mobility Support Required (Self-Reported)";

                        $severity = 'Low';
                        if ($has_condition && in_array($condition, ['Sickle Cell Disease', 'Epilepsy', 'Cardiovascular', 'Asthma'], true)) {
                            $severity = 'High';
                        } elseif ($has_condition && in_array($condition, ['Visual Impairment'], true)) {
                            $severity = 'Medium';
                        }

                        if ($mobility === 'Wheelchair User') {
                            $severity = 'High';
                        } elseif (in_array($mobility, ['Artificial Limb', 'Crutches/Walker'], true) && $severity !== 'High') {
                            $severity = 'Medium';
                        }

                        $severityMap = [
                            'low' => 'Low',
                            'medium' => 'Medium',
                            'high' => 'High',
                        ];
                        $severity = $severityMap[strtolower($severityInput)] ?? $severity;

                        $scorePayload = [
                            'id' => $new_id,
                            'condition' => $record_condition,
                            'mobility' => $mobility,
                            'severity' => $severity,
                            'academic_level' => $level,
                            'has_special_needs' => (int)$has_mobility,
                            'is_requested' => (int)$has_mobility,
                        ];

                        try {
                            $scoreService = new UrgencyScoreService();
                            $scoreResult  = $scoreService->scoreStudent($scorePayload);
                            $score        = (float)$scoreResult['score'];
                        } catch (Exception $e) {
                            error_log('[FairMedAlloc] Signup scoring fell back to PHP rules: ' . $e->getMessage());
                            $score = UrgencyScoreService::calculateFallbackScore($scorePayload);
                        }

                        $specialNeedsFlag = (int)$has_mobility;
                        $updateProfileStmt = DbHelper::prepare(
                            $conn,
                            "UPDATE student_profiles SET has_special_needs = ? WHERE user_id = ?",
                            'student signup profile update'
                        );
                        if (!$updateProfileStmt) {
                            throw new RuntimeException('Unable to update mobility details.');
                        }
                        $updateProfileStmt->bind_param("ii", $specialNeedsFlag, $new_id);
                        if (!$updateProfileStmt->execute()) {
                            throw new RuntimeException('Unable to update mobility details.');
                        }
                        $updateProfileStmt->close();

                        $medicalStmt = DbHelper::prepare(
                            $conn,
                            "INSERT INTO medical_records (student_id, condition_category, condition_details, severity_level, urgency_score, mobility_status, is_requested_mobility) VALUES (?, ?, ?, ?, ?, ?, ?)",
                            'student signup medical insert'
                        );
                        if (!$medicalStmt) {
                            throw new RuntimeException('Unable to save the medical record.');
                        }
                        $medicalStmt->bind_param("isssdsi", $new_id, $record_condition, $details, $severity, $score, $mobility, $specialNeedsFlag);
                        if (!$medicalStmt->execute()) {
                            throw new RuntimeException('Unable to save the medical record.');
                        }
                        $medicalStmt->close();
                    }

                    $conn->commit();

                    session_regenerate_id(true);
                    $_SESSION['logged_in']   = true;
                    $_SESSION['user_id']     = $new_id;
                    $_SESSION['role']        = $role;
                    $_SESSION['username']    = $matric;
                    $_SESSION['full_name']   = $name;
                    $_SESSION['profile_pic'] = 'default.png';
                    $_SESSION['must_change_password'] = false;

                    header("Location: student_dashboard.php");
                    exit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    error_log('[FairMedAlloc] Signup failed: ' . $e->getMessage());
                    $msg = "Error creating account. Please try again.";
                    $msg_type = "error";
                }
            }
        }
    }
}

$page_title = "Create Account | FairMedAlloc";
require_once 'includes/header.php';
?>
<style>
    /* Mobile-first responsive overrides for signup form */
    @media (max-width: 768px) {
        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="number"],
        input[type="date"],
        select,
        textarea {
            font-size: 1rem !important;
            min-height: 48px;
        }
        .input-group {
            position: relative;
        }
        .input-icon {
            font-size: 1rem;
            left: 12px;
        }
        .auth-headline {
            font-size: clamp(1.25rem, 5vw, 1.75rem) !important;
        }
        .auth-subtitle {
            font-size: 0.9rem !important;
        }
        .form-group {
            margin-bottom: 1.25rem;
        }
        .form-group label {
            font-size: 0.95rem;
            font-weight: 600;
        }
    }
</style>

<div class="auth-container">
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

    <div class="auth-right">
        <div class="auth-box animate-fade-in">
            <div class="mb-8">
                <span class="badge badge-primary mb-4" style="font-size:0.68rem;letter-spacing:0.1em;">NEW ACCOUNT</span>
                <h2 style="font-size:1.75rem;margin-bottom:0.35rem;color:var(--c-text-head);">Create Profile</h2>
                <p class="text-muted" style="font-size:0.9rem;">Fill in your academic details to get started.</p>
            </div>

            <?php if($msg): ?>
                <div class="alert <?php echo $msg_type == 'error' ? 'alert-danger' : 'alert-success'; ?>">
                    <?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?>
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
                                echo '<option value="'.$f['faculty_id'].'">'.htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8').'</option>';
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

                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label class="text-sm fw-700 mb-2">Medical Condition</label>
                        <select name="medical_condition" id="medCondition" required class="input-auth">
                            <option value="">Select Status...</option>
                            <option value="None / Healthy">None / Healthy</option>
                            <option value="Asthma">Asthma</option>
                            <option value="Epilepsy">Epilepsy</option>
                            <option value="Ulcer">Ulcer</option>
                            <option value="Sickle Cell Disease">Sickle Cell Disease</option>
                            <option value="Cardiovascular">Cardiovascular</option>
                            <option value="Visual Impairment">Visual Impairment</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group" id="mobilityGroup">
                        <label class="text-sm fw-700 mb-2">Mobility Status</label>
                        <select name="mobility" id="mobilityInput" class="input-auth">
                            <option value="0">Normal Mobility</option>
                            <option value="1">Artificial Limb</option>
                            <option value="2">Crutches / Walker</option>
                            <option value="3">Wheelchair User</option>
                        </select>
                        <div class="text-xs text-muted mt-2">
                            Select if you require mobility support. A wheelchair or
                            crutch declaration <strong>alone</strong> is enough to
                            trigger priority scoring - no medical condition required.
                        </div>
                    </div>
                </div>

                <div class="form-group mb-4">
                    <label class="text-sm fw-700 mb-2">Condition Severity</label>
                    <select name="severity_level" id="severityLevel" class="input-auth" required>
                        <option value="Low">Low</option>
                        <option value="Medium">Medium</option>
                        <option value="High">High</option>
                    </select>
                    <div class="text-xs text-muted mt-2">
                        Choose the level that best describes how serious the condition is for your daily routine.
                    </div>
                </div>

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
        icon.classList.toggle('fa-eye', !isHidden);
        icon.classList.toggle('fa-eye-slash', isHidden);
    }
}
</script>
</body>
</html>
