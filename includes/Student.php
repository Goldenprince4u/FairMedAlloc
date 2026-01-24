<?php
/**
 * Student Model
 * Handles student-specific data retrieval.
 */
class Student {
    private $conn;
    private $user_id;

    public function __construct($db, $user_id) {
        $this->conn = $db;
        $this->user_id = $user_id;
    }

    /**
     * Get full profile with medical records
     */
    public function getProfile() {
        $stmt = $this->conn->prepare("SELECT p.*, m.condition_category, m.mobility_status, u.profile_pic 
                                      FROM student_profiles p 
                                      JOIN users u ON p.user_id = u.user_id 
                                      LEFT JOIN medical_records m ON p.user_id = m.student_id 
                                      WHERE p.user_id = ?");
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Get allocation details
     */
    public function getAllocation() {
        $stmt = $this->conn->prepare("SELECT a.*, r.room_number, h.name as hostel_name 
                                      FROM allocations a 
                                      JOIN rooms r ON a.room_id = r.room_id 
                                      JOIN hostels h ON r.hostel_id = h.hostel_id 
                                      WHERE a.student_id = ?");
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Check payment status
     */
    public function hasPaid() {
        $stmt = $this->conn->prepare("SELECT status FROM payments WHERE student_id = ? AND status = 'paid' LIMIT 1");
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }
}
?>
