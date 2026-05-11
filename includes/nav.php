<?php
$current_page = basename($_SERVER['PHP_SELF']);
$role         = $_SESSION['role'] ?? 'student';

function active($p) {
    global $current_page;
    return $current_page === $p ? 'active' : '';
}

// Generate initials from username
$names    = explode(" ", $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'U');
$initials = strtoupper(substr($names[0], 0, 1));
if (isset($names[1])) {
    $initials .= strtoupper(substr($names[1], 0, 1));
} else {
    $initials .= strtoupper(substr($names[0], 1, 1));
}

// Per-request session sync: re-read profile_pic and full_name from DB so that
// a photo change on one device/session is reflected on every other already-
// logged-in session on its very next page load — no re-login required.
// $unread_count is initialised here so it is always defined even if the DB
// block below is skipped (no connection, prepare() failure, etc.).
$unread_count = 0;
if (
    isset($_SESSION['user_id']) &&
    isset($conn) &&
    $conn instanceof mysqli
) {
    $uid_nav    = (int)$_SESSION['user_id'];

    // ── Avatar + display name sync ────────────────────────────────────────────
    $sync_stmt = $conn->prepare(
        "SELECT profile_pic, full_name FROM users WHERE user_id = ? LIMIT 1"
    );
    if ($sync_stmt) {
        $sync_stmt->bind_param('i', $uid_nav);
        $sync_stmt->execute();
        $sync_row = $sync_stmt->get_result()->fetch_assoc();
        $sync_stmt->close();
        if ($sync_row) {
            // Keep session in sync — cheap write only when value actually changed
            if (($sync_row['profile_pic'] ?? null) !== ($_SESSION['profile_pic'] ?? null)) {
                $_SESSION['profile_pic'] = $sync_row['profile_pic'];
            }
            if (!empty($sync_row['full_name']) && $sync_row['full_name'] !== ($_SESSION['full_name'] ?? '')) {
                $_SESSION['full_name'] = $sync_row['full_name'];
                // Recompute initials from the refreshed name
                $names    = explode(' ', $sync_row['full_name']);
                $initials = strtoupper(substr($names[0], 0, 1));
                $initials .= isset($names[1])
                    ? strtoupper(substr($names[1], 0, 1))
                    : strtoupper(substr($names[0], 1, 1));
            }
        }
    }

    // ── Unread notification count ─────────────────────────────────────────────
    if ($role === 'student') {
        $notif_stmt = $conn->prepare(
            "SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ? AND is_read = 0"
        );
        if ($notif_stmt) {
            $notif_stmt->bind_param('i', $uid_nav);
            $notif_stmt->execute();
            $notif_res    = $notif_stmt->get_result();
            $unread_count = (int)($notif_res->fetch_assoc()['cnt'] ?? 0);
            $notif_res->free();
            $notif_stmt->close();
        }
    }
}
?>

<!-- Mobile Sidebar Toggle -->
<button class="sidebar-toggle" id="sidebarToggle" aria-label="Open navigation">
    <i class="fa-solid fa-bars"></i>
</button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebar">
    <!-- Brand Header Strip -->
    <div class="sidebar-brand">
        <div class="sidebar-brand-row flex items-center gap-3">
            <img src="assets/logo.jpeg"
                 alt="Redeemer's University Logo"
                 class="sidebar-brand-logo">
            <div class="sidebar-brand-copy">
                <h2 class="sidebar-brand-title">
                    FairMed<span style="color:var(--c-accent);">Alloc</span>
                </h2>
                <span class="sidebar-brand-kicker">Redeemer's University</span>
            </div>
        </div>
    </div>

    <nav class="nav-links flex-1">
        <a href="<?php echo $role; ?>_dashboard.php" class="nav-item <?php echo active($role . '_dashboard.php'); ?>">
            <i class="fa-solid fa-gauge-high"></i>
            Dashboard
            <?php if ($unread_count > 0): ?>
                <span class="nav-badge"><?php echo $unread_count; ?></span>
            <?php endif; ?>
        </a>

        <?php if ($role === 'admin'): ?>
            <span class="nav-section-title">Administration</span>
            <a href="view_table.php" class="nav-item <?php echo active('view_table.php'); ?>">
                <i class="fa-solid fa-table-cells"></i> View All Data
            </a>
            <a href="admin_reports.php" class="nav-item <?php echo active('admin_reports.php'); ?>">
                <i class="fa-solid fa-chart-pie"></i> System Reports
            </a>
            <a href="run_allocation.php" class="nav-item <?php echo active('run_allocation.php'); ?>">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Run Allocation
            </a>
            <span class="nav-section-title">Configuration</span>
            <a href="settings.php" class="nav-item <?php echo active('settings.php'); ?>">
                <i class="fa-solid fa-gears"></i> System Settings
            </a>
            <a href="admin_signup.php" class="nav-item <?php echo active('admin_signup.php'); ?>">
                <i class="fa-solid fa-user-plus"></i> Create Admin
            </a>
            <a href="admin_reset_password.php" class="nav-item <?php echo active('admin_reset_password.php'); ?>">
                <i class="fa-solid fa-key"></i> Reset Password
            </a>
            <a href="upload_data.php" class="nav-item <?php echo active('upload_data.php'); ?>">
                <i class="fa-solid fa-cloud-arrow-up"></i> Data Import
            </a>
            <a href="admin_profile.php" class="nav-item <?php echo active('admin_profile.php'); ?>">
                <i class="fa-solid fa-user-shield"></i> Admin Profile
            </a>
        <?php else: ?>
            <span class="nav-section-title">Student</span>
            <a href="profile.php" class="nav-item <?php echo active('profile.php'); ?>">
                <i class="fa-solid fa-user"></i> My Profile
            </a>

            <a href="print_slip.php" class="nav-item <?php echo active('print_slip.php'); ?>">
                <i class="fa-solid fa-print"></i> Allocation Slip
            </a>
            <a href="help.php" class="nav-item <?php echo active('help.php'); ?>">
                <i class="fa-solid fa-circle-question"></i> Help &amp; FAQs
            </a>
        <?php endif; ?>
    </nav>

    <!-- Sidebar Footer -->
    <div class="sidebar-footer">
        <!-- User identity row -->
        <div class="flex items-center gap-3 mt-3">
            <?php
            // Use the DB-refreshed value so all sessions stay in sync
            $nav_pic = $_SESSION['profile_pic'] ?? null;
            if ($nav_pic && $nav_pic !== 'default.png'):
            ?>
            <img src="uploads/profile_pics/<?php echo htmlspecialchars(basename($nav_pic)); ?>"
                 id="nav-avatar-img"
                 alt="Profile photo"
                 style="width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid rgba(255,255,255,0.25);"
                 onerror="this.style.display='none';document.getElementById('nav-avatar-initials').style.display='flex';">
            <?php else: ?>
            <div class="avatar-initials" id="nav-avatar-initials"><?php echo htmlspecialchars($initials); ?></div>
            <?php endif; ?>
            <div class="flex-1" style="overflow: hidden;">
                <div class="fw-700 text-sm" style="color: var(--c-text-head); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    <?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?>
                </div>
                <div class="text-xs text-muted capitalize"><?php echo $role; ?></div>
            </div>
            <a href="logout.php" title="Logout" class="nav-logout-btn" aria-label="Logout"
               style="display:flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:8px; color:var(--c-text-muted); transition: all 0.2s; flex-shrink:0;"
               onmouseover="this.style.background='rgba(248,81,73,0.1)'; this.style.color='var(--c-danger)';"
               onmouseout="this.style.background='transparent'; this.style.color='var(--c-text-muted)';">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
            </a>
        </div>
    </div>
