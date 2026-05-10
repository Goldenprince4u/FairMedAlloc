#!/usr/bin/env php
<?php
/**
 * Allocation Worker
 * =================
 * Background process that dequeues and executes one allocation job.
 *
 * Usage (manual, CLI only):
 *   php worker_allocation.php
 *   php worker_allocation.php --job-id=42    # run a specific job
 *
 * Usage (triggered by admin_api.php via popen):
 *   php worker_allocation.php --job-id=<id>
 *
 * Safety guarantees:
 *  - CLI-only: exits 403 if called via HTTP.
 *  - DB connection validated before any query.
 *  - MySQL GET_LOCK prevents concurrent workers.
 *  - Stale "running" jobs (> STALE_JOB_MINUTES) are auto-reset to "queued".
 *  - Failed jobs are retried up to max_retries times with a delay.
 *  - Graceful shutdown: honours a $shuttingDown flag (set via SIGTERM on Linux).
 */

if (!defined('FAIRMED_WORKER_LIBRARY_MODE') && php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("This script must be run from the command line only.\n");
}

// ── Constants ────────────────────────────────────────────────────────────────
const WORKER_LOCK_NAME   = 'fairmedalloc_allocation_worker';
const LOCK_WAIT_SECONDS  = 1;     // how long GET_LOCK waits before giving up
const STALE_JOB_MINUTES  = 45;   // "running" jobs older than this are reset.
                                  // Raised from 20 → 45 because OR-Tools can
                                  // run for 30+ min on large datasets with no
                                  // progress update between 30% and completion.
const PROGRESS_FLUSH_SEC = 3;    // minimum seconds between DB progress writes
const HEARTBEAT_SEC      = 300;  // touch updated_at every 5 min during solver
const RETRY_DELAY_SEC    = 10;   // seconds to wait before a retry attempt
const JOB_CANCELLED_EXCEPTION = '__FAIRMED_JOB_CANCELLED__';

// ── Bootstrap ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/db_config.php';
// Note: db_config.php already pulls in includes/Logger.php and includes/DbHelper.php
require_once __DIR__ . '/includes/AllocationEngine.php';
require_once __DIR__ . '/includes/CsvImportService.php';

// ── DB connection validation (Issue #6 fix) ───────────────────────────────────
if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    $errMsg = $conn->connect_error ?? 'Unknown connection error';
    error_log("[Worker] Database connection failed: $errMsg");
    exit(1);
}

// Optionally set a statement-level timeout (Issue #7 fix — MySQL ≥ 5.7.4)
// This prevents a single runaway query from blocking the worker indefinitely.
try {
    $conn->query('SET SESSION MAX_EXECUTION_TIME = 600000');
} catch (mysqli_sql_exception $e) {
    Logger::warning('Worker: MAX_EXECUTION_TIME is not supported by this database server; continuing without it.');
}

// ── Parse CLI arguments ───────────────────────────────────────────────────────
if (!defined('FAIRMED_WORKER_LIBRARY_MODE')) {
$opts       = getopt('', ['job-id:']);
$forced_job = isset($opts['job-id']) ? (int)$opts['job-id'] : null;

// ── Graceful shutdown flag (SIGTERM on Linux/Mac; Windows ignores pcntl) ──────
$shuttingDown = false;
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$shuttingDown) {
        Logger::info('Worker: received SIGTERM — will shut down after current job.');
        $shuttingDown = true;
    });
}

// ── Main ──────────────────────────────────────────────────────────────────────
main($conn, $forced_job, $shuttingDown);
}

// =============================================================================
// Functions
// =============================================================================

/**
 * Entry-point: validate DB → reset stale jobs → acquire lock → run → release.
 */
function main(mysqli $conn, ?int $forced_job, bool &$shuttingDown): void
{
    resetStaleRunningJobs($conn);

    if (!acquireWorkerLock($conn)) {
        Logger::warning('Worker: could not acquire GET_LOCK — another worker is running. Exiting.');
        exit(2);
    }

    try {
        $job = $forced_job !== null
            ? getJobById($conn, $forced_job)
            : getNextQueuedJob($conn);

        if (!$job) {
            Logger::info('Worker: no queued jobs found. Exiting.');
            exit(0);
        }

        Logger::info("Worker: processing Job #{$job['job_id']} (type={$job['job_type']}, retry={$job['retry_count']})");
        processAllocationJob($conn, $job);

    } finally {
        releaseWorkerLock($conn);
        Logger::info('Worker: lock released. Done.');

        if ($shuttingDown) {
            Logger::info('Worker: graceful shutdown complete.');
            exit(0);
        }
    }
}

