<?php
/**
 * Admin API Controller (The Traffic Cop)
 * ======================================
 * I built this to handle all the async AJAX requests from the admin dashboard.
 * It's basically a massive switch statement that routes actions like queuing up 
 * Min-Cost Flow algorithms, fetching chart data, or manually overriding beds.
 */
session_start();
require_once '../db_config.php';
require_once '../includes/security_helper.php';
require_once '../includes/DbHelper.php';
require_once '../includes/Logger.php';
require_once '../includes/JobDispatcher.php';

// All responses from this file will be JSON-formatted
header('Content-Type: application/json');
ini_set('display_errors', '0');
ob_start();

// ── Timeout & disconnection guards ────────────────────────────────────────────
// set_time_limit(0) removes the PHP execution ceiling for this script.
// ignore_user_abort(true) keeps PHP running even if the browser disconnects or
// the network drops mid-allocation — critical for long OR-Tools solver runs.
set_time_limit(0);
ignore_user_abort(true);

const MANUAL_ALLOCATION_VERSION = 'manual_override_v1';

// --- 1. Security Check ---
// Ensure the request is coming from an authenticated administrator
if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    sendJsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 403);
}

// Retrieve the requested action 
$action = $_GET['action'] ?? '';

// --- 2. Action Router ---
// Wrapped in a top-level try/catch so any uncaught exception still returns
// JSON instead of an HTML 500 page (which breaks the UI's JSON.parse()).
try {
    switch ($action) {
        case 'run_algorithm':
            handleRunAlgorithm($conn);
            break;

        // ── Async queue actions ───────────────────────────────────────────────────
        case 'queue_allocation':
            handleQueueAllocation($conn);
            break;

        case 'job_status':
            handleJobStatus($conn);
            break;

        case 'worker_health':
            handleWorkerHealth($conn);
            break;

        case 'cancel_job':
            handleCancelJob($conn);
            break;
        // ─────────────────────────────────────────────────────────────────────────

        case 'rescore_all':
            handleRescoreAll($conn);
            break;

        case 'manual_assign':
            handleManualAssign($conn);
            break;

        case 'get_rooms':
            handleGetRooms($conn);
            break;

        case 'analytics':
            handleAnalytics($conn);
            break;

        case 'hostel_stats':
            handleHostelStats($conn);
            break;

        case 'ml_status':
            handleMlStatus();
            break;

        default:
            // Reject unknown or missing actions
            sendJsonResponse(['status' => 'error', 'message' => 'Invalid action'], 400);
            break;
    }
} catch (Throwable $topLevelErr) {
    // Safety net: convert any uncaught fatal/exception into a JSON response.
    // This prevents HTML error pages breaking the frontend JSON.parse().
    sendJsonResponse([
        'status'  => 'error',
        'message' => 'An unexpected server error occurred. Please try again.',
    ], 500);
}

function sendJsonResponse(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Ensure response always has 'success' key for consistency
    if (!isset($payload['success']) && isset($payload['status'])) {
        $payload['success'] = ($payload['status'] === 'success');
    }
    
    echo json_encode($payload);
    exit;
}

function flushJsonResponse(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!isset($payload['success']) && isset($payload['status'])) {
        $payload['success'] = ($payload['status'] === 'success');
    }

    $json = json_encode($payload);
    header('Connection: close');
    header('Content-Length: ' . strlen((string) $json));
    echo $json;

    if (function_exists('session_write_close')) {
        session_write_close();
    }
    @flush();
}

// --------------------------------------------------------------------------
// Handlers
// --------------------------------------------------------------------------

/**
 * Queue an allocation job and immediately fire the background worker.
 *
 * Returns the job_id immediately so the UI can start polling job_status.
 * The actual engine run happens in worker_allocation.php which is launched
 * via proc_open so it does NOT block this HTTP response.
 */
