<?php
/**
 * Admin Reports & Analytics
 * Redesigned with richer data: urgency bands, mobility, faculty breakdown,
 * hostel occupancy by block, and allocation progress.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

$page_title = "Reports & Analytics | FairMedAlloc";

// ── Allocation progress ──────────────────────────────────────────────────────
$alloc = $conn->query("
    SELECT
        (SELECT COUNT(*) FROM student_profiles) AS total,
        (SELECT COUNT(*) FROM student_profiles p
            WHERE EXISTS (SELECT 1 FROM allocations a WHERE a.student_id = p.user_id)
        ) AS allocated,
        (SELECT COUNT(*) FROM student_profiles p
            WHERE NOT EXISTS (SELECT 1 FROM allocations a WHERE a.student_id = p.user_id)
              AND (p.is_paid = 1 OR EXISTS (
                    SELECT 1 FROM payments py WHERE py.student_id = p.user_id AND py.status = 'paid'))
        ) AS paid_pending,
        (SELECT COUNT(*) FROM student_profiles p
            WHERE NOT EXISTS (SELECT 1 FROM allocations a WHERE a.student_id = p.user_id)
              AND p.is_paid = 0
              AND NOT EXISTS (SELECT 1 FROM payments py WHERE py.student_id = p.user_id AND py.status = 'paid')
        ) AS unpaid_pending
")->fetch_assoc();

// ── Urgency band counts ──────────────────────────────────────────────────────
// Use prepared statements to safely fetch thresholds
$prox_stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'urgency_threshold_proximal' LIMIT 1");
$prox_stmt->execute();
$prox_t = (int)(($prox_stmt->get_result()->fetch_assoc()['setting_value'] ?? 75));
$prox_stmt->close();

$medium_stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'urgency_threshold_medium' LIMIT 1");
$medium_stmt->execute();
$medium_t = (int)(($medium_stmt->get_result()->fetch_assoc()['setting_value'] ?? 40));
$medium_stmt->close();

// Use parameterized query with placeholders instead of string interpolation
$urgency = $conn->query("
    SELECT
        SUM(CASE WHEN COALESCE(m.urgency_score,0) >= {$prox_t}   THEN 1 ELSE 0 END) AS high_count,
        SUM(CASE WHEN COALESCE(m.urgency_score,0) >= {$medium_t}
                  AND COALESCE(m.urgency_score,0) <  {$prox_t}   THEN 1 ELSE 0 END) AS medium_count,
        SUM(CASE WHEN COALESCE(m.urgency_score,0) <  {$medium_t} THEN 1 ELSE 0 END) AS low_count
    FROM student_profiles p
    LEFT JOIN medical_records m ON p.user_id = m.student_id
")->fetch_assoc();

// ── Medical condition distribution ──────────────────────────────────────────
$conditions = $conn->query("
    SELECT condition_category AS label, COUNT(*) AS cnt
    FROM medical_records
    WHERE COALESCE(NULLIF(condition_category, ''), 'None / Healthy') NOT IN ('None','None / Healthy')
    GROUP BY condition_category
    ORDER BY cnt DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// ── Mobility status breakdown ────────────────────────────────────────────────
$mobility_map = [
    '0' => 'Normal Mobility',
    '1' => 'Artificial Limb',
    '2' => 'Crutches/Walker',
    '3' => 'Wheelchair User',
    'Normal Mobility' => 'Normal Mobility',
    'Artificial Limb' => 'Artificial Limb',
    'Crutches/Walker' => 'Crutches/Walker',
    'Crutches / Walker' => 'Crutches/Walker',
    'Wheelchair User' => 'Wheelchair User',
];
$mob_raw = $conn->query("
    SELECT mobility_status, COUNT(*) AS cnt
    FROM medical_records
    GROUP BY mobility_status
    ORDER BY FIELD(mobility_status, 'Wheelchair User', 'Crutches/Walker', 'Artificial Limb', 'Normal Mobility', '3', '2', '1', '0') ASC
")->fetch_all(MYSQLI_ASSOC);
$mob_data = array_map(fn($r) => [
    'label' => $mobility_map[$r['mobility_status']] ?? ('Code '.$r['mobility_status']),
    'cnt'   => (int)$r['cnt']
], $mob_raw);

// ── Severity distribution ────────────────────────────────────────────────────
$severities = $conn->query("
    SELECT severity_level AS label, COUNT(*) AS cnt
    FROM medical_records
    GROUP BY severity_level
    ORDER BY FIELD(severity_level,'High','Medium','Low')
")->fetch_all(MYSQLI_ASSOC);

// ── Gender split ─────────────────────────────────────────────────────────────
$genders = $conn->query("
    SELECT gender, COUNT(*) AS cnt FROM student_profiles GROUP BY gender
")->fetch_all(MYSQLI_ASSOC);

// ── Faculty medical breakdown (top 8) ────────────────────────────────────────
$faculty_med = $conn->query("
    SELECT f.name AS faculty, COUNT(m.student_id) AS med_count
    FROM faculties f
    JOIN departments d  ON d.faculty_id  = f.faculty_id
    JOIN student_profiles p ON p.department_id = d.department_id
    LEFT JOIN medical_records m ON m.student_id = p.user_id
    GROUP BY f.faculty_id
    ORDER BY med_count DESC
    LIMIT 8
")->fetch_all(MYSQLI_ASSOC);

// ── Hostel occupancy by block ────────────────────────────────────────────────
$occupancy = $conn->query("
    SELECT h.name AS hostel_name,
           SUM(r.capacity) AS total_cap,
           SUM(r.occupied_count) AS occupied
    FROM rooms r
    JOIN hostels h ON r.hostel_id = h.hostel_id
    WHERE h.is_postgrad = 0 AND h.is_foundation = 0
    GROUP BY h.name
    ORDER BY h.name
")->fetch_all(MYSQLI_ASSOC);

require_once 'includes/header.php';
?>

<style>
/* ── Custom Reports CSS to supplement main.css ── */
.reports-chart-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 1.5rem;
}
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
}
.urgency-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1.5rem;
}
.faculty-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1rem;
}
.reports-card {
    display: flex;
    flex-direction: column;
}
.reports-title {
    font-size: 1.15rem;
    color: var(--c-text-head);
    font-weight: 800;
    margin-bottom: 1.25rem;
}
.chart-wrap {
    position: relative;
    width: 100%;
    height: 260px;
}
.chart-wrap--doughnut {
    height: 260px;
    display: flex;
    justify-content: center;
    align-items: center;
}
.chart-wrap--bar {
    height: 180px;
}
.chart-wrap--wide {
    height: 300px;
}
.reports-legend {
    display: flex;
    justify-content: center;
    gap: 1.5rem;
    margin-top: 1.5rem;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--c-text-body);
}
.reports-legend span {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
}
.reports-table th {
    background: var(--c-bg-surface-2);
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.05em;
    color: var(--c-text-muted);
}
.reports-table td {
    padding: 1rem;
    border-bottom: 1px solid var(--c-border-divider);
}
@media (max-width: 1024px) {
    .reports-chart-grid { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
    .kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 480px) {
    .kpi-grid, .faculty-grid, .urgency-grid { grid-template-columns: 1fr; }
}
</style>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-info">
                <h1>Reports &amp; Analytics</h1>
                <p class="text-muted">System-wide allocation statistics, medical insights, and hostel occupancy.</p>
            </div>
            <a href="admin_dashboard.php" class="btn btn-outline" id="reports-back-btn">
                <i class="fa-solid fa-arrow-left"></i> Dashboard
            </a>
        </div>

        <!-- ── KPI Row ──────────────────────────────────────────────────── -->
        <div class="kpi-grid mb-8">
            <div class="card stat-card">
                <div class="stat-icon" style="background:rgba(59,130,246,0.1);color:var(--c-info);"><i class="fa-solid fa-users"></i></div>
                <div class="stat-info"><h3><?php echo (int)$alloc['total']; ?></h3><p>Total Students</p></div>
            </div>
            <div class="card stat-card">
                <div class="stat-icon" style="background:rgba(16,185,129,0.1);color:var(--c-success);"><i class="fa-solid fa-circle-check"></i></div>
                <div class="stat-info"><h3><?php echo (int)$alloc['allocated']; ?></h3><p>Allocated</p></div>
            </div>
            <div class="card stat-card">
                <div class="stat-icon" style="background:rgba(245,158,11,0.1);color:var(--c-warning);"><i class="fa-solid fa-hourglass-half"></i></div>
                <div class="stat-info"><h3><?php echo (int)$alloc['paid_pending']; ?></h3><p>Paid, Awaiting Batch</p></div>
            </div>
            <div class="card stat-card">
                <div class="stat-icon" style="background:rgba(239,68,68,0.1);color:var(--c-danger);"><i class="fa-solid fa-wallet"></i></div>
                <div class="stat-info"><h3><?php echo (int)$alloc['unpaid_pending']; ?></h3><p>Payment Pending</p></div>
            </div>
        </div>

        <!-- ── Urgency Band Summary ──────────────────────────────────────── -->
        <div class="mb-8">
            <h2 style="font-size:1rem;font-weight:700;margin-bottom:1rem;color:var(--c-text-head);">Urgency Band Distribution</h2>
            <div class="urgency-grid">
                <div class="card stat-card">
                    <div class="stat-icon" style="background:rgba(239,68,68,0.1);color:var(--c-danger);"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <div class="stat-info"><h3><?php echo (int)$urgency['high_count']; ?></h3><p>High (&ge;<?php echo $prox_t; ?>)</p></div>
                </div>
                <div class="card stat-card">
                    <div class="stat-icon" style="background:rgba(245,158,11,0.1);color:var(--c-warning);"><i class="fa-solid fa-hourglass-half"></i></div>
                    <div class="stat-info"><h3><?php echo (int)$urgency['medium_count']; ?></h3><p>Medium (<?php echo $medium_t; ?>–<?php echo $prox_t-1; ?>)</p></div>
                </div>
                <div class="card stat-card">
                    <div class="stat-icon" style="background:rgba(16,185,129,0.1);color:var(--c-success);"><i class="fa-solid fa-circle-check"></i></div>
                    <div class="stat-info"><h3><?php echo (int)$urgency['low_count']; ?></h3><p>Low (&lt;<?php echo $medium_t; ?>)</p></div>
                </div>
            </div>
        </div>

        <!-- ── Chart Row 1: Allocation + Gender ─────────────────────────── -->
        <div class="reports-chart-grid mb-8">
            <div class="card reports-card p-6">
                <h3 class="serif reports-title">Allocation Progress</h3>
                <div class="chart-wrap chart-wrap--doughnut">
                    <canvas id="allocChart"></canvas>
                </div>
                <div class="reports-legend" style="flex-wrap: wrap;">
                    <span><span class="legend-dot" style="background:var(--c-success);"></span>Allocated</span>
                    <span><span class="legend-dot" style="background:var(--c-warning);"></span>Paid, Awaiting</span>
                    <span><span class="legend-dot" style="background:var(--c-text-muted);"></span>Payment Pending</span>
                </div>
            </div>

            <div class="card reports-card p-6" style="justify-content: space-between;">
                <h3 class="serif reports-title mb-4">Demographics &amp; Severity</h3>
                
                <?php
                // Process Gender Data
                $m_cnt = 0; $f_cnt = 0;
                foreach($genders as $g) {
                    if($g['gender'] === 'Male') $m_cnt = (int)$g['cnt'];
                    if($g['gender'] === 'Female') $f_cnt = (int)$g['cnt'];
                }
                $tot_g = $m_cnt + $f_cnt;
                $m_pct = $tot_g > 0 ? round(($m_cnt / $tot_g) * 100) : 0;
                $f_pct = $tot_g > 0 ? round(($f_cnt / $tot_g) * 100) : 0;

                // Process Severity Data
                $sev_counts = ['High' => 0, 'Medium' => 0, 'Low' => 0];
                $tot_sev = 0;
                foreach($severities as $s) {
                    $sev_counts[$s['label']] = (int)$s['cnt'];
                    $tot_sev += (int)$s['cnt'];
                }
                $h_pct = $tot_sev > 0 ? round(($sev_counts['High'] / $tot_sev) * 100) : 0;
                $m_pct_sev = $tot_sev > 0 ? round(($sev_counts['Medium'] / $tot_sev) * 100) : 0;
                $l_pct = $tot_sev > 0 ? round(($sev_counts['Low'] / $tot_sev) * 100) : 0;
                ?>

                <!-- Gender Split Bar -->
                <div class="mb-6">
                    <div class="flex justify-between text-sm fw-600 mb-2">
                        <span style="color: #3b82f6;"><i class="fa-solid fa-mars mr-2"></i>Male <span class="text-muted fw-400 ml-1">(<?php echo $m_cnt; ?>)</span></span>
                        <span style="color: #ec4899;"><span class="text-muted fw-400 mr-1">(<?php echo $f_cnt; ?>)</span> Female <i class="fa-solid fa-venus ml-2"></i></span>
                    </div>
                    <div style="display:flex; height: 12px; border-radius: 999px; overflow: hidden; background: var(--c-bg-surface-2); box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);">
                        <div style="width: <?php echo $m_pct; ?>%; background: #3b82f6; transition: width 1s ease;"></div>
                        <div style="width: <?php echo $f_pct; ?>%; background: #ec4899; transition: width 1s ease;"></div>
                    </div>
                </div>

                <!-- Severity Progress Bars -->
                <div style="background: var(--c-bg-surface-2); border: 1px solid var(--c-border); border-radius: var(--radius-md); padding: 1.25rem;">
                    <h4 class="text-xs uppercase text-muted mb-4 tracking-wider fw-800">Medical Severity Split</h4>
                    
                    <div class="mb-3">
                        <div class="flex justify-between text-xs fw-600 mb-1">
                            <span style="color: var(--c-danger);">High Risk</span>
                            <span><?php echo $sev_counts['High']; ?> <span class="text-muted fw-400 ml-1">(<?php echo $h_pct; ?>%)</span></span>
                        </div>
                        <div style="height: 6px; border-radius: 999px; background: var(--c-border);">
                            <div style="width: <?php echo $h_pct; ?>%; height: 100%; border-radius: 999px; background: var(--c-danger); transition: width 1s ease;"></div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="flex justify-between text-xs fw-600 mb-1">
                            <span style="color: var(--c-warning);">Medium Risk</span>
                            <span><?php echo $sev_counts['Medium']; ?> <span class="text-muted fw-400 ml-1">(<?php echo $m_pct_sev; ?>%)</span></span>
                        </div>
                        <div style="height: 6px; border-radius: 999px; background: var(--c-border);">
                            <div style="width: <?php echo $m_pct_sev; ?>%; height: 100%; border-radius: 999px; background: var(--c-warning); transition: width 1s ease;"></div>
                        </div>
                    </div>

                    <div>
                        <div class="flex justify-between text-xs fw-600 mb-1">
                            <span style="color: var(--c-success);">Low Risk</span>
                            <span><?php echo $sev_counts['Low']; ?> <span class="text-muted fw-400 ml-1">(<?php echo $l_pct; ?>%)</span></span>
                        </div>
                        <div style="height: 6px; border-radius: 999px; background: var(--c-border);">
                            <div style="width: <?php echo $l_pct; ?>%; height: 100%; border-radius: 999px; background: var(--c-success); transition: width 1s ease;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Chart Row 2: Conditions + Mobility ───────────────────────── -->
        <div class="reports-chart-grid mb-8">
            <div class="card reports-card p-6">
                <h3 class="serif reports-title">Medical Condition Distribution</h3>
                <?php if (empty($conditions)): ?>
                    <p class="text-muted text-sm">No medical condition data recorded yet.</p>
                <?php else: ?>
                    <div class="chart-wrap chart-wrap--bar">
                        <canvas id="condChart"></canvas>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card reports-card p-6">
                <h3 class="serif reports-title">Mobility Status Breakdown</h3>
                <div class="chart-wrap chart-wrap--doughnut">
                    <canvas id="mobChart"></canvas>
                </div>
                <div class="reports-legend" style="flex-wrap:wrap;">
                    <?php
                    $mob_colors = ['var(--c-text-muted)', '#3b82f6', 'var(--c-warning)', 'var(--c-danger)'];
                    foreach ($mob_data as $i => $m):
                    ?>
                        <span><span class="legend-dot" style="background:<?php echo $mob_colors[$i % 4]; ?>;"></span><?php echo htmlspecialchars($m['label']); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── Faculty Medical Cases ─────────────────────────────────────── -->
        <div class="card mb-8 reports-card p-6" style="background: linear-gradient(145deg, var(--c-bg-surface), var(--c-bg-surface-2));">
            <h3 class="serif reports-title mb-4">Medical Cases by Faculty</h3>
            <?php if (empty($faculty_med)): ?>
                <p class="text-muted text-sm">No faculty data yet.</p>
            <?php else: ?>
                <div class="faculty-grid">
                    <?php 
                    $icons = ['fa-laptop-medical', 'fa-notes-medical', 'fa-briefcase-medical', 'fa-book-medical', 'fa-file-medical', 'fa-truck-medical', 'fa-suitcase-medical', 'fa-kit-medical'];
                    $theme_colors = [
                        ['bg' => 'rgba(59,130,246,0.1)', 'color' => '#3b82f6'],
                        ['bg' => 'rgba(236,72,153,0.1)', 'color' => '#ec4899'],
                        ['bg' => 'rgba(16,185,129,0.1)', 'color' => '#10b981'],
                        ['bg' => 'rgba(245,158,11,0.1)', 'color' => '#f59e0b'],
                        ['bg' => 'rgba(139,92,246,0.1)', 'color' => '#8b5cf6'],
                        ['bg' => 'rgba(6,182,212,0.1)',  'color' => '#06b6d4'],
                        ['bg' => 'rgba(244,63,94,0.1)',  'color' => '#f43f5e'],
                        ['bg' => 'rgba(99,102,241,0.1)', 'color' => '#6366f1']
                    ];
                    foreach ($faculty_med as $index => $fac): 
                        $short_name = str_replace(['Faculty of ', ' and '], ['', ' & '], $fac['faculty']);
                        $count = (int)$fac['med_count'];
                        $theme = $theme_colors[$index % count($theme_colors)];
                        $icon  = $icons[$index % count($icons)];
                    ?>
                    <div style="background: var(--c-bg-surface); border: 1px solid var(--c-border); border-radius: var(--radius-md); padding: 1.25rem; display: flex; align-items: center; gap: 1rem; transition: transform 0.2s, box-shadow 0.2s; cursor: default;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='var(--shadow-md)';" onmouseout="this.style.transform='none'; this.style.boxShadow='none';">
                        <div style="width: 46px; height: 46px; border-radius: 12px; background: <?php echo $theme['bg']; ?>; color: <?php echo $theme['color']; ?>; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0;">
                            <i class="fa-solid <?php echo $icon; ?>"></i>
                        </div>
                        <div style="min-width: 0;">
                            <h4 style="font-size: 0.8rem; font-weight: 700; color: var(--c-text-body); line-height: 1.3; margin-bottom: 0.15rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($short_name); ?>">
                                <?php echo htmlspecialchars($short_name); ?>
                            </h4>
                            <div style="font-size: 1.25rem; font-weight: 800; color: var(--c-text-head); line-height: 1;">
                                <?php echo $count; ?> 
                                <span style="font-size: 0.65rem; font-weight: 700; color: var(--c-text-muted); text-transform: uppercase; letter-spacing: 0.05em; vertical-align: middle;">Cases</span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── Hostel Occupancy Table ────────────────────────────────────── -->
        <div class="card mb-8 reports-card p-0 overflow-hidden">
            <div class="p-6 pb-4 border-b border-divider">
                <h3 class="serif reports-title mb-0">Hostel Occupancy Breakdown</h3>
            </div>
            <div style="overflow-x:auto;">
                <table class="data-table reports-table">
                    <thead>
                        <tr>
                            <th>Hall</th>
                            <th class="text-center">Capacity</th>
                            <th class="text-center">Occupied</th>
                            <th class="text-center">Available</th>
                            <th style="min-width:140px;">Fill Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($occupancy as $row):
                            $cap   = (int)$row['total_cap'];
                            $occ   = (int)$row['occupied'];
                            $avail = $cap - $occ;
                            $pct   = $cap > 0 ? round($occ / $cap * 100) : 0;
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
    Chart.defaults.color        = isDark ? '#9aa8b6' : '#5f6f82';
    Chart.defaults.font.family  = 'Inter, Arial, sans-serif';
    Chart.defaults.font.size    = 12;

    const grid = { color: isDark ? 'rgba(148,163,184,0.08)' : 'rgba(100,116,139,0.08)' };

    const getCssVar = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

    const palette = {
        allocated:    getCssVar('--c-success') || '#10b981',
        paidPending:  getCssVar('--c-warning') || '#f59e0b',
        unpaidPending:getCssVar('--c-text-muted') || '#94a3b8',
        high:         getCssVar('--c-danger') || '#ef4444',
        medium:       getCssVar('--c-warning') || '#f59e0b',
        low:          getCssVar('--c-success') || '#10b981',
        male:         '#3b82f6',
        female:       '#ec4899',
        mobility:     [getCssVar('--c-text-muted') || '#94a3b8','#3b82f6',getCssVar('--c-warning') || '#f59e0b',getCssVar('--c-danger') || '#ef4444'],
        conditions:   ['#3b82f6','#8b5cf6','#ec4899','#14b8a6','#f43f5e','#f97316','#06b6d4','#6366f1','#10b981','#a855f7'],
        faculty:      '#6366f1'
    };

    // Allocation doughnut
    new Chart(document.getElementById('allocChart'), {
        type: 'doughnut',
        data: {
            labels: ['Allocated','Paid, Awaiting Allocation','Payment Pending'],
            datasets: [{
                data: [<?php echo (int)$alloc['allocated']; ?>, <?php echo (int)$alloc['paid_pending']; ?>, <?php echo (int)$alloc['unpaid_pending']; ?>],
                backgroundColor: [palette.allocated, palette.paidPending, palette.unpaidPending],
                borderWidth: 2,
                borderColor: isDark ? '#242c3d' : '#ffffff'
            }]
        },
        options: { plugins: { legend: { display: false } }, cutout: '75%', maintainAspectRatio: false }
    });



    // Medical conditions
    const condCanvas = document.getElementById('condChart');
    if (condCanvas) {
        const condData = <?php echo json_encode($conditions); ?>;
        new Chart(condCanvas, {
            type: 'bar',
            data: {
                labels: condData.map(d => d.label),
                datasets: [{ label: 'Students', data: condData.map(d => +d.cnt),
                    backgroundColor: condData.map((_, i) => palette.conditions[i % palette.conditions.length]),
                    borderRadius: 6 }]
            },
            options: { indexAxis: 'y', plugins: { legend: { display: false } },
                maintainAspectRatio: false, scales: { x: { grid }, y: { grid: { display: false } } } }
        });
    }

    // Mobility doughnut
    const mobData = <?php echo json_encode($mob_data); ?>;
    new Chart(document.getElementById('mobChart'), {
        type: 'doughnut',
        data: {
            labels: mobData.map(d => d.label),
            datasets: [{
                data: mobData.map(d => d.cnt),
                backgroundColor: palette.mobility,
                borderWidth: 2,
                borderColor: isDark ? '#242c3d' : '#ffffff'
            }]
        },
        options: { plugins: { legend: { display: false } }, cutout: '70%', maintainAspectRatio: false }
    });
})();
</script>

</body>
</html>
