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
                    <li>Strongly prioritise High urgency students for clinic-proximal space; if that space is full they fall through to the next available valid room. Apply the current Medium urgency faculty rule.</li>
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
/* ─── Shared helpers ─────────────────────────────────────────── */
let _elapsedInterval = null;

function startElapsedTimer(logEl) {
    const startTime = Date.now();
    _elapsedInterval = setInterval(() => {
        const secs = Math.floor((Date.now() - startTime) / 1000);
        const min  = String(Math.floor(secs / 60)).padStart(2, '0');
        const sec  = String(secs % 60).padStart(2, '0');
        const el   = document.getElementById('elapsed-timer');
        if (el) el.textContent = `${min}:${sec}`;
    }, 1000);
}

function stopElapsedTimer() {
    if (_elapsedInterval) { clearInterval(_elapsedInterval); _elapsedInterval = null; }
}

function logLine(logEl, msg, color) {
    const div = document.createElement('div');
    div.style.marginBottom = '0.5rem';
    if (color) div.style.color = color;
    div.innerHTML = msg;
    logEl.appendChild(div);
    logEl.scrollTop = logEl.scrollHeight;
}

function resetButtons(runBtn, rescoreBtn) {
    stopElapsedTimer();
    if (runBtn)     { runBtn.disabled     = false; runBtn.innerHTML     = '<i class="fa-solid fa-play"></i> Start Allocation Engine'; }
    if (rescoreBtn) { rescoreBtn.disabled = false; rescoreBtn.innerHTML = '<i class="fa-solid fa-rotate"></i> Recalculate All Urgency Scores'; }
}

async function parseApiJson(response) {
    const text = (await response.text()).trim();
    if (!response.ok) {
        throw new Error(`HTTP ${response.status} ${response.statusText}`);
    }

    try {
        return JSON.parse(text);
    } catch (err) {
        const compact = text.replace(/\s+/g, ' ').slice(0, 220);
        throw new Error(`Invalid JSON response from server: ${compact || 'empty response'}`);
    }
}

/* ─── Allocation ─────────────────────────────────────────────── */
function startAllocation() {
    const logEl     = document.getElementById('console');
    const runBtn    = document.getElementById('start-alloc-btn');
    const rescoreBtn= document.getElementById('rescore-btn');
    const csrf      = document.querySelector('#run-allocation-form input[name="csrf_token"]');

    if (!csrf) {
        logEl.innerHTML = '<div style="color:var(--c-danger);">Security token missing. Reload the page and try again.</div>';
        return;
    }

    // Disable buttons
    if (runBtn)     { runBtn.disabled     = true; runBtn.innerHTML     = '<i class="fa-solid fa-spinner fa-spin"></i> Running…'; }
    if (rescoreBtn) { rescoreBtn.disabled = true; }

    // Clear console and show live header
    logEl.innerHTML = `
        <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.75rem;color:var(--c-warning);font-weight:700;">
            <i class="fa-solid fa-circle-notch fa-spin"></i>
            Allocation Engine Running
            <span style="font-family:monospace;font-size:0.9em;opacity:0.8;">
                — Elapsed: <span id="elapsed-timer">00:00</span>
            </span>
        </div>`;

    logLine(logEl, '&#9654; Engine started — fetching students, scoring via XGBoost, and running the solver…');
    logLine(logEl,
        '<span style="opacity:0.5;font-style:italic;">The page will update automatically when the solver finishes. Do not reload.</span>');

    startElapsedTimer(logEl);

    // Add a "still working" ping every 30 s so the admin knows it hasn't hung
    let pingCount = 0;
    const pingInterval = setInterval(() => {
        pingCount++;
        logLine(logEl,
            `<span style="opacity:0.55;">&#9656; Still running… (${pingCount * 30}s elapsed — solver is working)</span>`);
    }, 30_000);

    // AbortController: cancel only after 10 minutes (600 s).
    // The solver itself is capped at 180 s inside allocate.py, so 600 s gives
    // plenty of headroom for I/O, scoring, DB writes, and notification dispatch.
    const controller = new AbortController();
    const networkTimeout = setTimeout(() => controller.abort(), 600_000);

    fetch('api/admin_api.php?action=run_algorithm', {
        method : 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body   : new URLSearchParams({ csrf_token: csrf.value }),
        signal : controller.signal,
        // keepalive tells the browser not to tear down the connection when the
        // user switches tabs — important for a long-running background task.
        keepalive: true,
    })
    .then(parseApiJson)
    .then(data => {
        clearInterval(pingInterval);
        clearTimeout(networkTimeout);
        resetButtons(runBtn, rescoreBtn);

        if (data.status === 'success') {
            // Show scoring backend actually used
            if (data.prediction_mode) {
                logLine(logEl, `&#10003; Urgency scoring: ${data.prediction_mode}`, 'var(--c-success)');
            }
            // Show solver actually used + its optimality status
            if (data.solver_mode) {
                const solverLabel = data.solver_mode;
                const optLabel = data.optimal ? 'OPTIMAL' : 'FEASIBLE (time-limit reached — still a valid allocation)';
                logLine(logEl, `&#10003; Solver: ${solverLabel} — ${optLabel}`, 'var(--c-success)');
            }
            if (data.total === 0) {
                logLine(logEl, '&#9888; No eligible students found (check that students are marked as paid).', 'var(--c-warning)');
            } else {
                logLine(logEl, `&#10003; Allocated: ${data.allocated} of ${data.total} eligible students`, 'var(--c-success)');
            }
            logLine(logEl, '<strong>&#187; ALLOCATION CYCLE COMPLETE &#171;</strong>', 'var(--c-success)');
            logLine(logEl,
                '<span style="font-size:0.72rem;opacity:0.6;">Audit logs written. Students can now view their status on the dashboard.</span>');
        } else {
            logLine(logEl, `&#10007; Error: ${data.message}`, 'var(--c-danger)');
            logLine(logEl,
                '<span style="font-size:0.72rem;opacity:0.6;">Check that Python, OR-Tools, the XGBoost dependencies, and shell execution are available to Apache.</span>');
        }
    })
    .catch(err => {
        clearInterval(pingInterval);
        clearTimeout(networkTimeout);
        resetButtons(runBtn, rescoreBtn);

        if (err.name === 'AbortError') {
            logLine(logEl,
                '&#10007; The request timed out after 10 minutes. The server may still be running — check the allocation results page before retrying.',
                'var(--c-danger)');
        } else {
            logLine(logEl, `&#10007; Network Error: ${err.message}`, 'var(--c-danger)');
        }
    });
}

