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
        $stmt_user = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'student')");
        $stmt_profile = $conn->prepare("INSERT INTO student_profiles (user_id, matric_no, full_name, level, faculty, department, gender) VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        while (($row = fgetcsv($file)) !== false) {
            // Expected CSV: Matric No, Full Name, Level, Faculty, Department, Gender
            if (count($row) < 6) continue; // Skip invalid rows
            
            $matric = trim($row[0]);
            $name   = trim($row[1]);
            $level  = (int)trim($row[2]);
            $faculty = trim($row[3]);
            $dept   = trim($row[4]);
            $gender = trim($row[5]); // Added Gender column requirement
            
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
            
            $stmt_user->bind_param("ss", $matric, $hash);
            if ($stmt_user->execute()) {
                $uid = $conn->insert_id;
                
                // Create Profile
                $stmt_profile->bind_param("ississs", $uid, $matric, $name, $level, $faculty, $dept, $gender);
                $stmt_profile->execute();
                
                // NEW: Medical Record from CSV
                // Format: ..., Gender, Condition, Severity(1-10)
                if (!empty($row[6])) {
                    $condition = trim($row[6]);
                    $severity = !empty($row[7]) ? (int)trim($row[7]) : 5;
                    
                    if ($condition && strtolower($condition) !== 'none') {
                        $stmt_med = $conn->prepare("INSERT INTO medical_records (student_id, condition_category, condition_details, severity_level, urgency_score) VALUES (?, ?, ?, ?, ?)");
                        
                        // Simple heuristic score for now (Model will update it later)
                        $score = ($severity * 10); 
                        $details = "$condition (Imported)";
                        
                        $stmt_med->bind_param("issid", $uid, $condition, $details, $severity, $score);
                        $stmt_med->execute();
                    }
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
                Required Columns: Matric No, Full Name, Level, Faculty, Department
            </div>
        </div>
    </main>
</div>
</body>
</html>
