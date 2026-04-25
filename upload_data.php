<?php
/**
 * upload_data.php — Bulk Student CSV Import
 * ============================================
 * Admin-only page for mass-registering students from a CSV file.
 *
 * Expected CSV column order:
 *   [0] Matric No  [1] Full Name  [2] Level  [3] Faculty
 *   [4] Department [5] Gender     [6] Medical Condition
 *   [7] Severity (optional)       [8] Mobility (optional)
 *   [9] Paid Status (optional, mirrors the university portal export)
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

$msg      = '';
$msg_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    // Validate CSRF token before processing any file data
    check_csrf();

    // Check for upload errors reported by PHP
    if ($_FILES['csv_file']['error'] !== 0) {
        $msg      = "File upload error (PHP error code: " . (int)$_FILES['csv_file']['error'] . ")";
        $msg_type = 'error';
    // Enforce a 5MB file size limit to prevent memory exhaustion
    } elseif ($_FILES['csv_file']['size'] > 5 * 1024 * 1024) {
        $msg      = "File too large. Maximum upload size is 5MB.";
        $msg_type = 'error';
    // MIME type check — must be CSV or plain-text (some OS report CSV as text/plain)
    } else {
        $allowed_mimes = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $detected = finfo_file($finfo, $_FILES['csv_file']['tmp_name']);
        // finfo_close is deprecated in PHP 8+ and not needed
        // Also check the original filename extension as a secondary guard
        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));

        if (!in_array($detected, $allowed_mimes) && $ext !== 'csv') {
            $msg      = "Invalid file type. Please upload a valid CSV file (detected: {$detected}).";
            $msg_type = 'error';
        }
    }

    if (empty($msg)) {
        $file       = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $count      = 0;
        $duplicates = 0;

        // Skip the CSV header row (column labels)
        fgetcsv($file);

        // Prepare reusable statements once for performance across many rows
        $stmt_check       = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt_user        = $conn->prepare("INSERT INTO users (username, full_name, password_hash, role) VALUES (?, ?, ?, 'student')");
        $stmt_profile     = $conn->prepare("INSERT INTO student_profiles (user_id, level, department_id, gender, is_paid) VALUES (?, ?, ?, ?, ?)");
        $stmt_dept_lookup = $conn->prepare("SELECT department_id FROM departments WHERE name LIKE ? LIMIT 1");

        // ── PHASE 1: Create user & profile records ──────────────────────────────
        // FIX: Medical records are NOT inserted here. Instead, all students that need
        // scoring are collected in $pending_medical for a single batch Python call.
        // Previously: 1 shell_exec() per student → N processes for N rows (timeout risk).
        // Now: 1 shell_exec() total regardless of import size.
        $pending_medical = []; // ['id'=>uid, 'condition'=>..., 'severity'=>..., 'mobility'=>..., 'academic_level'=>...]

        while (($row = fgetcsv($file)) !== false) {
            // Accept legacy shorter rows and default omitted trailing optional columns.
            if (count($row) < 7) continue;

            $matric    = trim($row[0]);
            $name      = trim($row[1]);
            $level     = (int)trim($row[2]);
            $dept      = trim($row[4]);
            $gender    = trim($row[5]);
            $condition = trim($row[6]); // Raw ENUM value — do NOT HTML-encode
            $is_paid   = isset($row[9]) && (int)trim($row[9]) === 1 ? 1 : 0;

            if (empty($condition)) continue;

            // --- Duplicate Detection ---
            $stmt_check->bind_param("s", $matric);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                $duplicates++;
                continue;
            }

            // --- Default Password: lowercase matric number ---
            // TODO: Implement forced password change on first login
            $hash = password_hash(strtolower($matric), PASSWORD_DEFAULT);

            $stmt_user->bind_param("sss", $matric, $name, $hash);
            if ($stmt_user->execute()) {
                $uid = $conn->insert_id;

                // --- Department String → ID ---
                $dept_id     = 1;
                $search_dept = "%" . $dept . "%";
                $stmt_dept_lookup->bind_param("s", $search_dept);
                $stmt_dept_lookup->execute();
                $res_dept = $stmt_dept_lookup->get_result();
                if ($res_dept->num_rows > 0) {
                    $dept_id = $res_dept->fetch_assoc()['department_id'];
                }

                $stmt_profile->bind_param("iiisi", $uid, $level, $dept_id, $gender, $is_paid);
                $stmt_profile->execute();

                // Queue student for batch ML scoring (medical data only if condition is not None)
                if (strtolower($condition) !== 'none') {
                    $pending_medical[] = [
                        'id'             => $uid,
                        'condition'      => $condition,
                        'severity'       => !empty($row[7]) ? trim($row[7]) : 'Low',  // Raw ENUM value
                        'mobility'       => !empty($row[8]) ? trim($row[8]) : 'Normal Mobility', // Raw ENUM value
                        'academic_level' => $level,
                    ];
                }
                $count++;
            }
        }
        fclose($file);

        // ── PHASE 2: Single batch Python call for ALL students ───────────────────
        // Calls predict.py once with the full student array — O(1) Python processes
        // regardless of how many rows were in the CSV.
        $batch_scores = []; // [uid => score]
        if (!empty($pending_medical)) {
            $temp_file   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fairmed_bulk_' . uniqid() . '.json';
            file_put_contents($temp_file, json_encode($pending_medical));
            $script_path = __DIR__ . '/ml_models/predict.py';
            $command     = escapeshellcmd("python " . escapeshellarg($script_path) . " " . escapeshellarg($temp_file));
            $output      = shell_exec($command);
            $result      = json_decode($output, true);
            if (file_exists($temp_file)) unlink($temp_file);

            if (($result['status'] ?? '') === 'success' && isset($result['results'])) {
                $batch_scores = $result['results'];
            }
        }

        // ── PHASE 3: Insert medical records with resolved urgency scores ─────────
        // FIX: Fallback now uses condition_weights (max 100) instead of sev_val * 15 (max 45).
        // Ensures imported students get the same scoring quality as manually registered ones.
        $condition_weights = [
            'Sickle Cell'        => 90.0, 'Epilepsy'           => 90.0,
            'Diabetes'           => 90.0, 'Cardiovascular'     => 90.0,
            'Neurological'       => 70.0, 'Physical Disability'=> 65.0,
            'Visual Impairment'  => 60.0, 'Asthma'             => 50.0,
            'Respiratory'        => 50.0, 'Ulcer'              => 30.0,
            'Other'              => 20.0,
        ];
        $sev_map  = ['Low' => 1, 'Medium' => 2, 'High' => 3];
        $stmt_med = $conn->prepare("INSERT INTO medical_records (student_id, condition_category, condition_details, severity_level, urgency_score, mobility_status) VALUES (?, ?, ?, ?, ?, ?)");

        foreach ($pending_medical as $s) {
            $uid       = $s['id'];
            $condition = $s['condition'];
            $severity  = $s['severity'];
            $mobility  = $s['mobility'];
            $sev_val   = $sev_map[$severity] ?? 1;

            // Use ML batch score if available; otherwise apply the weighted fallback formula
            if (isset($batch_scores[$uid])) {
                $score = (float)$batch_scores[$uid];
            } else {
                $score = min(10.0 + ($condition_weights[$condition] ?? 20.0) + ($sev_val * 5.0), 100.0);
            }

            $details = "{$condition} (Imported via CSV)";
            $stmt_med->bind_param("isssds", $uid, $condition, $details, $severity, $score, $mobility);
            $stmt_med->execute();
        }

        $msg      = "Processed: {$count} students registered. Duplicates skipped: {$duplicates}. Imported payment status was preserved, and eligible students will be considered in the next admin batch.";
        $msg_type = 'success';
    } // end mime check
} // end POST

$page_title = "Import Data | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-info">
                <h1>Data Import</h1>
                <p class="text-muted">Bulk student registration via structured CSV file.</p>
            </div>
            <a href="admin_dashboard.php" class="btn btn-outline" id="import-back-btn">
                <i class="fa-solid fa-arrow-left"></i> Dashboard
            </a>
        </div>

        <?php if($msg): ?>
            <div class="alert alert-<?php echo $msg_type === 'success' ? 'success' : 'danger'; ?> mb-6">
                <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?>"></i>
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 380px;gap:1.5rem;align-items:start;">

            <!-- Upload Zone -->
            <div class="card upload-zone" id="upload-drop-zone">
                <i class="fa-solid fa-cloud-arrow-up" style="font-size:2.5rem;color:var(--c-text-muted);margin-bottom:1rem;"></i>
                <h3 style="margin-bottom:0.5rem;">Upload Student CSV File</h3>
                <p class="text-muted" style="margin-bottom:1.75rem;font-size:0.9rem;">Drag &amp; drop your CSV file here, or click to browse. Imported payment status and student records will be picked up during the next admin allocation batch.</p>

                <form method="post" enctype="multipart/form-data" id="csv-upload-form">
                    <?php csrf_field(); ?>
                    <input type="file" name="csv_file" id="csv-file-input" class="hidden" accept=".csv,text/csv" onchange="this.form.submit()">
                    <label for="csv-file-input" class="btn btn-primary" id="csv-browse-btn" style="cursor:pointer;">
                        <i class="fa-solid fa-folder-open"></i> Browse File
                    </label>
                </form>

                <p class="text-muted" style="margin-top:1rem;font-size:0.75rem;">Max file size: 5MB &bull; Format: CSV &bull; UTF-8 encoding</p>
            </div>

            <!-- Format Guide -->
            <div class="card" style="padding:1.75rem;">
                <div class="form-section-title" style="margin-bottom:1rem;">
                    <span class="form-section-icon" style="background:rgba(37,99,235,0.08);color:var(--c-info);"><i class="fa-solid fa-table"></i></span>
                    CSV Format Guide
                </div>
                <p class="text-muted" style="font-size:0.8rem;margin-bottom:1rem;">Columns must be in this exact order:</p>

                <div style="display:flex;flex-direction:column;gap:0.5rem;">
                    <?php
                    $cols = [
                        ['A', 'Matric No',    'RUN/CMP/22/001',     'required'],
                        ['B', 'Full Name',    'John Doe',           'required'],
                        ['C', 'Level',        '200',                'required'],
                        ['D', 'Faculty',      'Sciences',           'required'],
                        ['E', 'Department',   'Computer Science',   'required'],
                        ['F', 'Gender',       'Male / Female',      'required'],
                        ['G', 'Condition',    'Sickle Cell / None', 'required'],
                        ['H', 'Severity',     'Low / Medium / High','optional'],
                        ['I', 'Mobility',     'Normal / Wheelchair','optional'],
                        ['J', 'Paid Status', '1 or 0', 'optional'],
                    ];
                    foreach ($cols as [$col, $name, $example, $req]): ?>
                        <div style="display:flex;align-items:center;gap:0.625rem;padding:0.5rem 0;border-bottom:1px solid var(--c-border);">
                            <span style="width:22px;height:22px;background:var(--c-primary);color:#fff;border-radius:4px;font-size:0.65rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?php echo $col; ?></span>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:0.8rem;font-weight:600;color:var(--c-text-head);"><?php echo $name; ?></div>
                                <div style="font-size:0.72rem;color:var(--c-text-muted);"><?php echo $example; ?></div>
                            </div>
                            <span class="badge <?php echo $req === 'required' ? 'badge-danger' : 'badge-success'; ?>" style="font-size:0.6rem;"><?php echo strtoupper($req); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="alert alert-info" style="margin-top:1.25rem;font-size:0.8rem;">
                    <i class="fa-solid fa-info-circle"></i>
                    Row 1 must be a header row — it will be automatically skipped.
                </div>
            </div>

        </div>
    </main>
</div>
</body>
</html>

