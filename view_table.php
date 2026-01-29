<?php
/**
 * Allocation Matrix
 * Comprehensive list of all students and their allocation status.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

// Auth Guard
if (($_SESSION['role'] ?? '') !== 'admin') { header("Location: login.php"); exit(); }

// Pagination Setup
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Count Total Records
$count_sql = "SELECT COUNT(*) as total FROM student_profiles p JOIN users u ON p.user_id = u.user_id";
$total_result = $conn->query($count_sql);
$total_rows = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

// Fetch Data with Limit
$query = "
    SELECT 
        p.user_id, p.full_name, p.matric_no, p.faculty, p.department, p.level,
        m.urgency_score, m.condition_category, m.mobility_status,
        h.name as hostel_name, r.room_number,
        u.profile_pic, p.email
    FROM student_profiles p 
    JOIN users u ON p.user_id = u.user_id 
    LEFT JOIN medical_records m ON p.user_id = m.student_id 
    LEFT JOIN allocations a ON p.user_id = a.student_id 
    LEFT JOIN rooms r ON a.room_id = r.room_id 
    LEFT JOIN hostels h ON r.hostel_id = h.hostel_id
    ORDER BY m.urgency_score DESC, p.matric_no ASC 
    LIMIT $limit OFFSET $offset
";
$result = $conn->query($query);

// Fetch Hostels for Manual Allocation
$hostels_result = $conn->query("SELECT hostel_id, name, gender_allowed FROM hostels");
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
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="serif mb-1 text-3xl">Allocation Matrix</h1>
                <p class="text-muted">Master list of student data and allocation decisions.</p>
            </div>
            
            <div class="flex gap-3">
            <div class="flex gap-3">
                <div class="relative">
                    <i class="fa-solid fa-search absolute left-3 top-3 text-muted"></i>
                    <input type="text" id="searchInput" placeholder="Search Matrix..." class="input pl-10 w-[250px]">
                </div>
                <button id="exportBtn" class="btn btn-primary">
                    <i class="fa-solid fa-download"></i> Export CSV
                </button>
            </div>
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
                                        <?php if(($row['urgency_score'] ?? 0) > 70): ?>
                                            <span class="badge badge-danger">
                                                <i class="fa-solid fa-heart-pulse"></i> HIGH RISK (<?php echo $row['urgency_score']; ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-success">
                                                <i class="fa-solid fa-check"></i> NORMAL
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if(!empty($row['condition_category']) && $row['condition_category'] !== 'None'): ?>
                                            <div class="text-xs text-muted mt-1">Condition: <?php echo htmlspecialchars($row['condition_category']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($row['hostel_name']): ?>
                                            <div class="text-sm fw-700 text-primary">
                                                <?php echo htmlspecialchars($row['hostel_name']); ?>
                                            </div>
                                            <div class="text-xs text-muted">Room <?php echo htmlspecialchars($row['room_number']); ?></div>
                                        <?php else: ?>
                                            <span class="text-xs text-muted" style="font-style: italic;">Pending Allocation</span>
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
                    Showing <?php echo ($offset + 1); ?>-<?php echo min($offset + $limit, $total_rows); ?> of <?php echo $total_rows; ?> entries
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
<div id="assignModal" class="modal-overlay hidden" style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 50; display: flex; align-items: center; justify-content: center;">
    <div class="card p-6 w-full max-w-md bg-white shadow-xl rounded-lg">
        <h3 class="text-xl font-bold mb-4">Manual Room Allocation</h3>
        <p class="text-sm text-muted mb-4">Assigning room for: <strong id="assignStudentName">...</strong></p>
        
        <form id="assignForm">
            <input type="hidden" id="assignStudentId" name="student_id">
            
            <div class="mb-4">
                <label class="block text-sm font-bold mb-2">Select Hostel</label>
                <select id="assignHostel" class="input w-full" required>
                    <option value="">-- Choose Hostel --</option>
                    <?php foreach($hostels as $h): ?>
                        <option value="<?php echo $h['hostel_id']; ?>"><?php echo htmlspecialchars($h['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="mb-6">
                <label class="block text-sm font-bold mb-2">Select Room</label>
                <select id="assignRoom" class="input w-full" disabled required>
                    <option value="">-- Select Hostel First --</option>
                </select>
            </div>
            
            <div class="flex justify-end gap-3">
                <button type="button" id="closeModalBtn" class="btn btn-outline">Cancel</button>
                <button type="submit" class="btn btn-primary">Assign Room</button>
            </div>
        </form>
    </div>
</div>

<script src="js/allocation_matrix.js"></script>

</body>
</html>
