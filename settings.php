<?php
/**
 * Settings Page
 * System Configuration for Allocation Logic
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

// Auth Guard
if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit();
}

$msg = '';
$msg_type = '';

// Handle Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $session   = sanitize_input($_POST['academic_session'] ?? '');
    $threshold = (int)($_POST['threshold'] ?? 0);
    $gf_threshold = (int)($_POST['gf_threshold'] ?? 85);
    $alloc_status = sanitize_input($_POST['alloc_status'] ?? 'open');

    if ($threshold < 0 || $threshold > 100 || $gf_threshold < 0 || $gf_threshold > 100) {
        $msg = "Threshold values must be between 0 and 100.";
        $msg_type = "error";
    } elseif (empty($session)) {
        $msg = "Academic session cannot be empty.";
        $msg_type = "error";
    } else {
        // Persist to DB using prepared statements
        $upd = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");

        $upd->bind_param("ss", $session, $dummy);
        $dummy = 'current_session';
        $upd->bind_param("ss", $session, $dummy);
        $upd->execute();

        $upd2 = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
        $t_str = (string)$threshold;
        $key2  = 'urgency_threshold_proximal';
        $upd2->bind_param("ss", $t_str, $key2);
        $upd2->execute();

        $upd3 = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
        $gf_str = (string)$gf_threshold;
        $key3   = 'urgency_threshold_ground_floor';
        $upd3->bind_param("ss", $gf_str, $key3);
        $upd3->execute();

        $upd4 = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
        $key4 = 'allocation_status';
        $upd4->bind_param("ss", $alloc_status, $key4);
        $upd4->execute();

        log_admin_action($conn, $_SESSION['user_id'], "Updated system settings: session=$session, threshold=$threshold");

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
$cur_gf         = $settings['urgency_threshold_ground_floor'] ?? '85';
$cur_status     = $settings['allocation_status'] ?? 'open';

$page_title = "Settings | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="serif mb-2">System Configurations</h1>
                <p class="text-muted">Manage global allocation parameters and academic session.</p>
            </div>
        </div>

        <?php if($msg): ?>
            <div class="alert alert-<?php echo $msg_type === 'success' ? 'success' : 'danger'; ?> mb-6">
                <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?> mr-2"></i>
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-2" style="max-width: 900px; gap: 1.5rem;">
            <!-- Main Settings Card -->
            <div class="glass-card col-span-2" style="grid-column: 1 / -1;">
                <h3 class="serif mb-6 text-xl" style="border-bottom: 1px solid var(--c-border); padding-bottom: 1rem;">
                    <i class="fa-solid fa-gears mr-2 text-primary"></i> Core Parameters
                </h3>

                <form method="post">
                    <?php csrf_field(); ?>

                    <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                        <div class="form-group">
                            <label>Academic Session</label>
                            <input type="text" name="academic_session" value="<?php echo htmlspecialchars($cur_session); ?>" placeholder="e.g. 2025/2026" required>
                            <div class="text-xs text-muted mt-2"><i class="fa-solid fa-info-circle mr-1"></i> Displayed on student allocation letters and reports.</div>
                        </div>

                        <div class="form-group">
                            <label>Allocation Status</label>
                            <select name="alloc_status">
                                <option value="open" <?php if($cur_status==='open') echo 'selected'; ?>>🟢 Open (Accepting Allocations)</option>
                                <option value="locked" <?php if($cur_status==='locked') echo 'selected'; ?>>🔒 Locked (Session Closed)</option>
                            </select>
                            <div class="text-xs text-muted mt-2">Controls whether the Run Algorithm button is active.</div>
                        </div>

                        <div class="form-group">
                            <label>Proximal Hostel Urgency Threshold</label>
                            <input type="number" name="threshold" value="<?php echo htmlspecialchars($cur_threshold); ?>" min="0" max="100">
                            <div class="text-xs text-muted mt-2">
                                Students with a score <strong>above</strong> this value are prioritized for proximal (faculty-adjacent) hostels.
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Ground Floor Urgency Threshold</label>
                            <input type="number" name="gf_threshold" value="<?php echo htmlspecialchars($cur_gf); ?>" min="0" max="100">
                            <div class="text-xs text-muted mt-2">
                                Students above this threshold get ground-floor rooms (for wheelchair/mobility users).
                            </div>
                        </div>
                    </div>

                    <div class="text-right mt-6" style="border-top: 1px solid var(--c-border); padding-top: 1.5rem;">
                        <button class="btn btn-primary">
                            <i class="fa-solid fa-floppy-disk mr-2"></i> Save Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Danger Zone -->
        <div class="glass-card mt-6" style="max-width: 900px; border-left: 4px solid var(--c-danger);">
            <h3 class="text-lg font-bold mb-4 text-danger"><i class="fa-solid fa-triangle-exclamation mr-2"></i> Danger Zone</h3>
            <p class="text-muted text-sm mb-4">The following actions are irreversible. Proceed with caution.</p>
            <form method="post" onsubmit="return confirm('Are you sure you want to unlock the allocation? This will allow re-running the algorithm.');">
                <?php csrf_field(); ?>
                <input type="hidden" name="academic_session" value="<?php echo htmlspecialchars($cur_session); ?>">
                <input type="hidden" name="threshold" value="<?php echo htmlspecialchars($cur_threshold); ?>">
                <input type="hidden" name="gf_threshold" value="<?php echo htmlspecialchars($cur_gf); ?>">
                <input type="hidden" name="alloc_status" value="open">
                <button type="submit" class="btn btn-sm" style="background: var(--c-danger); color: white;">
                    <i class="fa-solid fa-lock-open mr-2"></i> Unlock Allocation Session
                </button>
            </form>
        </div>
    </main>
</div>
</body>
</html>