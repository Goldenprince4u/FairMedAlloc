<?php
/**
 * Run Allocation
 * Triggers the allocation algorithm.
 */
session_start();
require_once 'db_config.php';

$page_title = "Run Allocation | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <h1 class="serif mb-2">Run Algorithm</h1>
        <p class="text-muted mb-8">Execute the fairness-aware allocation process.</p>

        <div class="grid grid-cols-2">
            
            <!-- Control Panel (White) -->
            <div class="card p-8">
                <h3 class="serif mb-4 text-2xl font-bold">Control Panel</h3>
                <p class="text-muted mb-4 text-sm">This process will:</p>
                <ul class="list-instructions">
                    <li>Clear all existing allocations.</li>
                    <li>Fetch latest student medical scores.</li>
                    <li>Prioritize high-risk students for proximal hostels.</li>
                    <li>Fill remaining spots with general population.</li>
                </ul>

                <button class="btn btn-primary w-full py-3 rounded-lg" onclick="startAllocation()">
                    <i class="fa-solid fa-play mr-2"></i> Start Allocation Engine
                </button>
            </div>

            <!-- Process Log (Dark Blue) -->
            <div class="card-console">
                <h3 class="serif mb-6 text-white text-lg font-bold border-b border-gray-700 pb-4">Process Log</h3>
                <div id="console" class="text-xs font-mono tracking-wide leading-loose">
                    <div class="mb-2 opacity-50">Waiting to start...</div>
                </div>
            </div>

        </div>
    </main>
</div>

<script>
function startAllocation() {
    const console = document.getElementById('console');
    console.innerHTML = '<div class="mb-2 text-warning">Initializing Engine...</div>';
    
    // Start Visualization
    setTimeout(() => {
        console.innerHTML += '<div class="mb-2">Fetching Student Data (Algorithm Input)...</div>';
    }, 500);

    setTimeout(() => {
        console.innerHTML += '<div class="mb-2">Connecting to XGBoost Model (Simulation)...</div>';
        
        // Actual AJAX Call
        fetch('api/run_algorithm.php')
            .then(response => response.json())
            .then(data => {
                if(data.status === 'success') {
                    console.innerHTML += '<div class="mb-2 text-green-400">> Algorithm Priority Sorting Complete</div>';
                    console.innerHTML += `<div class="mb-2 text-green-400">> Allocated: ${data.allocated} Students</div>`;
                    console.innerHTML += '<div class="text-success fw-700 mt-4">>> ALLOCATION CYCLE COMPLETE <<</div>';
                    console.innerHTML += '<div class="text-xs text-muted mt-2">Database Updated. Students can now verify status.</div>';
                } else {
                    console.innerHTML += `<div class="text-red-500 mt-4">Error: ${data.message}</div>`;
                }
            })
            .catch(err => {
                console.innerHTML += `<div class="text-red-500 mt-4">Network Error: ${err}</div>`;
            });
            
    }, 1500);
}
</script>
</body>
</html>