function handleQueueAllocation($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['status' => 'error', 'message' => 'POST required'], 405);
        return;
    }
    check_csrf();

    // ── Stale queued-job cleanup ──────────────────────────────────────────────
    // A job stuck in 'queued' for > 5 min means the background worker never
    // started (e.g. popen failed silently under Apache on Windows).
    // Mark it failed so a fresh job can be created immediately.
    try {
        $conn->query(
            "UPDATE allocation_jobs
                SET status        = 'failed',
                    error_message = 'Worker failed to start — job was stuck in queued state. Please retry.',
                    completed_at  = NOW(),
                    updated_at    = NOW()
              WHERE status = 'queued'
                AND (job_type = 'allocation' OR job_type IS NULL)
                AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
        );
    } catch (Throwable $ignored) { /* table may not exist yet */ }

    // Prevent duplicate jobs: if a job is already queued or running, return it.
    try {
        $existing = $conn->query(
            "SELECT job_id, job_type, status, progress_percent, progress_stage
               FROM allocation_jobs
              WHERE status IN ('queued','running')
              ORDER BY created_at DESC LIMIT 1"
        );
    } catch (Throwable $tableErr) {
        sendJsonResponse([
            'status'  => 'error',
            'message' => 'The allocation_jobs table does not exist. Please run the database migrations (sql/run_migrations.php) first.'
        ], 500);
        return;
    }
    if ($existing === false) {
        sendJsonResponse([
            'status'  => 'error',
            'message' => 'Could not query allocation jobs: ' . $conn->error
        ], 500);
        return;
    }
    if ($existing && $existing->num_rows > 0) {
        $row = $existing->fetch_assoc();
        $jobType = (string)($row['job_type'] ?? 'allocation');
        $jobLabel = $jobType === 'csv_import' ? 'data import' : 'allocation';
        sendJsonResponse([
            'status'   => 'queued',
            'job_id'   => (int)$row['job_id'],
            'message'  => "A {$jobLabel} job is already in progress.",
            'job_status' => $row['status'],
            'job_type' => $jobType,
        ]);
        return;
    }

    $admin_id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare(
        "INSERT INTO allocation_jobs (job_type, status, created_by_admin_id)
         VALUES ('allocation', 'queued', ?)"
    );
    $stmt->bind_param('i', $admin_id);
    if (!$stmt->execute()) {
        sendJsonResponse(['status' => 'error', 'message' => 'Could not create job record.'], 500);
        return;
    }
    $job_id = (int)$conn->insert_id;
    $stmt->close();

    log_admin_action($conn, $admin_id, "Queued allocation job #$job_id");

    // Fire the worker in the background (non-blocking).
    $dispatch = fairmedDispatchWorker($job_id);
    $shouldInlineFallback = false;
    if (!($dispatch['launched'] ?? false)) {
        $shouldInlineFallback = true;
    }

    usleep(750000);
    $warning = null;
    $statusStmt = $conn->prepare(
        "SELECT status, error_message
           FROM allocation_jobs
          WHERE job_id = ?
          LIMIT 1"
    );
    if ($statusStmt) {
        $statusStmt->bind_param('i', $job_id);
        $statusStmt->execute();
        $statusRow = $statusStmt->get_result()->fetch_assoc();
        $statusStmt->close();
        if (($statusRow['status'] ?? 'queued') === 'failed') {
            sendJsonResponse([
                'status'  => 'error',
                'message' => $statusRow['error_message'] ?: 'The worker exited before processing the job.'
            ], 500);
            return;
        }
        if (($statusRow['status'] ?? 'queued') === 'queued') {
            $warning = 'Background worker launch did not claim the job quickly; continuing with inline server-side processing.';
            $shouldInlineFallback = true;
        }
    }

    $response = [
        'status'  => 'queued',
        'job_id'  => $job_id,
        'message' => 'Allocation job queued and worker started.',
        'warning' => $warning
    ];

    if ($shouldInlineFallback) {
        // On Linux (Render): a still-queued job after 750ms is NORMAL.
        // The supervised background worker may not have claimed it yet.
        // Returning a 500 would strand the job in 'queued' AND block the
        // next attempt via the duplicate-job check for 5 minutes.
        // Correct behaviour: return job_id so the UI can poll.
        // The 5-min stale-queued cleanup marks it failed if never claimed.
        if (DIRECTORY_SEPARATOR !== '\\') {
            sendJsonResponse([
                'status'  => 'queued',
                'job_id'  => $job_id,
                'message' => 'Allocation job queued. The background worker will process it shortly.',
                'warning' => 'Worker has not claimed the job yet — polling will confirm when it starts.',
            ]);
            return;
        }

        // Windows / local XAMPP — safe to run inline.
        flushJsonResponse($response);
        if (!defined('FAIRMED_WORKER_LIBRARY_MODE')) {
            define('FAIRMED_WORKER_LIBRARY_MODE', true);
        }
        require_once dirname(__DIR__) . '/worker_allocation.php';
        runWorkerJobInline($conn, $job_id);
        exit;
    }

    sendJsonResponse($response);
}

/**
 * Launch worker_allocation.php as a background process.
 * Uses proc_open so the HTTP response is NOT held open.
 */
