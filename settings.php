<?php
/**
 * settings.php — System Configuration
 * ======================================
 * Admin-only page for managing global allocation parameters:
 *   - Current academic session label (displayed on allocation letters).
 *   - Allocation status: 'open' allows the algorithm to run; 'locked' freezes it.
 *   - High-urgency threshold for clinic-proximal hard placement (default: 75).
 *
 * Security measures applied:
 *   - Session-based admin auth guard.
 *   - CSRF validation on every POST.
 *   - All user inputs are sanitized and range-validated before DB writes.
 *   - All output is escaped with htmlspecialchars().
 *   - All DB updates use prepared statements.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

// --- Auth Guard: Admin Only ---
// Redirect non-admin visitors immediately before loading any configuration data.
if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

$msg = '';
$msg_type = '';

// --- Settings Update Handler ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token before processing any form input
    check_csrf();

    // Sanitize and cast all incoming values
    $session      = sanitize_input($_POST['academic_session'] ?? '');
    $threshold    = (int)($_POST['threshold'] ?? 0);
    // FIX: Whitelist-validate alloc_status — previously any string could be stored
    $raw_status   = sanitize_input($_POST['alloc_status'] ?? 'open');
    $alloc_status = in_array($raw_status, ['open', 'locked']) ? $raw_status : 'open';

    // --- Server-side Validation ---
    // Threshold values must be percentages (0–100).
    if ($threshold < 41 || $threshold > 100) {
        $msg = "High-urgency threshold must be between 41 and 100.";
        $msg_type = "error";
    } elseif (empty($session)) {
        $msg = "Academic session cannot be empty.";
        $msg_type = "error";
    } else {
        // Persist to DB using a single prepared statement reused for all keys.
        // This avoids repeated prepare() calls for a simple key=value pattern.
        $upd = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");

        // Update: current academic session
        $key = 'current_session';
        $upd->bind_param("ss", $session, $key);
        $upd->execute();

        // Update: clinic-proximal high-urgency threshold
        $t_str = (string)$threshold;
        $key   = 'urgency_threshold_proximal';
        $upd->bind_param("ss", $t_str, $key);
        $upd->execute();

        // Update: allocation status (open | locked)
        $key = 'allocation_status';
        $upd->bind_param("ss", $alloc_status, $key);
        $upd->execute();

        $upd->close();

        // Audit log the settings change for accountability
        log_admin_action($conn, $_SESSION['user_id'], "Updated system settings: session={$session}, threshold={$threshold}");

        $msg = "System configuration updated successfully.";
        $msg_type = "success";
    }
}

// Load current settings from DB
$settings = [];
$res = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $res->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$cur_session    = $settings['current_session'] ?? '2025/2026';
$cur_threshold  = $settings['urgency_threshold_proximal'] ?? '75';
$cur_status     = $settings['allocation_status'] ?? 'open';

$page_title = "Settings | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-info">
                <h1>System Configurations</h1>
                <p class="text-muted">Manage global allocation parameters and academic session.</p>
            </div>
        </div>

        <?php if($msg): ?>
            <div class="alert alert-<?php echo $msg_type === 'success' ? 'success' : 'danger'; ?> mb-6">
                <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?>"></i>
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <!-- Main Settings Card -->
        <div class="card mb-6" style="max-width:860px;">
            <div style="padding:1.75rem 2rem;border-bottom:1px solid var(--c-border);">
                <h3 style="margin:0;display:flex;align-items:center;gap:0.625rem;font-size:1rem;">
                    <i class="fa-solid fa-gears" style="color:var(--c-primary);"></i> Core Parameters
                </h3>
            </div>
            <div style="padding:2rem;">
                <form method="post" id="settings-form">
                    <?php csrf_field(); ?>

                    <div class="grid" style="grid-template-columns:1fr 1fr;gap:1.5rem;">

                        <div class="form-group">
                            <label for="setting-session">Academic Session</label>
                            <input type="text"
                                   id="setting-session"
                                   name="academic_session"
                                   value="<?php echo htmlspecialchars($cur_session); ?>"
                                   placeholder="e.g. 2025/2026"
                                   required>
                            <div class="text-xs text-muted mt-2">
                                <i class="fa-solid fa-info-circle"></i>
                                Displayed on student allocation letters and reports.
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="setting-alloc-status">Allocation Status</label>
                            <select id="setting-alloc-status" name="alloc_status">
                                <option value="open"   <?php if($cur_status==='open')   echo 'selected'; ?>>
                                    Open (Accepting Allocations)
                                </option>
                                <option value="locked" <?php if($cur_status==='locked') echo 'selected'; ?>>
                                    Locked (Session Closed)
                                </option>
                            </select>
                            <div class="text-xs text-muted mt-2">Controls whether the Run Algorithm button is active.</div>
                        </div>

                        <div class="form-group">
                            <label for="setting-threshold">Clinic-Proximal High Urgency Threshold</label>
                            <input type="number"
                                   id="setting-threshold"
                                   name="threshold"
                                   value="<?php echo htmlspecialchars($cur_threshold); ?>"
                                   min="41" max="100">
                            <div class="text-xs text-muted mt-2">
                                This value defines the lower bound for the <strong>High</strong> urgency band. Scores from 40 up to one point below this value remain <strong>Medium</strong>, and scores below 40 remain <strong>Low</strong>; they are not ignored.
                            </div>
                        </div>

                    </div>

                    <div style="display:flex;justify-content:flex-end;margin-top:1.75rem;padding-top:1.5rem;border-top:1px solid var(--c-border);">
                        <button type="submit" class="btn btn-primary" id="save-settings-btn">
                            <i class="fa-solid fa-floppy-disk"></i> Save Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </main>
</div>
</body>
</html>
