<?php
/**
 * Migration Runner for Allocation Jobs Queue
 * 
 * Run once to set up the allocation_jobs table and database event
 * Usage: php sql/run_migrations.php
 */

require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../includes/Logger.php';

$migrations = [
    '20260501_allocation_jobs_queue.sql' => 'Allocation Jobs Queue Table'
];

echo "====== FairMedAlloc Database Migrations ======\n\n";

foreach ($migrations as $file => $description) {
    $path = __DIR__ . DIRECTORY_SEPARATOR . $file;
    
    if (!file_exists($path)) {
        echo "[SKIP] $file — not found\n";
        continue;
    }
    
    echo "[RUN] $file — $description\n";
    
    try {
        $sql = file_get_contents($path);
        
        // Split by semicolon and execute each statement
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => !empty($s) && !str_starts_with($s, '--') && !str_starts_with($s, '/*')
        );
        
        foreach ($statements as $stmt) {
            if (!$conn->query($stmt)) {
                throw new Exception("SQL Error: " . $conn->error);
            }
        }
        
        echo "      ✓ Success\n";
    } catch (Exception $e) {
        echo "      ✗ Error: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

echo "====== Migration Complete ======\n";
