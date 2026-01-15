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

// Fetch Data
$query = "
    SELECT 
        p.full_name, p.matric_no, p.faculty, p.department, p.level,
        m.urgency_score, m.condition_category,
        h.name as hostel_name, r.room_number
    FROM student_profiles p 
    JOIN users u ON p.user_id = u.user_id 
    LEFT JOIN medical_records m ON p.user_id = m.student_id 
    LEFT JOIN allocations a ON p.user_id = a.student_id 
    LEFT JOIN rooms r ON a.room_id = r.room_id 
    LEFT JOIN hostels h ON r.hostel_id = h.hostel_id
    ORDER BY m.urgency_score DESC, p.matric_no ASC 
    LIMIT 200
";
$result = $conn->query($query);

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
                <button class="btn btn-outline hover:bg-slate-50">
                    <i class="fa-solid fa-filter text-muted"></i> Filter
                </button>
                <button class="btn btn-primary">
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
                                    <td>
                                        <button class="btn btn-sm btn-secondary" title="View Details">
                                            <i class="fa-solid fa-circle-info"></i>
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
                <div>Showing 1-<?php echo $result->num_rows; ?> of <?php echo $result->num_rows; ?> entries</div>
                <div class="flex gap-2">
                    <button class="btn btn-sm btn-secondary">Previous</button>
                    <button class="btn btn-sm btn-primary">1</button>
                    <button class="btn btn-sm btn-secondary">Next</button>
                </div>
            </div>
        </div>
        
    </main>
</div>
</body>
</html>
