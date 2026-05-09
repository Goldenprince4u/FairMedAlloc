<?php
/**
 * Run Allocation
 * ==============
 * Async job queue UI — queues a background job and polls for progress.
 * Supports large datasets (5,000 – 15,000+ students) without HTTP timeouts.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

// Auth Guard
if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

// Fetch Allocation Status (Open vs Locked)
$stmt = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'allocation_status'");
$status_row = $stmt ? $stmt->fetch_assoc() : null;
$is_locked = ($status_row['setting_value'] ?? 'open') === 'locked';

// Fetch most recent job for resume UI — guarded in case migration hasn't run yet
$recent_job = null;
try {
    $jq = $conn->query(
        "SELECT job_id, status, progress_percent, progress_stage,
                total_students, allocated_students, result_data, error_message,
                created_at, started_at, completed_at
           FROM allocation_jobs
          WHERE job_type = 'allocation'
          ORDER BY created_at DESC LIMIT 1"
    );
    if ($jq && $jq->num_rows > 0) {
        $recent_job = $jq->fetch_assoc();
        if (!empty($recent_job['result_data'])) {
            $recent_job['result'] = json_decode($recent_job['result_data'], true) ?? [];
        }
    }
} catch (Throwable $e) {
    // Table may not exist yet — run sql/run_migrations.php first
    $recent_job = null;
}

$page_title = "Run Allocation | FairMedAlloc";
require_once 'includes/header.php';
?>

<style>
    /* ── Async Progress UI Styles ───────────────────────────────────────────── */
    .progress-wrap {
        margin-top: 1.25rem;
    }

    .progress-bar-track {
        background: rgba(255, 255, 255, 0.08);
        border-radius: 999px;
        height: 10px;
        overflow: hidden;
        margin: 0.75rem 0 0.35rem;
    }

    .progress-bar-fill {
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(90deg, var(--c-accent), var(--c-primary));
        transition: width 0.6s ease;
        width: 0%;
    }

    .progress-label {
        display: flex;
        justify-content: space-between;
        font-size: 0.75rem;
        color: rgba(255, 255, 255, 0.55);
        font-family: monospace;
    }

    .job-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.2rem 0.65rem;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.03em;
    }

    .badge-queued {
        background: rgba(255, 195, 0, 0.18);
        color: #ffc300;
    }

    .badge-running {
        background: rgba(0, 120, 255, 0.18);
        color: #5b9ef7;
    }

    .badge-completed {
        background: rgba(0, 200, 100, 0.18);
        color: #40e08c;
    }

    .badge-failed {
        background: rgba(255, 60, 60, 0.18);
        color: #ff6b6b;
    }

    .badge-cancelled {
        background: rgba(180, 180, 180, 0.18);
        color: #b0b0b0;
    }

    #job-history-row {
        margin-top: 1.5rem;
        font-size: 0.82rem;
        opacity: 0.7;
    }
