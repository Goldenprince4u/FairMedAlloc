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

$page_title = "Reports | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="serif mb-1" style="font-size: 2rem;">System Analytics</h1>
                <p class="text-muted">Real-time breakdown of allocations and student demographics.</p>
            </div>
            <a href="admin_dashboard.php" class="btn btn-outline">
                <i class="fa-solid fa-arrow-left"></i> Dashboard
            </a>
        </div>

        <!-- Top summary stats (loaded via JS) -->
        <div class="stats-grid mb-8" id="summaryStats">
            <div class="stat-card skeleton" style="height: 90px;"></div>
            <div class="stat-card skeleton" style="height: 90px;"></div>
            <div class="stat-card skeleton" style="height: 90px;"></div>
        </div>

        <div class="grid grid-cols-2 gap-8 mb-8">
            <!-- Allocation Status -->
            <div class="glass-card">
                <h3 class="fw-700 mb-4 text-lg" style="border-bottom: 1px solid var(--c-border); padding-bottom: 0.75rem;">
                    <i class="fa-solid fa-chart-pie mr-2 text-primary"></i> Allocation Progress
                </h3>
                <div id="chartAllocContainer" style="position: relative; max-height: 280px; display: flex; align-items: center; justify-content: center;">
                    <canvas id="chartAlloc"></canvas>
                    <div id="chartAllocError" class="text-muted text-sm text-center" style="display:none;">
                        <i class="fa-regular fa-face-sad-tear mb-2" style="font-size: 2rem; opacity: 0.4; display:block;"></i>
                        No allocation data yet.
                    </div>
                </div>
            </div>

            <!-- Medical Distribution -->
            <div class="glass-card">
                <h3 class="fw-700 mb-4 text-lg" style="border-bottom: 1px solid var(--c-border); padding-bottom: 0.75rem;">
                    <i class="fa-solid fa-heart-pulse mr-2 text-danger"></i> Medical Conditions
                </h3>
                <div id="chartMedicalContainer" style="position: relative; max-height: 280px;">
                    <canvas id="chartMedical"></canvas>
                    <div id="chartMedicalError" class="text-muted text-sm text-center" style="display:none; padding: 2rem;">
                        <i class="fa-regular fa-face-sad-tear mb-2" style="font-size: 2rem; opacity: 0.4; display:block;"></i>
                        No medical records found.
                    </div>
                </div>
            </div>
        </div>

        <!-- Financial Overview -->
        <div class="glass-card mb-8">
            <h3 class="fw-700 mb-4 text-lg" style="border-bottom: 1px solid var(--c-border); padding-bottom: 0.75rem;">
                <i class="fa-solid fa-naira-sign mr-2 text-success"></i> Financial Overview
            </h3>
            <div id="statsPayment" class="grid grid-cols-3 gap-4 text-center">
                <div class="skeleton" style="height: 80px; border-radius: 12px;"></div>
                <div class="skeleton" style="height: 80px; border-radius: 12px;"></div>
                <div class="skeleton" style="height: 80px; border-radius: 12px;"></div>
            </div>
        </div>

        <!-- Hostel Capacity Table -->
        <div class="glass-card">
            <h3 class="fw-700 mb-4 text-lg" style="border-bottom: 1px solid var(--c-border); padding-bottom: 0.75rem;">
                <i class="fa-solid fa-building mr-2 text-info"></i> Hostel Occupancy
            </h3>
            <div id="hostelTable">
                <div class="skeleton" style="height: 200px; border-radius: 12px;"></div>
            </div>
        </div>

    </main>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
const brandColors = {
    primary: '#002147',
    accent:  '#FFD700',
    success: '#10b981',
    warning: '#f59e0b',
    danger:  '#ef4444',
    info:    '#3b82f6',
    light:   '#94a3b8'
};

Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.color = '#475569';