function dispatchWorker(int $job_id): array {
    $php   = resolvePhpCliBinary();
    $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'worker_allocation.php';

    if (!file_exists($script)) {
        $message = "Worker script not found at $script";
        Logger::error("dispatchWorker: $message");
        return ['launched' => false, 'message' => $message];
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        // Windows: Apache runs as a Windows service with no desktop/console session,
        // so "cmd /c start /B" silently fails (start requires an interactive session).
        // Use proc_open with an array command (bypass_shell) to spawn PHP CLI directly.
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', 'NUL', 'w'],
            2 => ['file', 'NUL', 'w'],
        ];
        $proc = @proc_open(
            [$php, $script, '--job-id=' . (int)$job_id],
            $descriptors,
            $pipes,
            dirname(__DIR__),
            null,
            ['bypass_shell' => true, 'create_process_group' => true]
        );
        if (is_resource($proc)) {
            foreach ($pipes as $pipe) { @fclose($pipe); }
            proc_close($proc);
            Logger::info("dispatchWorker: proc_open (detached) launched Job #$job_id");
            return ['launched' => true, 'message' => null];
        }

        // Fallback: plain popen without "start" (works in interactive XAMPP sessions)
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --job-id=' . (int)$job_id . ' > NUL 2>&1';
        $handle = @popen($cmd, 'r');
        if ($handle !== false) {
            pclose($handle);
            Logger::info("dispatchWorker: popen (fallback) launched Job #$job_id");
            return ['launched' => true, 'message' => null];
        }

        Logger::error("dispatchWorker: all Windows launch methods failed for Job #$job_id");
        return [
            'launched' => false,
            'message'  => "Unable to launch the background worker for Job #$job_id. Ensure php.exe is accessible to the Apache process.",
        ];
    } else {
        // Linux / macOS — redirect stderr to a temp log so launch errors aren't lost
        $errLog = sys_get_temp_dir() . '/fairmedalloc_worker_' . $job_id . '.err';
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script)
             . ' --job-id=' . (int)$job_id
             . ' > /dev/null 2>' . escapeshellarg($errLog) . ' &';
        exec($cmd, $out, $rc);
        if ($rc === 0) {
            Logger::info("dispatchWorker: exec launched Job #$job_id");
            return ['launched' => true, 'message' => null];
        }
        if ($rc !== 0) {
            Logger::error("dispatchWorker: exec() returned code $rc for Job #$job_id — command: $cmd");
        }
    }
    return [
        'launched' => false,
        'message' => "Unable to launch the background worker for Job #$job_id."
    ];
}

function resolvePhpCliBinary(): string {
    $candidates = [];

    if (defined('PHP_BINARY') && PHP_BINARY !== '') {
        $candidates[] = PHP_BINARY;
    }

    if (defined('PHP_BINDIR') && PHP_BINDIR !== '') {
        $binDir = rtrim((string)PHP_BINDIR, "\\/");
        if (DIRECTORY_SEPARATOR === '\\') {
            $candidates[] = $binDir . DIRECTORY_SEPARATOR . 'php.exe';
            $candidates[] = $binDir . DIRECTORY_SEPARATOR . 'php-cli.exe';
        } else {
            $candidates[] = $binDir . DIRECTORY_SEPARATOR . 'php';
        }
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        $candidates[] = 'C:\\xampp\\php\\php.exe';
    } else {
        $candidates[] = 'php';
    }

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') {
            continue;
        }
        if ($candidate === 'php') {
            return $candidate;
        }
        if (file_exists($candidate)) {
            return $candidate;
        }
    }

    return DIRECTORY_SEPARATOR === '\\' ? 'php' : 'php';
}

/**
 * Return the current status of a queued/running/completed allocation job.
 * Called by the UI's polling loop every ~2 seconds.
 */
