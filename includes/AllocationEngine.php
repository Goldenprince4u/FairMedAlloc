<?php
/**
 * Allocation Engine
 * =================
 * Core logic for assigning students to hostels based on fairness constraints.
 */
require_once __DIR__ . '/DbHelper.php';
class AllocationEngine {
    private const ALGORITHM_VERSION = 'allocation_engine_v2';

    private $conn;
    private $allocationsHasAlgorithmVersion = null;

    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }

    /**
     * Run the full allocation process
     */
    public function run(?int $single_student_id = null) {
        // Start Transaction for Atomicity
        $this->conn->begin_transaction();

        try {
            // 1. Sync Occupancy (Safety Check)
            $this->syncRoomOccupancy();

            // 2. Fetch ONLY NEW students (Not yet allocated) AND who have paid
            //    either through imported portal status or a recorded simulator payment.
            $sql = "SELECT p.user_id as id, p.gender, f.name as faculty, p.level as academic_level,
                           p.has_special_needs, p.allocation_status,
                           COALESCE(m.condition_category, 'None') as `condition`,
                           COALESCE(m.urgency_score, 0) as score,
                           COALESCE(m.severity_level, 'Low') as severity,
                           COALESCE(m.mobility_status, 'Normal Mobility') as mobility,
                           COALESCE(m.is_requested_mobility, 0) as is_requested
                    FROM student_profiles p 
                    JOIN departments d ON p.department_id = d.department_id
                    JOIN faculties f ON d.faculty_id = f.faculty_id
                    LEFT JOIN medical_records m ON p.user_id = m.student_id
                    WHERE p.allocation_status = 'Unallocated' 
                    AND (
                        p.is_paid = 1
                        OR EXISTS (
                            SELECT 1
                            FROM payments py
                            WHERE py.student_id = p.user_id
                              AND py.status = 'paid'
                        )
                    )";
            
            if ($single_student_id !== null) {
                $sql .= " AND p.user_id = " . (int)$single_student_id;
            }
            
            $sql .= " ORDER BY m.urgency_score DESC";
            
            $result = $this->conn->query($sql);
            $students = $result->fetch_all(MYSQLI_ASSOC);
            $allocated_count = 0;

            if (empty($students)) {
                $this->conn->commit();
                return ['status' => 'success', 'allocated' => 0, 'total' => 0];
            }

            $batch_payload = [];
            foreach ($students as $student) {
                $batch_payload[] = [
                    'id' => $student['id'],
                    'condition' => $student['condition'],
                    'mobility' => $student['mobility'],
                    'severity' => $student['severity'],
                    'academic_level' => (int)$student['academic_level'],
                    'has_special_needs' => (int)$student['has_special_needs'],
                    'is_requested' => (bool)$student['is_requested']
                ];
            }
            $scores_map = [];
            $prediction_mode = 'Stored Medical Scores';
            try {
                $result_data = $this->predictBatchScores($batch_payload);
                $scores_map = $result_data['results'] ?? [];
                $prediction_mode = $result_data['mode'] ?? 'XGBoost';
            } catch (Exception $e) {
                error_log('[FairMedAlloc] Score refresh skipped: ' . $e->getMessage());
            }

            // Update students array with latest scores
            foreach ($students as &$s) {
                if (isset($scores_map[$s['id']])) {
                    $s['score'] = $scores_map[$s['id']];
                }
            }
            unset($s);

            if (!empty($scores_map)) {
                $this->persistUrgencyScores($scores_map);
            }

            // Fetch Dynamic Threshold
            $prox_threshold = (float)$this->getSettingValue('urgency_threshold_proximal', 75);
            $medium_threshold = (float)$this->getSettingValue('urgency_threshold_medium', 40);

            // 4. Fetch Available Rooms for OR-Tools
            // NOTE: Exclude postgrad (is_postgrad=1) and foundation (is_foundation=1) rooms
            // from undergraduate allocation. These are blocks 19-20 and block 27.
            $roomQuery = "SELECT r.room_id as id, r.hostel_id, h.gender_allowed as gender, 
                                 f.name as faculty_target, h.is_proximal, h.name as hostel_name,
                                 h.has_elevator, h.block_name,
                                 (r.capacity - r.occupied_count) as available_capacity
                          FROM rooms r
                          JOIN hostels h ON r.hostel_id = h.hostel_id
                          LEFT JOIN faculties f ON h.proximal_faculty_id = f.faculty_id
                          WHERE r.occupied_count < r.capacity
                            AND h.is_postgrad = 0
                            AND h.is_foundation = 0";
            $roomResult = $this->conn->query($roomQuery);
            $rooms = $roomResult->fetch_all(MYSQLI_ASSOC);
            
            foreach ($rooms as &$r) {
                // Ensure correct types for Python JSON serialization
                $r['is_proximal'] = (bool)$r['is_proximal'];
                $r['has_elevator'] = (bool)$r['has_elevator'];
                $r['available_capacity'] = (int)$r['available_capacity'];
                $r['faculty_target'] = $r['faculty_target'] ?: 'General';
            }
            unset($r);

            // 5. Build Final Payload for OR-Tools (CSV format)
            $students_csv_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fairmed_students_' . uniqid() . '.csv';
            $rooms_csv_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fairmed_rooms_' . uniqid() . '.csv';
            $output_csv_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fairmed_output_' . uniqid() . '.csv';

            try {
                $fp_students = fopen($students_csv_file, 'w');
                fputcsv($fp_students, ['id', 'gender', 'faculty', 'score', 'mobility', 'severity', 'urgency_band']);
                foreach ($students as $s) {
                    $urgency_band = 'Low';
                    if ((float)$s['score'] >= $prox_threshold) {
                        $urgency_band = 'High';
                    } elseif ((float)$s['score'] >= $medium_threshold) {
                        $urgency_band = 'Medium';
                    }

                    fputcsv($fp_students, [$s['id'], $s['gender'], $s['faculty'], $s['score'], $s['mobility'], $s['severity'], $urgency_band]);
                }
                fclose($fp_students);

                $fp_rooms = fopen($rooms_csv_file, 'w');
                fputcsv($fp_rooms, ['id', 'hostel_id', 'gender', 'faculty_target', 'is_proximal', 'has_elevator', 'available_capacity', 'hostel_name', 'block_name']);
                foreach ($rooms as $r) {
                    fputcsv($fp_rooms, [$r['id'], $r['hostel_id'], $r['gender'], $r['faculty_target'], $r['is_proximal'] ? 1 : 0, $r['has_elevator'] ? 1 : 0, $r['available_capacity'], $r['hostel_name'], $r['block_name']]);
                }
                fclose($fp_rooms);

                // 6. Execute OR-Tools allocate.py
                $alloc_script = __DIR__ . '/../ml_models/allocate.py';
                $solver_output = $this->executeShellCommand(array_merge(
                    $this->getPythonCommandParts(),
                    [$alloc_script, $students_csv_file, $rooms_csv_file, $output_csv_file]
                ));
                if (!file_exists($output_csv_file)) {
                    $message = 'Allocation solver failed: OR-Tools output file not generated. Ensure Python and ortools are installed and accessible.';
                    if (!empty($solver_output)) {
                        $message .= ' Solver output: ' . $solver_output;
                    }
                    throw new Exception($message);
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
            } finally {
                foreach ([$students_csv_file, $rooms_csv_file, $output_csv_file] as $temp_path) {
                    if (is_string($temp_path) && file_exists($temp_path)) {
                        unlink($temp_path);
                    }
                }
            }

            // Fetch Current Session for Session Locking
            $session_res = $this->conn->query("SELECT setting_value FROM settings WHERE setting_key = 'current_session'");
            $session_row = $session_res->fetch_assoc();
            $current_session = $session_row['setting_value'] ?? '2025/2026';

            // 7. Process Allocations into Database
            require_once 'NotificationManager.php';
            $notifier = new NotificationManager($this->conn);

            // Severity string → integer map (used in audit log)
            $sev_map = ['Low' => 1, 'Medium' => 2, 'High' => 3];

            foreach ($students as $student) {
                $student_id  = $student['id'];
                $final_score = $student['score'];
                // Map severity string to integer (1=Low, 2=Medium, 3=High)
                $sev_int = $sev_map[$student['severity']] ?? (int)$student['severity'];

                if (isset($assignments[$student_id])) {
                    $room_id = $assignments[$student_id];

                    // Assign via Bed Configuration Logic (Retained exact bed logic)
                    if ($this->assignBed($room_id, $student_id, $current_session)) {
                        $allocated_count++;
                    
                        // UPDATE QUEUE STATUS
                        $upd_status = $this->conn->prepare("UPDATE student_profiles SET allocation_status = 'Allocated' WHERE user_id = ?");
                        $upd_status->bind_param("i", $student_id);
                        $upd_status->execute();

                        // AUDIT LOGGING
                        $hid_stmt = $this->conn->prepare("SELECT h.hostel_id, h.name FROM rooms r JOIN hostels h ON r.hostel_id = h.hostel_id WHERE r.room_id = ?");
                        $hid_stmt->bind_param("i", $room_id);
                        $hid_stmt->execute();
                        $h_row = $hid_stmt->get_result()->fetch_assoc();
                        $hid    = $h_row['hostel_id'] ?? null;
                        $h_name = $h_row['name'] ?? 'Hostel';

                        // NOTIFY STUDENT
                        $notifier->send($student_id, "Congratulations! You have been allocated a room in $h_name.");

                        
                        $audit_sql = "INSERT INTO algorithm_audit_logs 
                                      (student_id, input_severity, input_proximity_need, calculated_urgency_score, allocation_decision, assigned_hostel_id) 
                                      VALUES (?, ?, ?, ?, 'Allocated', ?)";
                        $prox_need  = ($final_score >= $prox_threshold) ? 1 : 0;
                        $stmt_audit = $this->conn->prepare($audit_sql);
                        $stmt_audit->bind_param("iiddi", $student_id, $sev_int, $prox_need, $final_score, $hid);
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
                                  VALUES (?, ?, ?, ?, 'No Bed', NULL)";
                    $prox_need  = ($final_score >= $prox_threshold) ? 1 : 0;
                    $stmt_audit = $this->conn->prepare($audit_sql);
                    $stmt_audit->bind_param("iidd", $student_id, $sev_int, $prox_need, $final_score);
                    $stmt_audit->execute();
                }
            }

            // Only lock the session if we actually allocated at least one student.
            // Locking on 0 allocations would prevent re-running when students register later.
            if ($allocated_count > 0) {
                $this->conn->query("UPDATE settings SET setting_value = 'locked' WHERE setting_key = 'allocation_status'");
            }

            // Commit the transaction
            $this->conn->commit();

            return [
                'status' => 'success',
                'allocated' => $allocated_count,
                'total' => count($students),
                'prediction_mode' => $prediction_mode
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
     * Recompute and persist urgency scores for every stored medical record.
     */
    public function rescoreAllMedicalRecords() {
        $this->conn->begin_transaction();

        try {
            $sql = "SELECT m.student_id as id,
                           COALESCE(m.condition_category, 'None') as `condition`,
                           COALESCE(m.mobility_status, 'Normal Mobility') as mobility,
                           COALESCE(m.severity_level, 'Low') as severity,
                           COALESCE(p.level, 100) as academic_level,
                           COALESCE(p.has_special_needs, 0) as has_special_needs,
                           COALESCE(m.is_requested_mobility, 0) as is_requested
                    FROM medical_records m
                    JOIN student_profiles p ON p.user_id = m.student_id";
            $result = $this->conn->query($sql);
            $students = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

            if (empty($students)) {
                $this->conn->commit();
                return [
                    'status' => 'success',
                    'rescored' => 0,
                    'mode' => 'No medical records'
                ];
            }

            $batch_payload = [];
            foreach ($students as $student) {
                $batch_payload[] = [
                    'id' => $student['id'],
                    'condition' => $student['condition'],
                    'mobility' => $student['mobility'],
                    'severity' => $student['severity'],
                    'academic_level' => (int)$student['academic_level'],
                    'has_special_needs' => (int)$student['has_special_needs'],
                    'is_requested' => (bool)$student['is_requested']
                ];
            }

            $prediction = $this->predictBatchScores($batch_payload);
            $scores_map = $prediction['results'] ?? [];
            $updated = $this->persistUrgencyScores($scores_map);

            $this->conn->commit();

            return [
                'status' => 'success',
                'rescored' => $updated,
                'mode' => $prediction['mode'] ?? 'XGBoost'
            ];
        } catch (Exception $e) {
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
                $count = (int)$row['count'];
                $rid   = (int)$row['room_id'];
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
            if (!in_array($i, $occupied_indices, true)) {
                $slot_index = $i;
                break;
            }
        }
        
        if ($slot_index === -1) return false; // Full
        
        // 4. Assign
        $bed_space = chr(65 + $slot_index); // 0->A
        $bed_label = $config_arr[$slot_index];
        
        if ($this->allocationsSupportAlgorithmVersion()) {
            $algorithm_version = $this->getCurrentAlgorithmVersion();
            $stmt_ins = $this->conn->prepare("INSERT INTO allocations (student_id, room_id, bed_space, bed_label, academic_session, algorithm_version) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_ins->bind_param("iissss", $student_id, $room_id, $bed_space, $bed_label, $academic_session, $algorithm_version);
        } else {
            $stmt_ins = $this->conn->prepare("INSERT INTO allocations (student_id, room_id, bed_space, bed_label, academic_session) VALUES (?, ?, ?, ?, ?)");
            $stmt_ins->bind_param("iisss", $student_id, $room_id, $bed_space, $bed_label, $academic_session);
        }
        
        if ($stmt_ins->execute()) {
            $upd_occ = $this->conn->prepare("UPDATE rooms SET occupied_count = occupied_count + 1 WHERE room_id = ?");
            $upd_occ->bind_param("i", $room_id);
            $upd_occ->execute();
            return true;
        }
        
        return false;
    }

    private function getSettingValue($setting_key, $default_value) {
        $stmt = $this->conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        if (!$stmt) {
            return $default_value;
        }

        $stmt->bind_param("s", $setting_key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['setting_value'] ?? $default_value;
    }

    private function predictBatchScores(array $batch_payload): array {
        require_once __DIR__ . '/UrgencyScoreService.php';
        $service = new UrgencyScoreService();
        $result = $service->scoreBatch($batch_payload);
        if (($result['status'] ?? '') !== 'success') {
            throw new Exception($result['message'] ?? 'predict.py returned an unexpected response.');
        }
        return $result;
    }

    private function runPythonJsonScript($script_path, array $payload): array {
        $temp_file = tempnam(sys_get_temp_dir(), 'fairmed_json_');
        if ($temp_file === false) {
            throw new Exception('Unable to create a temporary file for the Python bridge.');
        }

        if (file_put_contents($temp_file, json_encode($payload)) === false) {
            unlink($temp_file);
            throw new Exception('Unable to write the Python bridge payload.');
        }

        try {
            $output = $this->executeShellCommand(array_merge($this->getPythonCommandParts(), [$script_path, $temp_file]));
        } finally {
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }
        }

        if ($output === null || $output === '') {
            throw new Exception('Python bridge produced no output.');
        }

        $decoded = json_decode($output, true);
        if (!is_array($decoded)) {
            throw new Exception('Python bridge returned invalid JSON.');
        }

        return $decoded;
    }

    private function executeShellCommand(array $command_parts) {
        $escaped_parts = array_map([$this, 'escapeCommandPart'], $command_parts);
        $command = implode(' ', $escaped_parts);
        $output = @shell_exec($command . ' 2>&1');

        if (!is_string($output)) {
            return null;
        }

        return trim($output);
    }

    private function escapeCommandPart($value) {
        $value = (string)$value;
        if (DIRECTORY_SEPARATOR === '\\') {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return escapeshellarg($value);
    }

    private function getPythonCommandParts() {
        $configured = defined('PYTHON_BIN') && PYTHON_BIN !== ''
            ? trim((string)PYTHON_BIN)
            : (
                defined('FAIRMED_PYTHON_BIN') && FAIRMED_PYTHON_BIN !== ''
                    ? trim((string)FAIRMED_PYTHON_BIN)
                    : trim((string)(getenv('PYTHON_BIN') ?: getenv('FAIRMED_PYTHON_BIN')))
            );
        if ($configured !== '') {
            $parts = array_values(array_filter(str_getcsv($configured, ' '), static function ($part) {
                return $part !== null && $part !== '';
            }));
            if (is_array($parts) && !empty($parts)) {
                return $parts;
            }
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return ['python'];
        }

        return ['python3'];
    }

    private function persistUrgencyScores(array $scores_map) {
        if (empty($scores_map)) {
            return 0;
        }

        $updated = 0;
        $stmt = $this->conn->prepare("UPDATE medical_records SET urgency_score = ? WHERE student_id = ?");
        if (!$stmt) {
            throw new Exception('Unable to prepare the urgency score update statement.');
        }

        foreach ($scores_map as $student_id => $score) {
            $student_id = (int)$student_id;
            $score = (float)$score;
            $stmt->bind_param("di", $score, $student_id);
            if ($stmt->execute()) {
                $updated++;
            }
        }
        $stmt->close();

        return $updated;
    }

    private function allocationsSupportAlgorithmVersion(): bool {
        if ($this->allocationsHasAlgorithmVersion !== null) {
            return $this->allocationsHasAlgorithmVersion;
        }
        // Delegate to shared helper — single source of truth for this schema check.
        $this->allocationsHasAlgorithmVersion = DbHelper::supportsAlgorithmVersion($this->conn);
        return $this->allocationsHasAlgorithmVersion;
    }

    private function getCurrentAlgorithmVersion() {
        return (string)$this->getSettingValue('allocation_algorithm_version', self::ALGORITHM_VERSION);
    }
}
