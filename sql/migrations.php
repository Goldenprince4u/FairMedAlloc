<?php
/**
 * Migration Runner
 * ================
 * Automated database migration system that tracks which migrations have been
 * applied and executes pending ones in the correct order.
 *
 * This script should be run once during deployment or when adding new migrations.
 * It maintains a `migrations` table to prevent re-running migrations.
 *
 * Usage:
 *   php sql/migrations.php             # Run all pending migrations
 *   php sql/migrations.php --reset     # Reset (use with caution!)
 *
 * @package Database
 * @subpackage Migrations
 * @author FairMedAlloc Team
 * @version 1.0.0
 */

// Get database connection
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../includes/Logger.php';

$reset_flag = isset($argv[1]) && $argv[1] === '--reset';

// === Step 1: Create migrations tracking table if it doesn't exist ===
$migration_table_sql = "
CREATE TABLE IF NOT EXISTS migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) UNIQUE NOT NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
";
if (!$conn->query($migration_table_sql)) {
    Logger::error("Failed to create migrations table", new Exception($conn->error));
    exit(1);
}

// === Step 2: Get list of all migration files (in dependency order) ===
$migration_files = [
    'schema.sql',                                          // Base schema
    '20260425_add_algorithm_version.sql',                 // Add algorithm_version column
    '20260430_accessible_ground_floor_policy.sql',       // Settings and floor metadata
    'migrate_qe_extension_blocks.sql',                    // QE Hall extension blocks 33-37
    'migrate_qe_hall_blocks_33_37_fix.sql',              // Correct QE Hall room configs
    'migrate_qe_cleanup.sql',                             // Remove QE Hall Block 1 ghost rooms
    'migrate_pm_hall_block1_cleanup.sql',                 // Remove PM Hall Block 1 ghost rooms
    'add_is_test_column.sql',                             // Add is_test flag for test data
    'add_database_indexes.sql',                           // Performance indexes
];

// === Step 3: Handle reset flag (careful!) ===
if ($reset_flag) {
    echo "[!] RESET FLAG DETECTED - This will truncate the migrations table.\n";
    echo "    All migrations will be marked as unapplied.\n";
    echo "    Press Enter to continue or Ctrl+C to cancel...\n";
    fgets(STDIN);
    
    if (!$conn->query("TRUNCATE TABLE migrations")) {
        Logger::error("Failed to truncate migrations table", new Exception($conn->error));
        exit(1);
    }
    echo "[✓] Migrations table reset.\n";
}

// === Step 4: Find and execute pending migrations ===
$applied_count = 0;
$failed_migrations = [];

foreach ($migration_files as $filename) {
    $filepath = __DIR__ . '/' . $filename;
    
    // Skip if file doesn't exist
    if (!file_exists($filepath)) {
        Logger::warning("Migration file not found: {$filename}");
        continue;
    }
    
    // Check if already applied
    $check_stmt = $conn->prepare("SELECT id FROM migrations WHERE filename = ?");
    $check_stmt->bind_param("s", $filename);
    $check_stmt->execute();
    $already_applied = $check_stmt->get_result()->num_rows > 0;
    $check_stmt->close();
    
    if ($already_applied) {
        echo "[✓] Already applied: {$filename}\n";
        continue;
    }
    
    // Read and execute the migration
    echo "[→] Applying migration: {$filename}...\n";
    
    $sql = file_get_contents($filepath);
    
    try {
        // For files with multiple statements, split and execute separately
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => !empty($s) && !str_starts_with($s, '--')
        );
        
        $conn->begin_transaction();
        foreach ($statements as $statement) {
            if (!$conn->query($statement)) {
                throw new Exception("Query failed: " . $conn->error);
            }
        }
        
        // Record the migration as applied
        $record_stmt = $conn->prepare("INSERT INTO migrations (filename) VALUES (?)");
        $record_stmt->bind_param("s", $filename);
        if (!$record_stmt->execute()) {
            throw new Exception("Failed to record migration: " . $record_stmt->error);
        }
        $record_stmt->close();
        
        $conn->commit();
        
        echo "[✓] Applied successfully: {$filename}\n";
        $applied_count++;
        Logger::info("Migration applied: {$filename}");
        
    } catch (Exception $e) {
        $conn->rollback();
        echo "[✗] Failed: {$filename}\n";
        echo "    Error: " . $e->getMessage() . "\n";
        $failed_migrations[] = $filename;
        Logger::error("Migration failed: {$filename}", $e);
    }
}

// === Step 5: Summary ===
echo "\n" . str_repeat("=", 60) . "\n";
echo "Migration Summary:\n";
echo "  Applied:   $applied_count\n";
echo "  Failed:    " . count($failed_migrations) . "\n";

if (!empty($failed_migrations)) {
    echo "\nFailed migrations:\n";
    foreach ($failed_migrations as $file) {
        echo "  - $file\n";
    }
    echo "\nCheck PHP error log for details: " . ini_get('error_log') . "\n";
}

echo str_repeat("=", 60) . "\n";

exit(empty($failed_migrations) ? 0 : 1);
?>
