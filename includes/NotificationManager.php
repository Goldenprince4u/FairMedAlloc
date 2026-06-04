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

    public function send(int $user_id, string $message): bool {
        $check = $this->conn->prepare(
            "SELECT message
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT 1"
        );
        if (!$check) { return false; }
        $check->bind_param('i', $user_id);
        $check->execute();
        $latest = $check->get_result()->fetch_assoc();
        $check->close();

        if (($latest['message'] ?? null) === $message) {
            return true; // De-duplicate identical back-to-back messages
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO notifications (user_id, message) VALUES (?, ?)"
        );
        if (!$stmt) { return false; }
        $stmt->bind_param('is', $user_id, $message);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    /**
     * Get unread notifications for a user
     */
    public function getUnread(int $user_id): array {
        $stmt = $this->conn->prepare(
            "SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC"
        );
        if (!$stmt) { return []; }
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * Get recent notifications (both read and unread)
     */
    public function getRecent($user_id, $limit = 5) {
        $stmt = $this->conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 25");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $unique = [];
        $seen_messages = [];
        foreach ($rows as $row) {
            $message = (string)($row['message'] ?? '');
            // Normalize message to prevent duplicates with slight whitespace/case differences
            $norm_msg = strtolower(preg_replace('/\s+/', ' ', trim($message)));
            if (isset($seen_messages[$norm_msg])) {
                continue;
            }
            $seen_messages[$norm_msg] = true;
            $unique[] = $row;
            if (count($unique) >= $limit) {
                break;
            }
        }

        return $unique;
    }

    /**
     * Mark all as read
     */
    public function markAllRead(int $user_id): bool {
        $stmt = $this->conn->prepare(
            "UPDATE notifications SET is_read = 1 WHERE user_id = ?"
        );
        if (!$stmt) { return false; }
        $stmt->bind_param('i', $user_id);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }
}
?>