function handleJobStatus($conn) {
    $job_id = (int)($_GET['job_id'] ?? 0);
    if ($job_id <= 0) {
        sendJsonResponse(['status' => 'error', 'message' => 'Invalid job_id'], 400);
        return;
    }

    try {
        $stmt = $conn->prepare(
            "SELECT job_id, job_type, status, progress_stage, progress_percent,
                    total_students, allocated_students,
                    result_data, error_message,
                    created_at, started_at, completed_at
               FROM allocation_jobs
              WHERE job_id = ?
              LIMIT 1"
        );
        if (!$stmt) {
            sendJsonResponse(['status' => 'error', 'message' => 'Database error: could not prepare status query.'], 500);
            return;
        }
        $stmt->bind_param('i', $job_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (Throwable $e) {
        // DB transient error — return JSON so the frontend poll loop doesn't
        // crash on an unexpected token (HTML 500 page).
        sendJsonResponse([
            'status'  => 'error',
            'message' => 'Unable to fetch job status. The database may be temporarily unavailable.',
        ], 503);
        return;
    }

    if (!$row) {
        sendJsonResponse(['status' => 'error', 'message' => 'Job not found'], 404);
        return;
    }

    $payload = [
        'status'             => 'success',
        'job_id'             => (int)$row['job_id'],
        'job_type'           => (string)($row['job_type'] ?? 'allocation'),
        'job_status'         => $row['status'],
        'progress_stage'     => $row['progress_stage']   ?? '',
        'progress_percent'   => (int)$row['progress_percent'],
        'total_students'     => (int)$row['total_students'],
        'allocated_students' => (int)$row['allocated_students'],
        'created_at'         => $row['created_at'],
        'started_at'         => $row['started_at'],
        'completed_at'       => $row['completed_at'],
        'error_message'      => $row['error_message'] ?? '',
    ];

    // Decode result_data for the frontend when the job finished
    if (!empty($row['result_data'])) {
        $decoded = json_decode($row['result_data'], true);
        if (is_array($decoded)) {
            $payload['result'] = $decoded;
        }
    }

    sendJsonResponse($payload);
}

/**
 * Issue #10: Worker health check.
 * Returns queue depth, running job count, and recent completion summary.
 * GET /api/admin_api.php?action=worker_health
 */
function handleWorkerHealth($conn) {
    $queued  = 0;
    $running = 0;
    $todayDone = 0;
    $lastFailed = null;

    $res = $conn->query(
        "SELECT
            SUM(status = 'queued')    AS queued,
            SUM(status = 'running')   AS running,
            SUM(status = 'completed' AND DATE(completed_at) = CURDATE()) AS today_done,
            SUM(status = 'failed'    AND DATE(completed_at) = CURDATE()) AS today_failed
         FROM allocation_jobs"
    );
    if ($res) {
        $row       = $res->fetch_assoc();
        $queued    = (int)($row['queued']      ?? 0);
        $running   = (int)($row['running']     ?? 0);
        $todayDone = (int)($row['today_done']  ?? 0);
        $todayFailed = (int)($row['today_failed'] ?? 0);
    }

    // Most recent failed job message (if any)
    $failRes = $conn->query(
        "SELECT job_id, error_message, completed_at
           FROM allocation_jobs
          WHERE status = 'failed'
          ORDER BY completed_at DESC LIMIT 1"
    );
    if ($failRes && $failRes->num_rows > 0) {
        $lastFailed = $failRes->fetch_assoc();
    }

    sendJsonResponse([
        'status'        => 'ok',
        'worker_status' => ($running > 0) ? 'running' : (($queued > 0) ? 'busy' : 'idle'),
        'queued_jobs'   => $queued,
        'running_jobs'  => $running,
        'today_completed' => $todayDone,
        'today_failed'  => $todayFailed ?? 0,
        'last_failure'  => $lastFailed,
        'timestamp'     => date('c'),
    ]);
}

/**
 * Issue #18: Cancel a queued or running job.
 * Only queued jobs can be safely cancelled immediately.
 * Running jobs are marked cancelled — the worker honours it on next progress flush.
 * POST /api/admin_api.php?action=cancel_job  {csrf_token: Y}          (job_id optional)
 *
 * Cancels ALL queued/running allocation jobs immediately and releases all
 * processing locks so a fresh job can be queued straight away.
 */
function handleCancelJob($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['status' => 'error', 'message' => 'POST required'], 405);
        return;
    }
    check_csrf();

    $job_id = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;

    // Cancel all active jobs (or a specific one if job_id provided)
    if ($job_id > 0) {
        $sql    = "UPDATE allocation_jobs
                      SET status        = 'cancelled',
                          completed_at  = COALESCE(completed_at, NOW()),
                          updated_at    = NOW(),
                          error_message = 'Cancelled by administrator'
                    WHERE job_id = ?
                      AND status IN ('queued', 'running')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $job_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
    } else {
        // No specific job_id — cancel everything active
        $conn->query(
            "UPDATE allocation_jobs
                SET status        = 'cancelled',
                    completed_at  = COALESCE(completed_at, NOW()),
                    updated_at    = NOW(),
                    error_message = 'Cancelled by administrator'
              WHERE status IN ('queued', 'running')"
        );
        $affected = $conn->affected_rows;
    }

    // Always release the admin processing lock so a new job can start immediately
    releaseProcessingLock($conn, 'admin_processing_lock');

    // Release MySQL worker GET_LOCK in case the background worker is still holding it
    $conn->query("SELECT RELEASE_LOCK('fairmedalloc_allocation_worker')");

    $admin_id = (int)$_SESSION['user_id'];
    if ($affected > 0) {
        log_admin_action($conn, $admin_id, $job_id > 0 ? "Cancelled allocation job #$job_id" : "Cancelled all active allocation jobs");
        Logger::info("Admin cancelled " . ($job_id > 0 ? "Job #$job_id" : "all active jobs") . " and released all locks.");
        sendJsonResponse([
            'status'  => 'success',
            'message' => $affected . ' job(s) cancelled. You can now start a new allocation.',
        ]);
    } else {
        // Even if no jobs were found, still release locks — idempotent clean-up
        sendJsonResponse([
            'status'  => 'success',
            'message' => 'No active jobs found. Locks released — ready to start a new allocation.',
        ]);
    }
}


