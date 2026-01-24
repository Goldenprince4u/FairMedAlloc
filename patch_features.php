<?php
// Disable default exception mode to catch errors manually
mysqli_report(MYSQLI_REPORT_OFF);

require_once 'db_config.php';

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// 1. Create Notifications Table
$sql = "CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
)";

if ($conn->query($sql) === TRUE) {
    echo "Table 'notifications' created successfully.<br>";
} else {
    echo "Error creating table: " . $conn->error . "<br>";
}

// 2. Add Login Attempts to Users table (for Rate Limiting)
$sql2 = "ALTER TABLE users ADD COLUMN login_attempts INT DEFAULT 0, ADD COLUMN lock_until TIMESTAMP NULL";
if ($conn->query($sql2) === TRUE) {
    echo "Added 'login_attempts' to users table.<br>";
} else {
    // It might already exist, ignore
    echo "Column check: " . $conn->error . "<br>";
}
?>
