<?php
/**
 * upload_data.php — Bulk Student CSV Import
 * ============================================
 * Admin-only page for mass-registering students from a CSV file.
 *
 * Expected CSV column order:
 *   [0] Matric No  [1] Full Name  [2] Level  [3] Faculty
 *   [4] Department [5] Gender     [6] Medical Condition
 *   [7] Severity                  [8] Mobility
 *   [9] Paid Status (1 or 0)
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
require_once 'includes/UrgencyScoreService.php';
require_once 'includes/Logger.php';
// Auth Guard: admin only
if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

$msg = '';
$msg_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    // Prevent script timeout during massive batch processing
    set_time_limit(0);

    // Validate CSRF token before processing any file data
    check_csrf();

    // Check for upload errors reported by PHP
    if ($_FILES['csv_file']['error'] !== 0) {
        $msg = "File upload error (PHP error code: " . (int) $_FILES['csv_file']['error'] . ")";
        $msg_type = 'error';
        // Enforce a 5MB file size limit to prevent memory exhaustion
    } elseif ($_FILES['csv_file']['size'] > 5 * 1024 * 1024) {
        $msg = "File too large. Maximum upload size is 5MB.";
        $msg_type = 'error';
        // MIME type check — must be CSV or plain-text (some OS report CSV as text/plain)
    } else {
        $allowed_mimes = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($_FILES['csv_file']['tmp_name']);
        $ext      = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));

        if (!in_array($detected, $allowed_mimes) && $ext !== 'csv') {
            $msg = "Invalid file type. Please upload a valid CSV file (detected: {$detected}).";
            $msg_type = 'error';
        }
    }

    if (empty($msg)) {
        $file      = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $count     = 0;
        $duplicates = 0;

        // Skip the CSV header row (column labels)
        fgetcsv($file);

        // Prepare reusable statements once for performance across many rows
        $stmt_check      = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $user_insert_sql = FAIRMED_SUPPORTS_MUST_CHANGE_PASSWORD
            ? "INSERT INTO users (username, full_name, password_hash, must_change_password, role) VALUES (?, ?, ?, 1, 'student')"
            : "INSERT INTO users (username, full_name, password_hash, role) VALUES (?, ?, ?, 'student')";
        $stmt_user        = $conn->prepare($user_insert_sql);
        $stmt_profile         = $conn->prepare("INSERT INTO student_profiles (user_id, level, department_id, gender, has_special_needs, is_paid) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt_fac_lookup      = $conn->prepare("SELECT faculty_id FROM faculties WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $stmt_fac_insert      = $conn->prepare("INSERT INTO faculties (name) VALUES (?)");
        $stmt_dept_lookup     = $conn->prepare("SELECT department_id FROM departments WHERE faculty_id = ? AND LOWER(name) = LOWER(?) LIMIT 1");
        $stmt_dept_insert     = $conn->prepare("INSERT INTO departments (faculty_id, name) VALUES (?, ?)");

        // ── PHASE 1 + 3: Wrapped in a single atomic transaction ─────────────────
        // If the server crashes mid-import, the entire batch is rolled back so
        // no orphaned users rows are left without a matching student_profiles row.
        $pending_medical = [];

        $conn->begin_transaction();
        try {
            while (($row = fgetcsv($file)) !== false) {
                // Require all 10 columns
                if (count($row) < 10)
                    continue;

                $matric    = trim($row[0]);
                $name      = trim($row[1]);
                $level     = (int) trim($row[2]);
                $faculty   = trim($row[3]);
                $dept      = trim($row[4]);
                $gender    = trim($row[5]);
                $condition = UrgencyScoreService::normalizeCondition(trim($row[6]));
                $severity  = trim($row[7]);
                $mobility  = UrgencyScoreService::normalizeMobility(trim($row[8]));
                $paid_str  = trim($row[9]);

                if (empty($matric) || empty($name) || empty($faculty) || empty($dept) || empty($gender) || empty($condition) || empty($severity) || empty($mobility) || $paid_str === '')
                    continue;

                $is_paid = (int) $paid_str === 1 ? 1 : 0;
                $has_mobility_need = $mobility !== 'Normal Mobility' ? 1 : 0;

                $stmt_check->bind_param("s", $matric);
                $stmt_check->execute();
                if ($stmt_check->get_result()->num_rows > 0) {
                    $duplicates++;
                    continue;
                }

                // Default password is the lowercase matric number — must_change_password=1 forces a reset on first login.
                $hash = password_hash(strtolower($matric), PASSWORD_BCRYPT, ['cost' => 4]);

                $stmt_user->bind_param("sss", $matric, $name, $hash);
                if ($stmt_user->execute()) {
                    $uid = $conn->insert_id;

                    $faculty_id = null;
                    $stmt_fac_lookup->bind_param("s", $faculty);
                    $stmt_fac_lookup->execute();
                    $res_fac = $stmt_fac_lookup->get_result();
                    if ($res_fac->num_rows > 0) {
                        $faculty_id = (int)$res_fac->fetch_assoc()['faculty_id'];
                    } else {
                        $stmt_fac_insert->bind_param("s", $faculty);
                        $stmt_fac_insert->execute();
                        $faculty_id = (int)$conn->insert_id;
                    }

                    $dept_id = 0;
                    $stmt_dept_lookup->bind_param("is", $faculty_id, $dept);
                    $stmt_dept_lookup->execute();
                    $res_dept = $stmt_dept_lookup->get_result();
                    if ($res_dept->num_rows > 0) {
                        $dept_id = (int)$res_dept->fetch_assoc()['department_id'];
                    } else {
                        $stmt_dept_insert->bind_param("is", $faculty_id, $dept);
                        $stmt_dept_insert->execute();
                        $dept_id = (int)$conn->insert_id;
                    }

                    $stmt_profile->bind_param("iiisii", $uid, $level, $dept_id, $gender, $has_mobility_need, $is_paid);
                    $stmt_profile->execute();

                    if ($condition !== 'None' || $has_mobility_need === 1) {
                        $pending_medical[] = [
                            'id'            => $uid,
                            'condition'     => $condition,
                            'severity'      => $severity,
                            'mobility'      => $mobility,
                            'academic_level' => $level,
                            'has_special_needs' => $has_mobility_need,
                            'is_requested'  => $has_mobility_need,
                        ];
                    }
                    $count++;
                }
            }
            fclose($file);

            // ── PHASE 2: Single batch Python call for ALL students ───────────────
            // Runs outside the transaction — ML scoring is a pure computation
            // with its own fallback; it does not need rollback semantics.
            $batch_scores = [];
            if (!empty($pending_medical)) {
                try {
                    $scoreService = new UrgencyScoreService();
                    $result       = $scoreService->scoreBatch($pending_medical);
                    if (($result['status'] ?? '') === 'success' && isset($result['results'])) {
                        $batch_scores = $result['results'];
                    }
                } catch (Exception $e) {
                    error_log('[FairMedAlloc] Batch scoring failed during CSV import, falling back to PHP rules: ' . $e->getMessage());
                }
            }

            // ── PHASE 3: Insert medical records with resolved urgency scores ─────
            $stmt_med = $conn->prepare("INSERT INTO medical_records (student_id, condition_category, condition_details, severity_level, urgency_score, mobility_status, is_requested_mobility) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($pending_medical as $s) {
                $uid       = $s['id'];
                $condition = $s['condition'];
                $severity  = $s['severity'];
                $mobility  = $s['mobility'];
                $isRequested = (int)($s['is_requested'] ?? 0);
                $score     = isset($batch_scores[$uid])
                    ? (float) $batch_scores[$uid]
                    : UrgencyScoreService::calculateFallbackScore(['condition' => $condition, 'mobility' => $mobility, 'severity' => $severity]);

                $details = "{$condition} (Imported via CSV)";
                $stmt_med->bind_param("isssdsi", $uid, $condition, $details, $severity, $score, $mobility, $isRequested);
                $stmt_med->execute();
            }

            $conn->commit();
            $msg      = "Processed: {$count} students registered. Duplicates skipped: {$duplicates}. Payment, mobility, and department data were preserved for allocation.";
            $msg_type = 'success';
            
            // Log successful import
            Logger::info("CSV import completed: {$count} students imported, {$duplicates} duplicates skipped");
            log_admin_action($conn, (int)$_SESSION['user_id'], "Bulk CSV import: $count students registered, $duplicates duplicates");

        } catch (Exception $e) {
            $conn->rollback();
            if (is_resource($file)) {
                fclose($file);
            }
            $msg      = "Import failed and was rolled back: " . htmlspecialchars($e->getMessage()) . ". No records were written.";
            $msg_type = 'error';
            
            // Log the failure
            Logger::error("CSV import failed", $e);
        }
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

        <?php if ($msg): ?>
            <div class="alert alert-<?php echo $msg_type === 'success' ? 'success' : 'danger'; ?> mb-6">
                <i
                    class="fa-solid <?php echo $msg_type === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?>"></i>
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 380px;gap:1.5rem;align-items:start;">

            <!-- Upload Zone -->
            <div class="card upload-zone" id="upload-drop-zone">
                <i class="fa-solid fa-cloud-arrow-up"
                    style="font-size:2.5rem;color:var(--c-text-muted);margin-bottom:1rem;"></i>
                <h3 style="margin-bottom:0.5rem;">Upload Student CSV File</h3>
                <p class="text-muted" style="margin-bottom:1.75rem;font-size:0.9rem;">Drag &amp; drop your CSV file
                    here, or click to browse. Imported payment status, mobility support, and department records are
                    preserved exactly for allocation.</p>

                <form method="post" enctype="multipart/form-data" id="csv-upload-form">
                    <?php csrf_field(); ?>
                    <input type="file" name="csv_file" id="csv-file-input" class="hidden" accept=".csv,text/csv"
                        onchange="this.form.submit()">
                    <label for="csv-file-input" class="btn btn-primary" id="csv-browse-btn" style="cursor:pointer;">
                        <i class="fa-solid fa-folder-open"></i> Browse File
                    </label>
                </form>

                <p class="text-muted" style="margin-top:1rem;font-size:0.75rem;">Max file size: 5MB &bull; Format: CSV
                    &bull; UTF-8 encoding</p>
            </div>

            <!-- Format Guide -->
            <div class="card" style="padding:1.75rem;">
                <div class="form-section-title" style="margin-bottom:1rem;">
                    <span class="form-section-icon" style="background:rgba(37,99,235,0.08);color:var(--c-info);"><i
                            class="fa-solid fa-table"></i></span>
                    CSV Format Guide
                </div>
                <p class="text-muted" style="font-size:0.8rem;margin-bottom:1rem;">Columns must be in this exact order:
                </p>

                <div style="display:flex;flex-direction:column;gap:0.5rem;">
                    <?php
                    $cols = [
                        ['A', 'Matric No', 'RUN/CMP/22/001', 'required'],
                        ['B', 'Full Name', 'John Doe', 'required'],
                        ['C', 'Level', '200', 'required'],
                        ['D', 'Faculty', 'Sciences', 'required'],
                        ['E', 'Department', 'Computer Science', 'required'],
                        ['F', 'Gender', 'Male / Female', 'required'],
                        ['G', 'Condition', 'Sickle Cell / None', 'required'],
                        ['H', 'Severity', 'Low / Medium / High', 'required'],
                        ['I', 'Mobility', 'Normal / Wheelchair', 'required'],
                        ['J', 'Paid Status', '1 or 0', 'required'],
                    ];
                    foreach ($cols as [$col, $name, $example, $req]): ?>
                        <div
                            style="display:flex;align-items:center;gap:0.625rem;padding:0.5rem 0;border-bottom:1px solid var(--c-border);">
                            <span
                                style="width:22px;height:22px;background:var(--c-primary);color:#fff;border-radius:4px;font-size:0.65rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?php echo $col; ?></span>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:0.8rem;font-weight:600;color:var(--c-text-head);"><?php echo $name; ?>
                                </div>
                                <div style="font-size:0.72rem;color:var(--c-text-muted);"><?php echo $example; ?></div>
                            </div>
                            <span class="badge <?php echo $req === 'required' ? 'badge-danger' : 'badge-success'; ?>"
                                style="font-size:0.6rem;"><?php echo strtoupper($req); ?></span>
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
