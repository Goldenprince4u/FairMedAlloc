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

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-info">
                <h1>Run Algorithm</h1>
                <p class="text-muted">Execute the fairness-aware hostel allocation process.</p>
            </div>
            <a href="admin_dashboard.php" class="btn btn-outline" id="run-back-btn">
                <i class="fa-solid fa-arrow-left"></i> Dashboard
            </a>
        </div>

        <div class="grid grid-cols-2">

            <!-- Control Panel -->
            <div class="card" style="padding:2rem;">
                <div class="form-section-title" style="margin-bottom:1.25rem;">
                    <span class="form-section-icon" style="background:rgba(0,33,71,0.08);color:var(--c-primary);"><i class="fa-solid fa-sliders"></i></span>
                    Control Panel
                </div>
                <p class="text-muted" style="font-size:0.875rem;margin-bottom:1rem;">This process will:</p>
                <ul class="list-instructions">
                    <li>Fetch eligible students imported through Data Import and students whose portal payment has been confirmed through the pay simulator.</li>
                    <li>Recalculate urgency scores via the configured XGBoost model.</li>
                    <li>Send High urgency students to clinic-proximal space as a hard constraint, and apply the current Medium urgency faculty rule.</li>
                    <li>Run the OR-Tools CP-SAT solver to assign rooms.</li>
                    <li>Use randomness only to break ties between equally valid room options, then write audit logs and notify each student of the result.</li>
                </ul>

                <?php if ($is_locked): ?>
                    <div class="alert alert-danger" style="margin-top:1.5rem;">
                        <i class="fa-solid fa-lock"></i> Allocation session is locked for this academic year.
                    </div>
                    <button class="btn btn-secondary w-full" disabled style="margin-top:1rem;opacity:0.5;cursor:not-allowed;">
                        <i class="fa-solid fa-lock"></i> Session Locked
                    </button>
                <?php else: ?>
                    <form id="run-allocation-form" class="hidden">
                        <?php csrf_field(); ?>
                    </form>
                    <button class="btn btn-primary w-full" id="start-alloc-btn"
                            onclick="startAllocation()"
                            style="margin-top:1.5rem;padding:0.875rem;">
                        <i class="fa-solid fa-play"></i> Start Allocation Engine
                    </button>
                    <button class="btn btn-secondary w-full" id="rescore-btn"
                            onclick="rescoreAllScores()"
                            style="margin-top:0.875rem;padding:0.875rem;">
                        <i class="fa-solid fa-rotate"></i> Recalculate All Urgency Scores
                    </button>
                    <p class="text-muted" style="font-size:0.78rem;margin-top:0.875rem;">
                        Use rescoring after replacing the XGBoost `.pkl` model so existing medical records pick up the latest XGBoost output.
                    </p>
                <?php endif; ?>
            </div>

            <!-- Process Log -->
            <div class="card-console">
                <div style="font-size:0.875rem;font-weight:700;color:rgba(255,255,255,0.9);margin-bottom:1.25rem;padding-bottom:0.875rem;border-bottom:1px solid rgba(255,255,255,0.1);display:flex;align-items:center;gap:0.5rem;">
                    <i class="fa-solid fa-terminal" style="color:var(--c-accent);"></i> Process Log
                </div>
                <div id="console" style="font-size:0.78rem;font-family:monospace;line-height:1.85;color:rgba(255,255,255,0.7);">
                    <div style="opacity:0.4;">Waiting to start&hellip;</div>
                </div>
            </div>

        </div>
    </main>
</div>

