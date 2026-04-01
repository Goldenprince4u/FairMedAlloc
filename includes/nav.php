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

// Count unread notifications for badge
$unread_count = 0;
if ($role === 'student' && isset($_SESSION['user_id'])) {
    $uid_nav = (int)$_SESSION['user_id'];
    $notif_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ? AND is_read = 0");
    $notif_stmt->bind_param("i", $uid_nav);
    $notif_stmt->execute();
    $unread_count = (int)$notif_stmt->get_result()->fetch_assoc()['cnt'];
}
?>

<!-- Mobile Sidebar Toggle -->
<button class="sidebar-toggle" id="sidebarToggle" aria-label="Open navigation">
    <i class="fa-solid fa-bars"></i>
</button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="brand">
        <h2 class="serif">FairMed<span style="color: var(--c-accent);">Alloc</span></h2>
        <span>Redeemer's University</span>
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
                <i class="fa-solid fa-circle-question"></i> Help & FAQs
            </a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer flex items-center gap-3">
        <div class="avatar-initials"><?php echo htmlspecialchars($initials); ?></div>
        <div class="flex-1" style="overflow: hidden;">
            <div class="fw-700 text-sm" style="color: var(--c-text-head); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                <?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?>
            </div>
            <div class="text-xs text-muted capitalize"><?php echo $role; ?></div>
        </div>
        <a href="logout.php" title="Logout" style="color: var(--c-text-light); font-size: 1rem; transition: color 0.2s;">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
        </a>
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

    overlay.addEventListener('click', closeSidebar);
})();
</script>
