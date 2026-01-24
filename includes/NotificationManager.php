<?php
/**
 * Notification Manager
 * Handles creating and fetching user notifications.
 */
class NotificationManager {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * Create a new notification
     */
    public function send($user_id, $message) {
        $stmt = $this->conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        $stmt->bind_param("is", $user_id, $message);
        return $stmt->execute();
    }

    /**
     * Get unread notifications for a user
     */
    public function getUnread($user_id) {
        $stmt = $this->conn->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Mark all as read
     */
    public function markAllRead($user_id) {
        $stmt = $this->conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        return $stmt->execute();
    }
}
?>
