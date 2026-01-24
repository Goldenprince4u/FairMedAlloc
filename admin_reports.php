<?php
/**
 * Admin Reports & Analytics
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

if (($_SESSION['role'] ?? '') !== 'admin') { header("Location: login.php"); exit(); }

$page_title = "Reports | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <h1 class="serif mb-2 text-3xl">System Analytics</h1>
        <p class="text-muted mb-8">Real-time breakdown of allocations and student demographics.</p>

        <div class="grid grid-cols-2 gap-8 mb-8">
            <!-- Allocation Status -->
            <div class="card p-6">
                <h3 class="font-bold mb-4 border-b pb-2">Allocation Progress</h3>
                <canvas id="chartAlloc"></canvas>
            </div>

            <!-- Medical Distribution -->
            <div class="card p-6">
                <h3 class="font-bold mb-4 border-b pb-2">Medical Conditions</h3>
                <canvas id="chartMedical"></canvas>
            </div>
        </div>
        
        <div class="card p-6">
             <h3 class="font-bold mb-4 border-b pb-2">Financial Overview</h3>
             <div id="statsPayment" class="grid grid-cols-3 gap-4 text-center">
                 <!-- Populated by JS -->
                 <div class="animate-pulse h-10 w-full bg-slate-100 rounded"></div>
             </div>
        </div>

    </main>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    fetch('api/admin_analytics.php')
        .then(res => res.json())
        .then(data => {
            if(data.status !== 'success') return;

            // Allocation Chart
            new Chart(document.getElementById('chartAlloc'), {
                type: 'doughnut',
                data: {
                    labels: ['Allocated', 'Pending'],
                    datasets: [{
                        data: [data.allocation.allocated, data.allocation.pending],
                        backgroundColor: ['#22c55e', '#facc15']
                    }]
                }
            });

            // Medical Chart
            const labels = data.medical.map(x => x.condition_category);
            const counts = data.medical.map(x => x.count);
            
            new Chart(document.getElementById('chartMedical'), {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Students',
                        data: counts,
                        backgroundColor: '#1e3a8a'
                    }]
                },
                options: { scales: { y: { beginAtZero: true } } }
            });

            // Payments
            const payContainer = document.getElementById('statsPayment');
            payContainer.innerHTML = '';
            data.payments.forEach(stat => {
                const colors = { 'paid': 'text-green-600', 'pending': 'text-yellow-600', 'failed': 'text-red-600' };
                const color = colors[stat.status] || 'text-gray-600';
                
                payContainer.innerHTML += `
                    <div class="p-4 bg-slate-50 rounded">
                        <div class="text-xs text-muted uppercase">${stat.status}</div>
                        <div class="text-2xl font-bold ${color}">${stat.count}</div>
                    </div>
                `;
            });
        });
});
</script>
</body>
</html>
