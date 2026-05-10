#!/usr/bin/env php
<?php
/**
 * Allocation Worker Auto-Launcher
 * ================================
 * Continuous background process that polls the database for queued allocation
 * jobs and spawns worker_allocation.php to process them.
 *
 * Production-hardened: instead of exiting immediately on DB failure (which
 * causes a supervisord crash-loop during container cold-start), this launcher
 * retries the connection with exponential back-off for up to DB_CONNECT_TIMEOUT
 * seconds before giving up.
 *
 * Usage (CLI / supervisord):
 *   php worker_launcher.php
 *
 * Environment variables:
 *   FAIRMED_WORKER_INTERVAL  : poll interval in seconds (default: 2)
 *   FAIRMED_WORKER_MAX_JOBS  : max jobs per cycle (default: 1)
 *   DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT — standard DB credentials
 *   DB_CONNECT_TIMEOUT       : seconds to wait for DB on startup (default: 120)
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from CLI only.\n");
}

// ── Load env helpers without triggering the db connection ─────────────────────
require_once __DIR__ . '/includes/Logger.php';

// Pull env loader from db_config without executing the connection block.
// We do this by reading the .env file directly so the connection attempt
// is fully under our control (with retry logic).
function launcher_load_env(string $path): array {
    if (!file_exists($path) || !is_readable($path)) {
        return [];
    }
    $parsed = @parse_ini_file($path, false, INI_SCANNER_RAW);
    if (is_array($parsed)) {
        return $parsed;
    }
    $values = [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';') continue;
        $parts = explode('=', $trimmed, 2);
        if (count($parts) !== 2) continue;
        $key   = trim($parts[0]);
        $value = trim(trim($parts[1]), "\"'");
        if ($key !== '') $values[$key] = $value;
    }
    return $values;
}

$env = launcher_load_env(__DIR__ . '/.env');

$dbHost    = getenv('DB_HOST')    ?: ($env['DB_HOST']    ?? '127.0.0.1');
$dbPort    = (int)(getenv('DB_PORT')    ?: ($env['DB_PORT']    ?? 3306));
$dbUser    = getenv('DB_USER')    ?: ($env['DB_USER']    ?? 'root');
$dbPass    = getenv('DB_PASS')    ?: ($env['DB_PASS']    ?? '');
$dbName    = getenv('DB_NAME')    ?: ($env['DB_NAME']    ?? 'fairmedalloc');

const DEFAULT_INTERVAL      = 2;    // seconds between poll cycles
const DEFAULT_MAX_JOBS      = 1;    // one job at a time
const DB_CONNECT_TIMEOUT    = 120;  // max seconds to wait for DB on cold-start
const DB_RETRY_MAX_DELAY    = 16;   // cap on exponential back-off (seconds)

$interval = (int)(getenv('FAIRMED_WORKER_INTERVAL') ?: DEFAULT_INTERVAL);
$max_jobs  = (int)(getenv('FAIRMED_WORKER_MAX_JOBS')  ?: DEFAULT_MAX_JOBS);

// ── DB connection with retry loop ─────────────────────────────────────────────
Logger::info("Worker Launcher starting — waiting for database at {$dbHost}:{$dbPort}…");

$conn           = null;
$retryDelay     = 2;
$retryStart     = time();

mysqli_report(MYSQLI_REPORT_OFF);

while (true) {
    try {
        $attempt = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
        if ($attempt->connect_errno === 0) {
            $conn = $attempt;
            Logger::info("Worker Launcher connected to database successfully.");
            break;
        }
        $err = $attempt->connect_error;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }

    $elapsed = time() - $retryStart;
    if ($elapsed >= DB_CONNECT_TIMEOUT) {
        fwrite(STDERR,
            "[FairMedAlloc] FATAL: Worker Launcher could not connect to the database after " .
            DB_CONNECT_TIMEOUT . "s. Last error: {$err}\n" .
            "Check DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT environment variables.\n"
        );
        exit(1);
    }

    Logger::warning("DB not yet reachable ({$err}) — retrying in {$retryDelay}s (elapsed: {$elapsed}s)…");
    sleep($retryDelay);
    // Exponential back-off: 2 → 4 → 8 → 16 → 16 → …
    $retryDelay = min($retryDelay * 2, DB_RETRY_MAX_DELAY);
}

// ── Main polling loop ─────────────────────────────────────────────────────────
Logger::info("Worker Launcher polling (interval: {$interval}s, max_jobs: {$max_jobs})");

$cycles = 0;

while (true) {
    $cycles++;

    try {
        // Reconnect if the connection has gone away (long-running process)
        if (!$conn->ping()) {
            Logger::warning("DB connection lost — reconnecting…");
            $conn->close();
            $conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
            if ($conn->connect_errno) {
                Logger::error("Reconnect failed: " . $conn->connect_error);
                sleep($interval * 4);
                continue;
            }
        }

        $result = $conn->query(
            "SELECT job_id FROM allocation_jobs
              WHERE status = 'queued'
              ORDER BY created_at ASC
              LIMIT " . (int)$max_jobs
        );

        $jobs = [];
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $jobs[] = (int)$row['job_id'];
            }
            $result->free();
        }

        foreach ($jobs as $job_id) {
            spawnWorker($job_id);
        }

        // Log heartbeat every 30 cycles
        if ($cycles % 30 === 0) {
            Logger::info("Launcher alive (cycle $cycles)");
        }

        sleep($interval);

    } catch (Throwable $e) {
        Logger::error("Launcher poll error: " . $e->getMessage());
        sleep($interval * 2);
    }
}

// ── Worker spawn helper ───────────────────────────────────────────────────────
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
        // Windows: detached via cmd start
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --job-id=' . $job_id;
        $descriptors = [[0 => 'pipe', 'r'], [1 => 'pipe', 'w'], [2 => 'pipe', 'w']];
        $proc = @proc_open('cmd /c start /B "" ' . $cmd, $descriptors, $pipes);
        if (is_resource($proc)) {
            foreach ($pipes as $pipe) { fclose($pipe); }
            proc_close($proc);
        } else {
            $handle = popen('cmd /c start /B "" ' . $cmd . ' > NUL 2>&1', 'r');
            if ($handle !== false) { pclose($handle); }
        }
    } else {
        // Unix/Linux (production)
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script)
             . ' --job-id=' . $job_id . ' > /dev/null 2>&1 &';
        exec($cmd);
    }
}
