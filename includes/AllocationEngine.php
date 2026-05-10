<?php
/**
 * Allocation Engine (The Orchestrator)
 * ====================================
 * This is the PHP brain of the system. It handles talking to the database, 
 * pushing student data to the Python AI/Graph Matcher, and ultimately writing 
 * the assignments back to the database. It also enforces the strict Lower Bunk (LB) 
 * rules for disabled students.
 */
require_once __DIR__ . '/DbHelper.php';
require_once __DIR__ . '/PerformanceMonitor.php';
require_once __DIR__ . '/Logger.php';

class AllocationEngine {
    private const ALGORITHM_VERSION = 'allocation_engine_v3';
    private const JOB_CANCELLED_SIGNAL = '__FAIRMED_JOB_CANCELLED__';

    private $conn;
    private $allocationsHasAlgorithmVersion = null;
    private $monitor;
    private $progressCallback = null;
    /** Optional job_id: if set, total_students is persisted to allocation_jobs */
    private ?int $jobId = null;

    public function __construct($db_connection) {
        $this->conn = $db_connection;
        $this->monitor = new PerformanceMonitor();
    }

    /**
     * Bind this engine run to an allocation_jobs row.
     * When set, the engine updates total_students after counting eligible students.
     */
    public function setJobId(int $job_id): void {
        $this->jobId = $job_id;
    }

