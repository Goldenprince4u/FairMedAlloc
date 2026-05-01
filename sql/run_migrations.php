<?php
/**
 * run_migrations.php — Database Migration Runner
 * ================================================
 * Tracks and applies pending SQL migration files in chronological order.
 *
 * Usage:
 *   CLI:     php sql/run_migrations.php
 *   Browser: http://localhost/FairMedAlloc/sql/run_migrations.php
 *            (requires an active admin session when accessed via browser)
 *
 * How it works:
 *   1. Creates a `schema_migrations` table if it does not exist.
 *   2. Reads all *.sql files in this directory (sorted by filename).
 *   3. Skips files already recorded in schema_migrations.
 *   4. Executes each pending file and records it on success.
 *   5. Rolls back and reports the error if any statement fails.
 */

// ── Environment detection ────────────────────────────────────────────────────
$is_cli = (PHP_SAPI === 'cli');

if (!$is_cli) {
    // Protect browser access — require an active admin session
    session_start();
    if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Access denied. Admin session required.');
    }
    echo "<pre style='font-family:monospace;line-height:1.6;padding:1rem;'>";
}

// ── Resolve project root ─────────────────────────────────────────────────────
$project_root = dirname(__DIR__);
require_once $project_root . '/db_config.php'; // provides $conn

// ── Migration directory (same folder as this script) ─────────────────────────
$migrations_dir = __DIR__;

// ── Helper ───────────────────────────────────────────────────────────────────
function out(string $msg): void {
    echo $msg . (PHP_SAPI === 'cli' ? PHP_EOL : "\n");
}

function ok(string $msg): void  { out("  ✓ " . $msg); }
function err(string $msg): void { out("  ✗ " . $msg); }
function info(string $msg): void { out("  · " . $msg); }

// ── Step 1: Ensure schema_migrations table exists ───────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS schema_migrations (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        filename     VARCHAR(255) NOT NULL UNIQUE,
        applied_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        checksum     VARCHAR(64) NOT NULL
    )
");

out("=== FairMedAlloc Migration Runner ===");
out("DB: " . ($conn->host_info ?? 'unknown'));
out("");

// ── Step 2: Collect applied migrations ──────────────────────────────────────
$applied = [];
$res = $conn->query("SELECT filename FROM schema_migrations ORDER BY applied_at ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $applied[$row['filename']] = true;
    }
}

// ── Step 3: Collect all SQL files in order ───────────────────────────────────
$files = glob($migrations_dir . '/*.sql');
if (!$files) {
    out("No SQL migration files found in " . $migrations_dir);
    exit(0);
}
sort($files); // Chronological sort by filename (relies on YYYYMMDD_ prefix)

// ── Step 4: Apply pending migrations ─────────────────────────────────────────
$pending = 0;
$success = 0;
$errors  = 0;

foreach ($files as $filepath) {
    $filename = basename($filepath);

    if (isset($applied[$filename])) {
        info("Skipped (already applied): $filename");
        continue;
    }

    $pending++;
    out("");
    out("→ Applying: $filename");

    $sql      = file_get_contents($filepath);
    $checksum = hash('sha256', $sql);

    // Split on statement delimiter and filter empties
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => $s !== '' && !preg_match('/^--/m', ltrim($s))
    );

    $failed = false;
    $conn->begin_transaction();

    try {
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') continue;

            // Skip pure-comment blocks
            $stripped = preg_replace('/--[^\n]*\n?/', '', $stmt);
            if (trim($stripped) === '') continue;

            if (!$conn->query($stmt)) {
                throw new RuntimeException("SQL error: " . $conn->error . "\n  Statement: " . substr($stmt, 0, 120));
            }
        }

        // Record success
        $ins = $conn->prepare("INSERT INTO schema_migrations (filename, checksum) VALUES (?, ?)");
        $ins->bind_param("ss", $filename, $checksum);
        $ins->execute();

        $conn->commit();
        ok("Applied successfully: $filename");
        $success++;

    } catch (Throwable $e) {
        $conn->rollback();
        err("FAILED: " . $e->getMessage());
        $errors++;
        $failed = true;
    }

    if ($failed) {
        out("");
        out("Migration halted at: $filename");
        out("Fix the issue and re-run to continue.");
        break;
    }
}

// ── Step 5: Summary ──────────────────────────────────────────────────────────
out("");
out("=== Summary ===");
out("  Already applied : " . count($applied));
out("  Pending found   : $pending");
out("  Applied now     : $success");
out("  Errors          : $errors");

if ($errors === 0 && $pending === 0) {
    out("");
    out("✓ Database is up to date. No migrations needed.");
} elseif ($errors === 0) {
    out("");
    out("✓ All pending migrations applied successfully.");
} else {
    out("");
    out("✗ One or more migrations failed. See output above.");
}

if (!$is_cli) {
    echo "</pre>";
}
