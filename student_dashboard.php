<?php
/**
 * Student Dashboard
 * Main interface for students to view status.
 */
session_start();
require_once 'db_config.php';

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'student') { 
    header("Location: login.php"); 
    exit(); 
}

$user_id = $_SESSION['user_id'];

// Fetch Profile
$stmt = $conn->prepare("SELECT p.*, m.condition_category, u.profile_pic FROM student_profiles p JOIN users u ON p.user_id = u.user_id LEFT JOIN medical_records m ON p.user_id = m.student_id WHERE p.user_id = ?");
$stmt->bind_param("i", $user_id);
if ($stmt->execute()) {
    $student = $stmt->get_result()->fetch_assoc();
}
$stmt->close();

// Fetch Allocation
$stmt = $conn->prepare("SELECT a.*, r.room_number, h.name as hostel_name FROM allocations a JOIN rooms r ON a.room_id = r.room_id JOIN hostels h ON r.hostel_id = h.hostel_id WHERE a.student_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$alloc = $stmt->get_result()->fetch_assoc();
$stmt->close();

$page_title = "Dashboard | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <h1 class="serif mb-1 text-3xl">My Dashboard</h1>
        <p class="text-muted mb-8">Welcome, <?php echo htmlspecialchars($student['full_name'] ?? $_SESSION['username']); ?></p>

        <!-- Allocation Status Card -->
        <div class="glass-card mb-8 p-0 overflow-hidden relative"> <!-- Used glass-card -->
            <?php if ($alloc): ?>
                <div class="absolute left-0 top-0 bottom-0 w-2 bg-success"></div>
                <div class="p-8">
                    <h3 class="serif mb-4 text-xl">Allocation Status</h3>
                    
                    <div class="flex items-start gap-4 mb-6">
                        <i class="fa-solid fa-circle-check text-success text-xl mt-1"></i>
                        <div>
                            <div class="fw-700 text-success text-lg mb-2">Allocation Successful</div>
                            <p class="text-muted">You have been placed in <strong class="text-slate-800"><?php echo htmlspecialchars($alloc['hostel_name']); ?></strong>.</p>
                        </div>
                    </div>

                    <div class="border border-dashed border-gray-300 rounded p-4 bg-slate-50 inline-block min-w-[200px] mb-6">
                        <div class="text-xs text-muted uppercase tracking-wider mb-1">ROOM NUMBER</div>
                        <div class="text-3xl fw-700 text-primary">Rm <?php echo htmlspecialchars($alloc['room_number']); ?></div>
                    </div>

                    <div>
                        <a href="print_slip.php" target="_blank" class="btn btn-secondary text-primary hover:bg-blue-50">
                            <i class="fa-solid fa-print mr-2"></i> Print Official Slip
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="absolute left-0 top-0 bottom-0 w-2 bg-warning"></div>
                <div class="p-8">
                    <h3 class="serif mb-4 text-xl">Allocation Status</h3>
                    
                    <div class="flex items-start gap-4 mb-6">
                        <i class="fa-solid fa-clock text-warning text-xl mt-1"></i>
                        <div>
                            <div class="fw-700 text-warning text-lg mb-2">Allocation Pending</div>
                            <p class="text-muted">Your room allocation is currently being processed by the algorithm.</p>
                        </div>
                    </div>
                    
                     <div class="border border-dashed border-gray-300 rounded p-4 bg-slate-50 inline-block min-w-[200px] mb-6">
                        <div class="text-xs text-muted uppercase tracking-wider mb-1">STATUS</div>
                        <div class="text-3xl fw-700 text-warning">Waiting...</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Bottom Grid: Profile & Notices -->
        <div class="grid grid-dashboard-custom">
            
            <!-- My Profile Preview -->
            <div class="glass-card">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="serif text-xl mb-0">My Profile</h3>
                    <a href="profile.php" class="text-sm text-primary fw-700 hover:underline"><i class="fa-solid fa-pen"></i> Edit</a>
                </div>

                <div class="flex items-start gap-6">
                    <!-- Profile Pic -->
                     <img src="uploads/profile_pics/<?php echo $student['profile_pic'] ?: 'default.png'; ?>" class="rounded-full object-cover border-4 border-slate-50" style="width: 80px; height: 80px; border-radius: 50%;">

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
                            <div class="text-muted">Health:</div>
                            <?php if ($student['condition_category'] && $student['condition_category'] !== 'None'): ?>
                                <div class="text-danger fw-700"><?php echo htmlspecialchars($student['condition_category']); ?></div>
                            <?php else: ?>
                                <div class="text-success fw-700">No declared conditions</div>
                            <?php endif; ?>
                        </div>
                     </div>
                </div>
            </div>

            <!-- Notices -->
            <div class="glass-card">
                <h3 class="serif text-xl mb-6">Notices</h3>

                <div class="mb-6">
                    <div class="flex items-center gap-2 mb-1 text-primary fw-700">
                        <i class="fa-solid fa-bullhorn"></i> Registration Closing
                    </div>
                    <p class="text-sm text-muted leading-relaxed">
                        Hostel portal closes on Friday. Ensure all medical documents are verified before the deadline.
                    </p>
                </div>

                <div class="mb-4">
                    <div class="flex items-center gap-2 mb-1 text-primary fw-700">
                         <i class="fa-solid fa-user-doctor"></i> Health Center
                    </div>
                    <p class="text-sm text-muted leading-relaxed">
                        Students with severe mobility constraints should visit the clinic for manual verification.
                    </p>
                </div>
            </div>

        </div>
    </main>
</div>
</body>
</html>