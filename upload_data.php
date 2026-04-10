<?php
/**
 * upload_data.php — Bulk Student CSV Import
 * ============================================
 * Admin-only page for mass-registering students from a CSV file.
 *
 * Expected CSV column order:
 *   [0] Matric No  [1] Full Name  [2] Level  [3] Faculty
 *   [4] Department [5] Gender     [6] Medical Condition
 *   [7] Severity (optional, 1-5)  [8] Mobility (optional)
 *
 * Security measures applied:
 *   - Session-based admin auth guard.
 *   - CSRF token validation on every POST.
 *   - Prepared statements for all DB queries (no SQL injection risk).
 *   - Duplicate matric number check before each insert.
 *   - CSV rows with missing required columns are silently skipped.
 *   - Default password set to lowercase matric number (students must reset on first login).
 *
 * BUGS FIXED:
 *   - $msg output was not escaped — now uses htmlspecialchars().
 *   - File size now capped at 5MB to prevent memory exhaustion on large uploads.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';
// Auth Guard: admin only
if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') { header("Location: admin_login.php"); exit(); }

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    // Validate CSRF token before processing any file data
    check_csrf();

    // Check for upload errors reported by PHP
    if ($_FILES['csv_file']['error'] !== 0) {
        $msg = "File upload error (PHP error code: " . (int)$_FILES['csv_file']['error'] . ")";
    // Enforce a 5MB file size limit to prevent memory exhaustion
    } elseif ($_FILES['csv_file']['size'] > 5 * 1024 * 1024) {
        $msg = "File too large. Maximum upload size is 5MB.";
    } else {
        $file      = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $count     = 0;
        $duplicates = 0;
        
        // Skip the CSV header row (column labels)
        fgetcsv($file);
        
        // Prepare reusable statements for performance across many rows
        $stmt_check       = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt_user        = $conn->prepare("INSERT INTO users (username, full_name, password_hash, role) VALUES (?, ?, ?, 'student')");
        $stmt_profile     = $conn->prepare("INSERT INTO student_profiles (user_id, level, department_id, gender) VALUES (?, ?, ?, ?)");
        // LIKE used for loose matching of department name strings from CSV
        $stmt_dept_lookup = $conn->prepare("SELECT department_id FROM departments WHERE name LIKE ? LIMIT 1");
        
        while (($row = fgetcsv($file)) !== false) {
            // Skip rows that don't have the minimum 7 required columns
            if (count($row) < 7) continue;
            
            $matric    = trim($row[0]);
            $name      = trim($row[1]);
            $level     = (int)trim($row[2]);
            $faculty   = trim($row[3]);
            $dept      = trim($row[4]);
            $gender    = trim($row[5]);
            $condition = trim($row[6]);

            // Medical condition column must be present (at minimum 'None')
            if (empty($condition)) continue;
            
            // --- Duplicate Detection ---
            // Skip rows where the matric number already exists in the DB.
            $stmt_check->bind_param("s", $matric);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                $duplicates++;
                continue;
            }
            
            // --- Default Password ---
            // Initial password = lowercase matric number.
            // Students should be prompted to change this on first login.
            // (TODO: Implement forced password change on first login)
            $password = strtolower($matric);
            $hash     = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt_user->bind_param("sss", $matric, $name, $hash);
            if ($stmt_user->execute()) {
                $uid = $conn->insert_id;
                
                // --- Department String-to-ID Mapping ---
                // CSV contains department names; DB expects integer IDs.
                // We use a LIKE query for loose matching.
                // Fallback to dept_id = 1 if no match found.
                $dept_id     = 1;
                $search_dept = "%" . $dept . "%";
                $stmt_dept_lookup->bind_param("s", $search_dept);
                $stmt_dept_lookup->execute();
                $res_dept = $stmt_dept_lookup->get_result();
                if ($res_dept->num_rows > 0) {
                    $row_dept = $res_dept->fetch_assoc();
                    $dept_id  = $row_dept['department_id'];
                }

                // Create the student's academic profile record
                $stmt_profile->bind_param("iiis", $uid, $level, $dept_id, $gender);
                $stmt_profile->execute();
                
                // --- Medical Record: Only if condition is not 'None' ---
                if (strtolower($condition) !== 'none') {
                    $severity = !empty($row[7]) ? trim($row[7]) : 'Low';
                    $mobility = !empty($row[8]) ? trim($row[8]) : 'Normal Mobility';
                    
                    // --- Machine Learning Score Calculation ---
                    // We bridge to Python/XGBoost via a temporary JSON file.
                    // The ML script reads the payload, calculates an urgency score, and returns JSON.
                    $student_data = [
                        'id'             => $uid,
                        'condition'      => $condition,
                        'severity'       => $severity,
                        'mobility'       => $mobility,
                        'academic_level' => $level
                    ];
                    
                    $temp_file   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fairmed_csv_' . uniqid() . '.json';
                    file_put_contents($temp_file, json_encode($student_data));
                    
                    $script_path = __DIR__ . '/ml_models/predict.py';
                    // escapeshellarg() prevents shell injection from any data in the file path
                    $command     = escapeshellcmd("python " . escapeshellarg($script_path) . " " . escapeshellarg($temp_file));
                    
                    $output = shell_exec($command);
                    $result = json_decode($output, true);
                    
                    // Clean up temporary file after execution
                    if (file_exists($temp_file)) unlink($temp_file);
                    
                    // Use ML score if available; fall back to severity * 15 heuristic
                    $sev_map = ['Low' => 1, 'Medium' => 2, 'High' => 3];
                    $sev_val = $sev_map[$severity] ?? 1;

                    $score = ($result['status'] === 'success' && isset($result['results'][$uid])) 
                        ? $result['results'][$uid] 
                        : ($sev_val * 15);
                    
                    $details  = "{$condition} (Imported via CSV)";
                    
                    $stmt_med = $conn->prepare("INSERT INTO medical_records (student_id, condition_category, condition_details, severity_level, urgency_score, mobility_status) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt_med->bind_param("isssds", $uid, $condition, $details, $severity, $score, $mobility);
                    $stmt_med->execute();
                }

                $count++;
            }
        }
        fclose($file);
        $msg = "Processed: {$count} students registered. Duplicates skipped: {$duplicates}.";
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
            <!-- SECURITY: htmlspecialchars() prevents XSS if $msg contains user-originated data -->
            <div class="badge badge-success mb-6 w-full"><?php echo htmlspecialchars($msg); ?></div>
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
                <strong>Optional:</strong> Severity (Low/Medium/High), Mobility (Normal/Wheelchair User/Crutches/Walker)
            </div>
        </div>
    </main>
</div>
</body>
</html>
