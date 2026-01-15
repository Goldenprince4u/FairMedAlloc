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
        // 1. Sync Occupancy (Safety Check)
        // Ensure room counts match actual allocations before starting
        $this->syncRoomOccupancy();

        // 2. Fetch ONLY NEW students (Not yet allocated)
        $sql = "SELECT p.user_id, p.gender, m.urgency_score, m.condition_category 
                FROM student_profiles p 
                LEFT JOIN medical_records m ON p.user_id = m.student_id 
                LEFT JOIN allocations a ON p.user_id = a.student_id
                WHERE a.student_id IS NULL  -- Only fetch those without a room
                ORDER BY m.urgency_score DESC";
        
        $result = $this->conn->query($sql);
        $students = $result->fetch_all(MYSQLI_ASSOC);

        $allocated_count = 0;

        // 3. Batch Processing Preparation
        $batch_payload = [];
        foreach ($students as $student) {
            // Build the payload for each student
            $batch_payload[] = [
                'id' => $student['user_id'],
                'condition' => $student['condition_category'],
                'urgency_score' => $student['urgency_score'], // Passing current basic score as a feature
                'severity' => 0 // Assuming severity is part of condition logic for now, or fetch from DB if needed
            ];
        }

        // 4. Execute Python Batch Logic
        // Write to system temp directory for security (prevents public access via browser)
        $temp_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fairmed_batch_' . uniqid() . '.json';
        file_put_contents($temp_file, json_encode($batch_payload));
        
        // Execute Python
        // Using relative path, assuming CWD is index or api root, but best to be absolute or relative to script
        // We use __DIR__ to be safe for finding the script
        $script_path = __DIR__ . '/../ml_models/predict.py';
        $command = "python \"$script_path\" \"$temp_file\"";
        
        $output = shell_exec($command);
        $result_data = json_decode($output, true);
        
        // Cleanup temp file
        if (file_exists($temp_file)) unlink($temp_file);

        // Check if Python returned valid results
        $scores_map = [];
        if (($result_data['status'] ?? '') === 'success') {
            $scores_map = $result_data['results'];
        }

        // 5. Process Allocations
        foreach ($students as $student) {
            $student_id = $student['user_id'];
            $gender = $student['gender'];
            
            // Get Score from Map (or fallback)
            $final_score = $scores_map[$student_id] ?? ($student['urgency_score'] ?? 0);

            // Find Best Room
            // Priority: Proximal Hostel if Score > 70
            $is_priority = $final_score >= 70;
            $room_id = $this->findAvailableRoom($gender, $is_priority);

            if ($room_id) {
                // Assign
                $stmt = $this->conn->prepare("INSERT INTO allocations (student_id, room_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $student_id, $room_id);
                $stmt->execute();
                
                // Update room occupancy
                $this->conn->query("UPDATE rooms SET occupied_count = occupied_count + 1 WHERE room_id = $room_id");
                $allocated_count++;
            }
        }

        # Python Bridge Output Debugging (Optional: remove in production)
        # file_put_contents('debug_log.txt', "Processed $allocated_count students.\n", FILE_APPEND);

        return [
            'status' => 'success',
            'allocated' => $allocated_count,
            'total' => count($students)
        ];
    }

    /**
     * Helper: Find a room based on constraints
     */
    private function findAvailableRoom($gender, $priority) {
        // Validation for valid Gender ENUM
        $gender = ($gender === 'Female') ? 'Female' : 'Male'; 

        // Strategy: Look for proximal first if priority, else general
        // If priority room full, fallback to general.
        
        $proximal_clause = $priority ? "DESC" : "ASC"; // If priority, we want is_proximal=1 first
        
        // Find Hostel ID that matches gender and has capacity
        // Join with Rooms to check actual bed space
        $sql = "SELECT r.room_id 
                FROM rooms r
                JOIN hostels h ON r.hostel_id = h.hostel_id
                WHERE h.gender_allowed = ? 
                AND r.occupied_count < r.capacity
                ORDER BY h.is_proximal $proximal_clause, r.floor_level ASC
                LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("s", $gender);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($res->num_rows > 0) {
            return $res->fetch_assoc()['room_id'];
        }
        
        return null; // No rooms available
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
