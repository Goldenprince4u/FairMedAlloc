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
 * Usage (triggered by admin_api.php via proc_open):
 *   php worker_allocation.php --job-id=<id>
 *
 * The worker is safe against concurrent execution:
 *  - GET_LOCK prevents two workers running at the same time.
 *  - If a lock cannot be acquired the worker exits cleanly (exit 2).
 *  - A stale "running" job (> STALE_JOB_MINUTES old) is automatically reset.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("This script must be run from the command line only.\n");
}

// ── Constants ────────────────────────────────────────────────────────────────
const WORKER_LOCK_NAME  = 'fairmedalloc_allocation_worker';
const LOCK_WAIT_SECONDS = 1;       // how long GET_LOCK waits before giving up
const STALE_JOB_MINUTES = 20;      // "running" jobs older than this are reset
const PROGRESS_FLUSH_SEC = 3;      // minimum seconds between DB progress writes

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/AllocationEngine.php';

// ── Parse CLI arguments ───────────────────────────────────────────────────────
$opts       = getopt('', ['job-id:']);
$forced_job = isset($opts['job-id']) ? (int)$opts['job-id'] : null;

// ── Main ──────────────────────────────────────────────────────────────────────
main($conn, $forced_job);

// =============================================================================
// Functions
// =============================================================================

/**
 * Entry-point: acquire lock → fetch job → run → release lock.
 */
function main(mysqli $conn, ?int $forced_job): void
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

        Logger::info("Worker: processing Job #{$job['job_id']} (type={$job['job_type']})");
        processAllocationJob($conn, $job);

    } finally {
        releaseWorkerLock($conn);
        Logger::info('Worker: lock released. Done.');
    }
}

// ── Lock helpers ──────────────────────────────────────────────────────────────

function acquireWorkerLock(mysqli $conn): bool
{
    $name    = WORKER_LOCK_NAME;
    $wait    = LOCK_WAIT_SECONDS;
    $result  = $conn->query("SELECT GET_LOCK('$name', $wait) AS locked");
    $row     = $result ? $result->fetch_assoc() : null;
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
    return $result ? $result->fetch_assoc() : null;
}

function getJobById(mysqli $conn, int $job_id): ?array
{
    $stmt = $conn->prepare(
        "SELECT * FROM allocation_jobs
          WHERE job_id = ?
            AND status IN ('queued', 'running')
          LIMIT 1"
    );
    $stmt->bind_param('i', $job_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// ── Stale job recovery ────────────────────────────────────────────────────────

function resetStaleRunningJobs(mysqli $conn): void
{
    $minutes = STALE_JOB_MINUTES;
    $conn->query(
        "UPDATE allocation_jobs
            SET status = 'queued',
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
    $job_id = (int)$job['job_id'];

    // Mark as started
    $conn->query(
        "UPDATE allocation_jobs
            SET status           = 'running',
                started_at       = NOW(),
                progress_stage   = 'Initializing',
                progress_percent = 5,
                updated_at       = NOW()
          WHERE job_id = $job_id"
    );

    try {
        $engine = new AllocationEngine($conn);
        $engine->setJobId($job_id);   // enables total_students tracking in the DB

        // Progress callback — rate-limited to avoid hammering MySQL on every tick
        $lastFlush = 0;
        $progressCallback = function (array $progress) use ($conn, $job_id, &$lastFlush): void {
            $now = time();
            if ($now - $lastFlush < PROGRESS_FLUSH_SEC) {
                return;
            }
            $lastFlush = $now;

            $stage   = $conn->real_escape_string((string)($progress['stage']   ?? ''));
            $percent = (int)($progress['percent'] ?? 0);
            $percent = max(0, min(100, $percent));

            $conn->query(
                "UPDATE allocation_jobs
                    SET progress_stage   = '$stage',
                        progress_percent = $percent,
                        status           = 'running',
                        updated_at       = NOW()
                  WHERE job_id = $job_id"
            );
        };

        $result = $engine->run(null, $progressCallback);

        $status    = $result['status'] ?? 'error';
        $allocated = (int)($result['allocated'] ?? 0);
        $total     = (int)($result['total'] ?? 0);

        $resultJson = $conn->real_escape_string(json_encode([
            'status'          => $status,
            'allocated'       => $allocated,
            'total'           => $total,
            'solver_mode'     => $result['solver_mode']     ?? 'unknown',
            'solver_status'   => $result['solver_status']   ?? 'unknown',
            'prediction_mode' => $result['prediction_mode'] ?? 'unknown',
            'message'         => $result['message']         ?? '',
            'optimal'         => $result['optimal']         ?? false,
        ]));

        if ($status === 'success') {
            $conn->query(
                "UPDATE allocation_jobs
                    SET status              = 'completed',
                        progress_stage      = 'Completed',
                        progress_percent    = 100,
                        allocated_students  = $allocated,
                        total_students      = $total,
                        result_data         = '$resultJson',
                        completed_at        = NOW(),
                        updated_at          = NOW()
                  WHERE job_id = $job_id"
            );
            Logger::info("Worker: Job #$job_id completed — $allocated/$total students allocated.");
        } else {
            $errorMsg = $conn->real_escape_string($result['message'] ?? 'Engine returned non-success status.');
            $conn->query(
                "UPDATE allocation_jobs
                    SET status        = 'failed',
                        error_message = '$errorMsg',
                        result_data   = '$resultJson',
                        completed_at  = NOW(),
                        updated_at    = NOW()
                  WHERE job_id = $job_id"
            );
            Logger::error("Worker: Job #$job_id failed — {$errorMsg}");
        }

    } catch (Throwable $e) {
        Logger::error("Worker: Job #$job_id threw exception — " . $e->getMessage());
        $errorMsg = $conn->real_escape_string(
            substr($e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 0, 500)
        );
        $conn->query(
            "UPDATE allocation_jobs
                SET status        = 'failed',
                    error_message = '$errorMsg',
                    completed_at  = NOW(),
                    updated_at    = NOW()
              WHERE job_id = $job_id"
        );
    }
}
