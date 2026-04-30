<?php
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

            foreach ($students as $student) {
                $student_id  = (int)$student['id'];
                $final_score = (float)$student['score'];
                $sev_int = $sev_map[$student['severity']] ?? (int)$student['severity'];
                $prox_need  = ($final_score >= $prox_threshold) ? 1 : 0;

                if (isset($assignments[$student_id]) && isset($rooms_data[$assignments[$student_id]])) {
                    $room_id = $assignments[$student_id];
                    $room = &$rooms_data[$room_id];
                    
                    $slot_index = -1;
                    $config_count = count($room['config_arr']);
                    for ($i = 0; $i < $config_count; $i++) {
                        if (!in_array($i, $room['occupied_indices'], true)) {
                            $slot_index = $i;
                            break;
                        }
                    }

                    if ($slot_index !== -1) {
                        $room['occupied_indices'][] = $slot_index;
                        $room['new_occupants']++;
                        
                        $bed_space = chr(65 + $slot_index);
                        $bed_label = $room['config_arr'][$slot_index] ?? 'LB';
                        
                        $bed_label_esc = $this->conn->real_escape_string($bed_label);
                        $sess_esc = $this->conn->real_escape_string($current_session);
                        
                        if ($has_algorithm_version_col) {
                            $bulk_allocations[] = "($student_id, $room_id, '$bed_space', '$bed_label_esc', '$sess_esc', 'algorithm', '$algo_version')";
                        } else {
                            $bulk_allocations[] = "($student_id, $room_id, '$bed_space', '$bed_label_esc', '$sess_esc', 'algorithm')";
                        }
                        
                        $bulk_profiles[] = $student_id;
                        
                        $hid = (int)$room['hostel_id'];
                        $bulk_audit[] = "($student_id, $sev_int, $prox_need, $final_score, 'Allocated', $hid)";
                        
                        $msg = $this->conn->real_escape_string("Congratulations! You have been allocated a room in {$room['hostel_name']}.");
                        $bulk_notifications[] = "($student_id, '$msg')";
                        
                        $allocated_count++;
                    } else {
                        $bulk_audit[] = "($student_id, $sev_int, $prox_need, $final_score, 'No Bed', NULL)";
                        $msg = $this->conn->real_escape_string("Update: You have been placed on the waiting list as no suitable rooms are currently available.");
                        $bulk_notifications[] = "($student_id, '$msg')";
                    }
                } else {
                    $bulk_audit[] = "($student_id, $sev_int, $prox_need, $final_score, 'No Bed', NULL)";
                    $msg = $this->conn->real_escape_string("Update: You have been placed on the waiting list as no suitable rooms are currently available.");
                    $bulk_notifications[] = "($student_id, '$msg')";
                }
            }

            // Execute Bulk Inserts
            if (!empty($bulk_allocations)) {
                $insert_cols = $has_algorithm_version_col ? 
                    "(student_id, room_id, bed_space, bed_label, academic_session, allocation_method, algorithm_version)" : 
                    "(student_id, room_id, bed_space, bed_label, academic_session, allocation_method)";
                
                foreach (array_chunk($bulk_allocations, 1000) as $chunk) {
                    $this->conn->query("INSERT INTO allocations $insert_cols VALUES " . implode(',', $chunk));
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
