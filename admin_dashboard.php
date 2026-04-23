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
    header("Location: admin_login.php"); 
    exit(); 
}

// Aggregation Stats (Optimized Single Query)
$stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM users WHERE role='student') as total_students,
        (SELECT COUNT(*) FROM allocations) as total_alloc,
        (SELECT COUNT(*) FROM medical_records WHERE condition_category != 'None') as medical_cases,
        (SELECT COALESCE(SUM(r.capacity), 0)
         FROM rooms r
         JOIN hostels h ON r.hostel_id = h.hostel_id
         WHERE h.is_postgrad = 0 AND h.is_foundation = 0) as total_capacity
";
$stats = $conn->query($stats_query)->fetch_assoc();

$total_students = (int)$stats['total_students'];
$total_alloc    = (int)$stats['total_alloc'];
$medical_cases  = (int)$stats['medical_cases'];
$available_beds = max(0, (int)$stats['total_capacity'] - $total_alloc);

$page_title = "Command Center | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <!-- Page Header -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="serif mb-1" style="font-size: 2rem;">System Overview</h1>
                <p class="text-muted">Real-time usage statistics and management controls.</p>
            </div>
            <div class="flex gap-3">
                <a href="admin_reports.php" class="btn btn-outline">
                    <i class="fa-solid fa-chart-pie"></i> Reports
                </a>
                <a href="run_allocation.php" class="btn btn-primary">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Run Algorithm
                </a>
            </div>
        </div>

        <!-- Stats Row -->
        <div class="grid grid-cols-4 mb-8">
            <div class="card stat-card">
                <div class="stat-icon" style="background:rgba(59,130,246,0.1); color:var(--c-info);"><i class="fa-solid fa-users"></i></div>
                <div class="stat-info">
                    <h3><?php echo $total_students; ?></h3>
                    <p>Total Students</p>
                </div>
            </div>

            <div class="card stat-card">
                 <div class="stat-icon" style="background:rgba(239,68,68,0.1); color:var(--c-danger);"><i class="fa-solid fa-heart-pulse"></i></div>
                <div class="stat-info">
                    <h3><?php echo $medical_cases; ?></h3>
                    <p>High Priority</p>
                </div>
            </div>

            <div class="card stat-card">
                 <div class="stat-icon" style="background:rgba(16,185,129,0.1); color:var(--c-success);"><i class="fa-solid fa-bed"></i></div>
                <div class="stat-info">
                    <h3><?php echo $total_alloc; ?></h3>
                    <p>Allocated Beds</p>
                </div>
            </div>

            <div class="card stat-card">
                 <div class="stat-icon" style="background:rgba(245,158,11,0.1); color:var(--c-warning);"><i class="fa-solid fa-door-open"></i></div>
                <div class="stat-info">
                    <h3><?php echo $available_beds; ?></h3>
                    <p>Available Spaces</p>
                </div>
            </div>
        </div>

        <!-- Management Modules -->
        <h3 class="serif mb-4" style="font-size: 1.2rem;">Management Modules</h3>
        <div class="grid grid-cols-2 mb-8">

            <div class="glass-card flex items-start gap-4">
                <div class="stat-icon" style="background:rgba(59,130,246,0.1); color:var(--c-info);"><i class="fa-solid fa-table-list"></i></div>
                <div>
                    <h4 class="mb-2">Allocation Matrix</h4>
                    <p class="text-muted text-sm mb-4">View comprehensive list of all students and their allocation status, urgency scores, and hostel placements.</p>
                    <a href="view_table.php" class="btn btn-sm btn-primary">Open Matrix <i class="fa-solid fa-arrow-right ml-1"></i></a>
                </div>
            </div>

            <div class="glass-card flex items-start gap-4">
                <div class="stat-icon" style="background: rgba(245,158,11,0.1); color: var(--c-warning);"><i class="fa-solid fa-chart-pie"></i></div>
                <div>
                    <h4 class="mb-2">System Reports</h4>
                    <p class="text-muted text-sm mb-4">Analytics, allocation progress charts, medical condition distribution and payment overviews.</p>
                    <a href="admin_reports.php" class="btn btn-sm btn-secondary">View Analytics <i class="fa-solid fa-arrow-right ml-1"></i></a>
                </div>
            </div>

            <div class="glass-card flex items-start gap-4">
                <div class="stat-icon" style="background: rgba(16,185,129,0.1); color: var(--c-success);"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
                <div>
                    <h4 class="mb-2">Run Allocation</h4>
                    <p class="text-muted text-sm mb-4">Execute the fairness-aware ML allocation engine to assign students to hostels based on medical priority.</p>
                    <a href="run_allocation.php" class="btn btn-sm btn-primary">Start Engine <i class="fa-solid fa-arrow-right ml-1"></i></a>
                </div>
            </div>

            <div class="glass-card flex items-start gap-4">
                <div class="stat-icon" style="background: rgba(0,33,71,0.08); color: var(--c-primary);"><i class="fa-solid fa-gears"></i></div>
                <div>
                    <h4 class="mb-2">System Settings</h4>
                    <p class="text-muted text-sm mb-4">Configure academic session, urgency score thresholds, and allocation status (open/locked).</p>
                    <a href="settings.php" class="btn btn-sm btn-secondary">Manage Settings <i class="fa-solid fa-arrow-right ml-1"></i></a>
                </div>
            </div>
        </div>

        <div class="mt-6 pt-4 text-muted text-sm border-t" style="border-color: var(--c-border);">
            <i class="fa-solid fa-circle-info mr-1"></i>
            System Version 1.0.0 &bull; Licensed to Redeemer's University &bull; Session: <?php
            $sess = $conn->query("SELECT setting_value FROM settings WHERE setting_key='current_session'");
            echo htmlspecialchars($sess ? ($sess->fetch_assoc()['setting_value'] ?? '—') : '—');
            ?>
        </div>
    </main>
</div>
</body>
</html>