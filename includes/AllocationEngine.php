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
    public function run() {
        // Start Transaction for Atomicity
        $this->conn->begin_transaction();

        try {
            // 1. Sync Occupancy (Safety Check)
            $this->syncRoomOccupancy();

            // 2. Fetch ONLY NEW students (Not yet allocated) AND who have PAID
            $sql = "SELECT p.user_id as id, p.gender, f.name as faculty, p.distance_from_campus, p.has_special_needs, p.allocation_status, 
                           COALESCE(m.urgency_score, 0) as score, 
                           COALESCE(m.severity_level, 0) as severity, 
                           COALESCE(m.mobility_status, 'Normal Mobility') as mobility 
                    FROM student_profiles p 
                    JOIN departments d ON p.department_id = d.department_id
                    JOIN faculties f ON d.faculty_id = f.faculty_id
                    LEFT JOIN medical_records m ON p.user_id = m.student_id 
                    JOIN payments py ON p.user_id = py.student_id
                    WHERE p.allocation_status = 'Unallocated' 
                    AND py.status = 'paid'
                    ORDER BY m.urgency_score DESC";
            
            $result = $this->conn->query($sql);
            $students = $result->fetch_all(MYSQLI_ASSOC);
            $allocated_count = 0;

            if (empty($students)) {
                 return ['status' => 'success', 'allocated' => 0, 'total' => 0];
            }

            // 3. Score overriding (ML Model prediction step is skipped here because it's already recorded in urgency_score column, 
            //    or we could call predict.py if needed. Assuming scores are pre-calculated for simplicity right now based on previous runs).
            //    To keep it fully backwards compatible we evaluate predict.py first:
            $batch_payload = [];
            foreach ($students as $student) {
                // Ensure correct format for predict.py expected numerical/string mappings
                $batch_payload[] = [
                    'id' => $student['id'],
                    'mobility' => $student['mobility'],
                    'severity' => (int)$student['severity'],
                    'distance_from_campus' => (float)$student['distance_from_campus'],
                    'has_special_needs' => (int)$student['has_special_needs']
                ];
            }
            
            // Recalculate scores using bridge just to be safe
            $temp_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fairmed_batch_' . uniqid() . '.json';
            file_put_contents($temp_file, json_encode($batch_payload));
            $script_path = __DIR__ . '/../ml_models/predict.py';
            $command = escapeshellcmd("python \"$script_path\" \"$temp_file\"");
            $output = shell_exec($command);
            $result_data = json_decode($output, true);
            if (file_exists($temp_file)) unlink($temp_file);

            $scores_map = [];
            if (($result_data['status'] ?? '') === 'success') {
                $scores_map = $result_data['results'];
            }

            // Update students array with latest scores
            foreach ($students as &$s) {
                if (isset($scores_map[$s['id']])) {
                    $s['score'] = $scores_map[$s['id']];
                }
            }
            unset($s);

            // Fetch Dynamic Threshold
            $threshold_res = $this->conn->query("SELECT setting_value FROM settings WHERE setting_key = 'urgency_threshold_proximal'");
            $threshold_row = $threshold_res->fetch_assoc();
            $prox_threshold = (float)($threshold_row['setting_value'] ?? 75);

            // 4. Fetch Available Rooms for OR-Tools
            $roomQuery = "SELECT r.room_id as id, r.hostel_id, h.gender_allowed as gender, 
                                 f.name as faculty_target, h.is_proximal, 
                                 r.floor_level, h.has_elevator, 
                                 (r.capacity - r.occupied_count) as available_capacity
                          FROM rooms r
                          JOIN hostels h ON r.hostel_id = h.hostel_id
                          LEFT JOIN faculties f ON h.proximal_faculty_id = f.faculty_id
                          WHERE r.occupied_count < r.capacity";
            $roomResult = $this->conn->query($roomQuery);
            $rooms = $roomResult->fetch_all(MYSQLI_ASSOC);
            
            foreach ($rooms as &$r) {
                // Ensure correct types for Python JSON serialization
                $r['is_proximal'] = (bool)$r['is_proximal'];
                $r['has_elevator'] = (bool)$r['has_elevator'];
                $r['available_capacity'] = (int)$r['available_capacity'];
                $r['floor_level'] = (int)$r['floor_level'];
                $r['faculty_target'] = $r['faculty_target'] ?: 'General';
            }
            unset($r);

            // 5. Build Final Payload for OR-Tools (CSV format)
            $students_csv_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fairmed_students_' . uniqid() . '.csv';
            $rooms_csv_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fairmed_rooms_' . uniqid() . '.csv';
            $output_csv_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fairmed_output_' . uniqid() . '.csv';

            $fp_students = fopen($students_csv_file, 'w');
            fputcsv($fp_students, ['id', 'gender', 'faculty', 'score', 'mobility']);
            foreach ($students as $s) {
                fputcsv($fp_students, [$s['id'], $s['gender'], $s['faculty'], $s['score'], $s['mobility']]);
            }
            fclose($fp_students);

            $fp_rooms = fopen($rooms_csv_file, 'w');
            fputcsv($fp_rooms, ['id', 'hostel_id', 'gender', 'faculty_target', 'is_proximal', 'floor_level', 'has_elevator', 'available_capacity']);
            foreach ($rooms as $r) {
                fputcsv($fp_rooms, [$r['id'], $r['hostel_id'], $r['gender'], $r['faculty_target'], $r['is_proximal'] ? 1 : 0, $r['floor_level'], $r['has_elevator'] ? 1 : 0, $r['available_capacity']]);
            }
            fclose($fp_rooms);

            // 6. Execute OR-Tools allocate.py
            $alloc_script = __DIR__ . '/../ml_models/allocate.py';
            $alloc_command = escapeshellcmd("python \"$alloc_script\" \"$students_csv_file\" \"$rooms_csv_file\" \"$output_csv_file\"");
            shell_exec($alloc_command);

            if (file_exists($students_csv_file)) unlink($students_csv_file);
            if (file_exists($rooms_csv_file)) unlink($rooms_csv_file);

            if (!file_exists($output_csv_file)) {
                throw new Exception("OR-Tools allocation failed. No output CSV generated.");
            }

            $assignments = []; 
            $fp_out = fopen($output_csv_file, 'r');
            fgetcsv($fp_out); // Read header
            while (($row = fgetcsv($fp_out)) !== false) {
                if (count($row) >= 2) {
                    $assignments[(int)$row[0]] = (int)$row[1];
                }
            }
            fclose($fp_out);
            unlink($output_csv_file);

            // Fetch Current Session for Session Locking
            $session_res = $this->conn->query("SELECT setting_value FROM settings WHERE setting_key = 'current_session'");
            $session_row = $session_res->fetch_assoc();
            $current_session = $session_row['setting_value'] ?? '2025/2026';

            // 7. Process Allocations into Database
            require_once 'NotificationManager.php';
            $notifier = new NotificationManager($this->conn);

            foreach ($students as $student) {
                $student_id = $student['id'];
                $final_score = $student['score'];

                if (isset($assignments[$student_id])) {
                    $room_id = $assignments[$student_id];

                    // Assign via Bed Configuration Logic (Retained exact bed logic)
                    if ($this->assignBed($room_id, $student_id, $current_session)) {
                        $allocated_count++;
                    
                        // UPDATE QUEUE STATUS
                        $this->conn->query("UPDATE student_profiles SET allocation_status = 'Allocated' WHERE user_id = $student_id");

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
                                      
                        $prox_need = ($final_score >= $prox_threshold) ? 1 : 0;
                        $stmt_audit = $this->conn->prepare($audit_sql);
                        $stmt_audit->bind_param("idii", $student_id, $prox_need, $final_score, $hid);
                        $stmt_audit->execute();
                    } else {
                        // Room was technically full during exact bed assignment
                        $notifier->send($student_id, "Update: You have been placed on the waiting list as no suitable rooms are currently available.");
                    }
                } else {
                    // Log Missed Allocation
                    $notifier->send($student_id, "Update: You have been placed on the waiting list as no suitable rooms are currently available.");

                    $audit_sql = "INSERT INTO algorithm_audit_logs 
                                  (student_id, input_severity, input_proximity_need, calculated_urgency_score, allocation_decision, assigned_hostel_id) 
                                  VALUES (?, 0, ?, ?, 'No Bed', NULL)";
                    $prox_need = ($final_score >= $prox_threshold) ? 1 : 0;
                    $stmt_audit = $this->conn->prepare($audit_sql);
                    $stmt_audit->bind_param("idi", $student_id, $prox_need, $final_score);
                    $stmt_audit->execute();
                }
            }

            // Lock the session directly in DB
            $this->conn->query("UPDATE settings SET setting_value = 'locked' WHERE setting_key = 'allocation_status'");

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

    /**
     * Helper: Assign Bed based on configuration (LB/UB/SB)
     */
    private function assignBed($room_id, $student_id, $academic_session) {
        // 1. Get Room Bed Config
        $stmt = $this->conn->prepare("SELECT bed_config, capacity FROM rooms WHERE room_id = ?");
        $stmt->bind_param("i", $room_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $room = $res->fetch_assoc();
        
        $config_str = $room['bed_config'] ?? null;
        if (empty($config_str)) {
            // Fallback: Default all to 'LB' based on capacity
            $config_arr = array_fill(0, (int)$room['capacity'], 'LB');
        } else {
            $config_arr = array_map('trim', explode(',', $config_str));
        }

        // 2. Get Occupied Slots
        $stmt_check = $this->conn->prepare("SELECT bed_space FROM allocations WHERE room_id = ?");
        $stmt_check->bind_param("i", $room_id);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();
        
        $occupied_indices = [];
        while ($row = $res_check->fetch_assoc()) {
            if ($row['bed_space'] !== null) {
                // Ensure valid ASCII range 
                $ord = ord($row['bed_space']);
                if ($ord >= 65 && $ord <= 90) { // A-Z
                    $occupied_indices[] = $ord - 65; 
                }
            }
        }
        
        // 3. Find First Free Slot
        $slot_index = -1;
        for ($i = 0; $i < count($config_arr); $i++) {
            if (!in_array($i, $occupied_indices)) {
                $slot_index = $i;
                break;
            }
        }
        
        if ($slot_index === -1) return false; // Full
        
        // 4. Assign
        $bed_space = chr(65 + $slot_index); // 0->A
        $bed_label = $config_arr[$slot_index];
        
        $stmt_ins = $this->conn->prepare("INSERT INTO allocations (student_id, room_id, bed_space, bed_label, academic_session) VALUES (?, ?, ?, ?, ?)");
        $stmt_ins->bind_param("iisss", $student_id, $room_id, $bed_space, $bed_label, $academic_session);
        
        if ($stmt_ins->execute()) {
            $this->conn->query("UPDATE rooms SET occupied_count = occupied_count + 1 WHERE room_id = $room_id");
            return true;
        }
        
        return false;
    }
}