    /**
     * Update progress by invoking the callback
     */
    private function updateProgress(?callable $callback, string $stage, int $percent) {
        if ($callback && is_callable($callback)) {
            try {
                call_user_func($callback, [
                    'stage' => $stage,
                    'percent' => max(0, min(100, $percent))
                ]);
            } catch (Throwable $e) {
                // The worker uses a dedicated runtime exception as a control signal
                // when an administrator cancels a running allocation job.
                if ($e instanceof RuntimeException && $e->getMessage() === self::JOB_CANCELLED_SIGNAL) {
                    throw $e;
                }
                Logger::warning("Progress callback failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Run the full allocation process
     * 
     * @param int|null $single_student_id Optional single student to allocate
     * @param callable|null $progressCallback Optional callback for progress updates
     *        Called as: $progressCallback(['stage' => str, 'percent' => int])
     */
    public function run(?int $single_student_id = null, ?callable $progressCallback = null, bool $use_mutex = true) {
        $inTransaction = false;

        try {
            // Acquire a mutual exclusion lock to prevent concurrent direct calls.
            // When called from the worker (worker_allocation.php), the worker already
            // holds its own GET_LOCK so we skip this to avoid deadlocking ourselves.
            if ($use_mutex) {
                $lockResult = $this->conn->query("SELECT GET_LOCK('allocation_run_lock', 0) as got_lock");
                $lockRow = $lockResult ? $lockResult->fetch_assoc() : null;
                if (!($lockRow['got_lock'] ?? 0)) {
                    return ['status' => 'error', 'message' => 'Another allocation job is already running. Please wait for it to complete before starting a new one.'];
                }
            }

            // 1. Sync Occupancy (Safety Check)
            $this->updateProgress($progressCallback, 'Syncing occupancy', 5);
            $this->syncRoomOccupancy();

            // 2. Fetch ONLY NEW students (Not yet allocated) AND who have paid
            //    either through imported portal status or a recorded simulator payment.
            $sql = "SELECT p.user_id as id, p.gender, f.name as faculty, p.level as academic_level,
                           p.has_special_needs, p.allocation_status,
                           COALESCE(NULLIF(m.condition_category, ''), 'None') as `condition`,
                           COALESCE(m.urgency_score, 0) as score,
                           COALESCE(m.severity_level, 'Low') as severity,
                           COALESCE(NULLIF(m.mobility_status, ''), 'Normal Mobility') as mobility,
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
            
            // Measure query execution time
            $result = $this->monitor->query('fetch_unallocated_students', function() use ($sql) {
                return $this->conn->query($sql);
            }, 2000); // warn if > 2 seconds
            
            $students = $result->fetch_all(MYSQLI_ASSOC);
            $allocated_count = 0;
            $total_students  = count($students);

            if (empty($students)) {
                if ($use_mutex) {
                    $this->conn->query("SELECT RELEASE_LOCK('allocation_run_lock')");
                }
                return ['status' => 'success', 'allocated' => 0, 'total' => 0];
            }

            // Persist student count to jobs table so the UI can show "X / total" early
            if ($this->jobId !== null) {
                $jid = (int)$this->jobId;
                $this->conn->query(
                    "UPDATE allocation_jobs
                        SET total_students   = $total_students,
                            progress_stage   = 'Fetched $total_students students',
                            progress_percent = 15,
                            updated_at       = NOW()
                      WHERE job_id = $jid"
                );
            }

            $this->updateProgress($progressCallback, 'Fetched ' . $total_students . ' students', 15);

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
            
            $this->updateProgress($progressCallback, 'Scoring students with XGBoost', 20);
            
            $scores_map = [];
            $prediction_mode = 'Stored Medical Scores';
            try {
                $result_data = $this->predictBatchScores($batch_payload);
                $scores_map = $result_data['results'] ?? [];
                $prediction_mode = $result_data['mode'] ?? 'XGBoost';
                Logger::info("ML service score prediction successful for " . count($batch_payload) . " students");
            } catch (Throwable $e) {
                Logger::warning("ML service unavailable, falling back to stored urgency scores: " . $e->getMessage());
                // Log which students are affected so the audit trail captures the fallback mode.
                $fallback_ids = array_column($students, 'id');
                Logger::warning(
                    sprintf("Fallback scoring active for %d students. First 10 IDs: %s",
                        count($fallback_ids),
                        implode(', ', array_slice($fallback_ids, 0, 10))
                    )
                );
                // Use stored scores from database - already loaded in $students array
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
                                 h.has_elevator, h.block_name, r.floor_level,
                                 (r.capacity - r.occupied_count) as available_capacity
                          FROM rooms r
                          JOIN hostels h ON r.hostel_id = h.hostel_id
                          LEFT JOIN faculties f ON h.proximal_faculty_id = f.faculty_id
                          WHERE r.occupied_count < r.capacity
                            AND h.is_postgrad = 0
                            AND h.is_foundation = 0";
            
            $roomResult = $this->monitor->query('fetch_available_rooms', function() use ($roomQuery) {
                return $this->conn->query($roomQuery);
            }, 2000); // warn if > 2 seconds
            
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

            $solver_mode = 'OR-Tools Min-Cost Flow';
            $solver_status = 'OPTIMAL';

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
                fputcsv($fp_rooms, ['id', 'hostel_id', 'gender', 'faculty_target', 'is_proximal', 'has_elevator', 'available_capacity', 'hostel_name', 'block_name', 'floor_level']);
                foreach ($rooms as $r) {
                    fputcsv($fp_rooms, [$r['id'], $r['hostel_id'], $r['gender'], $r['faculty_target'], $r['is_proximal'] ? 1 : 0, $r['has_elevator'] ? 1 : 0, $r['available_capacity'], $r['hostel_name'], $r['block_name'], $r['floor_level']]);
                }
                fclose($fp_rooms);

                $this->updateProgress($progressCallback, 'Running OR-Tools solver', 30);

                // 6. Execute OR-Tools allocate.py
                $assignments = [];
                $solverBackend = strtolower((string)$this->getSettingValue('allocation_solver_backend', 'ortools'));
                if ($solverBackend === 'ortools') {
                    $alloc_script = __DIR__ . '/../ml_models/allocate.py';
                    $solver_output = $this->executeShellCommand(
                        array_merge(
                            $this->getPythonCommandParts(),
                            [$alloc_script, $students_csv_file, $rooms_csv_file, $output_csv_file]
                        ),
                        $this->conn,
                        $this->jobId
                    );

                    if (is_string($solver_output) && preg_match('/Solver status:\s*([A-Z_]+)/', $solver_output, $matches) === 1) {
                        $solver_status = strtoupper((string)$matches[1]);
                    }

                    if (file_exists($output_csv_file)) {
                        $fp_out = fopen($output_csv_file, 'r');
                        fgetcsv($fp_out); // Read header
                        while (($row = fgetcsv($fp_out)) !== false) {
                            if (count($row) >= 2) {
                                $assignments[(int)$row[0]] = (int)$row[1];
                            }
                        }
                        fclose($fp_out);
                    } else {
                        throw new Exception("OR-Tools solver failed to produce valid assignments. Output: " . substr((string)$solver_output, 0, 500));
                    }
                } else {
                    throw new Exception("Only 'ortools' is supported. Found: " . $solverBackend);
                }
            } finally {
                foreach ([$students_csv_file, $rooms_csv_file, $output_csv_file] as $temp_path) {
                    if (is_string($temp_path) && file_exists($temp_path)) {
                        unlink($temp_path);
                    }
                }
            }

            $this->conn->begin_transaction();
            $inTransaction = true;

            $this->syncRoomOccupancy();

            // Fetch Current Session for Session Locking
            $session_res = $this->conn->query("SELECT setting_value FROM settings WHERE setting_key = 'current_session'");
            $session_row = $session_res->fetch_assoc();
            $current_session = $session_row['setting_value'] ?? '2025/2026';

            // To be injected into AllocationEngine.php lines 201 to 260
            // Pre-fetch all rooms and their occupied beds to avoid thousands of queries
            $rooms_data = [];
            $res = $this->conn->query("SELECT r.room_id, r.capacity, r.bed_config, r.occupied_count, h.hostel_id, h.name as hostel_name FROM rooms r JOIN hostels h ON r.hostel_id = h.hostel_id");
            while ($row = $res->fetch_assoc()) {
                $config_str = $row['bed_config'] ?? null;
                $config_arr = empty($config_str) ? array_fill(0, (int)$row['capacity'], 'LB') : array_map('trim', explode(',', $config_str));
                
                $rooms_data[$row['room_id']] = [
                    'capacity' => (int)$row['capacity'],
                    'config_arr' => $config_arr,
                    'hostel_id' => $row['hostel_id'],
                    'hostel_name' => $row['hostel_name'],
                    'occupied_indices' => [],
                    'new_occupants' => 0
                ];
            }
            
            $res = $this->conn->query("SELECT room_id, bed_space FROM allocations");
            while ($row = $res->fetch_assoc()) {
                if (isset($rooms_data[$row['room_id']]) && $row['bed_space'] !== null) {
                    $ord = ord($row['bed_space']);
                    if ($ord >= 65 && $ord <= 90) { // A-Z
                        $rooms_data[$row['room_id']]['occupied_indices'][] = $ord - 65; 
                    }
                }
            }

            $bulk_allocations = [];
            $bulk_profiles = [];
            $bulk_audit = [];
            $bulk_notifications = [];

            $algo_version = self::ALGORITHM_VERSION;
            $has_algorithm_version_col = $this->allocationsHasAlgorithmVersion ?? DbHelper::supportsAlgorithmVersion($this->conn);
            $this->allocationsHasAlgorithmVersion = $has_algorithm_version_col;

            // Severity encoding for audit logs:
            //   PHP  → 1=Low, 2=Medium, 3=High, 4=Critical
            //   Python OR-Tools uses 'Low','Medium','High' strings directly from the CSV
            // The two systems are internally consistent. Critical is treated as High by the solver.
            $sev_map = ['Low' => 1, 'Medium' => 2, 'High' => 3, 'Critical' => 3];
            foreach ($students as $student) {
                $student_id  = (int)$student['id'];
                $final_score = (float)$student['score'];
                $sev_int = $sev_map[$student['severity']] ?? 2; // default Medium if unknown
                $prox_need  = ($final_score >= $prox_threshold) ? 1 : 0;

                if (isset($assignments[$student_id]) && isset($rooms_data[$assignments[$student_id]])) {
                    $room_id = $assignments[$student_id];
                    $room = &$rooms_data[$room_id];

                    // === POST-SOLVER VALIDATION ===
                    // Re-verify the OR-Tools output satisfies the combined-condition clinic constraint.
                    // If the solver routed a combined-condition student to a non-clinic room (e.g. due
                    // to a future code change or edge case), we skip the student to the waitlist rather
                    // than blindly committing a bad assignment. This does NOT fail the whole batch.
                    if ($this->hasCombinedConditions($student) && !$this->isClinicProximityRoom($room_id)) {
                        Logger::error("Constraint violation: Combined-condition student {$student_id} was routed to non-clinic room {$room_id}. Placing on waitlist.");
                        $bulk_audit[] = "($student_id, $sev_int, $prox_need, $final_score, 'Constraint Violation', NULL)";
                        $msg = $this->conn->real_escape_string("Your accommodation request requires a clinic-proximal room. No suitable beds are currently available in the designated blocks. Please contact Student Affairs.");
                        $bulk_notifications[] = "($student_id, '$msg')";
                        unset($room);
                        continue;
                    }
                    
                    $is_mobility_issue = false;
                    $mobility_val = strtolower(trim($student['mobility'] ?? ''));
                    if ($mobility_val !== '' && $mobility_val !== 'normal mobility' && $mobility_val !== 'none') {
                        $is_mobility_issue = true;
                    }

                    $slot_index = -1;
                    $config_count = count($room['config_arr']);

                    if ($is_mobility_issue) {
                        // Students with mobility conditions cannot use SB or UB (Single/Upper Bunk).
                        // They cannot climb the ladder to access these beds.
                        // Prefer LB (Lower Bunk) or other ground-level bed types.
                        for ($i = 0; $i < $config_count; $i++) {
                            if (!in_array($i, $room['occupied_indices'], true)) {
                                $label = trim($room['config_arr'][$i] ?? 'LB');
                                // Skip SB and UB for mobility students
                                if ($label !== 'SB' && $label !== 'UB') {
                                    $slot_index = $i;
                                    break;
                                }
                            }
                        }
                    } else {
                        // Standard students can take any available bed
                        for ($i = 0; $i < $config_count; $i++) {
                            if (!in_array($i, $room['occupied_indices'], true)) {
                                $slot_index = $i;
                                break;
                            }
                        }
                    }

                    if ($slot_index !== -1) {
                        $room['occupied_indices'][] = $slot_index;
                        $room['new_occupants']++;
                        
                        $bed_space = chr(65 + $slot_index);
                        $bed_label = $room['config_arr'][$slot_index] ?? 'LB';
                        if ($has_algorithm_version_col) {
                            $bulk_allocations[] = [
                                'student_id' => $student_id,
                                'room_id' => $room_id,
                                'bed_space' => $bed_space,
                                'bed_label' => $bed_label,
                                'academic_session' => $current_session,
                                'allocation_method' => 'algorithm',
                                'algorithm_version' => $algo_version
                            ];
                        } else {
                            $bulk_allocations[] = [
                                'student_id' => $student_id,
                                'room_id' => $room_id,
                                'bed_space' => $bed_space,
                                'bed_label' => $bed_label,
                                'academic_session' => $current_session,
                                'allocation_method' => 'algorithm'
                            ];
                        }
                        
                        $bulk_profiles[] = $student_id;
                        
                        $hid = (int)$room['hostel_id'];
                        $bulk_audit[] = "($student_id, $sev_int, $prox_need, $final_score, 'Allocated', $hid)";
                        
                        $msg = $this->conn->real_escape_string("Congratulations! You have been allocated a room in {$room['hostel_name']}.");
                        $bulk_notifications[] = "($student_id, '$msg')";
                        
                        $allocated_count++;
                    } else {
                        $bulk_audit[] = "($student_id, $sev_int, $prox_need, $final_score, 'No Bed', NULL)";
                        // Give combined-condition students a more specific waitlist message
                        if ($this->hasCombinedConditions($student)) {
                            $msg = $this->conn->real_escape_string("Your accommodation requires a clinic-proximal room with an accessible bed. No suitable beds are currently available in the designated blocks. Please contact Student Affairs immediately.");
                        } else {
                            $msg = $this->conn->real_escape_string("Update: You have been placed on the waiting list as no suitable rooms are currently available.");
                        }
                        $bulk_notifications[] = "($student_id, '$msg')";
                    }
                } else {
                    $bulk_audit[] = "($student_id, $sev_int, $prox_need, $final_score, 'No Bed', NULL)";
                    if ($this->hasCombinedConditions($student)) {
                        $msg = $this->conn->real_escape_string("Your accommodation requires a clinic-proximal room with an accessible bed. No suitable beds are currently available in the designated blocks. Please contact Student Affairs immediately.");
                    } else {
                        $msg = $this->conn->real_escape_string("Update: You have been placed on the waiting list as no suitable rooms are currently available.");
                    }
                    $bulk_notifications[] = "($student_id, '$msg')";
                }
            }

            $this->updateProgress($progressCallback, 'Writing allocation results', 80);

            // Execute Bulk Inserts
            if (!empty($bulk_allocations)) {
                $insert_cols = $has_algorithm_version_col ?
                    "(student_id, room_id, bed_space, bed_label, academic_session, allocation_method, algorithm_version)" :
                    "(student_id, room_id, bed_space, bed_label, academic_session, allocation_method)";

                foreach (array_chunk($bulk_allocations, 500) as $chunk) {
                    $rows = [];
                    foreach ($chunk as $a) {
                        $bs  = $this->conn->real_escape_string($a['bed_space']);
                        $bl  = $this->conn->real_escape_string($a['bed_label']);
                        $ses = $this->conn->real_escape_string($a['academic_session']);
                        $am  = $this->conn->real_escape_string($a['allocation_method']);
                        if ($has_algorithm_version_col) {
                            $av  = $this->conn->real_escape_string($a['algorithm_version']);
                            $rows[] = "({$a['student_id']},{$a['room_id']},'$bs','$bl','$ses','$am','$av')";
                        } else {
                            $rows[] = "({$a['student_id']},{$a['room_id']},'$bs','$bl','$ses','$am')";
                        }
                    }
                    $ok = $this->conn->query("INSERT INTO allocations $insert_cols VALUES " . implode(',', $rows));
                    if (!$ok) {
                        throw new Exception('Bulk allocation insert failed: ' . $this->conn->error);
                    }
                }
            }

            if (!empty($bulk_profiles)) {
                foreach (array_chunk($bulk_profiles, 1000) as $chunk) {
                    $ids = implode(',', $chunk);
                    $this->conn->query("UPDATE student_profiles SET allocation_status = 'Allocated' WHERE user_id IN ($ids)");
                }
            }
            if (!empty($bulk_audit)) {
                foreach (array_chunk($bulk_audit, 1000) as $chunk) {
                    $this->conn->query("INSERT INTO algorithm_audit_logs (student_id, input_severity, input_proximity_need, calculated_urgency_score, allocation_decision, assigned_hostel_id) VALUES " . implode(',', $chunk));
                }
            }
            if (!empty($bulk_notifications)) {
                foreach (array_chunk($bulk_notifications, 1000) as $chunk) {
                    $this->conn->query("INSERT INTO notifications (user_id, message) VALUES " . implode(',', $chunk));
                }
            }
            
            // Bulk Update Rooms
            foreach ($rooms_data as $room_id => $room) {
                if ($room['new_occupants'] > 0) {
                    $this->conn->query("UPDATE rooms SET occupied_count = occupied_count + {$room['new_occupants']} WHERE room_id = $room_id");
                }
            }

            // Commit the transaction
            $this->conn->commit();
            $inTransaction = false;

            // Release the mutual exclusion lock now that the transaction is committed
            if ($use_mutex) {
                $this->conn->query("SELECT RELEASE_LOCK('allocation_run_lock')");
            }

            $this->updateProgress($progressCallback, 'Allocation complete', 100);

            // Log allocation completion statistics
            $this->monitor->logStatistics();
            Logger::info("Allocation completed: {$allocated_count}/{$allocated_count} students processed, "
                . "Solver: $solver_mode, Status: $solver_status");

            return [
                'status' => 'success',
                'allocated' => $allocated_count,
                'total' => count($students),
                'prediction_mode' => $prediction_mode,
                'solver_mode' => $solver_mode,
                'solver_status' => $solver_status,
                'optimal' => $solver_status === 'OPTIMAL'
            ];

        } catch (Throwable $e) {
            // Rollback if anything fails — also catch rollback errors to prevent hung transactions
            if ($inTransaction) {
                try {
                    $this->conn->rollback();
                } catch (Throwable $rollbackErr) {
                    Logger::critical("CRITICAL: Rollback failed during allocation failure recovery. DB may be in inconsistent state: " . $rollbackErr->getMessage());
                }
            }
            // Always release the mutex lock on failure (only if we hold it)
            if ($use_mutex) {
                $this->conn->query("SELECT RELEASE_LOCK('allocation_run_lock')");
            }
            Logger::error("Allocation process failed: " . $e->getMessage());
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
                           COALESCE(NULLIF(m.condition_category, ''), 'None') as `condition`,
                           COALESCE(NULLIF(m.mobility_status, ''), 'Normal Mobility') as mobility,
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
        } catch (Throwable $e) {
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

    // =========================================================================
    // Phase 1 Remediation: Post-Solver Validation Helpers
    // =========================================================================

    /**
     * Returns true if this student has BOTH a qualifying mobility condition
     * AND a non-trivial medical severity (Medium or above).
     * These students are subject to the clinic-proximity hard constraint.
     */
    private function hasCombinedConditions(array $student): bool {
        $mobilityVal = strtolower(trim($student['mobility'] ?? ''));
        $isMobility  = $mobilityVal !== ''
                    && $mobilityVal !== 'normal mobility'
                    && $mobilityVal !== 'none';

        $severity   = strtolower(trim($student['severity'] ?? 'low'));
        $hasMedical = in_array($severity, ['medium', 'high', 'critical'], true);

        return $isMobility && $hasMedical;
    }

    /**
     * Returns true if the given room_id belongs to a clinic-proximal block.
     *   Male:   Prophet Moses Hall, Blocks 1 or 2
     *   Female: Queen Esther Extension Hall, Blocks 38 or 39
     *
     * This is intentionally a DB lookup (not hardcoded array) so that if the
     * is_clinic_proximity computed column is added to the schema later, this
     * query will automatically benefit from it.
     */
    private function isClinicProximityRoom(int $room_id): bool {
        $stmt = $this->conn->prepare("
            SELECT 1 FROM rooms r
            JOIN hostels h ON r.hostel_id = h.hostel_id
            WHERE r.room_id = ?
              AND (
                (h.name = 'Prophet Moses Hall'          AND h.block_name IN ('1','2'))
                OR
                (h.name = 'Queen Esther Extension Hall' AND h.block_name IN ('38','39'))
              )
            LIMIT 1
        ");
        if (!$stmt) return false;
        $stmt->bind_param("i", $room_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        return $result->num_rows > 0;
    }

    /**
     * Helper: Assign Bed based on configuration (LB/UB/SB)
     */

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



    /**
     * Execute a shell command and return its combined stdout+stderr output.
     *
     * When $heartbeatConn and $heartbeatJobId are provided, the method uses
     * proc_open instead of shell_exec so it can touch allocation_jobs.updated_at
     * every 60 seconds while the process is running. This prevents the stale-job
     * detector (STALE_JOB_MINUTES = 45) from incorrectly resetting a long OR-Tools
     * solve that emits no progress callbacks between 30% and completion.
     *
     * Falls back to shell_exec if proc_open is unavailable.
     */
    private function executeShellCommand(array $command_parts, ?mysqli $heartbeatConn = null, ?int $heartbeatJobId = null): ?string
    {
        $escaped_parts = array_map([$this, 'escapeCommandPart'], $command_parts);
        $command       = implode(' ', $escaped_parts);

        // Use proc_open when heartbeat is needed so we can poll the process
        // without blocking and periodically touch updated_at.
        if ($heartbeatConn instanceof mysqli && $heartbeatJobId !== null && function_exists('proc_open')) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = @proc_open($command, $descriptors, $pipes);
            if (is_resource($process)) {
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);

                $output      = '';
                $lastBeat    = time();
                $beatInterval = 60; // touch updated_at every 60 s during solve

                while (true) {
                    $chunk = fread($pipes[1], 8192);
                    if (is_string($chunk) && $chunk !== '') {
                        $output .= $chunk;
                    }
                    $err = fread($pipes[2], 8192);
                    if (is_string($err) && $err !== '') {
                        $output .= $err;
                    }

                    $status = proc_get_status($process);
                    if (!$status['running']) {
                        // Drain remaining output
                        $output .= stream_get_contents($pipes[1]);
                        $output .= stream_get_contents($pipes[2]);
                        break;
                    }

                    // Heartbeat: keep updated_at fresh so stale-job detector
                    // does not reset this job while the solver is running.
                    $now = time();
                    if ($now - $lastBeat >= $beatInterval) {
                        $lastBeat = $now;
                        @$heartbeatConn->query(
                            "UPDATE allocation_jobs SET updated_at = NOW() WHERE job_id = {$heartbeatJobId}"
                        );
                    }

                    usleep(500000); // poll every 0.5 s
                }

                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return trim($output);
            }
            // proc_open failed — fall through to shell_exec
        }

        $output = @shell_exec($command . ' 2>&1');
        return is_string($output) ? trim($output) : null;
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

        // Build a single bulk UPDATE with a CASE expression.
        // This avoids N round-trips to MySQL for large datasets (5k-15k students).
        $cases  = '';
        $ids    = [];
        foreach ($scores_map as $student_id => $score) {
            $sid    = (int)$student_id;
            $sc     = round((float)$score, 6);
            $cases .= " WHEN student_id = $sid THEN $sc";
            $ids[]  = $sid;
        }

        if (empty($ids)) {
            return 0;
        }

        $id_list = implode(',', $ids);
        $sql = "UPDATE medical_records
                   SET urgency_score = CASE $cases ELSE urgency_score END
                 WHERE student_id IN ($id_list)";

        $ok = $this->conn->query($sql);
        if (!$ok) {
            throw new Exception('Bulk urgency score update failed: ' . $this->conn->error);
        }

        return $this->conn->affected_rows;
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
