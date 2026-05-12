<?php
/**
 * Student Dashboard
 * Main interface for students to view status.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php'; // Enforces 30-min idle session timeout

require_once 'includes/Student.php';

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'student') {
    header("Location: login.php");
    exit();
}

$user_id    = $_SESSION['user_id'];
$studentObj = new Student($conn, $user_id);

// Fetch Data using Model
$student = $studentObj->getProfile();

if (!$student) {
    $profileLookupStatus = $studentObj->getLastProfileLookupStatus();

    if ($profileLookupStatus === 'missing_profile') {
        session_destroy();
        header("Location: login.php?error=profile_missing");
        exit();
    }

    http_response_code(503);
    $page_title = "Profile Unavailable | FairMedAlloc";
    require_once 'includes/header.php';
    ?>
    <div class="app-shell">
        <?php require_once 'includes/nav.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <div class="page-header-info">
                    <h1>Profile Temporarily Unavailable</h1>
                    <p class="text-muted">We could not load your student profile right now.</p>
                </div>
            </div>

            <div class="card" style="max-width:720px;padding:1.75rem;">
                <div class="alert alert-danger mb-4">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    Your session is still active, but the profile lookup failed on the server. Please try refreshing this page. If the problem persists, contact the administrator.
                </div>

                <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
                    <a href="student_dashboard.php" class="btn btn-primary">
                        <i class="fa-solid fa-rotate-right"></i> Try Again
                    </a>
                    <a href="logout.php" class="btn btn-outline">
                        <i class="fa-solid fa-right-from-bracket"></i> Sign Out
                    </a>
                </div>
            </div>
        </main>
    </div>
    </body>
    </html>
    <?php
    exit();
}

$alloc    = $studentObj->getAllocation();
$has_paid = $studentObj->hasPaid();

$general_notice_stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'general_notice' LIMIT 1");
$general_notice_stmt->execute();
// Read the stored notice as raw text. html_entity_decode() is intentionally
// NOT used — the value is plain text from a prepared statement with no HTML
// entities embedded. Decoding + re-encoding caused apostrophes to appear as
// the literal string "&#039;" (the "gives a number" bug) on some setups.
$general_notice = trim((string)($general_notice_stmt->get_result()->fetch_assoc()['setting_value'] ?? ''));
$general_notice = $general_notice !== ''
    ? $general_notice
    : 'Hostel allocation runs in admin-managed batches. Keep your academic and medical profile accurate before the next batch.';

// Pre-load notifications so we can show a badge count in the collapsed header
require_once 'includes/NotificationManager.php';
$notifier            = new NotificationManager($conn);
$notices             = $notifier->getRecent($user_id, 5);
$unread_notice_count = count(array_filter($notices, fn($n) => !(bool)$n['is_read']));

$page_title = "Dashboard | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-info">
                <h1>My Dashboard</h1>
                <p class="text-muted">Welcome, <?php echo htmlspecialchars($student['full_name'] ?? $_SESSION['username']); ?></p>
            </div>
        </div>

        <?php if (isset($_GET['password_changed']) && $_GET['password_changed'] === '1'): ?>
            <div class="alert alert-success mb-6">
                <i class="fa-solid fa-check-circle"></i>
                Your password has been updated successfully.
            </div>
        <?php endif; ?>

        <!-- Allocation Status Card -->
        <div class="card mb-8 p-0 overflow-hidden relative">
            <?php if ($has_paid): ?>
                <?php if ($alloc): ?>
                    <div class="absolute left-0 top-0 bottom-0 w-2 bg-success"></div>
                    <div class="p-8">
                        <h3 class="serif mb-4 text-xl">Allocation Status</h3>

                        <div class="flex items-start gap-4 mb-6">
                            <i class="fa-solid fa-circle-check text-success text-xl mt-1"></i>
                            <div>
                                <div class="fw-700 text-success text-lg mb-2">Allocation Successful</div>
                                <p class="text-muted">You have been placed in <strong class="text-head"><?php echo htmlspecialchars($alloc['hostel_name']); ?></strong><?php if (!empty($alloc['block_name'])) echo ', ' . htmlspecialchars($alloc['block_name']); ?>.</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-4 mb-6">
                            <div class="p-3 surface-inset">
                                <div class="text-xs text-muted uppercase tracking-wider mb-1">Block</div>
                                <div class="text-xl fw-700 text-primary">
                                    <?php
                                        $b_name = $alloc['block_name'] ?? '1';
                                        $b_name = str_ireplace('Block ', '', $b_name);
                                        if (stripos($b_name, 'Main') !== false) $b_name = '1';
                                        echo htmlspecialchars($b_name);
                                    ?>
                                </div>
                            </div>
                            <div class="p-3 surface-inset">
                                <div class="text-xs text-muted uppercase tracking-wider mb-1">Room</div>
                                <div class="text-xl fw-700 text-primary"><?php echo htmlspecialchars($alloc['room_number']); ?></div>
                            </div>
                            <div class="p-3 surface-inset">
                                <div class="text-xs text-muted uppercase tracking-wider mb-1">Bed</div>
                                <div class="text-xl fw-700 text-primary"><?php echo htmlspecialchars($alloc['bed_label'] ?? 'N/A'); ?></div>
                            </div>
                        </div>

                        <div>
                            <a href="print_slip.php" target="_blank" class="btn btn-secondary">
                                <i class="fa-solid fa-print mr-2"></i> Print Official Slip
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="absolute left-0 top-0 bottom-0 w-2 bg-warning"></div>
                    <div class="p-8">
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="serif mb-4 text-xl">Allocation Status</h3>
                                <div class="flex items-start gap-4 mb-6">
                                    <i class="fa-solid fa-clock text-warning text-xl mt-1"></i>
                                    <div>
                                        <div class="fw-700 text-warning text-lg mb-2">Allocation Pending</div>
                                        <p class="text-muted">Your portal payment has been confirmed. The system attempted allocation immediately and will keep you pending until a suitable room is available.</p>
                                    </div>
                                </div>
                            </div>
                            <span class="badge badge-success px-4 py-2"><i class="fa-solid fa-check mr-2"></i>School Fees Paid</span>
                        </div>

                        <div class="p-4 surface-inset mb-6" style="display:inline-block; min-width:200px;">
                            <div class="text-xs text-muted uppercase tracking-wider mb-1">STATUS</div>
                            <div class="text-3xl fw-700 text-warning">Waiting...</div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <!-- NOT PAID STATE -->
                <div class="absolute left-0 top-0 bottom-0 w-2 bg-danger"></div>
                <div class="p-8">
                    <h3 class="serif mb-4 text-xl">Allocation Status</h3>

                    <div class="flex items-start gap-4 mb-6">
                        <i class="fa-solid fa-circle-exclamation text-danger text-xl mt-1"></i>
                        <div>
                            <div class="fw-700 text-danger text-lg mb-2">Action Required</div>
                            <p class="text-muted">You must complete your school fee payment on the portal using the pay simulator before a room can be allocated to you.</p>
                        </div>
                    </div>

                    <div class="alert alert-info mb-4">
                        <i class="fa-solid fa-info-circle mr-2"></i> Fee: &#8358;50,000
                    </div>

                    <?php csrf_field(); ?>

                    <button id="payBtn" class="btn btn-primary">
                        <i class="fa-solid fa-credit-card mr-2"></i> Pay on Portal (Simulator) - &#8358;50,000
                    </button>
                    <div id="payMsg" class="mt-4 hidden"></div>
                </div>
            <?php endif; ?>
        </div>

        <script src="js/student_dashboard.js"></script>
        <form id="notice-csrf-form" class="hidden">
            <?php csrf_field(); ?>
        </form>

        <!-- Bottom Grid: Profile & Notices -->
        <div class="grid grid-dashboard-custom">

            <!-- My Profile Preview -->
            <div class="card" style="padding:1.75rem;">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="serif text-xl mb-0">My Profile</h3>
                    <a href="profile.php" class="text-sm text-primary fw-700"><i class="fa-solid fa-pen"></i> Edit</a>
                </div>

                <div class="flex items-start gap-6">
                    <!-- Profile Pic -->
                    <img src="uploads/profile_pics/<?php echo htmlspecialchars($student['profile_pic'] ?: 'default.png'); ?>"
                         class="avatar"
                         style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid #e2e8f0;">

                    <!-- Details List -->
                    <div class="flex-1">
                        <div class="grid grid-cols-2 gap-2 text-sm">
                            <div class="text-muted">Matric No:</div>
                            <div class="fw-700"><?php echo htmlspecialchars($student['matric_no']); ?></div>

                            <div class="text-muted">Level:</div>
                            <div><?php echo htmlspecialchars($student['level']); ?></div>

                            <div class="text-muted">Department:</div>
                            <div><?php echo htmlspecialchars($student['department'] ?: $student['faculty']); ?></div>

                            <div class="text-muted">Health:</div>
                            <?php if (!empty($student['condition_category']) && $student['condition_category'] !== 'None / Healthy'): ?>
                                <div class="text-danger fw-700"><?php echo htmlspecialchars($student['condition_category']); ?></div>
                            <?php else: ?>
                                <div class="text-success fw-700">No declared conditions</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notices -->
            <div class="card" style="padding:1.75rem;">
                <div class="flex items-center justify-between mb-4" style="gap:0.75rem;">
                    <div style="display:flex;align-items:center;gap:0.625rem;">
                        <i class="fa-solid fa-bell" style="color:var(--c-primary);font-size:1rem;"></i>
                        <h3 class="serif text-xl mb-0">Notices</h3>
                    </div>
                    <?php if ($unread_notice_count > 0): ?>
                        <span id="noticeBadge" class="badge badge-danger">
                            <?php echo $unread_notice_count; ?> New
                        </span>
                    <?php endif; ?>
                </div>

                <div class="mb-4 p-4 rounded" style="background:var(--c-bg-surface-2);border:1px solid var(--c-border);">
                    <div class="flex items-center gap-2 mb-2 text-primary fw-700">
                        <i class="fa-solid fa-bullhorn"></i> General Info
                    </div>
                    <p class="text-sm text-body leading-relaxed">
                        <?php echo htmlspecialchars($general_notice); ?>
                    </p>
                </div>

                <?php if (count($notices) > 0): ?>
                    <div style="display:flex;flex-direction:column;gap:0.875rem;">
                        <?php foreach ($notices as $notice):
                            $bg     = $notice['is_read'] ? 'rgba(0,0,0,0.02)' : 'rgba(59,130,246,0.06)';
                            $border = $notice['is_read'] ? 'var(--c-border)'   : 'var(--c-primary)';
                        ?>
                            <div class="p-3 rounded text-sm notice-item"
                                 data-read="<?php echo $notice['is_read'] ? '1' : '0'; ?>"
                                 style="background:<?php echo $bg; ?>;border-left:4px solid <?php echo $border; ?>;">
                                <p class="text-body"><?php echo htmlspecialchars($notice['message']); ?></p>
                                <div class="text-xs text-muted mt-1">
                                    <?php echo date('M d, H:i', strtotime($notice['created_at'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted text-sm" style="font-style:italic;">No recent notifications.</p>
                <?php endif; ?>
            </div>

        </div><!-- end grid -->
    </main>
</div>

<script>
let noticesMarkedRead = <?php echo $unread_notice_count > 0 ? 'false' : 'true'; ?>;

function markNoticesRead() {
    if (noticesMarkedRead) {
        return;
    }

    const csrfInput = document.querySelector('#notice-csrf-form input[name="csrf_token"]');
    if (!csrfInput) {
        return;
    }

    fetch('api/mark_notifications_read.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ csrf_token: csrfInput.value })
    })
        .then(response => response.json())
        .then(data => {
            if (data.status !== 'success') {
                return;
            }

            noticesMarkedRead = true;

            const badge = document.getElementById('noticeBadge');
            if (badge) {
                badge.remove();
            }

            document.querySelectorAll('.notice-item[data-read="0"]').forEach((item) => {
                item.dataset.read = '1';
                item.style.background = 'rgba(0,0,0,0.02)';
                item.style.borderLeftColor = 'var(--c-border)';
            });
        })
        .catch(() => {});
}
document.addEventListener('DOMContentLoaded', function () {
    <?php if ($unread_notice_count > 0): ?>
    markNoticesRead();
    <?php endif; ?>
});
</script>
</body>
</html>
