<?php
/**
 * Allocation Slip
 * ===============
 * A printable document proving allocation.
 */

session_start();
require_once 'db_config.php';

// Auth
if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'student') { 
    header("Location: login.php"); exit(); 
}

$user_id = $_SESSION['user_id'];

// Fetch Allocation
$stmt = $conn->prepare("SELECT a.*, h.name as hostel_name, h.block_name, r.room_number,
                               u.full_name, u.username AS matric_no, p.level, u.profile_pic,
                               d.name as department, f.name as faculty
                        FROM allocations a 
                        JOIN rooms r ON a.room_id = r.room_id
                        JOIN hostels h ON r.hostel_id = h.hostel_id 
                        JOIN student_profiles p ON a.student_id = p.user_id 
                        JOIN users u ON a.student_id = u.user_id
                        JOIN departments d ON p.department_id = d.department_id
                        JOIN faculties f ON d.faculty_id = f.faculty_id
                        WHERE a.student_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if (!$data) {
    http_response_code(404);
    echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>No Allocation | FairMedAlloc</title>
          <style>*{box-sizing:border-box;margin:0;padding:0;}body{font-family:Arial,Helvetica,sans-serif;background:#F5F7FA;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:2rem;}.box{background:#fff;border:1px solid #DDE1E7;border-radius:8px;padding:3rem 2.5rem;max-width:480px;width:100%;text-align:center;}.icon{font-size:2.5rem;color:var(--c-warning);margin-bottom:1.25rem;}.title{font-size:1.25rem;font-weight:700;color:#1e293b;margin-bottom:0.75rem;}.msg{font-size:0.9rem;color:#64748b;line-height:1.65;margin-bottom:1.75rem;}.btn{display:inline-flex;align-items:center;gap:0.5rem;background:var(--c-primary);color:#fff;padding:0.65rem 1.25rem;border-radius:6px;font-weight:600;font-size:0.875rem;text-decoration:none;}</style></head>
          <body><div class='box'><div class='icon'><i class='fa-solid fa-triangle-exclamation'></i></div>
          <h2 class='title'>No Allocation Found</h2>
          <p class='msg'>Your hostel allocation has not been processed yet. Ensure your school fee payment has been confirmed and your profile is complete. If this persists, contact the Student Affairs Division.</p>
          <a href='student_dashboard.php' class='btn'>&#8592; Return to Dashboard</a></div>
          <script src='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js'></script></body></html>";
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Allocation Slip — <?php echo htmlspecialchars($data['matric_no']); ?> | FairMedAlloc</title>
    <link rel="stylesheet" href="css/print.css">
</head>
<body>

    <button class="btn-print" onclick="window.print()" id="print-slip-btn">
        🖨 Print Official Slip
    </button>

    <div class="slip-container">

        <!-- Watermark + real content wrapper -->
        <div class="slip-inner">

            <!-- ── Full-width Navy Header ── -->
            <div class="header">
                <img src="assets/logo.jpeg"
                     alt="Redeemer's University Logo"
                     class="header-logo">
                <div class="header-text">
                    <div class="uni-name">Redeemer's University</div>
                    <div class="sub-head">Office of Student Affairs &amp; Accommodation — Ede, Osun State, Nigeria</div>
                </div>
            </div>

            <!-- ── Gold title bar ── -->
            <div class="slip-title-bar">Official Student Hostel Allocation Slip</div>

            <!-- ── Photo: absolute top-right ── -->
            <div class="photo-box">
                <?php
                    $pic    = $data['profile_pic'] ?: 'default.png';
                    $p_path = 'uploads/profile_pics/' . basename($pic);
                    if (!file_exists($p_path)) $p_path = 'uploads/profile_pics/default.png';
                ?>
                <img src="<?php echo htmlspecialchars($p_path); ?>" alt="Student Photo">
            </div>

            <!-- ── Student data rows ── -->
            <div class="slip-body">
                <div class="content">
                    <div class="row"><div class="label">Full Name</div><div class="value"><?php echo htmlspecialchars($data['full_name']); ?></div></div>
                    <div class="row"><div class="label">Matric No</div><div class="value"><?php echo htmlspecialchars($data['matric_no']); ?></div></div>
                    <div class="row"><div class="label">Faculty</div><div class="value"><?php echo htmlspecialchars($data['faculty']); ?></div></div>
                    <div class="row"><div class="label">Department</div><div class="value"><?php echo htmlspecialchars($data['department']); ?></div></div>
                    <div class="row"><div class="label">Level</div><div class="value"><?php echo htmlspecialchars($data['level']); ?> Level</div></div>
                    <div class="row"><div class="label">Date Issued</div><div class="value"><?php echo date('d F Y'); ?></div></div>
                </div>

                <!-- ── Allocation box ── -->
                <div class="alloc-box">
                    <div class="alloc-header">Allocated Hall of Residence</div>

                    <?php
                        $b_name = $data['block_name'] ?? '1';
                        $b_name = str_ireplace('Block ', '', $b_name);
                        if (stripos($b_name, 'Main') !== false) $b_name = '1';
                    ?>

                    <div class="alloc-hostel"><?php echo htmlspecialchars($data['hostel_name']); ?></div>

                    <div class="alloc-grid">
                        <div class="alloc-item">
                            <div class="alloc-label">Block</div>
                            <div class="alloc-value"><?php echo htmlspecialchars($b_name); ?></div>
                        </div>
                        <div class="alloc-item">
                            <div class="alloc-label">Room</div>
                            <div class="alloc-value"><?php echo htmlspecialchars($data['room_number']); ?></div>
                        </div>
                        <div class="alloc-item">
                            <div class="alloc-label">Bed</div>
                            <div class="alloc-value"><?php echo htmlspecialchars($data['bed_label'] ?? '—'); ?></div>
                        </div>
                    </div>
                </div>

                <!-- ── Stamp & footer ── -->
                <div style="text-align:right;margin-top:30px;">
                    <div class="stamp">Officially Allocated</div>
                </div>
            </div><!-- /slip-body -->

            <div class="footer">
                Generated by FairMedAlloc System on <?php echo date('Y-m-d H:i:s'); ?><br>
                Present this slip at the Hall Porter's Lodge for room clearance. This document is valid for one academic session only.
            </div>

        </div><!-- /slip-inner -->
    </div><!-- /slip-container -->

</body>
</html>