/**
 * Invokes the core Allocation Engine to process mathematical hostel placements.
 * NOTE: This is the SYNCHRONOUS path. For bulk data use queue_allocation instead.
 */
function handleRunAlgorithm($conn) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        sendJsonResponse(['status' => 'error', 'message' => 'POST required'], 405);
        return;
    }

    check_csrf();

    require_once '../includes/AllocationEngine.php';
    if (!acquireProcessingLock($conn, 'admin_processing_lock')) {
        sendJsonResponse(['status' => 'error', 'message' => 'Another admin processing job is already running.'], 409);
        return;
    }

    try {
        $engine = new AllocationEngine($conn);
        $result = $engine->run();
        if (($result['status'] ?? '') === 'success') {
            log_admin_action($conn, (int)$_SESSION['user_id'], 'Triggered allocation engine');
        }
        sendJsonResponse($result);
    } catch (Throwable $e) {
        sendJsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    } finally {
        releaseProcessingLock($conn, 'admin_processing_lock');
    }
}

function handleRescoreAll($conn) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        sendJsonResponse(['status' => 'error', 'message' => 'POST required'], 405);
        return;
    }

    check_csrf();

    require_once '../includes/AllocationEngine.php';
    if (!acquireProcessingLock($conn, 'admin_processing_lock')) {
        sendJsonResponse(['status' => 'error', 'message' => 'Another admin processing job is already running.'], 409);
        return;
    }

    try {
        $engine = new AllocationEngine($conn);
        $result = $engine->rescoreAllMedicalRecords();
        if (($result['status'] ?? '') === 'success') {
            log_admin_action($conn, (int)$_SESSION['user_id'], 'Recomputed all XGBoost urgency scores');
        }
        sendJsonResponse($result);
    } catch (Throwable $e) {
        sendJsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    } finally {
        releaseProcessingLock($conn, 'admin_processing_lock');
    }
}

