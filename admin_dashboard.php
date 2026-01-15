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
$total_students = $conn->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetch_row()[0];
$total_alloc = $conn->query("SELECT COUNT(*) FROM allocations")->fetch_row()[0];
$pending = $total_students - $total_alloc;
$medical_cases = $conn->query("SELECT COUNT(*) FROM medical_records WHERE condition_category != 'None'")->fetch_row()[0];

$page_title = "Command Center | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <h1 class="serif mb-2">System Overview</h1>
        <p class="text-muted mb-8">Real-time usage statistics and management controls.</p>

        <!-- Stats Row -->
        <div class="grid grid-cols-4 mb-8">
            
            <div class="card stat-card">
                <div class="stat-icon" style="background: #eff6ff; color: #3b82f6;"><i class="fa-solid fa-users"></i></div>
                <div class="stat-info">
                    <h3><?php echo $total_students; ?></h3>
                    <p>Total Students</p>
                </div>
            </div>

            <div class="card stat-card">
                 <div class="stat-icon" style="background: #fef2f2; color: #ef4444;"><i class="fa-solid fa-heart-pulse"></i></div>
                <div class="stat-info">
                    <h3><?php echo $medical_cases; ?></h3>
                    <p>High Priority</p>
                </div>
            </div>

            <div class="card stat-card">
                 <div class="stat-icon" style="background: #f0fdf4; color: #22c55e;"><i class="fa-solid fa-bed"></i></div>
                <div class="stat-info">
                    <h3><?php echo $total_alloc; ?></h3>
                    <p>Allocated Beds</p>
                </div>
            </div>

            <div class="card stat-card">
                 <div class="stat-icon" style="background: #fefce8; color: #eab308;"><i class="fa-solid fa-door-open"></i></div>
                <div class="stat-info">
                    <h3><?php echo $pending; ?></h3>
                    <p>Available Spaces</p>
                </div>
            </div>
        </div>

        <!-- Management Modules -->
        <h3 class="serif mb-4">Management Modules</h3>
        <div class="grid grid-cols-2">
            
            <div class="card glass-card flex items-start gap-4">
                 <div class="stat-icon" style="background: #eff6ff; color: #3b82f6;"><i class="fa-solid fa-table-list"></i></div>
                <div>
                    <h4 class="mb-2">Allocation Matrix</h4>
                    <p class="text-muted text-sm mb-4">View comprehensive list of all students and their allocation status.</p>
                    <a href="view_table.php" class="text-primary text-sm fw-700 hover:underline">Open Matrix &rarr;</a>
                </div>
            </div>

            <div class="card glass-card flex items-start gap-4">
                 <div class="stat-icon" style="background: #eff6ff; color: #3b82f6;"><i class="fa-solid fa-gears"></i></div>
                <div>
                    <h4 class="mb-2">System Settings</h4>
                    <p class="text-muted text-sm mb-4">Configure academic session and allocation thresholds.</p>
                    <a href="settings.php" class="text-primary text-sm fw-700 hover:underline">Manage Settings &rarr;</a>
                </div>
            </div>

        </div>

        <div style="position: absolute; top: 3rem; right: 4rem;">
            <a href="run_allocation.php" class="btn btn-primary"><i class="fa-solid fa-wand-magic-sparkles"></i> Run Algorithm</a>
        </div>

        <div class="mt-auto pt-8 text-muted text-sm border-t border-slate-200">
            System Version 1.0.0 • Licensed to Redeemer's University
        </div>
    </main>
</div>
</body>
</html>