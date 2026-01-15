<?php
$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? 'student';
$pic = "uploads/profile_pics/" . ($_SESSION['profile_pic'] ?? 'default.png');
if(!file_exists($pic)) $pic = "uploads/profile_pics/default.png";

function active($p) { global $current_page; return $current_page == $p ? 'active' : ''; }
?>

<aside class="sidebar">
    <div class="brand">
        <h2 class="serif">FairMedAlloc</h2>
        <span>Redeemer's University</span>
    </div>

    <nav class="nav-links" style="flex: 1;">
        <a href="<?php echo $role; ?>_dashboard.php" class="nav-item <?php echo active($role.'_dashboard.php'); ?>">
            <i class="fa-solid fa-gauge-high"></i> Dashboard
        </a>

        <?php if ($role === 'admin'): ?>
            <a href="view_table.php" class="nav-item <?php echo active('view_table.php'); ?>">
                <i class="fa-solid fa-table-cells"></i> View All Data
            </a>
            <a href="run_allocation.php" class="nav-item <?php echo active('run_allocation.php'); ?>">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Run Allocation
            </a>
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
            <a href="profile.php" class="nav-item <?php echo active('profile.php'); ?>">
                <i class="fa-solid fa-user"></i> My Profile
            </a>
            <a href="print_slip.php" class="nav-item">
                <i class="fa-solid fa-print"></i> Allocation Slip
            </a>
        <?php endif; ?>
    </nav>

    <?php
        // Generate Initials
        $names = explode(" ", $_SESSION['username']);
        $initials = strtoupper(substr($names[0], 0, 1));
        if(isset($names[1])) $initials .= strtoupper(substr($names[1], 0, 1));
        else $initials .= strtoupper(substr($names[0], 1, 1));
    ?>
    <div class="sidebar-footer flex items-center gap-3 mt-auto p-4 border-t border-gray-100">
        <div class="avatar-initials"><?php echo $initials; ?></div>
        <div style="flex:1;">
            <div class="fw-700 text-sm leading-tight"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
            <div class="text-xs text-muted capitalize"><?php echo $role; ?></div>
        </div>
        <a href="logout.php" class="text-muted hover:text-danger" title="Logout">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
        </a>
    </div>
</aside>
