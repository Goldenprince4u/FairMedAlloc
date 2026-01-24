<?php
// Disable default exception mode to catch errors manually
mysqli_report(MYSQLI_REPORT_OFF);

require_once 'db_config.php';

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$sql = "CREATE TABLE IF NOT EXISTS payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    amount DECIMAL(10, 2) DEFAULT 0.00,
    reference_no VARCHAR(50) UNIQUE NOT NULL,
    status ENUM('paid', 'pending', 'failed') DEFAULT 'paid',
    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE
)";

if ($conn->query($sql) === TRUE) {
    echo "Table 'payments' created successfully.";
} else {
    echo "Error creating table: " . $conn->error;
}
?>
