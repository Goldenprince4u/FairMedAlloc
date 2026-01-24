<?php
/**
 * Allocation Engine
 * =================
 * Core logic for assigning students to hostels based on fairness constraints.
 */
class AllocationEngine {
    private $conn;

    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }

    /**
     * Run the full allocation process
     */
    /**
     * Run the full allocation process
     */
    public function run() {
        // Start Transaction for Atomicity
        $this->conn->begin_transaction();

        try {
            // 1. Sync Occupancy (Safety Check)
            $this->syncRoomOccupancy();

            // 2. Fetch ONLY NEW students (Not yet allocated) AND who have PAID
            // Added JOIN to payments table to enforcing payment check
            $sql = "SELECT p.user_id, p.gender, p.faculty, m.urgency_score, m.condition_category, m.mobility_status 
                    FROM student_profiles p 
                    LEFT JOIN medical_records m ON p.user_id = m.student_id 
                    LEFT JOIN allocations a ON p.user_id = a.student_id
                    JOIN payments py ON p.user_id = py.student_id
                    WHERE a.student_id IS NULL 
                    AND py.status = 'paid'
                    ORDER BY m.urgency_score DESC";
            
            $result = $this->conn->query($sql);
            $students = $result->fetch_all(MYSQLI_ASSOC);
            $allocated_count = 0;

            // 3. Batch Processing (Python Score Calculation)
            $batch_payload = [];
            foreach ($students as $student) {
                $batch_payload[] = [
                    'id' => $student['user_id'],
                    'condition' => $student['condition_category'],
                    'urgency_score' => $student['urgency_score'],
                    'severity' => 0,
                    'mobility' => $student['mobility_status'] ?? 'Normal'
                ];
            }

            // Execute Python (Existing logic retained)
            $temp_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fairmed_batch_' . uniqid() . '.json';
            file_put_contents($temp_file, json_encode($batch_payload));
            $script_path = __DIR__ . '/../ml_models/predict.py';
            $command = "python \"$script_path\" \"$temp_file\""; // Assuming python is in PATH, if not might need full path
            $output = shell_exec($command);
            $result_data = json_decode($output, true);
            if (file_exists($temp_file)) unlink($temp_file);

            $scores_map = [];
            if (($result_data['status'] ?? '') === 'success') {
                $scores_map = $result_data['results'];
            }

            // 4. Process Allocations
            require_once 'NotificationManager.php';
            $notifier = new NotificationManager($this->conn);

            foreach ($students as $student) {
                // ... (Existing variables setup) ...
                $student_id = $student['user_id'];
                $gender = $student['gender'];
                $faculty = $student['faculty'];
                $mobility = $student['mobility_status'] ?? 'Normal';
                
                // Get Final Score
                $final_score = $scores_map[$student_id] ?? ($student['urgency_score'] ?? 0);

                // Find Best Room
                $room_id = $this->findAvailableRoom($gender, $final_score, $faculty, $mobility);

                if ($room_id) {
                    // Assign
                    $stmt = $this->conn->prepare("INSERT INTO allocations (student_id, room_id) VALUES (?, ?)");
                    $stmt->bind_param("ii", $student_id, $room_id);
                    $stmt->execute();
                    
                    // Update room occupancy
                    $this->conn->query("UPDATE rooms SET occupied_count = occupied_count + 1 WHERE room_id = $room_id");
                    $allocated_count++;
                    
                    // AUDIT LOGGING
                    $hid_res = $this->conn->query("SELECT h.hostel_id, h.name FROM rooms r JOIN hostels h ON r.hostel_id = h.hostel_id WHERE r.room_id = $room_id");
                    $h_row = $hid_res->fetch_assoc();
                    $hid = $h_row['hostel_id'] ?? null;
                    $h_name = $h_row['name'] ?? 'Hostel';

                    // NOTIFY STUDENT
                    $notifier->send($student_id, "Congratulations! You have been allocated a room in $h_name.");
                    
                    $audit_sql = "INSERT INTO algorithm_audit_logs 
                                  (student_id, input_severity, input_proximity_need, calculated_urgency_score, allocation_decision, assigned_hostel_id) 
                                  VALUES (?, 0, ?, ?, 'Allocated', ?)";
                                  
                    $prox_need = ($final_score >= 70) ? 1 : 0;
                    $stmt_audit = $this->conn->prepare($audit_sql);
                    $stmt_audit->bind_param("idii", $student_id, $prox_need, $final_score, $hid);
                    $stmt_audit->execute();
                } else {
                    // Log Missed Allocation
                    // NOTIFY STUDENT (Waitlist)
                    $notifier->send($student_id, "Update: You have been placed on the waiting list as no suitable rooms are currently available.");

                    $audit_sql = "INSERT INTO algorithm_audit_logs 
                                  (student_id, input_severity, input_proximity_need, calculated_urgency_score, allocation_decision, assigned_hostel_id) 
                                  VALUES (?, 0, ?, ?, 'No Bed', NULL)";
                    $prox_need = ($final_score >= 70) ? 1 : 0;
                    $stmt_audit = $this->conn->prepare($audit_sql);
                    $stmt_audit->bind_param("idi", $student_id, $prox_need, $final_score);
                    $stmt_audit->execute();
                }
            }

            // Commit the transaction
            $this->conn->commit();

            return [
                'status' => 'success',
                'allocated' => $allocated_count,
                'total' => count($students)
            ];

        } catch (Exception $e) {
            // Rollback if anything fails
            $this->conn->rollback();
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Helper: Find a room based on constraints
     * Tier 1: Accessibility (Ground Floor)
     * Tier 2: Urgency > 70 -> Proximal Hostels
     * Tier 3: Target Faculty -> Engineering Halls
     * Tier 4: General -> Remaining Halls
     */
    private function findAvailableRoom($gender, $score, $faculty, $mobility) {
        $gender = ($gender === 'Female') ? 'Female' : 'Male'; 
        
        // --- TIER 1: ACCESSIBILITY (GROUND FLOOR) ---
        $require_ground_floor = false;
        if (stripos($mobility, 'Wheelchair') !== false || stripos($mobility, 'Crutches') !== false || stripos($mobility, 'Walker') !== false) {
             $require_ground_floor = true;
        }

        // Define Target Hostels for each Tier
        $target_hostels = [];

        // Tier 2: High Urgency
        if ($score >= 70) {
            if ($gender === 'Male') {
                $target_hostels = ["Prophet Moses Hall", "Prophet Moses Extension Hall"];
            } else {
                $target_hostels = ["Queen Esther Extension Hall"];
            }
        } 
        // Tier 3: Faculty Based
        elseif (in_array($faculty, ['Engineering', 'Basic Medical Sciences', 'Law'])) {
             if ($gender === 'Male') {
                $target_hostels = ["Prophet Moses Engineering Hall"];
            } else {
                $target_hostels = ["Queen Esther Engineering Hall"];
            }
        }
        else {
             // General
             if ($gender === 'Male') {
                 $target_hostels = ["Prophet Moses Hall", "Prophet Moses Extension Hall", "Daniel Hall"];
             } else {
                 $target_hostels = ["Queen Esther Main Hall", "Guest House", "Mary Hall"];
             }
        }

        // Try to find room in target list first (with Ground Constraint if needed)
        foreach ($target_hostels as $h_name) {
            $rid = $this->queryRoom($h_name, $gender, $require_ground_floor);
            if ($rid) return $rid;
        }

        // Fallback Search (Any Hostel allowed for that Gender)
        // If they need ground floor, we MUST enforce it in fallback too.
        
        $sql = "SELECT r.room_id 
                FROM rooms r
                JOIN hostels h ON r.hostel_id = h.hostel_id
                WHERE h.gender_allowed = ? 
                AND r.occupied_count < r.capacity";
        
        if ($require_ground_floor) {
            $sql .= " AND (r.floor_level = 0 OR h.has_elevator = 1) ";
        }

        $sql .= " ORDER BY h.is_proximal DESC, r.floor_level ASC LIMIT 1";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("s", $gender);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($res->num_rows > 0) return $res->fetch_assoc()['room_id'];

        return null;
    }

    private function queryRoom($hostel_name, $gender, $ground_floor_only = false) {
        $sql = "SELECT r.room_id 
                FROM rooms r
                JOIN hostels h ON r.hostel_id = h.hostel_id
                WHERE h.name = ? 
                AND h.gender_allowed = ?
                AND r.occupied_count < r.capacity";
                
        if ($ground_floor_only) {
            $sql .= " AND (r.floor_level = 0 OR h.has_elevator = 1) ";
        }

        $sql .= " ORDER BY r.floor_level ASC LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ss", $hostel_name, $gender);
        $stmt->execute();
        $res = $stmt->get_result();
        return ($res->num_rows > 0) ? $res->fetch_assoc()['room_id'] : null;
    }

    /**
     * Helper: Sync Room Occupancy
     * Recalculates occupied_count for all rooms based on actual allocations table.
     */
    private function syncRoomOccupancy() {
        // 1. Reset all to 0
        $this->conn->query("UPDATE rooms SET occupied_count = 0");
        
        // 2. Count actual allocations per room
        $sql = "SELECT room_id, COUNT(*) as count FROM allocations GROUP BY room_id";
        $result = $this->conn->query($sql);
        
        // 3. Update rooms with actual counts
        if ($result) {
            $updateStmt = $this->conn->prepare("UPDATE rooms SET occupied_count = ? WHERE room_id = ?");
            while ($row = $result->fetch_assoc()) {
                $count = $row['count'];
                $rid = $row['room_id'];
                $updateStmt->bind_param("ii", $count, $rid);
                $updateStmt->execute();
            }
        }
    }
}
?>
