<?php
/**
 * upload_data.php - Bulk Student CSV Import
 * =========================================
 * Admin-only page for mass-registering students from a CSV file.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';
require_once 'includes/Logger.php';
require_once 'includes/JobDispatcher.php';

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

$msg = '';
$msg_type = 'success';
$active_import_job_id = isset($_GET['job_id']) ? max(0, (int)$_GET['job_id']) : 0;
$admin_id = (int)($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    check_csrf();

    if ($_FILES['csv_file']['error'] !== 0) {
        $msg = "File upload error (PHP error code: " . (int) $_FILES['csv_file']['error'] . ")";
        $msg_type = 'error';
    } elseif ($_FILES['csv_file']['size'] > 5 * 1024 * 1024) {
        $msg = "File too large. Maximum upload size is 5MB.";
        $msg_type = 'error';
    } else {
        $allowed_mimes = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($_FILES['csv_file']['tmp_name']);
        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));

        if (!in_array($detected, $allowed_mimes, true) && $ext !== 'csv') {
            $msg = "Invalid file type. Please upload a valid CSV file (detected: {$detected}).";
            $msg_type = 'error';
        }
    }

    if (empty($msg)) {
        $existing = $conn->prepare(
            "SELECT job_id, job_type
               FROM allocation_jobs
              WHERE status IN ('queued', 'running')
              ORDER BY created_at DESC
              LIMIT 1"
        );
        if ($existing) {
            $existing->execute();
            $existingRow = $existing->get_result()->fetch_assoc();
            $existing->close();
            if ($existingRow) {
                $existingJobType = (string)($existingRow['job_type'] ?? '');
                if ($existingJobType === 'csv_import') {
                    $active_import_job_id = (int)$existingRow['job_id'];
                    $msg = 'A CSV import is already in progress. Please wait for it to finish.';
                } else {
                    $msg = 'An allocation job is already in progress. Please wait for it to finish before importing data.';
                }
                $msg_type = 'error';
            }
        }
    }

    if (empty($msg)) {
        $importDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'fairmedalloc_imports';
        if (!is_dir($importDir) && !@mkdir($importDir, 0775, true) && !is_dir($importDir)) {
            $msg = 'Unable to prepare temporary server storage for the uploaded CSV.';
            $msg_type = 'error';
        } else {
            $storedFile = $importDir . DIRECTORY_SEPARATOR . 'import_' . uniqid('', true) . '.csv';
            if (!move_uploaded_file($_FILES['csv_file']['tmp_name'], $storedFile)) {
                $msg = 'The uploaded CSV could not be moved into background processing storage.';
                $msg_type = 'error';
            } else {
                $payload = json_encode([
                    'job_type' => 'csv_import',
                    'file_path' => $storedFile,
                    'original_name' => (string)($_FILES['csv_file']['name'] ?? basename($storedFile)),
                ]);

                $stmt = $conn->prepare(
                    "INSERT INTO allocation_jobs (job_type, status, created_by_admin_id, progress_stage, progress_percent, result_data)
                     VALUES ('csv_import', 'queued', ?, 'Queued import', 0, ?)"
                );

                if (!$stmt) {
                    @unlink($storedFile);
                    $msg = 'Could not create the import job record.';
                    $msg_type = 'error';
                } else {
                    $stmt->bind_param('is', $admin_id, $payload);
                    if (!$stmt->execute()) {
                        @unlink($storedFile);
                        $msg = 'Could not queue the import job.';
                        $msg_type = 'error';
                    } else {
                        $active_import_job_id = (int)$conn->insert_id;
                        $dispatch = fairmedDispatchWorker($active_import_job_id);

                        if ($dispatch['launched'] ?? false) {
                            usleep(750000);

                            $statusStmt = $conn->prepare(
                                "SELECT status, error_message
                                   FROM allocation_jobs
                                  WHERE job_id = ?
                                  LIMIT 1"
                            );
                            $statusRow = null;
                            if ($statusStmt) {
                                $statusStmt->bind_param('i', $active_import_job_id);
                                $statusStmt->execute();
                                $statusRow = $statusStmt->get_result()->fetch_assoc();
                                $statusStmt->close();
                            }

                            $currentStatus = (string)($statusRow['status'] ?? 'queued');
                            if ($currentStatus === 'failed') {
                                $msg = $statusRow['error_message'] ?: 'The background import worker exited before processing the job.';
                                $msg_type = 'error';
                            } elseif ($currentStatus === 'queued' && DIRECTORY_SEPARATOR === '\\') {
                                if (!defined('FAIRMED_WORKER_LIBRARY_MODE')) {
                                    define('FAIRMED_WORKER_LIBRARY_MODE', true);
                                }
                                require_once __DIR__ . '/worker_allocation.php';
                                runWorkerJobInline($conn, $active_import_job_id);
                                $msg = 'Background worker launch was delayed, so the CSV import is running inline on this Windows environment.';
                                $msg_type = 'success';
                                log_admin_action($conn, $admin_id, "Queued CSV import job #{$active_import_job_id}");
                            } elseif ($currentStatus === 'queued') {
                                $msg = 'CSV import queued successfully. Waiting for the background worker to claim it.';
                                $msg_type = 'success';
                                log_admin_action($conn, $admin_id, "Queued CSV import job #{$active_import_job_id}");
                            } else {
                                $msg = 'CSV import queued successfully. The background worker is processing it now.';
                                $msg_type = 'success';
                                log_admin_action($conn, $admin_id, "Queued CSV import job #{$active_import_job_id}");
                            }
                        } elseif (DIRECTORY_SEPARATOR === '\\') {
                            if (!defined('FAIRMED_WORKER_LIBRARY_MODE')) {
                                define('FAIRMED_WORKER_LIBRARY_MODE', true);
                            }
                            require_once __DIR__ . '/worker_allocation.php';
                            runWorkerJobInline($conn, $active_import_job_id);
                            $msg = 'Background worker launch failed, so the CSV import is running inline on this Windows environment.';
                            $msg_type = 'success';
                        } else {
                            $msg = $dispatch['message'] ?? 'Unable to launch the background import worker.';
                            $msg_type = 'error';
                        }
                    }
                    $stmt->close();
                }
            }
        }
    }
}

if ($active_import_job_id <= 0 && $admin_id > 0) {
    $recentStmt = $conn->prepare(
        "SELECT job_id
           FROM allocation_jobs
          WHERE job_type = 'csv_import'
            AND created_by_admin_id = ?
            AND status IN ('queued', 'running')
          ORDER BY created_at DESC
          LIMIT 1"
    );
    if ($recentStmt) {
        $recentStmt->bind_param('i', $admin_id);
        $recentStmt->execute();
        $recentRow = $recentStmt->get_result()->fetch_assoc();
        $recentStmt->close();
        if ($recentRow) {
            $active_import_job_id = (int)$recentRow['job_id'];
        }
    }
}

$page_title = "Import Data | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">

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
                <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?>"></i>
                <?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($active_import_job_id > 0): ?>
            <div class="card mobile-form-card mb-6" id="import-job-panel">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">
                    <div>
                        <div class="badge badge-primary mb-3">IMPORT JOB #<?php echo $active_import_job_id; ?></div>
                        <h3 style="margin-bottom:0.25rem;">Background Import Status</h3>
                        <p class="text-muted" id="import-job-stage" style="margin:0;">Waiting for worker update...</p>
                    </div>
                    <div class="text-sm text-muted" id="import-job-meta">Queued</div>
                </div>

                <div style="height:12px;border-radius:999px;background:var(--c-bg-surface-2);overflow:hidden;border:1px solid var(--c-border);">
                    <div id="import-job-bar" style="height:100%;width:0%;background:linear-gradient(90deg,var(--c-primary),var(--c-accent));transition:width 0.35s ease;"></div>
                </div>

                <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-top:0.9rem;font-size:0.85rem;">
                    <span class="text-muted">Progress: <strong id="import-job-percent" class="text-head">0%</strong></span>
                    <span class="text-muted">Rows: <strong id="import-job-count" class="text-head">0 / 0</strong></span>
                </div>

                <div id="import-job-result" class="alert alert-info" style="display:none;margin-top:1rem;">
                    <i class="fa-solid fa-circle-info"></i>
                    <span id="import-job-result-text"></span>
                </div>
            </div>
        <?php endif; ?>

        <div class="mobile-import-layout">

            <div class="card upload-zone" id="upload-drop-zone">
                <i class="fa-solid fa-cloud-arrow-up"
                    style="font-size:2.5rem;color:var(--c-text-muted);margin-bottom:1rem;"></i>
                <h3 style="margin-bottom:0.5rem;">Upload Student CSV File</h3>
                <p class="text-muted" style="margin-bottom:1.75rem;font-size:0.9rem;">The file is uploaded first, then processed in a background worker so Render does not time out on large datasets.</p>

                <form method="post" enctype="multipart/form-data" id="csv-upload-form">
                    <?php csrf_field(); ?>
                    <input type="file" name="csv_file" id="csv-file-input" class="hidden" accept=".csv,text/csv"
                        onchange="this.form.submit()">
                    <label for="csv-file-input" class="btn btn-primary" id="csv-browse-btn" style="cursor:pointer;">
                        <i class="fa-solid fa-folder-open"></i> Browse File
                    </label>
                </form>

                <p class="text-muted" style="margin-top:1rem;font-size:0.75rem;">Max file size: 5MB &bull; Format: CSV &bull; UTF-8 encoding</p>
            </div>

            <div class="card mobile-side-card">
                <div class="form-section-title" style="margin-bottom:1rem;">
                    <span class="form-section-icon" style="background:rgba(37,99,235,0.08);color:var(--c-info);"><i
                            class="fa-solid fa-table"></i></span>
                    CSV Format Guide
                </div>
                <p class="text-muted" style="font-size:0.8rem;margin-bottom:1rem;">Columns must be in this exact order:</p>

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
                        <div style="display:flex;align-items:center;gap:0.625rem;padding:0.5rem 0;border-bottom:1px solid var(--c-border);">
                            <span
                                style="width:22px;height:22px;background:var(--c-primary);color:#fff;border-radius:4px;font-size:0.65rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?php echo $col; ?></span>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:0.8rem;font-weight:600;color:var(--c-text-head);"><?php echo $name; ?></div>
                                <div style="font-size:0.72rem;color:var(--c-text-muted);"><?php echo $example; ?></div>
                            </div>
                            <span class="badge <?php echo $req === 'required' ? 'badge-danger' : 'badge-success'; ?>"
                                style="font-size:0.6rem;"><?php echo strtoupper($req); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="alert alert-info" style="margin-top:1.25rem;font-size:0.8rem;">
                    <i class="fa-solid fa-info-circle"></i>
                    Row 1 must be a header row - it will be automatically skipped.
                </div>
            </div>

        </div>
    </main>
</div>

<?php if ($active_import_job_id > 0): ?>
<script>
(function() {
    const jobId = <?php echo (int)$active_import_job_id; ?>;
    const stageEl = document.getElementById('import-job-stage');
    const metaEl = document.getElementById('import-job-meta');
    const barEl = document.getElementById('import-job-bar');
    const percentEl = document.getElementById('import-job-percent');
    const countEl = document.getElementById('import-job-count');
    const resultEl = document.getElementById('import-job-result');
    const resultTextEl = document.getElementById('import-job-result-text');

    if (!jobId || !stageEl || !metaEl || !barEl || !percentEl || !countEl || !resultEl || !resultTextEl) {
        return;
    }

    let timer = null;

    async function pollJob() {
        try {
            const response = await fetch(`api/admin_api.php?action=job_status&job_id=${jobId}`, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            const data = await response.json();

            if (!response.ok || data.status !== 'success') {
                throw new Error(data.message || 'Unable to load import status.');
            }

            const percent = Math.max(0, Math.min(100, Number(data.progress_percent || 0)));
            const total = Number(data.total_students || 0);
            const processed = Number(data.allocated_students || 0);
            const stage = data.progress_stage || 'Processing import';
            const jobStatus = data.job_status || 'queued';

            stageEl.textContent = stage;
            metaEl.textContent = jobStatus.toUpperCase();
            barEl.style.width = `${percent}%`;
            percentEl.textContent = `${percent}%`;
            countEl.textContent = `${processed} / ${total}`;

            if (jobStatus === 'completed') {
                const result = data.result || {};
                resultEl.className = 'alert alert-success';
                resultEl.style.display = 'block';
                resultTextEl.textContent = result.message || 'Import completed successfully.';
                stopPolling();
            } else if (jobStatus === 'failed' || jobStatus === 'cancelled') {
                resultEl.className = 'alert alert-danger';
                resultEl.style.display = 'block';
                resultTextEl.textContent = data.error_message || 'The import job failed.';
                stopPolling();
            } else {
                resultEl.style.display = 'none';
            }
        } catch (error) {
            resultEl.className = 'alert alert-danger';
            resultEl.style.display = 'block';
            resultTextEl.textContent = error.message || 'Unable to load import status.';
            stopPolling();
        }
    }

    function stopPolling() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    pollJob();
    timer = setInterval(pollJob, 2500);
})();
</script>
<?php endif; ?>

</body>
</html>