function handleMlStatus() {
    require_once '../includes/MlServiceClient.php';

    try {
        $client = new MlServiceClient();
        $result = $client->health();
        sendJsonResponse($result);
    } catch (Throwable $e) {
        sendJsonResponse([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 503);
    }
}

/**
 * Allows administrators to manually override the algorithm and assign a specific student to a specific room.
 */
function handleManualAssign($conn) {
    // Only accept form-submissions (POST) for data mutations
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        sendJsonResponse(['status' => 'error', 'message' => 'POST required'], 405);
        return;
    }

    // Validate CSRF token to prevent cross-site request forgery
    check_csrf();

    $student_id = (int)($_POST['student_id'] ?? 0);
    $room_id    = (int)($_POST['room_id'] ?? 0);

    // Input validation: IDs must be positive
    if ($room_id <= 0 || $student_id <= 0) {
        sendJsonResponse(['status' => 'error', 'message' => 'Invalid Data'], 400);
        return;
    }

    // Validate student exists before proceeding
    $student_check = $conn->prepare("SELECT user_id FROM users WHERE user_id = ?");
    $student_check->bind_param("i", $student_id);
    $student_check->execute();
    if ($student_check->get_result()->num_rows === 0) {
        Logger::warning("Manual assignment attempt with non-existent student ID: {$student_id}");
        sendJsonResponse(['status' => 'error', 'message' => 'Student not found'], 400);
        return;
    }

    // Validate room exists and has capacity before proceeding
    $room_check = $conn->prepare("SELECT r.room_id, r.capacity, r.occupied_count FROM rooms r WHERE r.room_id = ?");
    $room_check->bind_param("i", $room_id);
    $room_check->execute();
    $room_validation = $room_check->get_result()->fetch_assoc();
    if (!$room_validation) {
        Logger::warning("Manual assignment attempt with non-existent room ID: {$room_id}");
        sendJsonResponse(['status' => 'error', 'message' => 'Room not found'], 400);
        return;
    }
    if ((int)$room_validation['occupied_count'] >= (int)$room_validation['capacity']) {
        Logger::warning("Manual assignment attempt to full room: {$room_id}");
        sendJsonResponse(['status' => 'error', 'message' => 'Selected room is already full'], 400);
        return;
    }

    // Fetch current academic session
    $sess_res = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'current_session'");
    $current_session = $sess_res ? ($sess_res->fetch_assoc()['setting_value'] ?? '2025/2026') : '2025/2026';

    try {
        $conn->begin_transaction();

        $student = fetchStudentForManualAllocation($conn, $student_id);
        if (!$student) {
            throw new Exception('Student record not found.');
        }

        $target_room = fetchTargetRoomForManualAllocation($conn, $room_id);
        if (!$target_room) {
            throw new Exception('Selected room was not found.');
        }
        if ((int)$target_room['is_postgrad'] === 1 || (int)$target_room['is_foundation'] === 1) {
            throw new Exception('That room is not available for undergraduate allocation.');
        }
        if (($student['gender'] ?? '') !== ($target_room['gender_allowed'] ?? '')) {
            throw new Exception('Student gender does not match the selected hostel.');
        }

        $existing = fetchExistingAllocationForManualAllocation($conn, $student_id);
        if ($existing && (int)$existing['room_id'] === $room_id) {
            throw new Exception('Student is already assigned to that room.');
        }

        if ($existing) {
            $old_room_id = (int)$existing['room_id'];

            $del_stmt = $conn->prepare("DELETE FROM allocations WHERE student_id = ?");
            $del_stmt->bind_param("i", $student_id);
            $del_stmt->execute();

            $dec_stmt = $conn->prepare("UPDATE rooms SET occupied_count = GREATEST(0, occupied_count - 1) WHERE room_id = ?");
            $dec_stmt->bind_param("i", $old_room_id);
            $dec_stmt->execute();
        }

        $is_mobility_issue = false;
        $mobility_val = strtolower(trim($student['mobility'] ?? ($student['mobility_status'] ?? '')));
        if ($mobility_val !== '' && $mobility_val !== 'normal mobility' && $mobility_val !== 'none') {
            $is_mobility_issue = true;
        }

        $bed = determineAvailableBedForManualAllocation(
            $conn,
            $room_id,
            (int)$target_room['capacity'],
            $target_room['bed_config'] ?? '',
            $is_mobility_issue
        );
        if ($bed === null) {
            throw new Exception('The selected room is already full.');
        }

        if (DbHelper::supportsAlgorithmVersion($conn)) {
            $algorithm_version = MANUAL_ALLOCATION_VERSION;
            $ins_stmt = $conn->prepare("INSERT INTO allocations (student_id, room_id, bed_space, bed_label, academic_session, allocation_method, algorithm_version) VALUES (?, ?, ?, ?, ?, 'manual', ?)");
            $ins_stmt->bind_param("iissss", $student_id, $room_id, $bed['bed_space'], $bed['bed_label'], $current_session, $algorithm_version);
        } else {
            $ins_stmt = $conn->prepare("INSERT INTO allocations (student_id, room_id, bed_space, bed_label, academic_session, allocation_method) VALUES (?, ?, ?, ?, ?, 'manual')");
            $ins_stmt->bind_param("iisss", $student_id, $room_id, $bed['bed_space'], $bed['bed_label'], $current_session);
        }
        if (!$ins_stmt->execute()) {
            throw new Exception('Unable to save the new room allocation.');
        }

        $inc_stmt = $conn->prepare("UPDATE rooms SET occupied_count = occupied_count + 1 WHERE room_id = ? AND occupied_count < capacity");
        $inc_stmt->bind_param("i", $room_id);
        $inc_stmt->execute();
        if ($inc_stmt->affected_rows !== 1) {
            throw new Exception('The selected room became unavailable. Please try again.');
        }

        $upd_stmt = $conn->prepare("UPDATE student_profiles SET allocation_status = 'Allocated' WHERE user_id = ?");
        $upd_stmt->bind_param("i", $student_id);
        $upd_stmt->execute();

        log_admin_action(
            $conn,
            (int)$_SESSION['user_id'],
            "Manually assigned student {$student_id} to room {$room_id}"
        );

        $conn->commit();
        sendJsonResponse([
            'status' => 'success',
            'bed_space' => $bed['bed_space'],
            'bed_label' => $bed['bed_label']
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        sendJsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
    }
}

/**
 * Fetches available (unfilled) rooms for a specific hostel.
 * Joined against hostels to block postgrad and foundation rooms from the dropdown.
 */
function handleGetRooms($conn) {
    $hostel_id = (int)($_GET['hostel_id'] ?? 0);
    if (!$hostel_id) {
        sendJsonResponse([]);
        return;
    }

    // Only return rooms with capacity AND belonging to a non-restricted hostel.
    $stmt = $conn->prepare("
        SELECT r.room_id, r.room_number, r.floor_level, r.capacity, r.occupied_count
        FROM rooms r
        JOIN hostels h ON r.hostel_id = h.hostel_id
        WHERE r.hostel_id = ?
          AND r.occupied_count < r.capacity
          AND h.is_postgrad   = 0
          AND h.is_foundation = 0
        ORDER BY CAST(r.room_number AS UNSIGNED) ASC
    ");
    $stmt->bind_param("i", $hostel_id);
    $stmt->execute();
    $res   = $stmt->get_result();
    $rooms = [];
    while ($row = $res->fetch_assoc()) {
        $row['available'] = (int)$row['capacity'] - (int)$row['occupied_count'];
        $rooms[] = $row;
    }
    sendJsonResponse($rooms);
}

/**
 * Aggregates various statistics to feed the Admin Dashboard charts and metrics.
 */
function handleAnalytics($conn) {
    // 1. Allocation Status Check (How many allocated vs pending)
    $stats_alloc = $conn->query("
        SELECT 
            COUNT(CASE WHEN allocation_id IS NOT NULL THEN 1 END) as allocated,
            COUNT(CASE WHEN allocation_id IS NULL THEN 1 END) as pending
        FROM student_profiles p
        LEFT JOIN allocations a ON p.user_id = a.student_id
    ")->fetch_assoc();

    // 2. Aggregate counts grouping by Medical Conditions
    $stats_medical = $conn->query("
        SELECT condition_category, COUNT(*) as count 
        FROM medical_records 
        GROUP BY condition_category
    ")->fetch_all(MYSQLI_ASSOC);

    // 3. Overview of pending vs finalized Payments
    $stats_payment = $conn->query("
        SELECT status, COUNT(*) as count 
        FROM payments 
        GROUP BY status
    ")->fetch_all(MYSQLI_ASSOC);

    // Return the aggregated metrics back to the Admin JS frontend
    sendJsonResponse([
        'status'   => 'success',
        'allocation' => $stats_alloc,
        'medical'    => $stats_medical,
        'payments'   => $stats_payment
    ]);
}

/**
 * Returns per-hostel occupancy totals (capacity, occupied, gender, name).
 * Used by the hostel occupancy table on the reports page.
 */
function handleHostelStats($conn) {
    $res = $conn->query("
        SELECT
            h.hostel_id,
            h.name,
            h.block_name,
            h.gender_allowed   AS gender,
            SUM(r.capacity)          AS capacity,
            SUM(r.occupied_count)    AS occupied
        FROM hostels h
        JOIN rooms r ON r.hostel_id = h.hostel_id
        GROUP BY h.hostel_id
        ORDER BY h.name ASC, h.block_name ASC
    ");
    $rows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['capacity'] = (int)$row['capacity'];
            $row['occupied'] = (int)$row['occupied'];
            $rows[] = $row;
        }
    }
    sendJsonResponse($rows);
}

function fetchStudentForManualAllocation($conn, int $student_id): ?array {
    $stmt = $conn->prepare("SELECT p.user_id, p.gender FROM student_profiles p WHERE p.user_id = ? LIMIT 1");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function fetchTargetRoomForManualAllocation($conn, int $room_id): ?array {
    $stmt = $conn->prepare("
        SELECT r.room_id, r.capacity, r.occupied_count, r.bed_config,
               h.gender_allowed, h.is_postgrad, h.is_foundation
        FROM rooms r
        JOIN hostels h ON r.hostel_id = h.hostel_id
        WHERE r.room_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $room_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function fetchExistingAllocationForManualAllocation($conn, int $student_id): ?array {
    $stmt = $conn->prepare("SELECT allocation_id, room_id FROM allocations WHERE student_id = ? LIMIT 1");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function determineAvailableBedForManualAllocation($conn, int $room_id, int $capacity, string $bed_config, bool $is_mobility_issue = false): ?array {
    $config_arr = [];
    if ($bed_config !== '') {
        $config_arr = array_map('trim', explode(',', $bed_config));
    }
    if (count($config_arr) < $capacity) {
        $config_arr = array_pad($config_arr, $capacity, 'LB');
    }

    $stmt = $conn->prepare("SELECT bed_space FROM allocations WHERE room_id = ?");
    $stmt->bind_param("i", $room_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $occupied_indices = [];
    while ($row = $res->fetch_assoc()) {
        $bed_space = $row['bed_space'] ?? '';
        if ($bed_space !== '' && strlen($bed_space) === 1) {
            $ord = ord($bed_space);
            if ($ord >= 65 && $ord <= 90) {
                $occupied_indices[] = $ord - 65;
            }
        }
    }

    if ($is_mobility_issue) {
        for ($i = 0; $i < $capacity; $i++) {
            if (!in_array($i, $occupied_indices, true)) {
                $label = $config_arr[$i] ?? 'LB';
                if (trim($label) === 'LB') {
                    return [
                        'bed_space' => chr(65 + $i),
                        'bed_label' => $label
                    ];
                }
            }
        }
    }

    for ($i = 0; $i < $capacity; $i++) {
        if (!in_array($i, $occupied_indices, true)) {
            return [
                'bed_space' => chr(65 + $i),
                'bed_label' => $config_arr[$i] ?? 'LB'
            ];
        }
    }

    return null;
}

/**
 * Acquire a named processing lock stored in the settings table.
 *
 * Stale-lock protection: if a lock has been held for longer than
 * LOCK_STALE_SECONDS (default 15 min) it is presumed to belong to a
 * crashed process and is forcibly released before the new attempt.
 * This prevents the allocation UI from being permanently blocked after
 * an unexpected PHP/Python crash mid-run.
 */
function acquireProcessingLock($conn, string $lock_key): bool {
    $lock_stale_seconds = 900; // 15 minutes

    $ts_key = $lock_key . '_acquired_at';

    // Seed both the lock and its timestamp rows so ON DUPLICATE KEY works.
    foreach ([$lock_key => '0', $ts_key => '0'] as $k => $v) {
        $s = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_key = setting_key");
        if (!$s) return false;
        $s->bind_param("ss", $k, $v);
        $s->execute();
        $s->close();
    }

    // Check for a stale lock and force-release if needed.
    $chk = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    if ($chk) {
        $chk->bind_param("s", $lock_key);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (($row['setting_value'] ?? '0') === '1') {
            // Lock is held — check how long ago it was acquired.
            $ts_chk = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            if ($ts_chk) {
                $ts_chk->bind_param("s", $ts_key);
                $ts_chk->execute();
                $ts_row = $ts_chk->get_result()->fetch_assoc();
                $ts_chk->close();

                $acquired_at = (int)($ts_row['setting_value'] ?? 0);
                $age = time() - $acquired_at;

                if ($acquired_at > 0 && $age > $lock_stale_seconds) {
                    // Stale lock — force release so we don't block forever.
                    error_log("acquireProcessingLock: releasing stale lock '{$lock_key}' (held for {$age}s)");
                    releaseProcessingLock($conn, $lock_key);
                } else {
                    // Lock is genuinely held by another live process.
                    return false;
                }
            } else {
                return false;
            }
        }
    }

    // Try to atomically set the lock from '0' → '1' and record the timestamp.
    $lock_stmt = $conn->prepare("UPDATE settings SET setting_value = '1' WHERE setting_key = ? AND setting_value = '0'");
    if (!$lock_stmt) return false;
    $lock_stmt->bind_param("s", $lock_key);
    $lock_stmt->execute();
    $acquired = $lock_stmt->affected_rows === 1;
    $lock_stmt->close();

    if ($acquired) {
        $now = (string)time();
        $ts_stmt = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
        if ($ts_stmt) {
            $ts_stmt->bind_param("ss", $now, $ts_key);
            $ts_stmt->execute();
            $ts_stmt->close();
        }
    }

    return $acquired;
}

function releaseProcessingLock($conn, string $lock_key): void {
    $ts_key = $lock_key . '_acquired_at';

    foreach ([$lock_key => '0', $ts_key => '0'] as $k => $v) {
        $s = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
        if (!$s) continue;
        $s->bind_param("ss", $v, $k);
        $s->execute();
        $s->close();
    }
}

// allocationsSupportAlgorithmVersion() is no longer defined here.
// Use DbHelper::supportsAlgorithmVersion($conn) — the single shared implementation.
?>