</style>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-info">
                <h1>Run Algorithm</h1>
                <p class="text-muted">Fairness-aware hostel allocation — supports 15,000+ students via background queue.
                </p>
            </div>
            <a href="admin_dashboard.php" class="btn btn-outline" id="run-back-btn">
                <i class="fa-solid fa-arrow-left"></i> Dashboard
            </a>
        </div>

        <div class="grid grid-cols-2">

            <!-- ── Control Panel ─────────────────────────────────────────── -->
            <div class="card" style="padding:2rem;">
                <div class="form-section-title" style="margin-bottom:1.25rem;">
                    <span class="form-section-icon" style="background:rgba(0,33,71,0.08);color:var(--c-primary);">
                        <i class="fa-solid fa-sliders"></i>
                    </span>
                    Control Panel
                </div>

                <p class="text-muted" style="font-size:0.875rem;margin-bottom:1rem;">This process will execute the following steps:</p>
                <ul class="list-instructions" style="line-height: 1.6; color: rgba(255, 255, 255, 0.85); font-size: 0.9rem; margin-bottom: 1rem; padding-left: 1.5rem;">
                    <li><strong>Fetch Candidates:</strong> Retrieves all paid and unallocated students from the database.</li>
                    <li><strong>Calculate Urgency:</strong> Scores each student using the XGBoost model and calibrates them into High, Medium, or Low priority bands.</li>
                    <li><strong>Optimize Allocation:</strong> Runs the OR-Tools Min-Cost Flow solver to compute the optimal room assignments.</li>
                    <li><strong>Enforce Constraints:</strong> Applies the PHP Safety Inspector to guarantee clinic-proximal routing, lower bunk beds for mobility students, ground-floor placement, and strict gender separation.</li>
                    <li><strong>Manage Waitlist:</strong> Waitlists students whose specific constraints cannot be met (with a reason provided) and backfills any remaining space.</li>
                    <li><strong>Finalize:</strong> Writes a comprehensive audit log and saves all results securely in a single database transaction.</li>
                </ul>

                <div class="alert"
                    style="margin-top:1.25rem;background:rgba(0,120,255,0.08);border:1px solid rgba(91,158,247,0.3);color:#5b9ef7;font-size:0.82rem;padding:0.75rem 1rem;border-radius:8px;">
                    <i class="fa-solid fa-bolt"></i>
                    <strong>Async mode:</strong> The job runs in the background — you can safely close this tab or
                    navigate away. Progress is polled automatically every few seconds.
                </div>

                <?php if ($is_locked): ?>
                    <div class="alert alert-danger" style="margin-top:1.5rem;">
                        <i class="fa-solid fa-lock"></i> Allocation session is locked for this academic year.
                    </div>
                    <button class="btn btn-secondary w-full" disabled
                        style="margin-top:1rem;opacity:0.5;cursor:not-allowed;">
                        <i class="fa-solid fa-lock"></i> Session Locked
                    </button>
                <?php else: ?>
                    <!-- Hidden CSRF form -->
                    <form id="run-allocation-form" class="hidden">
                        <?php csrf_field(); ?>
                    </form>

                    <button class="btn btn-primary w-full" id="start-alloc-btn" onclick="queueAllocation()"
                        style="margin-top:1.5rem;padding:0.875rem;">
                        <i class="fa-solid fa-play"></i> Start Allocation Engine
                    </button>
                    <button class="btn btn-secondary w-full" id="rescore-btn" onclick="rescoreAllScores()"
                        style="margin-top:0.875rem;padding:0.875rem;">
                        <i class="fa-solid fa-rotate"></i> Recalculate All Urgency Scores
                    </button>
                    <button class="btn w-full" id="cancel-job-btn" onclick="cancelCurrentJob()" style="display:none;margin-top:0.875rem;padding:0.875rem;
                                   background:rgba(255,60,60,0.12);color:#ff6b6b;
                                   border:1px solid rgba(255,60,60,0.3);">
                        <i class="fa-solid fa-xmark"></i> Cancel Job
                    </button>
                <?php endif; ?>

                <!-- Recent job resumption notice -->
                <?php if ($recent_job && in_array($recent_job['status'], ['queued', 'running'])): ?>
                    <div id="job-history-row">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                        A job (<strong>#<?= (int) $recent_job['job_id'] ?></strong>) is currently
                        <span class="job-badge badge-<?= htmlspecialchars($recent_job['status']) ?>">
                            <?= ucfirst(htmlspecialchars($recent_job['status'])) ?>
                        </span>
                        — resuming progress display automatically.
                    </div>
                <?php endif; ?>
            </div>

            <!-- ── Process Log ─────────────────────────────────────────────── -->
            <div class="card-console" style="display:flex;flex-direction:column;overflow:hidden;">
                <div
                    style="font-size:0.875rem;font-weight:700;color:rgba(255,255,255,0.9);margin-bottom:1.25rem;padding-bottom:0.875rem;border-bottom:1px solid rgba(255,255,255,0.1);display:flex;align-items:center;gap:0.5rem;flex-shrink:0;">
                    <i class="fa-solid fa-terminal" style="color:var(--c-accent);"></i> Process Log
                    <span id="job-status-badge" style="margin-left:auto;"></span>
                </div>

                <!-- Progress bar (hidden until job starts) -->
                <div class="progress-wrap" id="progress-wrap" style="display:none;flex-shrink:0;">
                    <div class="progress-bar-track">
                        <div class="progress-bar-fill" id="progress-fill"></div>
                    </div>
                    <div class="progress-label">
                        <span id="progress-stage-label">Initializing…</span>
                        <span id="progress-pct-label">0%</span>
                    </div>
                </div>

                <div id="console"
                    style="font-size:0.78rem;font-family:monospace;line-height:1.85;color:rgba(255,255,255,0.7);margin-top:1rem;flex:1;overflow-y:auto;padding-right:0.5rem;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,0.2) transparent;">
                    <div style="opacity:0.4;">Waiting to start&hellip;</div>
                </div>
            </div>

        </div>
    </main>
</div>

<script>
    /* ═══════════════════════════════════════════════════════════════════════
       FairMedAlloc — Async Allocation UI
       ═══════════════════════════════════════════════════════════════════════ */

    const POLL_INTERVAL_MS = 2500;   // poll every 2.5 s while running
    const POLL_IDLE_MS = 8000;   // slow poll after completion for 60 s
    const MAX_IDLE_POLLS = 8;      // stop auto-polling after this many idle cycles

    let _elapsedInterval = null;
    let _pollTimer = null;
    let _currentJobId = null;
    let _pollCount = 0;
    let _idlePollCount = 0;

    // ── Boot: resume an active job if one exists ─────────────────────────────────
    <?php if ($recent_job && in_array($recent_job['status'], ['queued', 'running'])): ?>
        window.addEventListener('DOMContentLoaded', () => {
            _currentJobId = <?= (int) $recent_job['job_id'] ?>;
            logLine(document.getElementById('console'),
                `&#9654; Resuming progress for Job #${_currentJobId}…`, '#5b9ef7');
            showProgressBar();
            startElapsedTimer();
            schedulePoll();
        });
    <?php endif; ?>

    /* ── Helpers ───────────────────────────────────────────────────────────────── */

    function startElapsedTimer() {
        const startTime = Date.now();
        _elapsedInterval = setInterval(() => {
            const secs = Math.floor((Date.now() - startTime) / 1000);
            const min = String(Math.floor(secs / 60)).padStart(2, '0');
            const sec = String(secs % 60).padStart(2, '0');
            const el = document.getElementById('elapsed-timer');
            if (el) el.textContent = `${min}:${sec}`;
        }, 1000);
    }

    function stopElapsedTimer() {
        if (_elapsedInterval) { clearInterval(_elapsedInterval); _elapsedInterval = null; }
    }

    let _lastLogMsg = '';
    function logLine(logEl, msg, color) {
        // Prevent printing the exact same message back-to-back
        if (msg === _lastLogMsg) return;
        _lastLogMsg = msg;

        const now = new Date().toLocaleTimeString('en-GB', { hour12: false });
        const div = document.createElement('div');
        div.style.marginBottom = '0.4rem';
        if (color) div.style.color = color;
        div.innerHTML = `<span style="opacity:0.4;font-size:0.7em;">[${now}]</span> ${msg}`;
        logEl.appendChild(div);
        logEl.scrollTop = logEl.scrollHeight;
    }

    function showProgressBar() {
        const wrap = document.getElementById('progress-wrap');
        if (wrap) wrap.style.display = 'block';
    }

    function updateProgressBar(stage, percent) {
        const fill = document.getElementById('progress-fill');
        const stage_el = document.getElementById('progress-stage-label');
        const pct_el = document.getElementById('progress-pct-label');
        if (fill) fill.style.width = percent + '%';
        if (stage_el) stage_el.textContent = stage || 'Working…';
        if (pct_el) pct_el.textContent = percent + '%';
    }

    function setJobBadge(status) {
        const el = document.getElementById('job-status-badge');
        if (!el) return;
        const map = {
            queued: ['badge-queued', '&#9676; Queued'],
            running: ['badge-running', '&#9679; Running'],
            completed: ['badge-completed', '&#10003; Completed'],
            failed: ['badge-failed', '&#10007; Failed'],
            cancelled: ['badge-cancelled', '&#9940; Cancelled'],
        };
        const [cls, label] = map[status] ?? ['badge-queued', status];
        el.innerHTML = `<span class="job-badge ${cls}">${label}</span>`;
    }

    function resetButtons() {
        stopElapsedTimer();
        const runBtn = document.getElementById('start-alloc-btn');
        const rescoreBtn = document.getElementById('rescore-btn');
        const cancelBtn = document.getElementById('cancel-job-btn');
        if (runBtn) { runBtn.disabled = false; runBtn.innerHTML = '<i class="fa-solid fa-play"></i> Start Allocation Engine'; }
        if (rescoreBtn) { rescoreBtn.disabled = false; rescoreBtn.innerHTML = '<i class="fa-solid fa-rotate"></i> Recalculate All Urgency Scores'; }
        if (cancelBtn) { cancelBtn.style.display = 'none'; }
    }

    function getCSRF() {
        const el = document.querySelector('#run-allocation-form input[name="csrf_token"]');
        return el ? el.value : '';
    }

    async function parseApiJson(response) {
        const text = (await response.text()).trim();
        if (!response.ok) {
            // Try to surface the server's own error message before throwing
            try {
                const errData = JSON.parse(text);
                if (errData.message) throw new Error(errData.message);
            } catch (parseErr) {
                if (parseErr.message && parseErr.message !== 'JSON parse error') {
                    throw parseErr; // rethrow the server message
                }
            }
            throw new Error(`HTTP ${response.status}: ${text.slice(0, 200)}`);
        }
        try { return JSON.parse(text); }
        catch { throw new Error(`Invalid JSON from server: ${text.slice(0, 200)}`); }
    }

    /* ── Queue Allocation ──────────────────────────────────────────────────────── */

    async function queueAllocation() {
        const logEl = document.getElementById('console');
        const runBtn = document.getElementById('start-alloc-btn');
        const rescoreBtn = document.getElementById('rescore-btn');
        const csrf = getCSRF();

        if (!csrf) {
            logEl.innerHTML = '<div style="color:var(--c-danger);">Security token missing. Reload the page.</div>';
            return;
        }

        if (runBtn) { runBtn.disabled = true; runBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Queuing…'; }
        if (rescoreBtn) { rescoreBtn.disabled = true; }

        logEl.innerHTML = `
        <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.75rem;color:var(--c-warning);font-weight:700;">
            <i class="fa-solid fa-circle-notch fa-spin"></i>
            Allocation Engine Running
            <span style="font-family:monospace;font-size:0.9em;opacity:0.8;">
                — Elapsed: <span id="elapsed-timer">00:00</span>
            </span>
        </div>`;

        try {
            const resp = await fetch('api/admin_api.php?action=queue_allocation', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ csrf_token: csrf }),
            });
            const data = await parseApiJson(resp);

            if (data.job_id) {
                _currentJobId = data.job_id;
                logLine(logEl, `&#9654; Job #${_currentJobId} created — worker started in background.`, '#5b9ef7');
                if (data.warning) {
                    logLine(logEl, `&#9888; ${data.warning}`, 'var(--c-warning)');
                }
                logLine(logEl,
                    '<span style="opacity:0.5;font-style:italic;">You can safely close this tab. Progress will resume when you return.</span>');
                showProgressBar();
                setJobBadge('queued');
                startElapsedTimer();
                // Show the cancel button now that a job is active
                const cancelBtn = document.getElementById('cancel-job-btn');
                if (cancelBtn) cancelBtn.style.display = 'block';
                schedulePoll();
            } else {
                logLine(logEl, `&#10007; Failed to queue job: ${data.message ?? 'Unknown error'}`, 'var(--c-danger)');
                resetButtons();
            }
        } catch (err) {
            logLine(logEl, `&#10007; Network error: ${err.message}`, 'var(--c-danger)');
            resetButtons();
        }
    }

    /* ── Polling ───────────────────────────────────────────────────────────────── */

    function schedulePoll(delay = POLL_INTERVAL_MS) {
        clearTimeout(_pollTimer);
        _pollTimer = setTimeout(pollJobStatus, delay);
    }

    async function pollJobStatus() {
        if (!_currentJobId) return;

        const logEl = document.getElementById('console');

        try {
            const resp = await fetch(`api/admin_api.php?action=job_status&job_id=${_currentJobId}`);
            const data = await parseApiJson(resp);

            const jobStatus = data.job_status ?? 'unknown';
            setJobBadge(jobStatus);
            updateProgressBar(data.progress_stage, data.progress_percent ?? 0);

            if (jobStatus === 'running' || jobStatus === 'queued') {
                _pollCount++;

                // Log meaningful stage changes (not every tick)
                if (_pollCount % 3 === 1 && data.progress_stage) {
                    logLine(logEl,
                        `&#9656; ${data.progress_stage} (${data.progress_percent ?? 0}%)`,
                        'rgba(255,255,255,0.55)');
                }

                if (jobStatus === 'queued' && _pollCount >= 3 && _pollCount % 4 === 3) {
                    logLine(logEl,
                        '&#9888; Job is still queued. If this persists, check Apache/XAMPP PHP CLI launch permissions or start `worker_launcher.php` manually.',
                        'var(--c-warning)');
                }

                schedulePoll(POLL_INTERVAL_MS);

            } else if (jobStatus === 'completed') {
                stopElapsedTimer();
                updateProgressBar('Completed', 100);
                resetButtons();
                renderCompletionResult(logEl, data);

            } else if (jobStatus === 'failed') {
                stopElapsedTimer();
                resetButtons();
                logLine(logEl,
                    `&#10007; Job #${_currentJobId} failed: ${data.error_message ?? 'Unknown error'}`,
                    'var(--c-danger)');
                logLine(logEl,
                    '<span style="font-size:0.72rem;opacity:0.6;">Check that Python, OR-Tools, and XGBoost dependencies are available to Apache/PHP.</span>');
                updateProgressBar('Failed', data.progress_percent ?? 0);

            } else if (jobStatus === 'cancelled') {
                stopElapsedTimer();
                resetButtons();
                updateProgressBar('Cancelled', data.progress_percent ?? 0);
                logLine(logEl, `&#9940; Job #${_currentJobId} was cancelled by an administrator.`, '#b0b0b0');
            }
        } catch (err) {
            // Transient network error — keep polling
            logLine(logEl, `<span style="opacity:0.4;">&#9656; Poll error (will retry): ${err.message}</span>`);
            schedulePoll(POLL_INTERVAL_MS * 2);
        }
    }

    /* ── Cancel Job ────────────────────────────────────────────────────────────── */

    async function cancelCurrentJob() {
        const csrf = getCSRF();
        const logEl = document.getElementById('console');
        const cancelBtn = document.getElementById('cancel-job-btn');

        if (!csrf) { logLine(logEl, 'Security token missing. Reload page.', 'var(--c-danger)'); return; }
        if (!confirm('Cancel the current allocation job? This will stop the process and release all locks.')) return;

        if (cancelBtn) { cancelBtn.disabled = true; cancelBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Cancelling…'; }

        try {
            const body = new URLSearchParams({ csrf_token: csrf });
            if (_currentJobId) body.append('job_id', _currentJobId);

            const resp = await fetch('api/admin_api.php?action=cancel_job', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body,
            });
            const data = await parseApiJson(resp);

            // Both success and "no jobs found" are treated as success — both release locks
            clearTimeout(_pollTimer);
            stopElapsedTimer();
            setJobBadge('cancelled');
            updateProgressBar('Cancelled', 0);
            logLine(logEl, `&#9940; ${data.message}`, '#b0b0b0');
            logLine(logEl, '<span style="opacity:0.6;">You can now start a new allocation run.</span>');
            resetButtons();
            if (cancelBtn) cancelBtn.style.display = 'none';
        } catch (err) {
            logLine(logEl, `&#10007; Network error: ${err.message}`, 'var(--c-danger)');
            if (cancelBtn) { cancelBtn.disabled = false; cancelBtn.innerHTML = '<i class="fa-solid fa-xmark"></i> Cancel Job'; }
        }
    }


    function renderCompletionResult(logEl, data) {
        const res = data.result ?? {};

        if (res.prediction_mode) {
            logLine(logEl, `&#10003; Urgency scoring: ${res.prediction_mode}`, 'var(--c-success)');
        }
        if (res.solver_mode) {
            let optLabel = 'FEASIBLE (time-limit reached — still a valid allocation)';
            if (res.optimal) {
                optLabel = 'OPTIMAL';
            } else if ((res.solver_status ?? '') === 'FALLBACK' || /fallback/i.test(res.solver_mode)) {
                optLabel = 'FALLBACK (Python solver unavailable)';
            }
            logLine(logEl, `&#10003; Solver: ${res.solver_mode} — ${optLabel}`, 'var(--c-success)');
        }

        const total = data.total_students || res.total || 0;
        const allocated = data.allocated_students || res.allocated || 0;

        if (total === 0) {
            logLine(logEl,
                '&#9888; No eligible students found (check that students are marked as paid).',
                'var(--c-warning)');
        } else {
            logLine(logEl,
                `&#10003; Allocated: <strong>${allocated}</strong> of <strong>${total}</strong> eligible students`,
                'var(--c-success)');
        }

        logLine(logEl, '<strong>&#187; ALLOCATION CYCLE COMPLETE &#171;</strong>', 'var(--c-success)');
        logLine(logEl,
            '<span style="font-size:0.72rem;opacity:0.6;">Audit logs written. Students can now view their status on the dashboard.</span>');
    }

    /* ── Rescore (synchronous — scores only, no allocation) ────────────────────── */
    async function rescoreAllScores() {
        const logEl = document.getElementById('console');
        const runBtn = document.getElementById('start-alloc-btn');
        const rescoreBtn = document.getElementById('rescore-btn');
        const csrf = getCSRF();

        if (!csrf) {
            logEl.innerHTML = '<div style="color:var(--c-danger);">Security token missing. Reload the page.</div>';
            return;
        }

        if (rescoreBtn) { rescoreBtn.disabled = true; rescoreBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Rescoring…'; }
        if (runBtn) { runBtn.disabled = true; }

        logEl.innerHTML = `
        <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.75rem;color:var(--c-warning);font-weight:700;">
            <i class="fa-solid fa-circle-notch fa-spin"></i>
            Recalculating Urgency Scores
            <span style="font-family:monospace;font-size:0.9em;opacity:0.8;">
                — Elapsed: <span id="elapsed-timer">00:00</span>
            </span>
        </div>`;

        logLine(logEl, '&#9654; Fetching all medical records…');
        logLine(logEl, '&#9654; Invoking <em>predict.py</em> for XGBoost scoring and policy calibration…');
        logLine(logEl, '<span style="opacity:0.5;font-style:italic;">Please keep this page open while scoring completes.</span>');

        startElapsedTimer();

        let pingCount = 0;
        const pingInterval = setInterval(() => {
            pingCount++;
            logLine(logEl, `<span style="opacity:0.55;">&#9656; Still scoring… (${pingCount * 30}s elapsed)</span>`);
        }, 30_000);

        const controller = new AbortController();
        const networkTimeout = setTimeout(() => controller.abort(), 600_000);

        try {
            const resp = await fetch('api/admin_api.php?action=rescore_all', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ csrf_token: csrf }),
                signal: controller.signal,
                keepalive: true,
            });
            const data = await parseApiJson(resp);

            clearInterval(pingInterval);
            clearTimeout(networkTimeout);
            stopElapsedTimer();
            resetButtons();

            if (data.status === 'success') {
                logLine(logEl, `&#10003; Rescored medical records: ${data.rescored}`, 'var(--c-success)');
                if (data.mode) logLine(logEl, `&#10003; XGBoost score mode: ${data.mode}`, 'var(--c-success)');
                logLine(logEl, '<strong>&#187; RESCORE COMPLETE &#171;</strong>', 'var(--c-success)');
            } else {
                logLine(logEl, `&#10007; Error: ${data.message}`, 'var(--c-danger)');
            }
        } catch (err) {
            clearInterval(pingInterval);
            clearTimeout(networkTimeout);
            stopElapsedTimer();
            resetButtons();

            if (err.name === 'AbortError') {
                logLine(logEl, '&#10007; Request timed out. Check the ML service logs.', 'var(--c-danger)');
            } else {
                logLine(logEl, `&#10007; Network Error: ${err.message}`, 'var(--c-danger)');
            }
        }
    }
</script>
</body>

</html>
