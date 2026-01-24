<?php
/**
 * Admin Dashboard
 * Command center for allocation data.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

// Auth Guard
if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') { 
    header("Location: login.php"); 
    exit(); 
}

// Aggregation Stats
// Aggregation Stats (Optimized Single Query)
$stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM users WHERE role='student') as total_students,
        (SELECT COUNT(*) FROM allocations) as total_alloc,
        (SELECT COUNT(*) FROM medical_records WHERE condition_category != 'None') as medical_cases,
        (SELECT COALESCE(SUM(capacity), 0) FROM rooms) as total_capacity
";
$stats = $conn->query($stats_query)->fetch_assoc();

$total_students = $stats['total_students'];
$total_alloc    = $stats['total_alloc'];
$medical_cases  = $stats['medical_cases'];
$available_beds = $stats['total_capacity'] - $total_alloc;

$page_title = "Command Center | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="serif mb-1">System Overview</h1>
                <p class="text-muted">Real-time usage statistics and management controls.</p>
            </div>
            <a href="run_allocation.php" class="btn btn-primary shadow-lg">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Run Algorithm
            </a>
        </div>

        <!-- Stats Row -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon text-info"><i class="fa-solid fa-users"></i></div>
                <div class="stat-info">
                    <h3><?php echo $total_students; ?></h3>
                    <p>Total Students</p>
                </div>
            </div>

            <div class="stat-card">
                 <div class="stat-icon text-danger"><i class="fa-solid fa-heart-pulse"></i></div>
                <div class="stat-info">
                    <h3><?php echo $medical_cases; ?></h3>
                    <p>High Priority</p>
                </div>
            </div>

            <div class="stat-card">
                 <div class="stat-icon text-success"><i class="fa-solid fa-bed"></i></div>
                <div class="stat-info">
                    <h3><?php echo $total_alloc; ?></h3>
                    <p>Allocated Beds</p>
                </div>
            </div>

            <div class="stat-card">
                 <div class="stat-icon text-warning"><i class="fa-solid fa-door-open"></i></div>
                <div class="stat-info">
                    <h3><?php echo $available_beds; ?></h3>
                    <p>Available Spaces</p>
                </div>
            </div>
        </div>

        <!-- Management Modules -->
        <h3 class="serif mb-4">Management Modules</h3>
        <div class="grid grid-cols-2 gap-6">
            
            <a href="view_table.php" class="card p-6 flex items-start gap-4 hover:bg-slate-50 transition-colors group">
                 <div class="stat-icon text-info group-hover:bg-blue-100 transition-colors"><i class="fa-solid fa-table-list"></i></div>
                <div>
                    <h4 class="mb-2 text-primary group-hover:underline">Allocation Matrix</h4>
                    <p class="text-muted text-sm">View comprehensive list of all students and their allocation status.</p>
                </div>
            </a>

            <a href="settings.php" class="card p-6 flex items-start gap-4 hover:bg-slate-50 transition-colors group">
                 <div class="stat-icon text-info group-hover:bg-blue-100 transition-colors"><i class="fa-solid fa-gears"></i></div>
                <div>
                    <h4 class="mb-2 text-primary group-hover:underline">System Settings</h4>
                    <p class="text-muted text-sm">Configure academic session and allocation thresholds.</p>
                </div>
            </a>

        </div>

        <div class="mt-auto pt-8 text-muted text-sm border-t border-slate-200">
            System Version 1.0.0 • Licensed to Redeemer's University
        </div>
    </main>
</div>
</body>
</html>