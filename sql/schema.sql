-- ============================================================
-- Database: fairmedalloc
-- Purpose : Schema only (no large seed data)
-- ============================================================

CREATE DATABASE IF NOT EXISTS fairmedalloc;
USE fairmedalloc;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS algorithm_audit_logs;
DROP TABLE IF EXISTS admin_audit_logs;
DROP TABLE IF EXISTS allocations;
DROP TABLE IF EXISTS rooms;
DROP TABLE IF EXISTS hostels;
DROP TABLE IF EXISTS medical_records;
DROP TABLE IF EXISTS student_profiles;
DROP TABLE IF EXISTS departments;
DROP TABLE IF EXISTS faculties;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS faqs;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS password_resets;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    full_name VARCHAR(100),
    email VARCHAR(100),
    password_hash VARCHAR(255) NOT NULL,
    must_change_password BOOLEAN NOT NULL DEFAULT FALSE,
    role ENUM('student','admin','medical_officer') NOT NULL DEFAULT 'student',
    profile_pic VARCHAR(255) DEFAULT 'default.png',
    last_login TIMESTAMP NULL,
    login_attempts INT DEFAULT 0,
    lock_until TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    amount DECIMAL(10,2) DEFAULT 0.00,
    reference_no VARCHAR(50) UNIQUE NOT NULL,
    status ENUM('paid','pending','failed') DEFAULT 'paid',
    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE faculties (
    faculty_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL
);

CREATE TABLE departments (
    department_id INT AUTO_INCREMENT PRIMARY KEY,
    faculty_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    FOREIGN KEY (faculty_id) REFERENCES faculties(faculty_id) ON DELETE CASCADE,
    UNIQUE (faculty_id, name)
);

CREATE TABLE student_profiles (
    profile_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNIQUE NOT NULL,
    gender ENUM('Male','Female') NOT NULL,
    level INT NOT NULL,
    department_id INT NOT NULL,
    allocation_status ENUM('Unallocated','Queued','Allocated') DEFAULT 'Unallocated',
    has_special_needs BOOLEAN DEFAULT FALSE,
    is_paid BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE RESTRICT
);

CREATE TABLE medical_records (
    record_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    condition_category VARCHAR(100) DEFAULT 'None / Healthy',
    mobility_status VARCHAR(50) DEFAULT 'Normal Mobility',
    condition_details TEXT,
    severity_level ENUM('Low', 'Medium', 'High') DEFAULT 'Low',
    urgency_score FLOAT DEFAULT 0,
    supporting_document_path VARCHAR(255),
    verification_status ENUM('Pending','Verified','Rejected') DEFAULT 'Pending',
    is_requested_mobility BOOLEAN DEFAULT FALSE,
    verified_by INT,
    verified_at TIMESTAMP NULL,
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_student_id (student_id),
    INDEX idx_condition (condition_category)
);

CREATE TABLE hostels (
    hostel_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    block_name VARCHAR(50) DEFAULT 'Main Block',
    gender_allowed ENUM('Male','Female') NOT NULL,
    proximal_faculty_id INT,
    is_proximal BOOLEAN DEFAULT FALSE,
    has_elevator BOOLEAN DEFAULT FALSE,
    is_postgrad BOOLEAN DEFAULT FALSE,
    is_foundation BOOLEAN DEFAULT FALSE,
    total_capacity INT NOT NULL,
    FOREIGN KEY (proximal_faculty_id) REFERENCES faculties(faculty_id) ON DELETE SET NULL
);

CREATE TABLE rooms (
    room_id INT AUTO_INCREMENT PRIMARY KEY,
    hostel_id INT NOT NULL,
    room_number VARCHAR(10) NOT NULL,
    floor_level INT DEFAULT 0,
    capacity INT DEFAULT 4,
    occupied_count INT DEFAULT 0,
    is_corner BOOLEAN DEFAULT FALSE,
    is_reserved BOOLEAN DEFAULT FALSE,
    bed_config VARCHAR(255) DEFAULT NULL,
    UNIQUE (hostel_id, room_number),
    FOREIGN KEY (hostel_id) REFERENCES hostels(hostel_id) ON DELETE CASCADE
);

CREATE TABLE allocations (
    allocation_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNIQUE NOT NULL,
    room_id INT NOT NULL,
    bed_space VARCHAR(5),
    bed_label ENUM('LB','TB','SB','UB') DEFAULT 'LB',
    academic_session VARCHAR(20) NOT NULL,
    allocated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    allocation_method ENUM('algorithm','manual') DEFAULT 'algorithm',
    algorithm_version VARCHAR(64) DEFAULT NULL,
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(room_id),
    UNIQUE KEY uniq_allocations_room_bed (room_id, bed_space),
    INDEX idx_room_id (room_id),
    INDEX idx_session (academic_session)
);

CREATE TABLE algorithm_audit_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    run_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    input_severity INT,
    input_proximity_need BOOLEAN,
    calculated_urgency_score FLOAT,
    allocation_decision ENUM('Allocated','Waitlisted','No Bed','Constraint Violation'),
    assigned_hostel_id INT,
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE admin_audit_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    action_description VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(255)
);

CREATE TABLE faqs (
    faq_id INT AUTO_INCREMENT PRIMARY KEY,
    question VARCHAR(255) NOT NULL,
    answer TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    UNIQUE KEY uniq_user_token (user_id, token_hash),
    INDEX idx_token (token_hash)
);
