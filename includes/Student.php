<?php
/**
 * Student Model
 * Handles student-specific data retrieval.
 */
class Student {
    private mysqli $conn;
    private int $user_id;
    private string $lastProfileLookupStatus = 'not_loaded';

    public function __construct(mysqli $db, int $user_id) {
        $this->conn    = $db;
        $this->user_id = $user_id;
    }

    /**
     * Get full profile with medical records.
     * Uses a correlated subquery on the medical LEFT JOIN to prevent duplicate
     * rows when a student has more than one medical_records entry.
     */
    public function getProfile(): ?array {
        $this->lastProfileLookupStatus = 'query_error';
        $stmt = $this->conn->prepare(
            "SELECT p.*, m.condition_category, m.mobility_status,
                    u.profile_pic, u.full_name, u.email,
                    u.username AS matric_no,
                    d.name AS department, f.name AS faculty
             FROM   student_profiles p
             JOIN   users       u ON p.user_id       = u.user_id
             JOIN   departments d ON p.department_id = d.department_id
             JOIN   faculties   f ON d.faculty_id    = f.faculty_id
             LEFT JOIN medical_records m
                    ON  p.user_id = m.student_id
                    AND m.record_id = (
                        SELECT record_id FROM medical_records
                        WHERE student_id = p.user_id
                        ORDER BY record_id DESC LIMIT 1
                    )
             WHERE  p.user_id = ?
             LIMIT  1"
        );
        if (!$stmt) {
            error_log('[FairMedAlloc] Student::getProfile prepare failed: ' . $this->conn->error);
            return null;
        }
        $stmt->bind_param('i', $this->user_id);
        if (!$stmt->execute()) {
            error_log('[FairMedAlloc] Student::getProfile execute failed for user ' . $this->user_id . ': ' . $stmt->error);
            $stmt->close();
            return null;
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $this->lastProfileLookupStatus = $row ? 'ok' : 'missing_profile';
        return $row ?: null;
    }

    public function getLastProfileLookupStatus(): string {
        return $this->lastProfileLookupStatus;
    }

    /**
     * Get allocation details.
     * Returns the most-recent allocation (ORDER BY created_at DESC) in case
     * re-allocations occur.
     */
    public function getAllocation(): ?array {
        $stmt = $this->conn->prepare(
            "SELECT a.*, r.room_number, h.name AS hostel_name, h.block_name
             FROM   allocations a
             JOIN   rooms   r ON a.room_id  = r.room_id
             JOIN   hostels h ON r.hostel_id = h.hostel_id
             WHERE  a.student_id = ?
             ORDER BY a.created_at DESC
             LIMIT 1"
        );
        if (!$stmt) { return null; }
        $stmt->bind_param('i', $this->user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row;
    }

    /**
     * Check payment status.
     * Uses a lightweight single-column query instead of re-running getProfile(),
     * which would fire the expensive multi-table JOIN a second time.
     */
    public function hasPaid(): bool {
        $stmt = $this->conn->prepare(
            "SELECT is_paid FROM student_profiles WHERE user_id = ? LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('i', $this->user_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!empty($row['is_paid'])) {
                return true;
            }
        }

        // Fallback: check the payments table in case is_paid flag lags behind
        $stmt2 = $this->conn->prepare(
            "SELECT status FROM payments WHERE student_id = ? AND status = 'paid' LIMIT 1"
        );
        if (!$stmt2) { return false; }
        $stmt2->bind_param('i', $this->user_id);
        $stmt2->execute();
        $found = $stmt2->get_result()->num_rows > 0;
        $stmt2->close();
        return $found;
    }
}
?>