<script>
function startAllocation() {
    const logEl = document.getElementById('console');
    const runBtn = document.getElementById('start-alloc-btn');
    const rescoreBtn = document.getElementById('rescore-btn');
    const csrf  = document.querySelector('#run-allocation-form input[name="csrf_token"]');

    if (!csrf) {
        logEl.innerHTML = '<div style="color:var(--c-danger);">Security token missing. Reload the page and try again.</div>';
        return;
    }

    // Disable button and show starting state
    if (runBtn) { runBtn.disabled = true; runBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Running...'; }
    if (rescoreBtn) { rescoreBtn.disabled = true; }
    logEl.innerHTML = '<div style="color:var(--c-warning);margin-bottom:0.5rem;">&#9654; Initializing Allocation Engine...</div>';
    logEl.innerHTML += '<div style="margin-bottom:0.5rem;">&#9654; Fetching imported students and portal-paid students eligible for this batch...</div>';
    logEl.innerHTML += '<div style="margin-bottom:0.5rem;">&#9654; Invoking the XGBoost `.pkl` model bridge (predict.py)...</div>';
    logEl.innerHTML += '<div style="margin-bottom:0.5rem;">&#9654; Running OR-Tools CP-SAT solver (allocate.py)...</div>';
    logEl.innerHTML += '<div style="margin-bottom:0.5rem;opacity:0.6;font-style:italic;">Please wait - this may take up to 60 seconds...</div>';

    fetch('api/admin_api.php?action=run_algorithm', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ csrf_token: csrf.value })
    })
        .then(response => response.json())
        .then(data => {
            if (runBtn) { runBtn.disabled = false; runBtn.innerHTML = '<i class="fa-solid fa-play"></i> Start Allocation Engine'; }
            if (rescoreBtn) { rescoreBtn.disabled = false; }

            if (data.status === 'success') {
                const optimality = data.optimal ? 'OPTIMAL' : 'FEASIBLE (time limit reached)';
                logEl.innerHTML += `<div style="color:var(--c-success);margin-bottom:0.5rem;">&#10003; Solver finished: ${optimality}</div>`;
                if (data.prediction_mode) {
                    logEl.innerHTML += `<div style="color:var(--c-success);margin-bottom:0.5rem;">&#10003; XGBoost score mode: ${data.prediction_mode}</div>`;
                }
                logEl.innerHTML += `<div style="color:var(--c-success);margin-bottom:0.5rem;">&#10003; Allocated: ${data.allocated} of ${data.total} eligible students</div>`;
                logEl.innerHTML += '<div style="font-weight:700;margin-top:1rem;color:var(--c-success);">&#187; ALLOCATION CYCLE COMPLETE &#171;</div>';
                logEl.innerHTML += '<div style="font-size:0.72rem;opacity:0.6;margin-top:0.5rem;">Audit logs written. Students can now view their status on the dashboard.</div>';
            } else {
                logEl.innerHTML += `<div style="margin-top:1rem;color:var(--c-danger);">&#10007; Error: ${data.message}</div>`;
                logEl.innerHTML += '<div style="font-size:0.72rem;opacity:0.6;margin-top:0.5rem;">Check that Python, OR-Tools, the bundled XGBoost dependencies, and shell execution are available to Apache.</div>';
            }
        })
        .catch(err => {
            if (runBtn) { runBtn.disabled = false; runBtn.innerHTML = '<i class="fa-solid fa-play"></i> Start Allocation Engine'; }
            if (rescoreBtn) { rescoreBtn.disabled = false; }
            logEl.innerHTML += `<div style="margin-top:1rem;color:var(--c-danger);">&#10007; Network Error: ${err}</div>`;
        });
}

function rescoreAllScores() {
    const logEl = document.getElementById('console');
    const runBtn = document.getElementById('start-alloc-btn');
    const rescoreBtn = document.getElementById('rescore-btn');
    const csrf = document.querySelector('#run-allocation-form input[name="csrf_token"]');

    if (!csrf) {
        logEl.innerHTML = '<div style="color:var(--c-danger);">Security token missing. Reload the page and try again.</div>';
        return;
    }

    if (rescoreBtn) { rescoreBtn.disabled = true; rescoreBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Rescoring...'; }
    if (runBtn) { runBtn.disabled = true; }
    logEl.innerHTML = '<div style="color:var(--c-warning);margin-bottom:0.5rem;">&#9654; Recomputing urgency scores for all medical records...</div>';
    logEl.innerHTML += '<div style="margin-bottom:0.5rem;">&#9654; Invoking predict.py against the XGBoost `.pkl` model...</div>';

    fetch('api/admin_api.php?action=rescore_all', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ csrf_token: csrf.value })
    })
        .then(response => response.json())
        .then(data => {
            if (rescoreBtn) { rescoreBtn.disabled = false; rescoreBtn.innerHTML = '<i class="fa-solid fa-rotate"></i> Recalculate All Urgency Scores'; }
            if (runBtn) { runBtn.disabled = false; }

            if (data.status === 'success') {
                logEl.innerHTML += `<div style="color:var(--c-success);margin-bottom:0.5rem;">&#10003; Rescored medical records: ${data.rescored}</div>`;
                if (data.mode) {
                    logEl.innerHTML += `<div style="color:var(--c-success);margin-bottom:0.5rem;">&#10003; XGBoost score mode: ${data.mode}</div>`;
                }
                logEl.innerHTML += '<div style="font-weight:700;margin-top:1rem;color:var(--c-success);">&#187; RESCORE COMPLETE &#171;</div>';
            } else {
                logEl.innerHTML += `<div style="margin-top:1rem;color:var(--c-danger);">&#10007; Error: ${data.message}</div>`;
            }
        })
        .catch(err => {
            if (rescoreBtn) { rescoreBtn.disabled = false; rescoreBtn.innerHTML = '<i class="fa-solid fa-rotate"></i> Recalculate All Urgency Scores'; }
            if (runBtn) { runBtn.disabled = false; }
            logEl.innerHTML += `<div style="margin-top:1rem;color:var(--c-danger);">&#10007; Network Error: ${err}</div>`;
        });
}
</script>
</body>
</html>

