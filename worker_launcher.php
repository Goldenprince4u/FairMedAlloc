#!/usr/bin/env php
<?php
/**
 * Allocation Worker Auto-Launcher
 * ================================
 * Continuous background process that polls the database for queued allocation jobs
 * and spawns worker_allocation.php to process them.
 *
 * This is the recommended way to run the queue in production.
 *
 * Usage:
 *   # Run directly from CLI (foreground):
 *   php worker_launcher.php
 *
 *   # Or as a service/cron (background):
 *   nohup php /path/to/worker_launcher.php > /var/log/fairmed_worker.log 2>&1 &
 *
 * On Windows (XAMPP), you can run this via Task Scheduler or manually in a dedicated terminal.
 *
 * Environment:
 *   FAIRMED_WORKER_INTERVAL  : polling interval in seconds (default: 2)
 *   FAIRMED_WORKER_MAX_JOBS  : max jobs to process per cycle (default: 1)
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from CLI only.\n");
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/Logger.php';

const DEFAULT_INTERVAL = 2;      // seconds
const DEFAULT_MAX_JOBS = 1;      // one job at a time
const LOCK_TTL = 5;              // seconds

// Issue #2 fix: use getenv() — $_SERVER does not expose shell env vars in CLI PHP
$interval = (int)(getenv('FAIRMED_WORKER_INTERVAL') ?: DEFAULT_INTERVAL);
$max_jobs  = (int)(getenv('FAIRMED_WORKER_MAX_JOBS')  ?: DEFAULT_MAX_JOBS);

Logger::info("Allocation Worker Launcher started (poll interval: {$interval}s)");

$cycles = 0;
while (true) {
    $cycles++;

    try {
        // Get the next queued job(s)
        $result = $conn->query(
            "SELECT job_id FROM allocation_jobs
              WHERE status = 'queued'
              ORDER BY created_at ASC
              LIMIT $max_jobs"
        );

        // Issue #1 fix: guard against false on DB error before calling fetch_assoc()
        $jobs = [];
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $jobs[] = (int)$row['job_id'];
            }
        }

        // Spawn worker for each queued job
        foreach ($jobs as $job_id) {
            spawnWorker($job_id);
        }

        // Log periodically (every 30 cycles = 60s for default 2s interval)
        if ($cycles % 30 === 0) {
            Logger::info("Launcher alive (cycle $cycles)");
        }

        sleep($interval);

    } catch (Throwable $e) {
        Logger::error("Launcher error: " . $e->getMessage());
        sleep($interval * 2); // back off on error
    }
}

function spawnWorker(int $job_id): void
{
    $php    = PHP_BINARY;
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'worker_allocation.php';

    if (!file_exists($script)) {
        Logger::error("Worker script not found at $script");
        return;
    }

    Logger::info("Spawning worker for job #$job_id");

    if (DIRECTORY_SEPARATOR === '\\') {
        // Windows: use proc_open for reliable detached launch under Apache-as-service
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --job-id=' . (int)$job_id;
        $descriptors = [[0 => 'pipe', 'r'], [1 => 'pipe', 'w'], [2 => 'pipe', 'w']];
        $proc = @proc_open('cmd /c start /B "" ' . $cmd, $descriptors, $pipes);
        if (is_resource($proc)) {
            foreach ($pipes as $pipe) { fclose($pipe); }
            proc_close($proc);
        } else {
            // popen fallback
            $handle = popen('cmd /c start /B "" ' . $cmd . ' > NUL 2>&1', 'r');
            if ($handle !== false) { pclose($handle); }
        }
    } else {
        // Unix/Linux
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script)
             . ' --job-id=' . (int)$job_id . ' > /dev/null 2>&1 &';
        exec($cmd);
    }
}
