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

$high_urgency_threshold = 75;
$medium_urgency_threshold = 40;
// Use prepared statement to safely fetch threshold settings
$threshold_stmt = $conn->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('urgency_threshold_proximal', 'urgency_threshold_medium')");
if ($threshold_stmt) {
    $threshold_stmt->execute();
    $threshold_rows = $threshold_stmt->get_result();
    while ($threshold_row = $threshold_rows->fetch_assoc()) {
        if (($threshold_row['setting_key'] ?? '') === 'urgency_threshold_proximal') {
            $high_urgency_threshold = (int)$threshold_row['setting_value'];
        }
        if (($threshold_row['setting_key'] ?? '') === 'urgency_threshold_medium') {
            $medium_urgency_threshold = (int)$threshold_row['setting_value'];
        }
    }
    $threshold_rows->free();
    $threshold_stmt->close();
}

// Aggregation Stats (Optimized Single Query)
$stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM users WHERE role='student') as total_students,
        (SELECT COUNT(*) FROM allocations) as total_alloc,
        (SELECT COUNT(*) FROM medical_records WHERE COALESCE(NULLIF(condition_category, ''), 'None / Healthy') NOT IN ('None', 'None / Healthy')) as medical_cases,
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

$urgency_query = "
    SELECT
        SUM(CASE WHEN COALESCE(m.urgency_score, 0) >= {$high_urgency_threshold} THEN 1 ELSE 0 END) AS high_count,
        SUM(CASE WHEN COALESCE(m.urgency_score, 0) >= {$medium_urgency_threshold} AND COALESCE(m.urgency_score, 0) < {$high_urgency_threshold} THEN 1 ELSE 0 END) AS medium_count,
        SUM(CASE WHEN COALESCE(m.urgency_score, 0) < {$medium_urgency_threshold} THEN 1 ELSE 0 END) AS low_count
    FROM student_profiles p
    LEFT JOIN medical_records m ON p.user_id = m.student_id
";
$urgency_stats = $conn->query($urgency_query)->fetch_assoc();
$high_urgency_count = (int)($urgency_stats['high_count'] ?? 0);
$medium_urgency_count = (int)($urgency_stats['medium_count'] ?? 0);
$low_urgency_count = (int)($urgency_stats['low_count'] ?? 0);

// Refresh admin display name from DB on every load so nav always shows the latest
$admin_name_q = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
$admin_name_q->bind_param("i", $_SESSION['user_id']);
$admin_name_q->execute();
$admin_name_row = $admin_name_q->get_result()->fetch_assoc();
if (!empty($admin_name_row['full_name'])) {
    $_SESSION['full_name'] = $admin_name_row['full_name'];
}

