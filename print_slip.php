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
                               p.full_name, p.matric_no, p.level, p.faculty, p.department, u.profile_pic 
                        FROM allocations a 
                        JOIN rooms r ON a.room_id = r.room_id
                        JOIN hostels h ON r.hostel_id = h.hostel_id 
                        JOIN student_profiles p ON a.student_id = p.user_id 
                        JOIN users u ON a.student_id = u.user_id
                        WHERE a.student_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if (!$data) {
    die("No allocation found. Please contact admin.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Allocation Slip - <?php echo htmlspecialchars($matric); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:wght@700&family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/print.css">
</head>
<body>
    <button class="btn-print" onclick="window.print()">Print this Slip</button>
    
    <div class="slip-container">
        <div class="header">
            <div class="uni-name">Redeemer's University</div>
            <div class="sub-head">Ede, Osun State, Nigeria</div>
            <div class="sub-head" style="margin-top: 10px; font-weight: bold; color: black;">STUDENT HOSTEL ALLOCATION SLIP</div>
        </div>
        
        <div class="photo-box">
             <?php 
                $pic = $data['profile_pic'] ?: 'default.png';
                $p_path = "uploads/profile_pics/" . htmlspecialchars($pic);
                if (!file_exists($p_path)) $p_path = "uploads/profile_pics/default.png";
             ?>
             <img src="<?php echo $p_path; ?>">
        </div>
        
        <div class="content">
            <div class="row"><div class="label">Full Name:</div><div class="value"><?php echo htmlspecialchars($data['full_name']); ?></div></div>
            <div class="row"><div class="label">Matric No:</div><div class="value"><?php echo htmlspecialchars($data['matric_no']); ?></div></div>
            <div class="row"><div class="label">Faculty:</div><div class="value"><?php echo htmlspecialchars($data['faculty']); ?></div></div>
            <div class="row"><div class="label">Department:</div><div class="value"><?php echo htmlspecialchars($data['department']); ?></div></div>
            <div class="row"><div class="label">Level:</div><div class="value"><?php echo htmlspecialchars($data['level']); ?></div></div>
            <div class="row"><div class="label">Date Issued:</div><div class="value"><?php echo date('d M Y'); ?></div></div>
        </div>
        
        <div class="alloc-box">
            <div class="alloc-header">Allocated Hall of Residence</div>
            
            <?php
            // Format Block Name
            $b_name = $data['block_name'] ?? '1';
            $b_name = str_ireplace('Block ', '', $b_name);
            if (stripos($b_name, 'Main') !== false) $b_name = '1';
            ?>
            
            <div class="alloc-hostel"><?php echo htmlspecialchars($data['hostel_name']); ?></div>
            
            <div class="alloc-grid">
                <div class="alloc-item">
                    <div class="alloc-label">BLOCK</div>
                    <div class="alloc-value"><?php echo htmlspecialchars($b_name); ?></div>
                </div>
                <div class="alloc-item">
                    <div class="alloc-label">ROOM</div>
                    <div class="alloc-value"><?php echo htmlspecialchars($data['room_number']); ?></div>
                </div>
                <div class="alloc-item">
                    <div class="alloc-label">BED</div>
                    <div class="alloc-value"><?php echo htmlspecialchars($data['bed_label']); ?></div>
                </div>
            </div>
        </div>
        
        <div style="text-align: right;">
            <div class="stamp">OFFICIALLY ALLOCATED</div>
        </div>
        
        <div class="footer">
            Printed from FairMedAlloc System generated at <?php echo date('Y-m-d H:i:s'); ?>.<br>
            Present this slip at the Hall Porter's Lodge for clearance.
        </div>
    </div>
</body>
</html>