</aside>

<script>
(function() {
    const toggle  = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (!toggle || !sidebar || !overlay) return;

    function openSidebar() {
        sidebar.classList.add('open');
        overlay.classList.add('open');
        toggle.innerHTML = '<i class="fa-solid fa-xmark"></i>';
    }

    function closeSidebar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('open');
        toggle.innerHTML = '<i class="fa-solid fa-bars"></i>';
    }

    toggle.addEventListener('click', () => {
        sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
    });

    // Close sidebar when overlay is tapped
    overlay.addEventListener('click', closeSidebar);

    // Close sidebar when a nav link is tapped (smooth UX on mobile)
    sidebar.querySelectorAll('.nav-item').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768) closeSidebar();
        });
    });
})();
</script>

<!-- ═══════════════════════════════════════════════════════
     MOBILE BOTTOM NAVIGATION BAR
     Visible only on ≤768px. Provides app-like quick access
     to the most-used pages without opening the sidebar.
     ═══════════════════════════════════════════════════════ -->
<nav class="mobile-bottom-nav" id="mobileBottomNav" aria-label="Quick navigation">

    <?php if ($role === 'admin'): ?>

        <a href="admin_dashboard.php" class="mobile-nav-item <?php echo active('admin_dashboard.php'); ?>" aria-label="Dashboard">
            <i class="fa-solid fa-gauge-high"></i>
            <span>Home</span>
        </a>
        <a href="upload_data.php" class="mobile-nav-item <?php echo active('upload_data.php'); ?>" aria-label="Import Data">
            <i class="fa-solid fa-cloud-arrow-up"></i>
            <span>Import</span>
        </a>
        <a href="run_allocation.php" class="mobile-nav-item <?php echo active('run_allocation.php'); ?>" aria-label="Run Allocation">
            <i class="fa-solid fa-wand-magic-sparkles"></i>
            <span>Allocate</span>
        </a>
        <a href="admin_reports.php" class="mobile-nav-item <?php echo active('admin_reports.php'); ?>" aria-label="Reports">
            <i class="fa-solid fa-chart-pie"></i>
            <span>Reports</span>
        </a>
        <a href="view_table.php" class="mobile-nav-item <?php echo active('view_table.php'); ?>" aria-label="View Data">
            <i class="fa-solid fa-table-cells"></i>
            <span>Data</span>
        </a>

    <?php else: ?>

        <a href="student_dashboard.php" class="mobile-nav-item <?php echo active('student_dashboard.php'); ?>" aria-label="Dashboard">
            <span class="mobile-nav-icon-wrap">
                <i class="fa-solid fa-house"></i>
                <?php if ($unread_count > 0): ?>
                    <span class="mobile-nav-badge"><?php echo $unread_count > 9 ? '9+' : $unread_count; ?></span>
                <?php endif; ?>
            </span>
            <span>Home</span>
        </a>
        <a href="profile.php" class="mobile-nav-item <?php echo active('profile.php'); ?>" aria-label="My Profile">
            <i class="fa-solid fa-user"></i>
            <span>Profile</span>
        </a>
        <a href="print_slip.php" class="mobile-nav-item <?php echo active('print_slip.php'); ?>" aria-label="Allocation Slip">
            <i class="fa-solid fa-print"></i>
            <span>My Slip</span>
        </a>
        <a href="help.php" class="mobile-nav-item <?php echo active('help.php'); ?>" aria-label="Help">
            <i class="fa-solid fa-circle-question"></i>
            <span>Help</span>
        </a>

    <?php endif; ?>

</nav>