$page_title = "Command Center | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-info">
                <h1>System Overview</h1>
                <p class="text-muted">Real-time usage statistics and management controls.</p>
            </div>
            <div class="flex gap-3">
                <a href="admin_reports.php" class="btn btn-outline" id="dash-reports-btn">
                    <i class="fa-solid fa-chart-pie"></i> Reports
                </a>
                <a href="run_allocation.php" class="btn btn-primary" id="dash-run-btn">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Run Algorithm
                </a>
            </div>
        </div>

        <?php if (isset($_GET['password_changed']) && $_GET['password_changed'] === '1'): ?>
            <div class="alert alert-success mb-6">
                <i class="fa-solid fa-check-circle"></i>
                Your password has been updated successfully.
            </div>
        <?php endif; ?>

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
                    <p>Medical Cases</p>
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

        <div class="mb-8">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem;">
                <div>
                    <h2 style="font-size:1rem;font-weight:700;color:var(--c-text-head);margin:0 0 0.35rem 0;">Urgency Bands</h2>
                    <p class="text-muted" style="margin:0;font-size:0.875rem;">
                        Score-based XGBoost grouping for students with medical records.
                    </p>
                </div>
                <div class="text-xs text-muted" style="text-align:right;">
                    High: <?php echo $high_urgency_threshold; ?>+<br>
                    Medium: <?php echo $medium_urgency_threshold; ?>-<?php echo max($medium_urgency_threshold, $high_urgency_threshold - 1); ?><br>
                    Low: below <?php echo $medium_urgency_threshold; ?>
                </div>
            </div>

            <div class="grid grid-cols-3">
                <div class="card stat-card">
                    <div class="stat-icon" style="background:rgba(239,68,68,0.1); color:var(--c-danger);"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $high_urgency_count; ?></h3>
                        <p>High</p>
                    </div>
                </div>

                <div class="card stat-card">
                    <div class="stat-icon" style="background:rgba(245,158,11,0.1); color:var(--c-warning);"><i class="fa-solid fa-hourglass-half"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $medium_urgency_count; ?></h3>
                        <p>Medium</p>
                    </div>
                </div>

                <div class="card stat-card">
                    <div class="stat-icon" style="background:rgba(16,185,129,0.1); color:var(--c-success);"><i class="fa-solid fa-circle-check"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $low_urgency_count; ?></h3>
                        <p>Low</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Management Modules -->
        <div style="margin-bottom:0.75rem;">
            <h2 style="font-size:1rem;font-weight:700;color:var(--c-text-head);margin-bottom:1.25rem;padding-bottom:0.75rem;border-bottom:1px solid var(--c-border);">
                Management Modules
            </h2>
        </div>
        <div class="grid grid-cols-2 mb-8 gap-4">

            <div class="card" style="padding:1.75rem;">
                <div>
                    <h4 class="mb-2" style="font-size: 1.35rem; font-weight: 800; color: var(--c-primary);">Allocation Matrix</h4>
                    <p class="text-muted text-sm mb-4">View comprehensive list of all students and their allocation status, urgency scores, and hostel placements.</p>
                    <a href="view_table.php" class="btn btn-sm btn-primary">Open Matrix <i class="fa-solid fa-arrow-right ml-1"></i></a>
                </div>
            </div>

            <div class="card" style="padding:1.75rem;">
                <div>
                    <h4 class="mb-2" style="font-size: 1.35rem; font-weight: 800; color: var(--c-primary);">System Reports</h4>
                    <p class="text-muted text-sm mb-4">Analytics, allocation progress charts, medical condition distribution and payment overviews.</p>
                    <a href="admin_reports.php" class="btn btn-sm btn-secondary">View Analytics <i class="fa-solid fa-arrow-right ml-1"></i></a>
                </div>
            </div>

            <div class="card" style="padding:1.75rem;">
                <div>
                    <h4 class="mb-2" style="font-size: 1.35rem; font-weight: 800; color: var(--c-primary);">Run Allocation</h4>
                    <p class="text-muted text-sm mb-4">Execute the fairness-aware ML allocation engine to assign students to hostels based on medical priority.</p>
                    <a href="run_allocation.php" class="btn btn-sm btn-primary">Start Engine <i class="fa-solid fa-arrow-right ml-1"></i></a>
                </div>
            </div>

            <div class="card" style="padding:1.75rem;">
                <div>
                    <h4 class="mb-2" style="font-size: 1.35rem; font-weight: 800; color: var(--c-primary);">System Settings</h4>
                    <p class="text-muted text-sm mb-4">Configure academic session, the High urgency threshold for clinic proximity, and allocation status (open/locked).</p>
                    <a href="settings.php" class="btn btn-sm btn-secondary">Manage Settings <i class="fa-solid fa-arrow-right ml-1"></i></a>
                </div>
            </div>
        </div>

        <div class="mt-6 pt-4 text-muted" style="font-size:0.8rem;border-top:1px solid var(--c-border);display:flex;align-items:center;gap:0.5rem;">
            <i class="fa-solid fa-circle-info"></i>
            System Version 1.0.0 &bull; Licensed to Redeemer's University &bull; Session: <?php
            $sess = $conn->query("SELECT setting_value FROM settings WHERE setting_key='current_session'");
            echo htmlspecialchars($sess ? ($sess->fetch_assoc()['setting_value'] ?? '—') : '—');
            ?>
        </div>
    </main>
</div>
</body>
</html>
