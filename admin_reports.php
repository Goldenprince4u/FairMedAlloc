<?php
/**
 * Admin Reports & Analytics
 * ==========================
 * Provides analytics charts and summary data for the administrator:
 *  - Overall allocation progress
 *  - Medical condition distribution
 *  - Hostel occupancy breakdown
 *  - Gender distribution
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

$page_title = "Reports & Analytics | FairMedAlloc";

// ── 1. Allocation Progress ────────────────────────────────────────────────
$alloc_res = $conn->query("
    SELECT
        (SELECT COUNT(*) FROM student_profiles) as total,
        (SELECT COUNT(*) FROM student_profiles WHERE allocation_status='Allocated')  as allocated,
        (SELECT COUNT(*) FROM student_profiles WHERE allocation_status='Queued')     as queued,
        (SELECT COUNT(*) FROM student_profiles WHERE allocation_status='Unallocated') as unallocated
");
$alloc = $alloc_res->fetch_assoc();

// ── 2. Medical Condition Distribution ────────────────────────────────────
$cond_res = $conn->query("
    SELECT condition_category as label, COUNT(*) as cnt
    FROM medical_records
    WHERE condition_category != 'None'
    GROUP BY condition_category
    ORDER BY cnt DESC
    LIMIT 10
");
$conditions = $cond_res->fetch_all(MYSQLI_ASSOC);

// ── 3. Hostel Occupancy ───────────────────────────────────────────────────
$occ_res = $conn->query("
    SELECT h.name as hostel_name,
           SUM(r.capacity)       as total_cap,
           SUM(r.occupied_count) as occupied
    FROM rooms r
    JOIN hostels h ON r.hostel_id = h.hostel_id
    WHERE h.is_postgrad = 0 AND h.is_foundation = 0
    GROUP BY h.name
    ORDER BY h.name
");
$occupancy = $occ_res->fetch_all(MYSQLI_ASSOC);

// ── 4. Gender split ───────────────────────────────────────────────────────
$gender_res = $conn->query("
    SELECT gender, COUNT(*) as cnt
    FROM student_profiles
    GROUP BY gender
");
$genders = $gender_res->fetch_all(MYSQLI_ASSOC);

// ── 5. Severity Distribution ─────────────────────────────────────────────
$sev_res = $conn->query("
    SELECT severity_level as label, COUNT(*) as cnt
    FROM medical_records
    GROUP BY severity_level
");
$severities = $sev_res->fetch_all(MYSQLI_ASSOC);

require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-info">
                <h1>Reports &amp; Analytics</h1>
                <p class="text-muted">System-wide allocation statistics and medical data insights.</p>
            </div>
            <a href="admin_dashboard.php" class="btn btn-outline" id="reports-back-btn">
                <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <!-- ── Top Summary Cards ─────────────────────────────────────────── -->
        <div class="grid grid-cols-4 mb-8">
            <div class="card stat-card">
                <div class="stat-icon" style="background:rgba(59,130,246,0.1);color:var(--c-info);">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo (int)$alloc['total']; ?></h3>
                    <p>Total Students</p>
                </div>
            </div>
            <div class="card stat-card">
                <div class="stat-icon" style="background:rgba(239,68,68,0.1);color:var(--c-danger);">
                    <i class="fa-solid fa-circle-xmark"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo (int)$alloc['unallocated']; ?></h3>
                    <p>Unallocated</p>
                </div>
            </div>
            <div class="card stat-card">
                <div class="stat-icon" style="background:rgba(245,158,11,0.1);color:var(--c-warning);">
                    <i class="fa-solid fa-hourglass-half"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo (int)$alloc['queued']; ?></h3>
                    <p>Queued</p>
                </div>
            </div>
            <div class="card stat-card">
                <div class="stat-icon" style="background:rgba(16,185,129,0.1);color:var(--c-success);">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo (int)$alloc['allocated']; ?></h3>
                    <p>Allocated</p>
                </div>
            </div>
        </div>

        <!-- ── Charts Row 1 ───────────────────────────────────────────────── -->
        <div class="grid grid-cols-2 mb-8">

            <!-- Allocation Progress Doughnut -->
            <div class="card" style="padding:1.75rem;">
                <h3 class="serif mb-4" style="font-size:1.1rem;">Allocation Progress</h3>
                <div style="max-width:260px;margin:0 auto;">
                    <canvas id="allocChart"></canvas>
                </div>
                <div class="flex gap-4 mt-4 justify-center" style="font-size:.85rem;">
                    <span><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#10b981;margin-right:4px;"></span>Allocated</span>
                    <span><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#f59e0b;margin-right:4px;"></span>Queued</span>
                    <span><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#ef4444;margin-right:4px;"></span>Unallocated</span>
                </div>
            </div>

            <!-- Gender & Severity Side Charts -->
            <div class="card" style="padding:1.75rem;">
                <h3 class="serif mb-4" style="font-size:1.1rem;">Gender &amp; Medical Severity</h3>
                <canvas id="genderChart" style="max-height:140px;margin-bottom:1.5rem;"></canvas>
                <canvas id="severityChart" style="max-height:140px;"></canvas>
            </div>
        </div>

        <!-- ── Medical Condition Distribution ─────────────────────────────── -->
        <div class="card mb-8" style="padding:1.75rem;">
            <h3 class="serif mb-4" style="font-size:1.1rem;">Medical Condition Distribution</h3>
            <?php if (empty($conditions)): ?>
                <p class="text-muted">No medical condition data yet.</p>
            <?php else: ?>
                <canvas id="condChart" style="max-height:220px;"></canvas>
            <?php endif; ?>
        </div>

        <!-- ── Hostel Occupancy Table ─────────────────────────────────────── -->
        <div class="card mb-8" style="padding:1.75rem;">
            <h3 class="serif mb-4" style="font-size:1.1rem;">Hostel Occupancy Breakdown</h3>
            <div style="overflow-x:auto;">
                <table class="data-table" style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="text-align:left;padding:.6rem .8rem;">Hall</th>
                            <th style="text-align:center;padding:.6rem .8rem;">Capacity</th>
                            <th style="text-align:center;padding:.6rem .8rem;">Occupied</th>
                            <th style="text-align:center;padding:.6rem .8rem;">Available</th>
                            <th style="padding:.6rem .8rem;">Fill Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($occupancy as $row):
                            $cap  = (int)$row['total_cap'];
                            $occ  = (int)$row['occupied'];
                            $avail = $cap - $occ;
                            $pct  = $cap > 0 ? round($occ / $cap * 100) : 0;
                            $bar_color = $pct >= 90 ? '#ef4444' : ($pct >= 70 ? '#f59e0b' : '#10b981');
                        ?>
                        <tr>
                            <td style="padding:.6rem .8rem;"><?php echo htmlspecialchars($row['hostel_name']); ?></td>
                            <td style="text-align:center;padding:.6rem .8rem;"><?php echo $cap; ?></td>
                            <td style="text-align:center;padding:.6rem .8rem;"><?php echo $occ; ?></td>
                            <td style="text-align:center;padding:.6rem .8rem;"><?php echo $avail; ?></td>
                            <td style="padding:.6rem .8rem;min-width:120px;">
                                <div style="background:var(--c-border);border-radius:4px;height:8px;">
                                    <div style="width:<?php echo $pct; ?>%;background:<?php echo $bar_color; ?>;border-radius:4px;height:8px;transition:width .4s;"></div>
                                </div>
                                <small style="color:var(--c-muted);"><?php echo $pct; ?>%</small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    Chart.defaults.color = isDark ? '#94a3b8' : '#64748b';

    // ── Allocation Doughnut ───────────────────────────────────────────────
    new Chart(document.getElementById('allocChart'), {
        type: 'doughnut',
        data: {
            labels: ['Allocated', 'Queued', 'Unallocated'],
            datasets: [{
                data: [<?php echo (int)$alloc['allocated']; ?>, <?php echo (int)$alloc['queued']; ?>, <?php echo (int)$alloc['unallocated']; ?>],
                backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                borderWidth: 2,
                borderColor: isDark ? '#1e293b' : '#ffffff'
            }]
        },
        options: { plugins: { legend: { display: false } }, cutout: '70%' }
    });

    // ── Gender Bar ───────────────────────────────────────────────────────
    const genderData = <?php echo json_encode($genders); ?>;
    new Chart(document.getElementById('genderChart'), {
        type: 'bar',
        data: {
            labels: genderData.map(d => d.gender),
            datasets: [{ label: 'Students', data: genderData.map(d => parseInt(d.cnt)), backgroundColor: ['#3b82f6','#ec4899'], borderRadius: 4 }]
        },
        options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { grid: { display: false } } } }
    });

    // ── Severity Bar ─────────────────────────────────────────────────────
    const sevData = <?php echo json_encode($severities); ?>;
    new Chart(document.getElementById('severityChart'), {
        type: 'bar',
        data: {
            labels: sevData.map(d => d.label),
            datasets: [{ label: 'Cases', data: sevData.map(d => parseInt(d.cnt)), backgroundColor: ['#10b981','#f59e0b','#ef4444'], borderRadius: 4 }]
        },
        options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { grid: { display: false } } } }
    });

    // ── Medical Conditions Horizontal Bar ────────────────────────────────
    const condCanvas = document.getElementById('condChart');
    if (condCanvas) {
        const condData = <?php echo json_encode($conditions); ?>;
        new Chart(condCanvas, {
            type: 'bar',
            data: {
                labels: condData.map(d => d.label),
                datasets: [{ label: 'Students', data: condData.map(d => parseInt(d.cnt)), backgroundColor: '#6366f1', borderRadius: 4 }]
            },
            options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { grid: { display: false } } } }
        });
    }
})();
</script>

</body>
</html>
