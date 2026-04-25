<?php
/**
 * Allocation Matrix
 * Comprehensive list of all students and their allocation status.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

// Auth Guard
if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

$medium_urgency_threshold = 40;
$threshold_result = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'urgency_threshold_proximal'");
$threshold_row = $threshold_result ? $threshold_result->fetch_assoc() : null;
$high_urgency_threshold = (float)($threshold_row['setting_value'] ?? 75);

// Pagination Setup
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Count Total Records
$count_sql = "SELECT COUNT(*) as total FROM student_profiles p JOIN users u ON p.user_id = u.user_id";
$total_result = $conn->query($count_sql);
$total_rows = (int)($total_result->fetch_assoc()['total'] ?? 0);
$total_pages = max(1, ceil($total_rows / $limit));
// Clamp page to valid range
$page = max(1, min($page, $total_pages));
$offset = ($page - 1) * $limit;

// Fetch Data with Limit
$query_sql = "
    SELECT 
        p.user_id, u.full_name, u.username AS matric_no, p.level,
        d.name as department, f.name as faculty,
        p.gender,
        m.urgency_score, m.condition_category, m.mobility_status, m.severity_level,
        h.name as hostel_name, h.block_name, r.room_number,
        u.profile_pic, u.email
    FROM student_profiles p 
    JOIN users u ON p.user_id = u.user_id 
    JOIN departments d ON p.department_id = d.department_id
    JOIN faculties f ON d.faculty_id = f.faculty_id
    LEFT JOIN medical_records m ON p.user_id = m.student_id 
    LEFT JOIN allocations a ON p.user_id = a.student_id 
    LEFT JOIN rooms r ON a.room_id = r.room_id 
    LEFT JOIN hostels h ON r.hostel_id = h.hostel_id
    ORDER BY m.urgency_score DESC, u.username ASC 
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($query_sql);
$stmt->bind_param("ii", $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();

// Fetch Hostels for Manual Allocation (exclude postgrad and foundation blocks)
$hostels_result = $conn->query(
    "SELECT hostel_id, name, block_name, gender_allowed 
     FROM hostels 
     WHERE is_postgrad = 0 AND is_foundation = 0 
     ORDER BY gender_allowed, name, CAST(block_name AS UNSIGNED)"
);
$hostels = [];
while ($h = $hostels_result->fetch_assoc()) {
    $hostels[] = $h;
}

$page_title = "Allocation Matrix | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-info">
                <h1>Allocation Matrix</h1>
                <p class="text-muted">Master list of all student records and allocation decisions.</p>
            </div>
            <div style="display:flex;gap:0.75rem;align-items:center;">
                <div style="position:relative;">
                    <i class="fa-solid fa-search" style="position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);color:var(--c-text-light);font-size:0.8rem;"></i>
                    <input type="text" id="searchInput" placeholder="Search name, matric, hostel…"
                           style="padding-left:2.25rem;width:260px;" class="input">
                </div>
                <button id="exportBtn" class="btn btn-primary">
                    <i class="fa-solid fa-download"></i> Export CSV
                </button>
            </div>
        </div>

        <div class="card p-0 overflow-hidden">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Student Details</th>
                            <th>Academic Info</th>
                            <th>Medical Priority</th>
                            <th>Allocation Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div class="fw-700"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                        <div class="text-xs text-muted"><?php echo htmlspecialchars($row['matric_no']); ?></div>
                                    </td>
                                    <td>
                                        <div class="text-sm"><?php echo htmlspecialchars($row['faculty']); ?></div>
                                        <div class="text-xs text-muted"><?php echo htmlspecialchars($row['department']); ?> • <?php echo $row['level']; ?>L</div>
                                    </td>
                                    <td>
                                        <?php 
                                        $score = (float)($row['urgency_score'] ?? 0);
                                        $severity = $row['severity_level'] ?? 'Low';
                                        if ($score >= $high_urgency_threshold): ?>
                                            <span class="badge badge-danger">
                                                <i class="fa-solid fa-heart-pulse"></i> HIGH (<?php echo number_format($score, 1); ?>)
                                            </span>
                                        <?php elseif ($score >= $medium_urgency_threshold): ?>
                                            <span class="badge badge-warning" style="color:var(--c-primary-dark);">
                                                <i class="fa-solid fa-triangle-exclamation"></i> MEDIUM (<?php echo number_format($score, 1); ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-success">
                                                <i class="fa-solid fa-check"></i> LOW (<?php echo number_format($score, 1); ?>)
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if(!empty($row['condition_category']) && $row['condition_category'] !== 'None'): ?>
                                            <div class="text-xs text-muted mt-1"><?php echo htmlspecialchars($row['condition_category']); ?> &bull; <?php echo htmlspecialchars($severity); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($row['hostel_name']): ?>
                                            <div class="text-sm fw-700 text-primary">
                                                <?php echo htmlspecialchars($row['hostel_name']); ?> — Blk <?php echo htmlspecialchars($row['block_name']); ?>
                                            </div>
                                            <div class="text-xs text-muted">Room <?php echo htmlspecialchars($row['room_number']); ?></div>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right">
                                        <button type="button" 
                                                class="btn btn-sm btn-outline text-primary btn-assign-trigger relative z-10" 
                                                data-id="<?php echo $row['user_id']; ?>" 
                                                data-name="<?php echo htmlspecialchars($row['full_name']); ?>"
                                                title="Manual Allocation">
                                            <i class="fa-solid fa-bed"></i> Assign Room
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-8 text-muted">
                                    <i class="fa-regular fa-folder-open mb-2" style="font-size: 2rem; opacity: 0.5;"></i>
                                    <p>No student data found for the current session.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <div class="p-4 flex justify-between items-center text-xs text-muted" style="border-top: 1px solid var(--c-border);">
                <div>
                    <?php if ($total_rows > 0): ?>
                        Showing <?php echo ($offset + 1); ?>–<?php echo min($offset + $limit, $total_rows); ?> of <?php echo $total_rows; ?> entries
                    <?php else: ?>
                        No entries found
                    <?php endif; ?>
                </div>
                <div class="flex gap-2">
                    <a href="?page=<?php echo max(1, $page - 1); ?>" class="btn btn-sm btn-secondary <?php echo ($page <= 1) ? 'opacity-50 pointer-events-none' : ''; ?>">
                        Previous
                    </a>
                    <button class="btn btn-sm btn-primary"><?php echo $page; ?></button>
                    <a href="?page=<?php echo min($total_pages, $page + 1); ?>" class="btn btn-sm btn-secondary <?php echo ($page >= $total_pages) ? 'opacity-50 pointer-events-none' : ''; ?>">
                        Next
                    </a>
                </div>
            </div>
        </div>
        
    </main>
</div>

    <!-- Manual Allocation Modal -->
    <div id="assignModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:50;align-items:center;justify-content:center;">
        <div class="card" style="max-width:480px;width:100%;background:var(--c-bg-surface);box-shadow:var(--shadow-lg);padding:2rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;padding-bottom:1rem;border-bottom:1px solid var(--c-border);">
                <h3 style="margin:0;font-size:1rem;font-weight:700;">Manual Room Allocation</h3>
                <button type="button" id="closeModalIconBtn" style="background:none;border:none;cursor:pointer;color:var(--c-text-muted);font-size:1.1rem;padding:0.25rem;" aria-label="Close modal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <p style="font-size:0.875rem;color:var(--c-text-muted);margin-bottom:1.25rem;">Assigning room for: <strong id="assignStudentName" style="color:var(--c-text-head);">...</strong></p>
        
        <form id="assignForm">
            <input type="hidden" id="assignStudentId" name="student_id">
            <?php csrf_field(); ?>
            
            <div class="mb-4">
                <label class="block text-sm font-bold mb-2">Select Hostel</label>
                <select id="assignHostel" name="hostel_id" class="input w-full" required>
                    <option value="">-- Choose Hostel --</option>
                    <?php 
                    $last_gender = '';
                    foreach($hostels as $h): 
                        // Add optgroup separators by gender
                        if ($h['gender_allowed'] !== $last_gender):
                            if ($last_gender !== '') echo '</optgroup>';
                            echo '<optgroup label="' . htmlspecialchars($h['gender_allowed']) . ' Hostels">';
                            $last_gender = $h['gender_allowed'];
                        endif;
                    ?>
                        <option value="<?php echo $h['hostel_id']; ?>">
                            <?php echo htmlspecialchars($h['name']); ?> — Block <?php echo htmlspecialchars($h['block_name']); ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if ($last_gender !== '') echo '</optgroup>'; ?>
                </select>
            </div>
            
            <div class="mb-6">
                <label class="block text-sm font-bold mb-2">Select Room</label>
                <select id="assignRoom" name="room_id" class="input w-full" disabled required>
                    <option value="">-- Select Hostel First --</option>
                </select>
                <div id="roomAvailInfo" class="text-xs text-muted mt-1" style="display:none;"></div>
            </div>
            
            <div class="flex justify-end gap-3">
                <button type="button" id="closeModalCancelBtn" class="btn btn-outline">Cancel</button>
                <button type="submit" class="btn btn-primary">Assign Room</button>
            </div>
        </form>
    </div>
</div>

<script src="js/allocation_matrix.js"></script>

</body>
</html>
