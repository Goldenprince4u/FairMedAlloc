<?php
/**
 * Admin Reports & Analytics
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

$page_title = "Reports & Analytics | FairMedAlloc";

$alloc_res = $conn->query("
    SELECT
        (SELECT COUNT(*) FROM student_profiles) AS total,
        (SELECT COUNT(*) FROM student_profiles p
            WHERE EXISTS (
                SELECT 1 FROM allocations a WHERE a.student_id = p.user_id
            )
        ) AS allocated,
        (SELECT COUNT(*) FROM student_profiles p
            WHERE NOT EXISTS (
                SELECT 1 FROM allocations a WHERE a.student_id = p.user_id
            )
            AND (
                p.is_paid = 1
                OR EXISTS (
                    SELECT 1 FROM payments py
                    WHERE py.student_id = p.user_id
                      AND py.status = 'paid'
                )
            )
        ) AS paid_pending,
        (SELECT COUNT(*) FROM student_profiles p
            WHERE NOT EXISTS (
                SELECT 1 FROM allocations a WHERE a.student_id = p.user_id
            )
            AND p.is_paid = 0
            AND NOT EXISTS (
                SELECT 1 FROM payments py
                WHERE py.student_id = p.user_id
                  AND py.status = 'paid'
            )
        ) AS unpaid_pending
");
$alloc = $alloc_res->fetch_assoc();

$cond_res = $conn->query("
    SELECT condition_category AS label, COUNT(*) AS cnt
    FROM medical_records
    WHERE condition_category != 'None'
    GROUP BY condition_category
    ORDER BY cnt DESC
    LIMIT 10
");
$conditions = $cond_res->fetch_all(MYSQLI_ASSOC);

$occ_res = $conn->query("
    SELECT h.name AS hostel_name,
           SUM(r.capacity) AS total_cap,
           SUM(r.occupied_count) AS occupied
    FROM rooms r
    JOIN hostels h ON r.hostel_id = h.hostel_id
    WHERE h.is_postgrad = 0 AND h.is_foundation = 0
    GROUP BY h.name
    ORDER BY h.name
");
$occupancy = $occ_res->fetch_all(MYSQLI_ASSOC);

$gender_res = $conn->query("
    SELECT gender, COUNT(*) AS cnt
    FROM student_profiles
    GROUP BY gender
");
$genders = $gender_res->fetch_all(MYSQLI_ASSOC);

$sev_res = $conn->query("
    SELECT severity_level AS label, COUNT(*) AS cnt
    FROM medical_records
    GROUP BY severity_level
");
$severities = $sev_res->fetch_all(MYSQLI_ASSOC);

require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div class="page-header-info">
                <h1>Reports &amp; Analytics</h1>
                <p class="text-muted">System-wide allocation statistics and medical data insights.</p>
            </div>
            <a href="admin_dashboard.php" class="btn btn-outline" id="reports-back-btn">
                <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <div class="grid grid-cols-4 mb-8">
            <div class="card stat-card">
                <div class="stat-icon" style="background:rgba(76,124,138,0.12);color:#4c7c8a;">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo (int)$alloc['total']; ?></h3>
                    <p>Total Students</p>
                </div>
            </div>
            <div class="card stat-card">
                <div class="stat-icon" style="background:rgba(103,122,138,0.12);color:#677a8a;">
                    <i class="fa-solid fa-wallet"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo (int)$alloc['unpaid_pending']; ?></h3>
                    <p>Payment Pending</p>
                </div>
            </div>
            <div class="card stat-card">
                <div class="stat-icon" style="background:rgba(201,168,76,0.14);color:#a5822d;">
                    <i class="fa-solid fa-hourglass-half"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo (int)$alloc['paid_pending']; ?></h3>
                    <p>Paid, Awaiting Allocation</p>
                </div>
            </div>
            <div class="card stat-card">
                <div class="stat-icon" style="background:rgba(76,149,108,0.14);color:#4c956c;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo (int)$alloc['allocated']; ?></h3>
                    <p>Allocated</p>
                </div>
            </div>
        </div>

        <div class="grid reports-chart-grid mb-8">
            <div class="card reports-card">
                <h3 class="serif mb-4 reports-title">Allocation Progress</h3>
                <div class="chart-wrap chart-wrap--doughnut">
                    <canvas id="allocChart"></canvas>
                </div>
                <div class="reports-legend">
                    <span><span class="legend-dot" style="background:#10b981;"></span>Allocated</span>
                    <span><span class="legend-dot" style="background:#f59e0b;"></span>Paid, Awaiting Allocation</span>
                    <span><span class="legend-dot" style="background:#94a3b8;"></span>Payment Pending</span>
                </div>
            </div>

            <div class="card reports-card">
                <h3 class="serif mb-4 reports-title">Gender &amp; Medical Severity</h3>
                <div class="chart-wrap chart-wrap--bar">
                    <canvas id="genderChart"></canvas>
                </div>
                <div class="chart-wrap chart-wrap--bar chart-wrap--stacked">
                    <canvas id="severityChart"></canvas>
                </div>
            </div>
        </div>

        <div class="card mb-8 reports-card">
            <h3 class="serif mb-4 reports-title">Medical Condition Distribution</h3>
            <?php if (empty($conditions)): ?>
                <p class="text-muted">No medical condition data yet.</p>
            <?php else: ?>
                <div class="chart-wrap chart-wrap--wide">
                    <canvas id="condChart"></canvas>
                </div>
            <?php endif; ?>
        </div>

        <div class="card mb-8 reports-card">
            <h3 class="serif mb-4 reports-title">Hostel Occupancy Breakdown</h3>
            <div style="overflow-x:auto;">
                <table class="data-table reports-table">
                    <thead>
                        <tr>
                            <th>Hall</th>
                            <th class="text-center">Capacity</th>
                            <th class="text-center">Occupied</th>
                            <th class="text-center">Available</th>
                            <th>Fill Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($occupancy as $row):
                            $cap = (int)$row['total_cap'];
                            $occ = (int)$row['occupied'];
                            $avail = $cap - $occ;
                            $pct = $cap > 0 ? round($occ / $cap * 100) : 0;
                            $bar_color = $pct >= 90 ? '#b85c5c' : ($pct >= 70 ? '#c9a84c' : '#4c956c');
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['hostel_name']); ?></td>
                                <td class="text-center"><?php echo $cap; ?></td>
                                <td class="text-center"><?php echo $occ; ?></td>
                                <td class="text-center"><?php echo $avail; ?></td>
                                <td style="min-width:140px;">
                                    <div style="background:var(--c-border);border-radius:999px;height:8px;">
                                        <div style="width:<?php echo $pct; ?>%;background:<?php echo $bar_color; ?>;border-radius:999px;height:8px;transition:width .4s;"></div>
                                    </div>
                                    <small style="color:var(--c-text-muted);"><?php echo $pct; ?>%</small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    Chart.defaults.color = isDark ? '#9aa8b6' : '#5f6f82';
    Chart.defaults.font.family = 'Arial, Helvetica, sans-serif';

    const palette = {
        allocated: '#10b981',
        paidPending: '#f59e0b',
        unpaidPending: '#94a3b8',
        condition: ['#3b82f6', '#8b5cf6', '#ec4899', '#14b8a6', '#f43f5e', '#f97316', '#06b6d4', '#6366f1', '#10b981', '#a855f7'],
        gender: ['#3b82f6', '#ec4899'],
        severity: ['#10b981', '#f59e0b', '#ef4444']
    };

    new Chart(document.getElementById('allocChart'), {
        type: 'doughnut',
        data: {
            labels: ['Allocated', 'Paid, Awaiting Allocation', 'Payment Pending'],
            datasets: [{
                data: [<?php echo (int)$alloc['allocated']; ?>, <?php echo (int)$alloc['paid_pending']; ?>, <?php echo (int)$alloc['unpaid_pending']; ?>],
                backgroundColor: [palette.allocated, palette.paidPending, palette.unpaidPending],
                borderWidth: 2,
                borderColor: isDark ? '#161b22' : '#ffffff'
            }]
        },
        options: {
            plugins: { legend: { display: false } },
            cutout: '68%',
            maintainAspectRatio: false
        }
    });

    const genderData = <?php echo json_encode($genders); ?>;
    new Chart(document.getElementById('genderChart'), {
        type: 'bar',
        data: {
            labels: genderData.map(d => d.gender),
            datasets: [{
                label: 'Students',
                data: genderData.map(d => parseInt(d.cnt, 10)),
                backgroundColor: palette.gender,
                borderRadius: 6
            }]
        },
        options: {
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            maintainAspectRatio: false,
            scales: {
                x: { grid: { color: isDark ? 'rgba(148,163,184,0.08)' : 'rgba(100,116,139,0.08)' } },
                y: { grid: { display: false } }
            }
        }
    });

    const sevData = <?php echo json_encode($severities); ?>;
    new Chart(document.getElementById('severityChart'), {
        type: 'bar',
        data: {
            labels: sevData.map(d => d.label),
            datasets: [{
                label: 'Cases',
                data: sevData.map(d => parseInt(d.cnt, 10)),
                backgroundColor: palette.severity,
                borderRadius: 6
            }]
        },
        options: {
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            maintainAspectRatio: false,
            scales: {
                x: { grid: { color: isDark ? 'rgba(148,163,184,0.08)' : 'rgba(100,116,139,0.08)' } },
                y: { grid: { display: false } }
            }
        }
    });

    const condCanvas = document.getElementById('condChart');
    if (condCanvas) {
        const condData = <?php echo json_encode($conditions); ?>;
        new Chart(condCanvas, {
            type: 'bar',
            data: {
                labels: condData.map(d => d.label),
                datasets: [{
                    label: 'Students',
                    data: condData.map(d => parseInt(d.cnt, 10)),
                    backgroundColor: condData.map((_, index) => palette.condition[index % palette.condition.length]),
                    borderRadius: 6
                }]
            },
            options: {
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { color: isDark ? 'rgba(148,163,184,0.08)' : 'rgba(100,116,139,0.08)' } },
                    y: { grid: { display: false } }
                }
            }
        });
    }
})();
</script>

</body>
</html>
