<?php
/**
 * Run Allocation
 * Triggers the allocation algorithm.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

// Auth Guard
if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: admin_login.php"); exit();
}

// Fetch Allocation Status (Open vs Locked)
$stmt = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'allocation_status'");
$status_row = $stmt->fetch_assoc();
$is_locked = ($status_row['setting_value'] ?? 'open') === 'locked';

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
                    <li>Fetch all unallocated students who have paid.</li>
                    <li>Recalculate urgency scores via the XGBoost model.</li>
                    <li>Prioritize high-risk students for proximal hostels.</li>
                    <li>Run the OR-Tools CP-SAT solver to assign rooms.</li>
                    <li>Write audit logs and notify each student of their result.</li>
                </ul>

                <?php if ($is_locked): ?>
                    <div class="alert alert-danger mb-4 text-center">
                        <i class="fa-solid fa-lock mr-2"></i> Allocation Session is Locked for this Academic Year
                    </div>
                    <button class="btn btn-secondary w-full" disabled style="opacity:0.5; cursor:not-allowed;">
                        <i class="fa-solid fa-lock mr-2"></i> Session Locked
                    </button>
                <?php else: ?>
                    <button class="btn btn-primary w-full py-3 rounded-lg" onclick="startAllocation()">
                        <i class="fa-solid fa-play mr-2"></i> Start Allocation Engine
                    </button>
                <?php endif; ?>
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
    const logEl = document.getElementById('console');
    const btn   = document.querySelector('.btn-primary[onclick]');

    // Disable button and show starting state
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Running...'; }
    logEl.innerHTML = '<div class="mb-2" style="color:var(--c-warning);">&#9654; Initializing Allocation Engine...</div>';
    logEl.innerHTML += '<div class="mb-2">&#9654; Fetching paid, unallocated students...</div>';
    logEl.innerHTML += '<div class="mb-2">&#9654; Invoking XGBoost urgency scorer (predict.py)...</div>';
    logEl.innerHTML += '<div class="mb-2">&#9654; Running OR-Tools CP-SAT solver (allocate.py)...</div>';
    logEl.innerHTML += '<div class="mb-2 opacity-60" style="font-style:italic;">Please wait — this may take up to 60 seconds...</div>';

    fetch('api/admin_api.php?action=run_algorithm')
        .then(response => response.json())
        .then(data => {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-play mr-2"></i> Start Allocation Engine'; }

            if (data.status === 'success') {
                const optimality = data.optimal ? 'OPTIMAL' : 'FEASIBLE (time limit reached)';
                logEl.innerHTML += `<div class="mb-2" style="color:var(--c-success);">&#10003; Solver finished: ${optimality}</div>`;
                logEl.innerHTML += `<div class="mb-2" style="color:var(--c-success);">&#10003; Allocated: ${data.allocated} of ${data.total} eligible students</div>`;
                logEl.innerHTML += '<div class="fw-700 mt-4" style="color:var(--c-success);">&#187; ALLOCATION CYCLE COMPLETE &#171;</div>';
                logEl.innerHTML += '<div class="text-xs text-muted mt-2">Audit logs written. Students can now view their status on the dashboard.</div>';
            } else {
                logEl.innerHTML += `<div class="mt-4" style="color:var(--c-danger);">&#10007; Error: ${data.message}</div>`;
                logEl.innerHTML += '<div class="text-xs text-muted mt-1">Check that Python, ortools, and xgboost are installed and that XAMPP has permission to run shell commands.</div>';
            }
        })
        .catch(err => {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-play mr-2"></i> Start Allocation Engine'; }
            logEl.innerHTML += `<div class="mt-4" style="color:var(--c-danger);">&#10007; Network Error: ${err}</div>`;
        });
}
</script>
</body>
</html>
