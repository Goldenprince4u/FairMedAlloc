<?php
/**
 * Student Dashboard
 * Main interface for students to view status.
 */
session_start();
require_once 'db_config.php';

require_once 'includes/Student.php';

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'student') { 
    header("Location: login.php"); 
    exit(); 
}

$user_id = $_SESSION['user_id'];
$studentObj = new Student($conn, $user_id);

// Fetch Data using Model
$student = $studentObj->getProfile();
$alloc = $studentObj->getAllocation();
$has_paid = $studentObj->hasPaid();

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
            <?php if ($has_paid): ?>
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
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="serif mb-4 text-xl">Allocation Status</h3>
                                <div class="flex items-start gap-4 mb-6">
                                    <i class="fa-solid fa-clock text-warning text-xl mt-1"></i>
                                    <div>
                                        <div class="fw-700 text-warning text-lg mb-2">Allocation Pending</div>
                                        <p class="text-muted">Payment verified. Your room allocation is being processed.</p>
                                    </div>
                                </div>
                            </div>
                            <span class="badge badge-success px-4 py-2"><i class="fa-solid fa-check mr-2"></i>School Fees Paid</span>
                        </div>
                        
                         <div class="border border-dashed border-gray-300 rounded p-4 bg-slate-50 inline-block min-w-[200px] mb-6">
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
                            <p class="text-muted">You must pay your school fees before a room can be allocated to you.</p>
                        </div>
                    </div>

                    <div class="alert alert-info mb-4">
                        <i class="fa-solid fa-info-circle mr-2"></i> Fee: ₦50,000
                    </div>

                    <?php require_once 'includes/security_helper.php'; csrf_field(); ?>

                    <button onclick="payFees()" id="payBtn" class="btn btn-primary">
                        <i class="fa-solid fa-credit-card mr-2"></i> Pay School Fees (₦50,000)
                    </button>
                    <div id="payMsg" class="mt-4 hidden"></div>
                </div>
            <?php endif; ?>
        </div>
        
        <script>
        function payFees() {
            const btn = document.getElementById('payBtn');
            const msg = document.getElementById('payMsg');
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Processing...';
            btn.disabled = true;

            fetch('api/pay_simulation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    csrf_token: document.querySelector('input[name="csrf_token"]').value
                })
            })
                .then(res => res.json())
                .then(data => {
                    if(data.status === 'success') {
                        btn.innerHTML = '<i class="fa-solid fa-check mr-2"></i> Paid Successfully';
                        btn.classList.remove('btn-primary');
                        btn.classList.add('bg-green-600', 'text-white');
                        
                        // Show detailed allocation message
                        msg.innerHTML = `<span class="text-success">${data.message}</span>`;
                        msg.classList.remove('hidden');
                        
                        setTimeout(() => window.location.reload(), 2000);
                    } else {
                        btn.innerHTML = 'Try Again';
                        btn.disabled = false;
                        msg.innerHTML = `<span class="text-danger">${data.message}</span>`;
                        msg.classList.remove('hidden');
                    }
                });
        }
        </script>

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

                <!-- Dynamic Notifications -->
                <?php
                require_once 'includes/NotificationManager.php';
                $notifier = new NotificationManager($conn);
                $notices = $notifier->getUnread($user_id);

                if (count($notices) > 0) {
                    foreach ($notices as $notice) {
                        echo '<div class="mb-4 p-3 bg-blue-50 border-l-4 border-primary rounded text-sm">';
                        echo '<div class="fw-700 text-primary mb-1"><i class="fa-solid fa-bell mr-2"></i>New Alert</div>';
                        echo '<p class="text-slate-700">' . htmlspecialchars($notice['message']) . '</p>';
                        echo '<div class="text-xs text-muted mt-1">' . date('M d, H:i', strtotime($notice['created_at'])) . '</div>';
                        echo '</div>';
                    }
                } else {
                    echo '<p class="text-muted text-sm italic">No new notifications.</p>';
                }
                ?>

                <div class="mt-6 border-t pt-4">
                    <div class="flex items-center gap-2 mb-1 text-primary fw-700">
                        <i class="fa-solid fa-bullhorn"></i> General Info
                    </div>
                    <p class="text-sm text-muted leading-relaxed">
                        Hostel portal closes on Friday. Ensure all medical documents are verified before the deadline.
                    </p>
                </div>
            </div>

        </div>
    </main>
</div>
</body>
</html>