function runWorkerJobInline(mysqli $conn, int $job_id): void
{
    resetStaleRunningJobs($conn);

    if (!acquireWorkerLock($conn)) {
        Logger::warning("Worker: inline fallback could not acquire GET_LOCK for Job #$job_id.");
        return;
    }

    try {
        $job = getJobById($conn, $job_id);
        if (!$job) {
            Logger::warning("Worker: inline fallback could not find Job #$job_id.");
            return;
        }

        Logger::info("Worker: inline fallback processing Job #{$job['job_id']}.");
        processAllocationJob($conn, $job);
    } finally {
        releaseWorkerLock($conn);
        Logger::info('Worker: inline fallback lock released.');
    }
}

// ── Lock helpers ──────────────────────────────────────────────────────────────

function acquireWorkerLock(mysqli $conn): bool
{
    $name   = WORKER_LOCK_NAME;
    $wait   = LOCK_WAIT_SECONDS;
    $result = $conn->query("SELECT GET_LOCK('$name', $wait) AS locked");
    $row    = $result ? $result->fetch_assoc() : null;
    return ($row['locked'] ?? 0) == 1;
}

function releaseWorkerLock(mysqli $conn): void
{
    $name = WORKER_LOCK_NAME;
    $conn->query("SELECT RELEASE_LOCK('$name')");
}

// ── Job fetching ──────────────────────────────────────────────────────────────

function getNextQueuedJob(mysqli $conn): ?array
{
    $result = $conn->query(
        "SELECT * FROM allocation_jobs
          WHERE status = 'queued'
          ORDER BY created_at ASC
          LIMIT 1"
    );
    // Issue #8: explicit null-check on query result
    if (!$result || $result->num_rows === 0) {
        return null;
    }
    return $result->fetch_assoc();
}

