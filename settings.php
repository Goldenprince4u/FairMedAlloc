<?php
/**
 * settings.php - System Configuration
 * Admin-only page for managing allocation parameters and session maintenance.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

$msg = '';
$msg_type = '';

function clearCurrentSessionAllocations(mysqli $conn, string $session, int $admin_id): void {
    $conn->begin_transaction();

    try {
        // Reset ALL students' allocation and payment status for the new session
        $conn->query("UPDATE student_profiles SET allocation_status = 'Unallocated', is_paid = 0");

        // Delete all simulated payments
        $conn->query("DELETE FROM payments");

        // Clean up old allocation notifications
        $conn->query("
            DELETE FROM notifications 
            WHERE message LIKE 'Congratulations! You have been allocated a room in %'
               OR message LIKE 'Update: You have been placed on the waiting list%'
        ");

        // Notify all students that a new session has started
        $reset_notice = "A new hostel allocation cycle has been opened for {$session}. Please ensure your school fee payment is completed via the portal to participate.";
        $notice_stmt = $conn->prepare("INSERT INTO notifications (user_id, message) SELECT user_id, ? FROM student_profiles");
        $notice_stmt->bind_param("s", $reset_notice);
        $notice_stmt->execute();

        // Delete the allocations for the current session
        $delete_stmt = $conn->prepare("DELETE FROM allocations WHERE academic_session = ?");
        $delete_stmt->bind_param("s", $session);
        $delete_stmt->execute();

        // Reset room occupancy counts
        $conn->query("UPDATE rooms SET occupied_count = 0");
        $conn->query("
            UPDATE rooms r
            JOIN (
                SELECT room_id, COUNT(*) AS cnt
                FROM allocations
                GROUP BY room_id
            ) a ON a.room_id = r.room_id
            SET r.occupied_count = a.cnt
        ");

        log_admin_action($conn, $admin_id, "Cleared allocations and reset payments for new academic session: {$session}");
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function deleteAllImportedData(mysqli $conn, int $admin_id): void {
    $conn->begin_transaction();
    try {
        $conn->query("DELETE FROM allocations");
        $conn->query("DELETE FROM algorithm_audit_logs");
        $conn->query("DELETE FROM medical_records");
        $conn->query("DELETE FROM notifications");
        $conn->query("DELETE FROM payments");
        $conn->query("DELETE FROM student_profiles");
        $conn->query("DELETE FROM users WHERE role = 'student'");
        $conn->query("UPDATE rooms SET occupied_count = 0");

        log_admin_action($conn, $admin_id, "Permanently deleted ALL imported student data");
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    if (isset($_POST['delete_all_data'])) {
        try {
            deleteAllImportedData($conn, (int)$_SESSION['user_id']);
            $msg = "All imported student data, medical records, and allocations have been permanently deleted.";
            $msg_type = "success";
        } catch (Throwable $e) {
            $msg = "Unable to delete data. Please try again.";
            $msg_type = "error";
            error_log('[FairMedAlloc] Delete all data failed: ' . $e->getMessage());
        }
    } elseif (isset($_POST['clear_session_allocations'])) {
        $session_to_clear = sanitize_input($_POST['session_to_clear'] ?? '');
        if ($session_to_clear === '') {
            $msg = "No academic session was supplied for clearing allocations.";
            $msg_type = "error";
        } else {
            try {
                clearCurrentSessionAllocations($conn, $session_to_clear, (int)$_SESSION['user_id']);
                $msg = "Cleared room allocations for {$session_to_clear}. Student records were preserved and room occupancy was recalculated.";
                $msg_type = "success";
            } catch (Throwable $e) {
                $msg = "Unable to clear allocations right now. Please try again.";
                $msg_type = "error";
                error_log('[FairMedAlloc] Session allocation reset failed: ' . $e->getMessage());
            }
        }
    } else {
        $session = sanitize_input($_POST['academic_session'] ?? '');
        $threshold = (int)($_POST['threshold'] ?? 0);
        $medium_threshold = (int)($_POST['medium_threshold'] ?? 0);
        // Read the notice as raw text — no sanitize_input() here because
        // that would HTML-encode it before DB storage, causing double-encoding
        // when it is later displayed with htmlspecialchars().
        $general_notice = trim((string)($_POST['general_notice'] ?? ''));
        $raw_status = sanitize_input($_POST['alloc_status'] ?? 'open');
        $alloc_status = in_array($raw_status, ['open', 'locked'], true) ? $raw_status : 'open';

        if ($medium_threshold < 0 || $medium_threshold > 99) {
            $msg = "Medium-urgency threshold must be between 0 and 99.";
            $msg_type = "error";
        } elseif ($threshold < 41 || $threshold > 100) {
            $msg = "High-urgency threshold must be between 41 and 100.";
            $msg_type = "error";
        } elseif ($medium_threshold >= $threshold) {
            $msg = "Medium-urgency threshold must stay below the high-urgency threshold.";
            $msg_type = "error";
        } elseif (strlen($general_notice) > 600) {
            $msg = "General notice must not exceed 600 characters.";
            $msg_type = "error";
        } elseif ($session === '') {
            $msg = "Academic session cannot be empty.";
            $msg_type = "error";
        } else {
            $upd = $conn->prepare("
                INSERT INTO settings (setting_key, setting_value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");

            $key = 'current_session';
            $upd->bind_param("ss", $key, $session);
            $upd->execute();

            $key = 'urgency_threshold_proximal';
            $threshold_value = (string)$threshold;
            $upd->bind_param("ss", $key, $threshold_value);
            $upd->execute();

            $key = 'urgency_threshold_medium';
            $medium_value = (string)$medium_threshold;
            $upd->bind_param("ss", $key, $medium_value);
            $upd->execute();

            $key = 'allocation_status';
            $upd->bind_param("ss", $key, $alloc_status);
            $upd->execute();

            $key = 'general_notice';
            $upd->bind_param("ss", $key, $general_notice);
            $upd->execute();

            $upd->close();

            log_admin_action(
                $conn,
                (int)$_SESSION['user_id'],
                "Updated system settings: session={$session}, high_threshold={$threshold}, medium_threshold={$medium_threshold}"
            );

            $msg = "System configuration updated successfully.";
            $msg_type = "success";
        }
    }
}

$settings = [];
$res = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $res->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$cur_session = $settings['current_session'] ?? '2025/2026';
$cur_threshold = $settings['urgency_threshold_proximal'] ?? '75';
$cur_medium_threshold = $settings['urgency_threshold_medium'] ?? '40';
$cur_status = $settings['allocation_status'] ?? 'open';
// Read the stored value as-is (plain text). html_entity_decode() is intentionally
// NOT used here — the value is raw text stored via a prepared statement, so no
// HTML entities are present. Decoding would be a no-op at best and could corrupt
// apostrophes on re-save (the '→&#039; number-display bug).
$cur_general_notice = (string)($settings['general_notice'] ?? '');

$page_title = "Settings | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div class="page-header-info">
                <h1>System Configurations</h1>
                <p class="text-muted">Manage global allocation parameters, notices, and session maintenance.</p>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-<?php echo $msg_type === 'success' ? 'success' : 'danger'; ?> mb-6">
                <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?>"></i>
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <div class="card mb-6 settings-card">
            <div style="padding:1.75rem 2rem;border-bottom:1px solid var(--c-border);">
                <h3 style="margin:0;display:flex;align-items:center;gap:0.625rem;font-size:1rem;">
                    <i class="fa-solid fa-gears" style="color:var(--c-primary);"></i> Core Parameters
                </h3>
            </div>
            <div style="padding:2rem;">
                <form method="post" id="settings-form">
                    <?php csrf_field(); ?>

                    <div class="settings-grid">
                        <div class="form-group">
                            <label for="setting-session">Academic Session</label>
                            <input
                                type="text"
                                id="setting-session"
                                name="academic_session"
                                value="<?php echo htmlspecialchars($cur_session); ?>"
                                placeholder="e.g. 2025/2026"
                                required
                            >
                            <div class="text-xs text-muted mt-2">
                                <i class="fa-solid fa-info-circle"></i>
                                Displayed on student allocation letters and reports.
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="setting-alloc-status">Allocation Status</label>
                            <select id="setting-alloc-status" name="alloc_status">
                                <option value="open" <?php if ($cur_status === 'open') echo 'selected'; ?>>Open (Accepting Allocations)</option>
                                <option value="locked" <?php if ($cur_status === 'locked') echo 'selected'; ?>>Locked (Session Closed)</option>
                            </select>
                            <div class="text-xs text-muted mt-2">Controls whether the Run Algorithm button is active.</div>
                        </div>

                        <div class="form-group">
                            <label for="setting-threshold">Clinic-Proximal High Urgency Threshold</label>
                            <input
                                type="number"
                                id="setting-threshold"
                                name="threshold"
                                value="<?php echo htmlspecialchars($cur_threshold); ?>"
                                min="41"
                                max="100"
                            >
                            <div class="text-xs text-muted mt-2">
                                This value defines the lower bound for the <strong>High</strong> urgency band.
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="setting-medium-threshold">Medium Urgency Threshold</label>
                            <input
                                type="number"
                                id="setting-medium-threshold"
                                name="medium_threshold"
                                value="<?php echo htmlspecialchars($cur_medium_threshold); ?>"
                                min="0"
                                max="99"
                            >
                            <div class="text-xs text-muted mt-2">
                                Scores at or above this value enter the <strong>Medium</strong> band unless they already meet the High threshold.
                            </div>
                        </div>
                    </div>

                    <div class="form-group mt-4">
                        <label for="setting-general-notice">General Notice for Students</label>
                        <textarea
                            id="setting-general-notice"
                            name="general_notice"
                            rows="4"
                            maxlength="600"
                            placeholder="Write a message that should appear on every student's dashboard."
                        ><?php echo htmlspecialchars($cur_general_notice, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <div class="text-xs text-muted mt-2">
                            This message appears under General Info on every student dashboard.
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

        <div class="card settings-card">
            <div style="padding:1.75rem 2rem;border-bottom:1px solid var(--c-border);">
                <h3 style="margin:0;display:flex;align-items:center;gap:0.625rem;font-size:1rem;">
                    <i class="fa-solid fa-broom" style="color:var(--c-warning);"></i> Session Maintenance
                </h3>
            </div>
            <div style="padding:2rem;">
                <p class="text-muted mb-4">
                    Clear room allocations for the current academic session. This keeps all student and medical records, but resets payment statuses and room assignments so a new session can begin.
                </p>
                <div class="alert alert-warning mb-4">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    This removes room assignments for <strong><?php echo htmlspecialchars($cur_session); ?></strong>, recalculates room occupancy, resets all students to <strong>Unallocated</strong>, and clears all simulated portal payments.
                </div>
                <form method="post" onsubmit="return confirm('Clear all allocations and reset payments for <?php echo htmlspecialchars($cur_session); ?>? Student and medical records will remain.');" style="margin-bottom: 2rem;">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="session_to_clear" value="<?php echo htmlspecialchars($cur_session); ?>">
                    <input type="hidden" name="clear_session_allocations" value="1">
                    <button type="submit" class="btn btn-outline" style="border-color:var(--c-warning);color:var(--c-warning);">
                        <i class="fa-solid fa-rotate-left"></i> Clear Current Session Allocations
                    </button>
                </form>

                <hr style="border: 0; border-top: 1px solid var(--c-border); margin: 2rem 0;">

                <h4 style="margin-bottom: 1rem; color: var(--c-danger);"><i class="fa-solid fa-trash"></i> Delete All Imported Data</h4>
                <p class="text-muted mb-4">
                    <span style="color: var(--c-danger); font-weight: bold;">Developer / Testing Only:</span> This will permanently delete ALL students, medical records, payments, notifications, and allocations from the database. It is unrecoverable.
                </p>
                <form method="post" onsubmit="return confirm('Are you absolutely sure you want to delete ALL imported student data? This cannot be undone!');">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="delete_all_data" value="1">
                    <button type="submit" class="btn btn-primary" style="background-color: var(--c-danger); border-color: var(--c-danger);">
                        <i class="fa-solid fa-trash-can"></i> Delete All Imported Data
                    </button>
                </form>
            </div>
        </div>
    </main>
</div>
</body>
</html>