document.addEventListener('DOMContentLoaded', () => {
    fetch('api/admin_api.php?action=analytics')
        .then(res => res.json())
        .then(data => {
            if (data.status !== 'success') {
                showError('Failed to load analytics. Please refresh.');
                return;
            }

            // ---- Summary Stats ----
            const allocated = parseInt(data.allocation.allocated || 0);
            const pending   = parseInt(data.allocation.pending || 0);
            const total     = allocated + pending;
            const pct       = total > 0 ? Math.round((allocated / total) * 100) : 0;

            document.getElementById('summaryStats').innerHTML = `
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(59,130,246,0.1); color:#3b82f6;"><i class="fa-solid fa-users"></i></div>
                    <div class="stat-info">
                        <h3>${total}</h3>
                        <p>Total Students</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(16,185,129,0.1); color:#10b981;"><i class="fa-solid fa-bed"></i></div>
                    <div class="stat-info">
                        <h3>${allocated}</h3>
                        <p>Allocated</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(245,158,11,0.1); color:#f59e0b;"><i class="fa-solid fa-clock"></i></div>
                    <div class="stat-info">
                        <h3>${pct}%</h3>
                        <p>Completion Rate</p>
                    </div>
                </div>
            `;

            // ---- Allocation Doughnut ----
            if (allocated > 0 || pending > 0) {
                new Chart(document.getElementById('chartAlloc'), {
                    type: 'doughnut',
                    data: {
                        labels: ['Allocated', 'Pending'],
                        datasets: [{
                            data: [allocated, pending],
                            backgroundColor: [brandColors.success, brandColors.warning],
                            borderWidth: 0,
                            hoverOffset: 6
                        }]
                    },
                    options: {
                        cutout: '72%',
                        plugins: {
                            legend: { position: 'bottom', labels: { padding: 20, usePointStyle: true } }
                        }
                    }
                });
            } else {
                document.getElementById('chartAllocError').style.display = 'block';
                document.getElementById('chartAlloc').style.display = 'none';
            }

            // ---- Medical Bar Chart ----
            const medFiltered = data.medical.filter(x => x.condition_category && x.condition_category !== 'None');
            if (medFiltered.length > 0) {
                const labels = medFiltered.map(x => x.condition_category);
                const counts = medFiltered.map(x => parseInt(x.count));
                const palette = [brandColors.primary, brandColors.info, brandColors.danger, brandColors.warning, brandColors.success, brandColors.light];

                new Chart(document.getElementById('chartMedical'), {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [{
                            label: 'Students',
                            data: counts,
                            backgroundColor: labels.map((_, i) => palette[i % palette.length] + 'cc'),
                            borderColor:     labels.map((_, i) => palette[i % palette.length]),
                            borderWidth: 2,
                            borderRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { precision: 0 } },
                            x: { grid: { display: false } }
                        }
                    }
                });
            } else {
                document.getElementById('chartMedicalError').style.display = 'block';
                document.getElementById('chartMedical').style.display = 'none';
            }

            // ---- Payments ----
            const payContainer = document.getElementById('statsPayment');
            if (data.payments && data.payments.length > 0) {
                const colorMap = {
                    paid:    { bg: 'rgba(16,185,129,0.08)', text: brandColors.success, icon: 'fa-check-circle' },
                    pending: { bg: 'rgba(245,158,11,0.08)', text: brandColors.warning, icon: 'fa-clock' },
                    failed:  { bg: 'rgba(239,68,68,0.08)',  text: brandColors.danger,  icon: 'fa-times-circle' }
                };
                payContainer.innerHTML = data.payments.map(stat => {
                    const c = colorMap[stat.status] || { bg: '#f8fafc', text: brandColors.light, icon: 'fa-circle' };
                    return `
                        <div class="p-4 rounded-xl text-center" style="background: ${c.bg};">
                            <i class="fa-solid ${c.icon} mb-2" style="font-size: 1.5rem; color: ${c.text};"></i>
                            <div class="text-2xl fw-700" style="color: ${c.text};">${parseInt(stat.count).toLocaleString()}</div>
                            <div class="text-xs text-muted uppercase tracking-wider mt-1">${stat.status}</div>
                        </div>
                    `;
                }).join('');
            } else {
                payContainer.innerHTML = '<p class="text-muted text-sm">No payment records found.</p>';
            }

            // ---- Hostel Occupancy Table ----
            loadHostelTable();
        })
        .catch(() => showError('Network error. Could not reach the API.'));
});

function loadHostelTable() {
    fetch('api/admin_api.php?action=hostel_stats')
        .then(r => r.json())
        .then(data => {
            const el = document.getElementById('hostelTable');
            if (!data || !data.length) {
                el.innerHTML = '<p class="text-muted text-sm italic">No hostel data available.</p>';
                return;
            }
            let rows = data.map(h => {
                const pct = Math.round((h.occupied / h.capacity) * 100);
                const colour = pct >= 90 ? 'var(--c-danger)' : pct >= 60 ? 'var(--c-warning)' : 'var(--c-success)';
                return `
                    <tr>
                        <td><div class="fw-600">${h.name}</div><div class="text-xs text-muted">${h.block_name}</div></td>
                        <td class="text-center">${h.gender}</td>
                        <td class="text-center">${h.occupied} / ${h.capacity}</td>
                        <td style="min-width: 120px;">
                            <div style="background: #e2e8f0; border-radius: 999px; height: 8px; overflow: hidden;">
                                <div style="width: ${pct}%; height: 100%; background: ${colour}; border-radius: 999px; transition: width 0.8s ease;"></div>
                            </div>
                            <div class="text-xs text-muted mt-1">${pct}% full</div>
                        </td>
                    </tr>
                `;
            }).join('');
            el.innerHTML = `
                <div class="table-container">
                    <table>
                        <thead><tr><th>Hostel</th><th class="text-center">Gender</th><th class="text-center">Occupancy</th><th>Progress</th></tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            `;
        })
        .catch(() => {
            document.getElementById('hostelTable').innerHTML = '<p class="text-muted text-sm">Hostel stats unavailable.</p>';
        });
}

function showError(msg) {
    document.getElementById('summaryStats').innerHTML = `<div class="alert alert-danger col-span-3">${msg}</div>`;
}
</script>
</body>
</html>
