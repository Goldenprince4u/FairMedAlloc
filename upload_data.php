<?php
/**
 * Data Import
 * Bulk student registration.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';
if (($_SESSION['role'] ?? '') !== 'admin') { header("Location: login.php"); exit(); }

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    if ($_FILES['csv_file']['error'] === 0) {
        $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $count = 0;
        $duplicates = 0;
        
        // Skip header
        fgetcsv($file);
        
        // Prepare Statements
        $stmt_check = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt_user = $conn->prepare("INSERT INTO users (username, full_name, password_hash, role) VALUES (?, ?, ?, 'student')");
        $stmt_profile = $conn->prepare("INSERT INTO student_profiles (user_id, matric_no, level, faculty, department, gender) VALUES (?, ?, ?, ?, ?, ?)");
        
        while (($row = fgetcsv($file)) !== false) {
            // Expected CSV: Matric No, Full Name, Level, Faculty, Department, Gender, Medical Condition
            if (count($row) < 7) continue; // Skip if mandatory columns are missing
            
            $matric = trim($row[0]);
            $name   = trim($row[1]);
            $level  = (int)trim($row[2]);
            $faculty = trim($row[3]);
            $dept   = trim($row[4]);
            $gender = trim($row[5]);
            $condition = trim($row[6]);

            // Enforce Mandatory Medical Declaration
            if (empty($condition)) {
                // Skip rows with empty medical condition (it must be at least "None")
                continue; 
            }
            
            // Check Duplicate
            $stmt_check->bind_param("s", $matric);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                $duplicates++;
                continue; 
            }
            
            // Create User (Password = lowercase matric, e.g. "run/cmp/...")
            $password = strtolower($matric); 
            $hash = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt_user->bind_param("sss", $matric, $name, $hash);
            if ($stmt_user->execute()) {
                $uid = $conn->insert_id;
                
                // Create Profile
                $stmt_profile->bind_param("isisss", $uid, $matric, $level, $faculty, $dept, $gender);
                $stmt_profile->execute();
                
                // Process Medical Record
                // Logic: Only insert if not "None"
                if (strtolower($condition) !== 'none') {
                    $severity = !empty($row[7]) ? (int)trim($row[7]) : 3;
                    $mobility = !empty($row[8]) ? trim($row[8]) : 'Normal';
                    
                    // Call Python ML model for urgency score calculation
                    $student_data = [
                        'id' => $uid,
                        'condition' => $condition,
                        'severity' => $severity,
                        'mobility' => $mobility,
                        'academic_level' => $level
                    ];
                    
                    $temp_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fairmed_csv_' . uniqid() . '.json';
                    file_put_contents($temp_file, json_encode($student_data));
                    
                    $script_path = __DIR__ . '/ml_models/predict.py';
                    $command = "python \"$script_path\" \"$temp_file\"";
                    $output = shell_exec($command);
                    $result = json_decode($output, true);
                    
                    if (file_exists($temp_file)) unlink($temp_file);
                    
                    // Extract score from ML result or fallback
                    $score = ($result['status'] === 'success' && isset($result['results'][$uid])) 
                        ? $result['results'][$uid] 
                        : ($severity * 15); // Fallback
                    
                    $details = "$condition (Imported via CSV)";
                    
                    $stmt_med = $conn->prepare("INSERT INTO medical_records (student_id, condition_category, condition_details, severity_level, urgency_score, mobility_status) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt_med->bind_param("issids", $uid, $condition, $details, $severity, $score, $mobility);
                    $stmt_med->execute();
                }

                $count++;
            }
        }
        fclose($file);
        $msg = "Processed: $count students registered. Duplicates skipped: $duplicates.";
    } else {
        $msg = "File upload error.";
    }
}

$page_title = "Import Data | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <h1>Data Import</h1>
        <p class="text-muted mb-8">Bulk student registration via CSV.</p>

        <?php if($msg): ?>
            <div class="badge badge-success mb-6 w-full"><?php echo $msg; ?></div>
        <?php endif; ?>

        <div class="card upload-zone">
            <i class="fa-solid fa-cloud-arrow-up text-4xl text-muted mb-4 text-5xl"></i>
            <h3 class="mb-2">Drag Configuration File Here</h3>
            <p class="text-muted mb-6">or click to browse local storage</p>

            <form method="post" enctype="multipart/form-data">
                <?php csrf_field(); ?>
                <input type="file" name="csv_file" id="fileIn" class="hidden" onchange="this.form.submit()">
                <label for="fileIn" class="btn btn-primary">
                    Select CSV File
                </label>
            </form>
            
            <div class="mt-8 text-xs text-muted">
                <strong>Required:</strong> Matric No, Full Name, Level, Faculty, Department, Gender, Medical Condition<br>
                <strong>Optional:</strong> Severity (1-5), Mobility (Normal/Wheelchair User/Crutches/Walker)
            </div>
        </div>
    </main>
</div>
</body>
</html>
