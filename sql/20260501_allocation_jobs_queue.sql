-- Allocation Jobs Queue Table
-- Tracks background allocation job status for async processing
-- Created: 2026-05-01

CREATE TABLE IF NOT EXISTS allocation_jobs (
    job_id INT AUTO_INCREMENT PRIMARY KEY,
    job_type VARCHAR(32) NOT NULL DEFAULT 'allocation',
    status VARCHAR(32) NOT NULL DEFAULT 'queued',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    progress_stage VARCHAR(64),
    progress_percent INT DEFAULT 0,
    total_students INT DEFAULT 0,
    allocated_students INT DEFAULT 0,
    result_data JSON,
    error_message TEXT,
    created_by_admin_id INT,
    FOREIGN KEY (created_by_admin_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_status_updated (status, updated_at),
    INDEX idx_admin (created_by_admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cleanup old jobs older than 30 days (optional)
CREATE EVENT IF NOT EXISTS cleanup_old_allocation_jobs
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
    DELETE FROM allocation_jobs 
    WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