function getJobById(mysqli $conn, int $job_id): ?array
{
    $stmt = $conn->prepare(
        "SELECT * FROM allocation_jobs
          WHERE job_id = ?
            AND status IN ('queued', 'running')
          LIMIT 1"
    );
    if (!$stmt) {
        Logger::error('Worker: could not prepare getJobById statement: ' . $conn->error);
        return null;
    }
    $stmt->bind_param('i', $job_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// ── Stale job recovery ────────────────────────────────────────────────────────

function resetStaleRunningJobs(mysqli $conn): void
{
    // Uses the composite index idx_status_updated (status, updated_at)
    $minutes = (int)STALE_JOB_MINUTES;
    $conn->query(
        "UPDATE allocation_jobs
            SET status           = 'queued',
                progress_stage   = 'Reset (stale)',
                progress_percent = 0,
                started_at       = NULL,
                updated_at       = NOW()
          WHERE status = 'running'
            AND updated_at < DATE_SUB(NOW(), INTERVAL $minutes MINUTE)"
    );
    if ($conn->affected_rows > 0) {
        Logger::warning("Worker: reset {$conn->affected_rows} stale running job(s) back to 'queued'.");
    }
}

// ── Job processing ────────────────────────────────────────────────────────────

function processAllocationJob(mysqli $conn, array $job): void
{
    $jobType = (string)($job['job_type'] ?? 'allocation');
    if ($jobType === 'csv_import') {
        processCsvImportJob($conn, $job);
        return;
    }

    $job_id     = (int)$job['job_id'];
    $retryCount = max(0, (int)($job['retry_count'] ?? 0));
    $maxRetries = (int)($job['max_retries'] ?? 3);

    while (true) {
        markJobRunning($conn, $job_id);

        try {
            $engine = new AllocationEngine($conn);
            $engine->setJobId($job_id);  // enables total_students tracking in the DB

            // Rate-limited progress callback (Issue #4: already uses prepared statements)
            $lastFlush     = 0;
            $lastHeartbeat = 0;
            $progressCallback = function (array $progress) use ($conn, $job_id, &$lastFlush, &$lastHeartbeat): void {
                $now = time();

                // Heartbeat: touch updated_at every HEARTBEAT_SEC even when we
                // skip the full progress write, so the stale-job detector never
                // resets a legitimately running OR-Tools solve.
                if ($now - $lastHeartbeat >= HEARTBEAT_SEC) {
                    $lastHeartbeat = $now;
                    $conn->query("UPDATE allocation_jobs SET updated_at = NOW() WHERE job_id = {$job_id}");
                }

                if ($now - $lastFlush < PROGRESS_FLUSH_SEC) {
                    return;
                }
                $lastFlush = $now;

                $stage   = (string)($progress['stage']   ?? '');
                $percent = max(0, min(100, (int)($progress['percent'] ?? 0)));

                // Check if an administrator cancelled this job mid-run
                $cancelCheck = $conn->prepare(
                    "SELECT status FROM allocation_jobs WHERE job_id = ? LIMIT 1"
                );
                if ($cancelCheck) {
                    $cancelCheck->bind_param('i', $job_id);
                    $cancelCheck->execute();
                    $checkRow = $cancelCheck->get_result()->fetch_assoc();
                    $cancelCheck->close();
                    if (($checkRow['status'] ?? '') === 'cancelled') {
                        throw new RuntimeException(JOB_CANCELLED_EXCEPTION);
                    }
                }

                $stmt = $conn->prepare(
                    "UPDATE allocation_jobs
                        SET progress_stage   = ?,
                            progress_percent = ?,
                            status           = 'running',
                            updated_at       = NOW()
                      WHERE job_id = ?"
                );
                if (!$stmt) {
                    return;
                }
                $stmt->bind_param('sii', $stage, $percent, $job_id);
                $stmt->execute();
                $stmt->close();
            };

            // Pass use_mutex=false — this worker already holds 'fairmedalloc_allocation_worker'
            // via acquireWorkerLock(). Asking the engine to acquire a second lock on the same
            // connection would cause a self-deadlock when the inline fallback path is used.
            $result    = $engine->run(null, $progressCallback, false);
            $status    = $result['status'] ?? 'error';
            $allocated = (int)($result['allocated'] ?? 0);
            $total     = (int)($result['total']     ?? 0);

            $resultJson = json_encode([
                'status'          => $status,
                'allocated'       => $allocated,
                'total'           => $total,
                'solver_mode'     => $result['solver_mode']     ?? 'unknown',
                'solver_status'   => $result['solver_status']   ?? 'unknown',
                'prediction_mode' => $result['prediction_mode'] ?? 'unknown',
                'message'         => $result['message']         ?? '',
                'optimal'         => $result['optimal']         ?? false,
            ]);

            if ($status === 'success') {
                $stmt = $conn->prepare(
                    "UPDATE allocation_jobs
                        SET status             = 'completed',
                            progress_stage     = 'Completed',
                            progress_percent   = 100,
                            allocated_students = ?,
                            total_students     = ?,
                            result_data        = ?,
                            completed_at       = NOW(),
                            updated_at         = NOW()
                      WHERE job_id = ?"
                );
                $stmt->bind_param('iisi', $allocated, $total, $resultJson, $job_id);
                $stmt->execute();
                $stmt->close();
                Logger::info("Worker: Job #$job_id completed — $allocated/$total students allocated.");
                return;
            }

            $errorMsg = $result['message'] ?? 'Engine returned non-success status.';
            if (!retryJobOrFail($conn, $job_id, $retryCount, $maxRetries, $errorMsg, $resultJson)) {
                return;
            }
            $retryCount++;

        } catch (Throwable $e) {
            if ($e instanceof RuntimeException && $e->getMessage() === JOB_CANCELLED_EXCEPTION) {
                markJobCancelled($conn, $job_id);
                Logger::info("Worker: Job #$job_id was cancelled by administrator.");
                return;
            }

            Logger::error("Worker: Job #$job_id threw exception — " . $e->getMessage());
            $errorMsg = substr(
                $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')',
                0, 2000
            );
            if (!retryJobOrFail($conn, $job_id, $retryCount, $maxRetries, $errorMsg, null)) {
                return;
            }
            $retryCount++;
        }
    }
}

function processCsvImportJob(mysqli $conn, array $job): void
{
    $jobId = (int)$job['job_id'];
    $payload = decodeImportJobPayload($job);
    $filePath = (string)($payload['file_path'] ?? '');
    $originalName = (string)($payload['original_name'] ?? basename($filePath));
    $jobConn = openAuxJobConnection();

    if ($filePath === '' || !is_file($filePath)) {
        closeAuxJobConnection($jobConn);
        markJobFailed($conn, $jobId, 'Queued import file could not be found on the server.', null);
        return;
    }

    markJobRunning($conn, $jobId);

    try {
        $service = new CsvImportService($conn, $jobId);
        $result = $service->processCsvFile($filePath, function (array $progress) use ($conn, $jobConn, $jobId): void {
            $trackerConn = $jobConn instanceof mysqli ? $jobConn : $conn;
            $statusCheck = $trackerConn->prepare("SELECT status FROM allocation_jobs WHERE job_id = ? LIMIT 1");
            if ($statusCheck) {
                $statusCheck->bind_param('i', $jobId);
                $statusCheck->execute();
                $statusRow = $statusCheck->get_result()->fetch_assoc();
                $statusCheck->close();
                if (($statusRow['status'] ?? '') === 'cancelled') {
                    throw new RuntimeException(JOB_CANCELLED_EXCEPTION);
                }
            }

            $stage = (string)($progress['stage'] ?? 'Processing import');
            $percent = max(0, min(100, (int)($progress['percent'] ?? 0)));
            $total = max(0, (int)($progress['total'] ?? 0));
            $processed = max(0, (int)($progress['processed'] ?? 0));

            $stmt = $trackerConn->prepare(
                "UPDATE allocation_jobs
                    SET status = 'running',
                        progress_stage = ?,
                        progress_percent = ?,
                        total_students = ?,
                        allocated_students = ?,
                        updated_at = NOW()
                  WHERE job_id = ?"
            );
            if ($stmt) {
                $stmt->bind_param('siiii', $stage, $percent, $total, $processed, $jobId);
                $stmt->execute();
                $stmt->close();
            }
        });

        $resultJson = json_encode([
            'status' => 'success',
            'job_type' => 'csv_import',
            'file_name' => $originalName,
            'imported' => (int)($result['imported'] ?? 0),
            'duplicates' => (int)($result['duplicates'] ?? 0),
            'total' => (int)($result['total'] ?? 0),
            'duration_ms' => (float)($result['duration_ms'] ?? 0),
            'message' => (string)($result['message'] ?? 'Import completed successfully.'),
        ]);

        $imported = (int)($result['imported'] ?? 0);
        $total = (int)($result['total'] ?? 0);
        $stmt = $conn->prepare(
            "UPDATE allocation_jobs
                SET status = 'completed',
                    progress_stage = 'Completed',
                    progress_percent = 100,
                    allocated_students = ?,
                    total_students = ?,
                    result_data = ?,
                    completed_at = NOW(),
                    updated_at = NOW()
              WHERE job_id = ?"
        );
        if ($stmt) {
            $stmt->bind_param('iisi', $imported, $total, $resultJson, $jobId);
            $stmt->execute();
            $stmt->close();
        }

        cleanupImportFile($filePath);
        Logger::info("Worker: CSV import job #$jobId completed - {$imported}/{$total} valid rows imported.");
    } catch (Throwable $e) {
        cleanupImportFile($filePath);
        if ($e instanceof RuntimeException && $e->getMessage() === JOB_CANCELLED_EXCEPTION) {
            markJobCancelled($conn, $jobId);
            Logger::info("Worker: CSV import job #$jobId was cancelled by administrator.");
            closeAuxJobConnection($jobConn);
            return;
        }

        $errorMsg = substr(
            $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')',
            0,
            2000
        );
        markJobFailed($conn, $jobId, $errorMsg, null);
        Logger::error("Worker: CSV import job #$jobId failed - " . $e->getMessage());
    }

    closeAuxJobConnection($jobConn);
}

function decodeImportJobPayload(array $job): array
{
    $raw = $job['result_data'] ?? null;
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function cleanupImportFile(string $filePath): void
{
    if ($filePath !== '' && is_file($filePath)) {
        @unlink($filePath);
    }
}

function openAuxJobConnection(): ?mysqli
{
    try {
        $jobConn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        if ($jobConn->connect_errno) {
            return null;
        }

        return $jobConn;
    } catch (Throwable $e) {
        Logger::warning('Worker: unable to open auxiliary job tracker connection: ' . $e->getMessage());
        return null;
    }
}

function closeAuxJobConnection(?mysqli $jobConn): void
{
    if ($jobConn instanceof mysqli) {
        @$jobConn->close();
    }
}

/**
 * Mark a job as running while preserving its first started_at timestamp.
 */
function markJobRunning(mysqli $conn, int $job_id): void
{
    $startStmt = $conn->prepare(
        "UPDATE allocation_jobs
            SET status           = 'running',
                started_at       = COALESCE(started_at, NOW()),
                completed_at     = NULL,
                progress_stage   = 'Initializing',
                progress_percent = 5,
                updated_at       = NOW()
          WHERE job_id = ?"
    );
    if (!$startStmt) {
        throw new RuntimeException('Could not prepare start statement: ' . $conn->error);
    }
    $startStmt->bind_param('i', $job_id);
    $startStmt->execute();
    $startStmt->close();
}

function markJobCancelled(mysqli $conn, int $job_id): void
{
    $stmt = $conn->prepare(
        "UPDATE allocation_jobs
            SET status        = 'cancelled',
                completed_at  = COALESCE(completed_at, NOW()),
                updated_at    = NOW(),
                error_message = 'Cancelled by administrator'
          WHERE job_id = ?"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $job_id);
    $stmt->execute();
    $stmt->close();
}

function markJobFailed(mysqli $conn, int $job_id, string $errorMsg, ?string $resultJson): void
{
    $stmt = $conn->prepare(
        "UPDATE allocation_jobs
            SET status = 'failed',
                error_message = ?,
                result_data = ?,
                completed_at = NOW(),
                updated_at = NOW()
          WHERE job_id = ?"
    );
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('ssi', $errorMsg, $resultJson, $job_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Retry inside the same worker process so a one-off worker launched from the
 * admin UI can finish its own retry cycle without depending on worker_launcher.
 * Returns true when another attempt should be made after backoff.
 */
function retryJobOrFail(
    mysqli $conn,
    int    $job_id,
    int    $retryCount,
    int    $maxRetries,
    string $errorMsg,
    ?string $resultJson
): bool {
    $newRetryCount = $retryCount + 1;

    if ($newRetryCount <= $maxRetries) {
        $delaySec = RETRY_DELAY_SEC * (2 ** ($newRetryCount - 1)); // exponential backoff
        Logger::warning("Worker: Job #$job_id failed (attempt $newRetryCount/$maxRetries). Retrying in {$delaySec}s.");

        $stmt = $conn->prepare(
            "UPDATE allocation_jobs
                SET status           = 'running',
                    retry_count      = ?,
                    progress_stage   = ?,
                    progress_percent = 0,
                    error_message    = ?,
                    updated_at       = NOW()
              WHERE job_id = ?"
        );
        $stage = "Retry $newRetryCount/$maxRetries in {$delaySec}s";
        $stmt->bind_param('issi', $newRetryCount, $stage, $errorMsg, $job_id);
        $stmt->execute();
        $stmt->close();
        sleep($delaySec);
        return true;

    } else {
        Logger::error("Worker: Job #$job_id permanently failed after $maxRetries retries — $errorMsg");

        $stmt = $conn->prepare(
            "UPDATE allocation_jobs
                SET status        = 'failed',
                    error_message = ?,
                    result_data   = ?,
                    completed_at  = NOW(),
                    updated_at    = NOW()
              WHERE job_id = ?"
        );
        $stmt->bind_param('ssi', $errorMsg, $resultJson, $job_id);
        $stmt->execute();
        $stmt->close();
        return false;
    }
}