/* ─── Rescore ────────────────────────────────────────────────── */
function rescoreAllScores() {
    const logEl     = document.getElementById('console');
    const runBtn    = document.getElementById('start-alloc-btn');
    const rescoreBtn= document.getElementById('rescore-btn');
    const csrf      = document.querySelector('#run-allocation-form input[name="csrf_token"]');

    if (!csrf) {
        logEl.innerHTML = '<div style="color:var(--c-danger);">Security token missing. Reload the page and try again.</div>';
        return;
    }

    if (rescoreBtn) { rescoreBtn.disabled = true; rescoreBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Rescoring…'; }
    if (runBtn)     { runBtn.disabled = true; }

    logEl.innerHTML = `
        <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.75rem;color:var(--c-warning);font-weight:700;">
            <i class="fa-solid fa-circle-notch fa-spin"></i>
            Recalculating Urgency Scores
            <span style="font-family:monospace;font-size:0.9em;opacity:0.8;">
                — Elapsed: <span id="elapsed-timer">00:00</span>
            </span>
        </div>`;

    logLine(logEl, '&#9654; Fetching all medical records…');
    logLine(logEl, '&#9654; Invoking <em>predict.py</em> against the XGBoost <code>.pkl</code> model…');
    logLine(logEl, '<span style="opacity:0.5;font-style:italic;">Please keep this page open while scoring completes.</span>');

    startElapsedTimer(logEl);

    let pingCount = 0;
    const pingInterval = setInterval(() => {
        pingCount++;
        logLine(logEl,
            `<span style="opacity:0.55;">&#9656; Still scoring… (${pingCount * 30}s elapsed)</span>`);
    }, 30_000);

    const controller  = new AbortController();
    const networkTimeout = setTimeout(() => controller.abort(), 300_000); // 5 min cap

    fetch('api/admin_api.php?action=rescore_all', {
        method : 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body   : new URLSearchParams({ csrf_token: csrf.value }),
        signal : controller.signal,
        keepalive: true,
    })
    .then(parseApiJson)
    .then(data => {
        clearInterval(pingInterval);
        clearTimeout(networkTimeout);
        resetButtons(runBtn, rescoreBtn);

        if (data.status === 'success') {
            logLine(logEl, `&#10003; Rescored medical records: ${data.rescored}`, 'var(--c-success)');
            if (data.mode) {
                logLine(logEl, `&#10003; XGBoost score mode: ${data.mode}`, 'var(--c-success)');
            }
            logLine(logEl, '<strong>&#187; RESCORE COMPLETE &#171;</strong>', 'var(--c-success)');
        } else {
            logLine(logEl, `&#10007; Error: ${data.message}`, 'var(--c-danger)');
        }
    })
    .catch(err => {
        clearInterval(pingInterval);
        clearTimeout(networkTimeout);
        resetButtons(runBtn, rescoreBtn);

        if (err.name === 'AbortError') {
            logLine(logEl,
                '&#10007; Request timed out after 5 minutes. Check the ML service logs.',
                'var(--c-danger)');
        } else {
            logLine(logEl, `&#10007; Network Error: ${err.message}`, 'var(--c-danger)');
        }
    });
}
</script>
</body>
</html>

