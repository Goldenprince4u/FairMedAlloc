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
                               p.full_name, p.matric_no, p.level, p.faculty, u.profile_pic 
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
    <style>
        body { font-family: 'Inter', sans-serif; background: #525659; padding: 20px; text-align: center; }
        .slip-container {
            background: white; width: 210mm; min-height: 297mm; /* A4 */
            margin: 0 auto; padding: 40px; text-align: left;
            box-shadow: 0 0 10px rgba(0,0,0,0.5);
            position: relative;
        }
        .header { text-align: center; border-bottom: 2px solid #1e3a8a; padding-bottom: 20px; margin-bottom: 30px; }
        .uni-name { font-family: 'Merriweather', serif; font-size: 24px; color: #1e3a8a; font-weight: bold; text-transform: uppercase; }
        .sub-head { color: #555; font-size: 14px; margin-top: 5px; }
        
        .photo-box { position: absolute; top: 40px; right: 40px; width: 120px; height: 120px; border: 1px solid #ccc; }
        .photo-box img { width: 100%; height: 100%; object-fit: cover; }
        
        .content { margin-top: 40px; }
        .row { display: flex; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 5px; }
        .label { width: 150px; font-weight: 600; color: #555; }
        .value { flex: 1; font-weight: 500; font-family: monospace; font-size: 16px; }
        
        .alloc-box {
            margin-top: 40px; border: 2px solid #1e3a8a; padding: 20px; background: #f0f9ff;
            text-align: center;
        }
        .alloc-title { color: #1e3a8a; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; }
        .alloc-hostel { font-size: 28px; font-weight: bold; margin: 10px 0; color: #333; }
        .alloc-room { font-size: 18px; color: #555; }
        
        .footer { margin-top: 80px; text-align: center; font-size: 12px; color: #999; border-top: 1px solid #eee; padding-top: 20px; }
        .stamp { 
            margin-top: 50px; border: 3px double #c2410c; color: #c2410c; 
            padding: 10px; display: inline-block; transform: rotate(-5deg); font-weight: bold; 
            text-transform: uppercase; font-size: 18px; opacity: 0.8;
        }
        
        @media print {
            body { background: white; padding: 0; }
            .slip-container { box-shadow: none; width: 100%; }
            .btn-print { display: none; }
        }
    </style>
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
            <div class="row"><div class="label">Level:</div><div class="value"><?php echo htmlspecialchars($data['level']); ?></div></div>
            <div class="row"><div class="label">Date Issued:</div><div class="value"><?php echo date('d M Y'); ?></div></div>
        </div>
        
        <div class="alloc-box">
            <div class="alloc-title">Allocated Hall of Residence</div>
            <div class="alloc-hostel"><?php echo htmlspecialchars($data['hostel_name']); ?>, <?php echo htmlspecialchars($data['block_name']); ?></div>
            <div class="alloc-room">
                Room No: <strong><?php echo htmlspecialchars($data['room_number']); ?></strong>
                <span style="font-size: 0.6em; vertical-align: middle;">(<?php echo htmlspecialchars($data['bed_label']); ?>)</span>
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
