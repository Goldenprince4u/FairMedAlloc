<?php
/**
 * Settings Page
 * System Configuration for Allocation Logic
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

// Auth Guard
if (($_SESSION['role'] ?? '') !== 'admin') { header("Location: login.php"); exit(); }

$msg = '';
$msg_type = '';

// Handle Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Save settings (Simulation)
    $session = sanitize_input($_POST['academic_session']);
    $threshold = (int) $_POST['threshold'];
    
    if ($threshold < 0 || $threshold > 100) {
        $msg = "Threshold must be between 0 and 100.";
        $msg_type = "error";
    } else {
        $msg = "System configuration updated for Session $session.";
        $msg_type = "success";
    }
}

$page_title = "Settings | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <h1 class="serif mb-2">System Configurations</h1>
        <p class="text-muted mb-8">Manage global allocation parameters.</p>

        <?php if($msg): ?>
            <div class="mb-6 p-4 rounded-lg flex items-center gap-3 <?php echo $msg_type == 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
                 <i class="fa-solid <?php echo $msg_type == 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?>"></i>
                 <?php echo $msg; ?>
            </div>
        <?php endif; ?>

        <div class="card p-8 bg-white border border-slate-200 rounded-xl" style="max-width: 600px;">
            
            <form method="post">
                <div class="form-group mb-6">
                    <label class="block font-bold mb-2 text-slate-700">Academic Session</label>
                    <input type="text" name="academic_session" value="2025/2026" class="p-3 border border-slate-300 rounded-lg w-full">
                    <div class="text-xs text-muted mt-2">Displayed on student allocation letters.</div>
                </div>

                <div class="form-group mb-6">
                    <label class="block font-bold mb-2 text-slate-700">Urgency Threshold (0-100)</label>
                    <input type="number" name="threshold" value="70" min="0" max="100" class="p-3 border border-slate-300 rounded-lg w-full">
                    <div class="text-xs text-muted mt-2">
                        Students with a score <strong>above</strong> this value are automatically prioritized for proximal hostels.
                    </div>
                </div>

                <div class="form-group mb-8">
                    <label class="block font-bold mb-2 text-slate-700">Algorithm Mode</label>
                    <select class="p-3 border border-slate-300 rounded-lg w-full bg-white">
                        <option>XGBoost (Prioritized)</option>
                        <option>Random Forest</option>
                        <option>Linear Regression</option>
                    </select>
                    <div class="text-xs text-muted mt-2">Determines the logic used for calculating urgency and prioritizing students.</div>
                </div>

                <div>
                    <button class="btn btn-primary px-6 py-3 rounded-lg" style="background: #002147;">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </main>
</div>
</body>
</html>