-- Database: fairmedalloc
-- Project: Machine Learning-Driven Hostel Allocation System
-- Author: FairMedAlloc Team
-- Updated: 2026-01-30 (Standardized Hostels)

CREATE DATABASE IF NOT EXISTS fairmedalloc;
USE fairmedalloc;

-- Disable foreign key checks for clean teardown/setup
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS algorithm_audit_logs;
DROP TABLE IF EXISTS allocations;
DROP TABLE IF EXISTS rooms;
DROP TABLE IF EXISTS hostels;
DROP TABLE IF EXISTS medical_records;
DROP TABLE IF EXISTS student_profiles;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS faqs;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS password_resets;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. Users Table (Authentication & Roles)
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL, 
    full_name VARCHAR(100),
    email VARCHAR(100),
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('student', 'admin', 'medical_officer') NOT NULL DEFAULT 'student',
    profile_pic VARCHAR(255) DEFAULT 'default.png',
    last_login TIMESTAMP NULL,
    login_attempts INT DEFAULT 0,
    lock_until TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 1b. Payments Table
CREATE TABLE payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    amount DECIMAL(10, 2) DEFAULT 0.00,
    reference_no VARCHAR(50) UNIQUE NOT NULL,
    status ENUM('paid', 'pending', 'failed') DEFAULT 'paid',
    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- Default Admin
INSERT INTO users (username, full_name, email, password_hash, role) 
VALUES ('AbdulQuadri', 'Admin Default', 'admin@fairmedalloc.com', '$2y$10$y70s17lPl9im2LEN17zvFORoJSaH7tDAtcmX3CIlzETGuXLYdaeQ2', 'admin'); 

-- 2. Student Profiles
CREATE TABLE student_profiles (
    profile_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNIQUE NOT NULL,
    matric_no VARCHAR(20) UNIQUE NOT NULL,
    gender ENUM('Male', 'Female') NOT NULL,
    level INT NOT NULL,
    faculty VARCHAR(50) NOT NULL,
    department VARCHAR(50),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- 3. Medical Records
CREATE TABLE medical_records (
    record_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    condition_category ENUM('None', 'Mobility', 'Respiratory', 'Visual', 'Other') DEFAULT 'None',
    mobility_status VARCHAR(50) DEFAULT 'Normal Mobility',
    condition_details TEXT,
    severity_level INT DEFAULT 0,
    urgency_score FLOAT DEFAULT 0,
    supporting_document_path VARCHAR(255),
    verification_status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending',
    is_requested_mobility BOOLEAN DEFAULT FALSE,
    verified_by INT,
    verified_at TIMESTAMP NULL,
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- 4. Hostels
CREATE TABLE hostels (
    hostel_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    block_name VARCHAR(50) DEFAULT 'Main Block',
    gender_allowed ENUM('Male', 'Female') NOT NULL,
    proximal_faculty VARCHAR(100),
    is_proximal BOOLEAN DEFAULT FALSE,
    has_elevator BOOLEAN DEFAULT FALSE,
    total_capacity INT NOT NULL,
    description VARCHAR(255)
);

-- 5. Rooms
CREATE TABLE rooms (
    room_id INT AUTO_INCREMENT PRIMARY KEY,
    hostel_id INT NOT NULL,
    room_number VARCHAR(10) NOT NULL,
    floor_level INT NOT NULL, -- 0=Ground, 1=First
    capacity INT DEFAULT 4,
    occupied_count INT DEFAULT 0,
    is_corner BOOLEAN DEFAULT FALSE,
    bed_config VARCHAR(255) DEFAULT NULL,    
    UNIQUE(hostel_id, room_number),
    FOREIGN KEY (hostel_id) REFERENCES hostels(hostel_id) ON DELETE CASCADE
);

-- 6. Allocations
CREATE TABLE allocations (
    allocation_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNIQUE NOT NULL,
    room_id INT NOT NULL,
    bed_space VARCHAR(5),
    bed_label ENUM('LB', 'TB', 'SB', 'UB') DEFAULT 'LB',
    academic_session VARCHAR(20) NOT NULL,
    allocated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    allocation_method ENUM('algorithm', 'manual') DEFAULT 'algorithm',
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(room_id)
);

-- 7. Audit Logs
CREATE TABLE algorithm_audit_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    run_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    input_severity INT,
    input_proximity_need BOOLEAN,
    calculated_urgency_score FLOAT,
    allocation_decision ENUM('Allocated', 'Waitlisted', 'No Bed'),
    assigned_hostel_id INT,
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- 8. Settings
CREATE TABLE settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(255)
);
INSERT INTO settings (setting_key, setting_value) VALUES 
('current_session', '2025/2026'),
('urgency_threshold_proximal', '75'),
('urgency_threshold_ground_floor', '85'),
('allocation_status', 'open');

-- 9. FAQs
CREATE TABLE faqs (
    faq_id INT AUTO_INCREMENT PRIMARY KEY,
    question VARCHAR(255) NOT NULL,
    answer TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO faqs (question, answer) VALUES 
('How is the "Urgency Score" calculated?', 'The system uses a Machine Learning algorithm (XGBoost) trained on historical medical data given by the school clinic. It considers your reported medical conditions, mobility status, and severity level to assign a priority score (0-100).'),
('What if my allocation is pending?', 'Allocations are done in batches. If your status is "Pending", the admin has likely not run the final allocation for the session yet. Ensure your profile is up to date.'),
('How do I correct a wrong medical entry?', 'You can edit your profile via the "Student Dashboard > Edit Profile" link. However, false claims are subject to physical verification at the University Health Center.');

-- 10. Notifications
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- 11. Password Resets
CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- ========================================================
-- SEED DATA (GENERATED STANDARD HOSTELS)
-- ========================================================

-- MALE HOSTELS
INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES
(1, 'Prophet Moses Hall', 'Block 1', 'Male', 'General', FALSE, 'Male Hostel', 80);
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES
(1, '1', 0, 4, 1, 'LB, UB, LB, UB'),
(1, '2', 0, 3, 0, 'SB, LB, UB'),
(1, '3', 0, 3, 0, 'SB, LB, UB'),
(1, '4', 0, 3, 0, 'SB, LB, UB'),
(1, '5', 0, 3, 0, 'SB, LB, UB'),
(1, '6', 0, 3, 0, 'SB, LB, UB'),
(1, '7', 0, 3, 0, 'SB, LB, UB'),
(1, '8', 0, 3, 0, 'SB, LB, UB'),
(1, '9', 0, 3, 0, 'SB, LB, UB'),
(1, '10', 0, 3, 0, 'SB, LB, UB'),
(1, '11', 0, 3, 0, 'SB, LB, UB'),
(1, '12', 0, 4, 1, 'LB, UB, LB, UB'),
(1, '13', 1, 4, 1, 'LB, UB, LB, UB'),
(1, '14', 1, 3, 0, 'SB, LB, UB'),
(1, '15', 1, 3, 0, 'SB, LB, UB'),
(1, '16', 1, 3, 0, 'SB, LB, UB'),
(1, '17', 1, 3, 0, 'SB, LB, UB'),
(1, '18', 1, 3, 0, 'SB, LB, UB'),
(1, '19', 1, 3, 0, 'SB, LB, UB'),
(1, '20', 1, 3, 0, 'SB, LB, UB'),
(1, '21', 1, 3, 0, 'SB, LB, UB'),
(1, '22', 1, 3, 0, 'SB, LB, UB'),
(1, '23', 1, 3, 0, 'SB, LB, UB'),
(1, '24', 1, 4, 1, 'LB, UB, LB, UB');

INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES
(2, 'Prophet Moses Hall', 'Block 2', 'Male', 'General', FALSE, 'Male Hostel', 80);
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES
(2, '1', 0, 4, 1, 'LB, UB, LB, UB'),
(2, '2', 0, 3, 0, 'SB, LB, UB'),
(2, '3', 0, 3, 0, 'SB, LB, UB'),
(2, '4', 0, 3, 0, 'SB, LB, UB'),
(2, '5', 0, 3, 0, 'SB, LB, UB'),
(2, '6', 0, 3, 0, 'SB, LB, UB'),
(2, '7', 0, 3, 0, 'SB, LB, UB'),
(2, '8', 0, 3, 0, 'SB, LB, UB'),
(2, '9', 0, 3, 0, 'SB, LB, UB'),
(2, '10', 0, 3, 0, 'SB, LB, UB'),
(2, '11', 0, 3, 0, 'SB, LB, UB'),
(2, '12', 0, 4, 1, 'LB, UB, LB, UB'),
(2, '13', 1, 4, 1, 'LB, UB, LB, UB'),
(2, '14', 1, 3, 0, 'SB, LB, UB'),
(2, '15', 1, 3, 0, 'SB, LB, UB'),
(2, '16', 1, 3, 0, 'SB, LB, UB'),
(2, '17', 1, 3, 0, 'SB, LB, UB'),
(2, '18', 1, 3, 0, 'SB, LB, UB'),
(2, '19', 1, 3, 0, 'SB, LB, UB'),
(2, '20', 1, 3, 0, 'SB, LB, UB'),
(2, '21', 1, 3, 0, 'SB, LB, UB'),
(2, '22', 1, 3, 0, 'SB, LB, UB'),
(2, '23', 1, 3, 0, 'SB, LB, UB'),
(2, '24', 1, 4, 1, 'LB, UB, LB, UB');

INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES
(3, 'Prophet Moses Hall', 'Block 3', 'Male', 'General', FALSE, 'Male Hostel', 80);
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES
(3, '1', 0, 4, 1, 'LB, UB, LB, UB'),
(3, '2', 0, 3, 0, 'SB, LB, UB'),
(3, '3', 0, 3, 0, 'SB, LB, UB'),
(3, '4', 0, 3, 0, 'SB, LB, UB'),
(3, '5', 0, 3, 0, 'SB, LB, UB'),
(3, '6', 0, 3, 0, 'SB, LB, UB'),
(3, '7', 0, 3, 0, 'SB, LB, UB'),
(3, '8', 0, 3, 0, 'SB, LB, UB'),
(3, '9', 0, 3, 0, 'SB, LB, UB'),
(3, '10', 0, 3, 0, 'SB, LB, UB'),
(3, '11', 0, 3, 0, 'SB, LB, UB'),
(3, '12', 0, 4, 1, 'LB, UB, LB, UB'),
(3, '13', 1, 4, 1, 'LB, UB, LB, UB'),
(3, '14', 1, 3, 0, 'SB, LB, UB'),
(3, '15', 1, 3, 0, 'SB, LB, UB'),
(3, '16', 1, 3, 0, 'SB, LB, UB'),
(3, '17', 1, 3, 0, 'SB, LB, UB'),
(3, '18', 1, 3, 0, 'SB, LB, UB'),
(3, '19', 1, 3, 0, 'SB, LB, UB'),
(3, '20', 1, 3, 0, 'SB, LB, UB'),
(3, '21', 1, 3, 0, 'SB, LB, UB'),
(3, '22', 1, 3, 0, 'SB, LB, UB'),
(3, '23', 1, 3, 0, 'SB, LB, UB'),
(3, '24', 1, 4, 1, 'LB, UB, LB, UB');

INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES
(4, 'Prophet Moses Extension Hall', 'Block 1', 'Male', 'Health Sciences', TRUE, 'Male Hostel', 80);
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES
(4, '1', 0, 4, 1, 'LB, UB, LB, UB'),
(4, '2', 0, 3, 0, 'SB, LB, UB'),
(4, '3', 0, 3, 0, 'SB, LB, UB'),
(4, '4', 0, 3, 0, 'SB, LB, UB'),
(4, '5', 0, 3, 0, 'SB, LB, UB'),
(4, '6', 0, 3, 0, 'SB, LB, UB'),
(4, '7', 0, 3, 0, 'SB, LB, UB'),
(4, '8', 0, 3, 0, 'SB, LB, UB'),
(4, '9', 0, 3, 0, 'SB, LB, UB'),
(4, '10', 0, 3, 0, 'SB, LB, UB'),
(4, '11', 0, 3, 0, 'SB, LB, UB'),
(4, '12', 0, 4, 1, 'LB, UB, LB, UB'),
(4, '13', 1, 4, 1, 'LB, UB, LB, UB'),
(4, '14', 1, 3, 0, 'SB, LB, UB'),
(4, '15', 1, 3, 0, 'SB, LB, UB'),
(4, '16', 1, 3, 0, 'SB, LB, UB'),
(4, '17', 1, 3, 0, 'SB, LB, UB'),
(4, '18', 1, 3, 0, 'SB, LB, UB'),
(4, '19', 1, 3, 0, 'SB, LB, UB'),
(4, '20', 1, 3, 0, 'SB, LB, UB'),
(4, '21', 1, 3, 0, 'SB, LB, UB'),
(4, '22', 1, 3, 0, 'SB, LB, UB'),
(4, '23', 1, 3, 0, 'SB, LB, UB'),
(4, '24', 1, 4, 1, 'LB, UB, LB, UB');

INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES
(5, 'Prophet Moses Extension Hall', 'Block 2', 'Male', 'Health Sciences', TRUE, 'Male Hostel', 80);
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES
(5, '1', 0, 4, 1, 'LB, UB, LB, UB'),
(5, '2', 0, 3, 0, 'SB, LB, UB'),
(5, '3', 0, 3, 0, 'SB, LB, UB'),
(5, '4', 0, 3, 0, 'SB, LB, UB'),
(5, '5', 0, 3, 0, 'SB, LB, UB'),
(5, '6', 0, 3, 0, 'SB, LB, UB'),
(5, '7', 0, 3, 0, 'SB, LB, UB'),
(5, '8', 0, 3, 0, 'SB, LB, UB'),
(5, '9', 0, 3, 0, 'SB, LB, UB'),
(5, '10', 0, 3, 0, 'SB, LB, UB'),
(5, '11', 0, 3, 0, 'SB, LB, UB'),
(5, '12', 0, 4, 1, 'LB, UB, LB, UB'),
(5, '13', 1, 4, 1, 'LB, UB, LB, UB'),
(5, '14', 1, 3, 0, 'SB, LB, UB'),
(5, '15', 1, 3, 0, 'SB, LB, UB'),
(5, '16', 1, 3, 0, 'SB, LB, UB'),
(5, '17', 1, 3, 0, 'SB, LB, UB'),
(5, '18', 1, 3, 0, 'SB, LB, UB'),
(5, '19', 1, 3, 0, 'SB, LB, UB'),
(5, '20', 1, 3, 0, 'SB, LB, UB'),
(5, '21', 1, 3, 0, 'SB, LB, UB'),
(5, '22', 1, 3, 0, 'SB, LB, UB'),
(5, '23', 1, 3, 0, 'SB, LB, UB'),
(5, '24', 1, 4, 1, 'LB, UB, LB, UB');

INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES
(6, 'Prophet Moses Extension Hall', 'Block 3', 'Male', 'Health Sciences', TRUE, 'Male Hostel', 80);
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES
(6, '1', 0, 4, 1, 'LB, UB, LB, UB'),
(6, '2', 0, 3, 0, 'SB, LB, UB'),
(6, '3', 0, 3, 0, 'SB, LB, UB'),
(6, '4', 0, 3, 0, 'SB, LB, UB'),
(6, '5', 0, 3, 0, 'SB, LB, UB'),
(6, '6', 0, 3, 0, 'SB, LB, UB'),
(6, '7', 0, 3, 0, 'SB, LB, UB'),
(6, '8', 0, 3, 0, 'SB, LB, UB'),
(6, '9', 0, 3, 0, 'SB, LB, UB'),
(6, '10', 0, 3, 0, 'SB, LB, UB'),
(6, '11', 0, 3, 0, 'SB, LB, UB'),
(6, '12', 0, 4, 1, 'LB, UB, LB, UB'),
(6, '13', 1, 4, 1, 'LB, UB, LB, UB'),
(6, '14', 1, 3, 0, 'SB, LB, UB'),
(6, '15', 1, 3, 0, 'SB, LB, UB'),
(6, '16', 1, 3, 0, 'SB, LB, UB'),
(6, '17', 1, 3, 0, 'SB, LB, UB'),
(6, '18', 1, 3, 0, 'SB, LB, UB'),
(6, '19', 1, 3, 0, 'SB, LB, UB'),
(6, '20', 1, 3, 0, 'SB, LB, UB'),
(6, '21', 1, 3, 0, 'SB, LB, UB'),
(6, '22', 1, 3, 0, 'SB, LB, UB'),
(6, '23', 1, 3, 0, 'SB, LB, UB'),
(6, '24', 1, 4, 1, 'LB, UB, LB, UB');

INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES
(7, 'Prophet Moses Engineering Hall', 'Block 1', 'Male', 'Engineering', FALSE, 'Male Hostel', 80);
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES
(7, '1', 0, 4, 1, 'LB, UB, LB, UB'),
(7, '2', 0, 3, 0, 'SB, LB, UB'),
(7, '3', 0, 3, 0, 'SB, LB, UB'),
(7, '4', 0, 3, 0, 'SB, LB, UB'),
(7, '5', 0, 3, 0, 'SB, LB, UB'),
(7, '6', 0, 3, 0, 'SB, LB, UB'),
(7, '7', 0, 3, 0, 'SB, LB, UB'),
(7, '8', 0, 3, 0, 'SB, LB, UB'),
(7, '9', 0, 3, 0, 'SB, LB, UB'),
(7, '10', 0, 3, 0, 'SB, LB, UB'),
(7, '11', 0, 3, 0, 'SB, LB, UB'),
(7, '12', 0, 4, 1, 'LB, UB, LB, UB'),
(7, '13', 1, 4, 1, 'LB, UB, LB, UB'),
(7, '14', 1, 3, 0, 'SB, LB, UB'),
(7, '15', 1, 3, 0, 'SB, LB, UB'),
(7, '16', 1, 3, 0, 'SB, LB, UB'),
(7, '17', 1, 3, 0, 'SB, LB, UB'),
(7, '18', 1, 3, 0, 'SB, LB, UB'),
(7, '19', 1, 3, 0, 'SB, LB, UB'),
(7, '20', 1, 3, 0, 'SB, LB, UB'),
(7, '21', 1, 3, 0, 'SB, LB, UB'),
(7, '22', 1, 3, 0, 'SB, LB, UB'),
(7, '23', 1, 3, 0, 'SB, LB, UB'),
(7, '24', 1, 4, 1, 'LB, UB, LB, UB');

INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES
(8, 'Prophet Moses Engineering Hall', 'Block 2', 'Male', 'Engineering', FALSE, 'Male Hostel', 80);
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES
(8, '1', 0, 4, 1, 'LB, UB, LB, UB'),
(8, '2', 0, 3, 0, 'SB, LB, UB'),
(8, '3', 0, 3, 0, 'SB, LB, UB'),
(8, '4', 0, 3, 0, 'SB, LB, UB'),
(8, '5', 0, 3, 0, 'SB, LB, UB'),
(8, '6', 0, 3, 0, 'SB, LB, UB'),
(8, '7', 0, 3, 0, 'SB, LB, UB'),
(8, '8', 0, 3, 0, 'SB, LB, UB'),
(8, '9', 0, 3, 0, 'SB, LB, UB'),
(8, '10', 0, 3, 0, 'SB, LB, UB'),
(8, '11', 0, 3, 0, 'SB, LB, UB'),
(8, '12', 0, 4, 1, 'LB, UB, LB, UB'),
(8, '13', 1, 4, 1, 'LB, UB, LB, UB'),
(8, '14', 1, 3, 0, 'SB, LB, UB'),
(8, '15', 1, 3, 0, 'SB, LB, UB'),
(8, '16', 1, 3, 0, 'SB, LB, UB'),
(8, '17', 1, 3, 0, 'SB, LB, UB'),
(8, '18', 1, 3, 0, 'SB, LB, UB'),
(8, '19', 1, 3, 0, 'SB, LB, UB'),
(8, '20', 1, 3, 0, 'SB, LB, UB'),
(8, '21', 1, 3, 0, 'SB, LB, UB'),
(8, '22', 1, 3, 0, 'SB, LB, UB'),
(8, '23', 1, 3, 0, 'SB, LB, UB'),
(8, '24', 1, 4, 1, 'LB, UB, LB, UB');

INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES
(9, 'Prophet Moses Engineering Hall', 'Block 3', 'Male', 'Engineering', FALSE, 'Male Hostel', 80);
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES
(9, '1', 0, 4, 1, 'LB, UB, LB, UB'),
(9, '2', 0, 3, 0, 'SB, LB, UB'),
(9, '3', 0, 3, 0, 'SB, LB, UB'),
(9, '4', 0, 3, 0, 'SB, LB, UB'),
(9, '5', 0, 3, 0, 'SB, LB, UB'),
(9, '6', 0, 3, 0, 'SB, LB, UB'),
(9, '7', 0, 3, 0, 'SB, LB, UB'),
(9, '8', 0, 3, 0, 'SB, LB, UB'),
(9, '9', 0, 3, 0, 'SB, LB, UB'),
(9, '10', 0, 3, 0, 'SB, LB, UB'),
(9, '11', 0, 3, 0, 'SB, LB, UB'),
(9, '12', 0, 4, 1, 'LB, UB, LB, UB'),
(9, '13', 1, 4, 1, 'LB, UB, LB, UB'),
(9, '14', 1, 3, 0, 'SB, LB, UB'),
(9, '15', 1, 3, 0, 'SB, LB, UB'),
(9, '16', 1, 3, 0, 'SB, LB, UB'),
(9, '17', 1, 3, 0, 'SB, LB, UB'),
(9, '18', 1, 3, 0, 'SB, LB, UB'),
(9, '19', 1, 3, 0, 'SB, LB, UB'),
(9, '20', 1, 3, 0, 'SB, LB, UB'),
(9, '21', 1, 3, 0, 'SB, LB, UB'),
(9, '22', 1, 3, 0, 'SB, LB, UB'),
(9, '23', 1, 3, 0, 'SB, LB, UB'),
(9, '24', 1, 4, 1, 'LB, UB, LB, UB');

-- FEMALE HOSTELS
INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES
(10, 'Queen Esther Main Hall', 'Block 1', 'Female', 'General', FALSE, 'Female Hostel', 80);
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES
(10, '1', 0, 4, 1, 'LB, UB, LB, UB'),
(10, '2', 0, 3, 0, 'SB, LB, UB'),
(10, '3', 0, 3, 0, 'SB, LB, UB'),
(10, '4', 0, 3, 0, 'SB, LB, UB'),
(10, '5', 0, 3, 0, 'SB, LB, UB'),
(10, '6', 0, 3, 0, 'SB, LB, UB'),
(10, '7', 0, 3, 0, 'SB, LB, UB'),
(10, '8', 0, 3, 0, 'SB, LB, UB'),
(10, '9', 0, 3, 0, 'SB, LB, UB'),
(10, '10', 0, 3, 0, 'SB, LB, UB'),
(10, '11', 0, 3, 0, 'SB, LB, UB'),
(10, '12', 0, 4, 1, 'LB, UB, LB, UB'),
(10, '13', 1, 4, 1, 'LB, UB, LB, UB'),
(10, '14', 1, 3, 0, 'SB, LB, UB'),
(10, '15', 1, 3, 0, 'SB, LB, UB'),
(10, '16', 1, 3, 0, 'SB, LB, UB'),
(10, '17', 1, 3, 0, 'SB, LB, UB'),
(10, '18', 1, 3, 0, 'SB, LB, UB'),
(10, '19', 1, 3, 0, 'SB, LB, UB'),
(10, '20', 1, 3, 0, 'SB, LB, UB'),
(10, '21', 1, 3, 0, 'SB, LB, UB'),
(10, '22', 1, 3, 0, 'SB, LB, UB'),
(10, '23', 1, 3, 0, 'SB, LB, UB'),
(10, '24', 1, 4, 1, 'LB, UB, LB, UB');

INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES
(11, 'Queen Esther Main Hall', 'Block 2', 'Female', 'General', FALSE, 'Female Hostel', 80);
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES
(11, '1', 0, 4, 1, 'LB, UB, LB, UB'),
(11, '2', 0, 3, 0, 'SB, LB, UB'),
(11, '3', 0, 3, 0, 'SB, LB, UB'),
(11, '4', 0, 3, 0, 'SB, LB, UB'),
(11, '5', 0, 3, 0, 'SB, LB, UB'),
(11, '6', 0, 3, 0, 'SB, LB, UB'),
(11, '7', 0, 3, 0, 'SB, LB, UB'),
(11, '8', 0, 3, 0, 'SB, LB, UB'),
(11, '9', 0, 3, 0, 'SB, LB, UB'),
(11, '10', 0, 3, 0, 'SB, LB, UB'),
(11, '11', 0, 3, 0, 'SB, LB, UB'),
(11, '12', 0, 4, 1, 'LB, UB, LB, UB'),
(11, '13', 1, 4, 1, 'LB, UB, LB, UB'),
(11, '14', 1, 3, 0, 'SB, LB, UB'),
(11, '15', 1, 3, 0, 'SB, LB, UB'),
(11, '16', 1, 3, 0, 'SB, LB, UB'),
(11, '17', 1, 3, 0, 'SB, LB, UB'),
(11, '18', 1, 3, 0, 'SB, LB, UB'),
(11, '19', 1, 3, 0, 'SB, LB, UB'),
(11, '20', 1, 3, 0, 'SB, LB, UB'),
(11, '21', 1, 3, 0, 'SB, LB, UB'),
(11, '22', 1, 3, 0, 'SB, LB, UB'),
(11, '23', 1, 3, 0, 'SB, LB, UB'),
(11, '24', 1, 4, 1, 'LB, UB, LB, UB');

INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES
(12, 'Queen Esther Main Hall', 'Block 3', 'Female', 'General', FALSE, 'Female Hostel', 80);
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES
(12, '1', 0, 4, 1, 'LB, UB, LB, UB'),
(12, '2', 0, 3, 0, 'SB, LB, UB'),
(12, '3', 0, 3, 0, 'SB, LB, UB'),
(12, '4', 0, 3, 0, 'SB, LB, UB'),
(12, '5', 0, 3, 0, 'SB, LB, UB'),
(12, '6', 0, 3, 0, 'SB, LB, UB'),
(12, '7', 0, 3, 0, 'SB, LB, UB'),
(12, '8', 0, 3, 0, 'SB, LB, UB'),
(12, '9', 0, 3, 0, 'SB, LB, UB'),
(12, '10', 0, 3, 0, 'SB, LB, UB'),
(12, '11', 0, 3, 0, 'SB, LB, UB'),
(12, '12', 0, 4, 1, 'LB, UB, LB, UB'),
(12, '13', 1, 4, 1, 'LB, UB, LB, UB'),
(12, '14', 1, 3, 0, 'SB, LB, UB'),
(12, '15', 1, 3, 0, 'SB, LB, UB'),
(12, '16', 1, 3, 0, 'SB, LB, UB'),
(12, '17', 1, 3, 0, 'SB, LB, UB'),
(12, '18', 1, 3, 0, 'SB, LB, UB'),
(12, '19', 1, 3, 0, 'SB, LB, UB'),
(12, '20', 1, 3, 0, 'SB, LB, UB'),
(12, '21', 1, 3, 0, 'SB, LB, UB'),
(12, '22', 1, 3, 0, 'SB, LB, UB'),
(12, '23', 1, 3, 0, 'SB, LB, UB'),
(12, '24', 1, 4, 1, 'LB, UB, LB, UB');

INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES
(13, 'Queen Esther Extension Hall', 'Block 1', 'Female', 'Health Sciences', TRUE, 'Female Hostel', 80);
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES
(13, '1', 0, 4, 1, 'LB, UB, LB, UB'),
(13, '2', 0, 3, 0, 'SB, LB, UB'),
(13, '3', 0, 3, 0, 'SB, LB, UB'),
(13, '4', 0, 3, 0, 'SB, LB, UB'),
(13, '5', 0, 3, 0, 'SB, LB, UB'),
(13, '6', 0, 3, 0, 'SB, LB, UB'),
(13, '7', 0, 3, 0, 'SB, LB, UB'),
(13, '8', 0, 3, 0, 'SB, LB, UB'),
(13, '9', 0, 3, 0, 'SB, LB, UB'),
(13, '10', 0, 3, 0, 'SB, LB, UB'),
(13, '11', 0, 3, 0, 'SB, LB, UB'),
(13, '12', 0, 4, 1, 'LB, UB, LB, UB'),
(13, '13', 1, 4, 1, 'LB, UB, LB, UB'),
(13, '14', 1, 3, 0, 'SB, LB, UB'),
(13, '15', 1, 3, 0, 'SB, LB, UB'),
(13, '16', 1, 3, 0, 'SB, LB, UB'),
(13, '17', 1, 3, 0, 'SB, LB, UB'),
(13, '18', 1, 3, 0, 'SB, LB, UB'),
(13, '19', 1, 3, 0, 'SB, LB, UB'),
(13, '20', 1, 3, 0, 'SB, LB, UB'),
(13, '21', 1, 3, 0, 'SB, LB, UB'),
(13, '22', 1, 3, 0, 'SB, LB, UB'),
(13, '23', 1, 3, 0, 'SB, LB, UB'),
(13, '24', 1, 4, 1, 'LB, UB, LB, UB');

INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES
(14, 'Queen Esther Extension Hall', 'Block 2', 'Female', 'Health Sciences', TRUE, 'Female Hostel', 80);
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES
(14, '1', 0, 4, 1, 'LB, UB, LB, UB'),
(14, '2', 0, 3, 0, 'SB, LB, UB'),
(14, '3', 0, 3, 0, 'SB, LB, UB'),
(14, '4', 0, 3, 0, 'SB, LB, UB'),
(14, '5', 0, 3, 0, 'SB, LB, UB'),
(14, '6', 0, 3, 0, 'SB, LB, UB'),
(14, '7', 0, 3, 0, 'SB, LB, UB'),
(14, '8', 0, 3, 0, 'SB, LB, UB'),
(14, '9', 0, 3, 0, 'SB, LB, UB'),
(14, '10', 0, 3, 0, 'SB, LB, UB'),
(14, '11', 0, 3, 0, 'SB, LB, UB'),
(14, '12', 0, 4, 1, 'LB, UB, LB, UB'),
(14, '13', 1, 4, 1, 'LB, UB, LB, UB'),
(14, '14', 1, 3, 0, 'SB, LB, UB'),
(14, '15', 1, 3, 0, 'SB, LB, UB'),
(14, '16', 1, 3, 0, 'SB, LB, UB'),
(14, '17', 1, 3, 0, 'SB, LB, UB'),
(14, '18', 1, 3, 0, 'SB, LB, UB'),
(14, '19', 1, 3, 0, 'SB, LB, UB'),
(14, '20', 1, 3, 0, 'SB, LB, UB'),
(14, '21', 1, 3, 0, 'SB, LB, UB'),
(14, '22', 1, 3, 0, 'SB, LB, UB'),
(14, '23', 1, 3, 0, 'SB, LB, UB'),
(14, '24', 1, 4, 1, 'LB, UB, LB, UB');

INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES
(15, 'Queen Esther Extension Hall', 'Block 3', 'Female', 'Health Sciences', TRUE, 'Female Hostel', 80);
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES
(15, '1', 0, 4, 1, 'LB, UB, LB, UB'),
(15, '2', 0, 3, 0, 'SB, LB, UB'),
(15, '3', 0, 3, 0, 'SB, LB, UB'),
(15, '4', 0, 3, 0, 'SB, LB, UB'),
(15, '5', 0, 3, 0, 'SB, LB, UB'),
(15, '6', 0, 3, 0, 'SB, LB, UB'),
(15, '7', 0, 3, 0, 'SB, LB, UB'),
(15, '8', 0, 3, 0, 'SB, LB, UB'),
(15, '9', 0, 3, 0, 'SB, LB, UB'),
(15, '10', 0, 3, 0, 'SB, LB, UB'),
(15, '11', 0, 3, 0, 'SB, LB, UB'),
(15, '12', 0, 4, 1, 'LB, UB, LB, UB'),
(15, '13', 1, 4, 1, 'LB, UB, LB, UB'),
(15, '14', 1, 3, 0, 'SB, LB, UB'),
(15, '15', 1, 3, 0, 'SB, LB, UB'),
(15, '16', 1, 3, 0, 'SB, LB, UB'),
(15, '17', 1, 3, 0, 'SB, LB, UB'),
(15, '18', 1, 3, 0, 'SB, LB, UB'),
(15, '19', 1, 3, 0, 'SB, LB, UB'),
(15, '20', 1, 3, 0, 'SB, LB, UB'),
(15, '21', 1, 3, 0, 'SB, LB, UB'),
(15, '22', 1, 3, 0, 'SB, LB, UB'),
(15, '23', 1, 3, 0, 'SB, LB, UB'),
(15, '24', 1, 4, 1, 'LB, UB, LB, UB');

INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES
(16, 'Queen Esther Engineering Hall (New)', 'Block 1', 'Female', 'Engineering', FALSE, 'Female Hostel', 80);
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES
(16, '1', 0, 4, 1, 'LB, UB, LB, UB'),
(16, '2', 0, 3, 0, 'SB, LB, UB'),
(16, '3', 0, 3, 0, 'SB, LB, UB'),
(16, '4', 0, 3, 0, 'SB, LB, UB'),
(16, '5', 0, 3, 0, 'SB, LB, UB'),
(16, '6', 0, 3, 0, 'SB, LB, UB'),
(16, '7', 0, 3, 0, 'SB, LB, UB'),
(16, '8', 0, 3, 0, 'SB, LB, UB'),
(16, '9', 0, 3, 0, 'SB, LB, UB'),
(16, '10', 0, 3, 0, 'SB, LB, UB'),
(16, '11', 0, 3, 0, 'SB, LB, UB'),
(16, '12', 0, 4, 1, 'LB, UB, LB, UB'),
(16, '13', 1, 4, 1, 'LB, UB, LB, UB'),
(16, '14', 1, 3, 0, 'SB, LB, UB'),
(16, '15', 1, 3, 0, 'SB, LB, UB'),
(16, '16', 1, 3, 0, 'SB, LB, UB'),
(16, '17', 1, 3, 0, 'SB, LB, UB'),
(16, '18', 1, 3, 0, 'SB, LB, UB'),
(16, '19', 1, 3, 0, 'SB, LB, UB'),
(16, '20', 1, 3, 0, 'SB, LB, UB'),
(16, '21', 1, 3, 0, 'SB, LB, UB'),
(16, '22', 1, 3, 0, 'SB, LB, UB'),
(16, '23', 1, 3, 0, 'SB, LB, UB'),
(16, '24', 1, 4, 1, 'LB, UB, LB, UB');

INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES
(17, 'Queen Esther Engineering Hall (New)', 'Block 2', 'Female', 'Engineering', FALSE, 'Female Hostel', 80);
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES
(17, '1', 0, 4, 1, 'LB, UB, LB, UB'),
(17, '2', 0, 3, 0, 'SB, LB, UB'),
(17, '3', 0, 3, 0, 'SB, LB, UB'),
(17, '4', 0, 3, 0, 'SB, LB, UB'),
(17, '5', 0, 3, 0, 'SB, LB, UB'),
(17, '6', 0, 3, 0, 'SB, LB, UB'),
(17, '7', 0, 3, 0, 'SB, LB, UB'),
(17, '8', 0, 3, 0, 'SB, LB, UB'),
(17, '9', 0, 3, 0, 'SB, LB, UB'),
(17, '10', 0, 3, 0, 'SB, LB, UB'),
(17, '11', 0, 3, 0, 'SB, LB, UB'),
(17, '12', 0, 4, 1, 'LB, UB, LB, UB'),
(17, '13', 1, 4, 1, 'LB, UB, LB, UB'),
(17, '14', 1, 3, 0, 'SB, LB, UB'),
(17, '15', 1, 3, 0, 'SB, LB, UB'),
(17, '16', 1, 3, 0, 'SB, LB, UB'),
(17, '17', 1, 3, 0, 'SB, LB, UB'),
(17, '18', 1, 3, 0, 'SB, LB, UB'),
(17, '19', 1, 3, 0, 'SB, LB, UB'),
(17, '20', 1, 3, 0, 'SB, LB, UB'),
(17, '21', 1, 3, 0, 'SB, LB, UB'),
(17, '22', 1, 3, 0, 'SB, LB, UB'),
(17, '23', 1, 3, 0, 'SB, LB, UB'),
(17, '24', 1, 4, 1, 'LB, UB, LB, UB');

INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES
(18, 'Queen Esther Engineering Hall (New)', 'Block 3', 'Female', 'Engineering', FALSE, 'Female Hostel', 80);
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES
(18, '1', 0, 4, 1, 'LB, UB, LB, UB'),
(18, '2', 0, 3, 0, 'SB, LB, UB'),
(18, '3', 0, 3, 0, 'SB, LB, UB'),
(18, '4', 0, 3, 0, 'SB, LB, UB'),
(18, '5', 0, 3, 0, 'SB, LB, UB'),
(18, '6', 0, 3, 0, 'SB, LB, UB'),
(18, '7', 0, 3, 0, 'SB, LB, UB'),
(18, '8', 0, 3, 0, 'SB, LB, UB'),
(18, '9', 0, 3, 0, 'SB, LB, UB'),
(18, '10', 0, 3, 0, 'SB, LB, UB'),
(18, '11', 0, 3, 0, 'SB, LB, UB'),
(18, '12', 0, 4, 1, 'LB, UB, LB, UB'),
(18, '13', 1, 4, 1, 'LB, UB, LB, UB'),
(18, '14', 1, 3, 0, 'SB, LB, UB'),
(18, '15', 1, 3, 0, 'SB, LB, UB'),
(18, '16', 1, 3, 0, 'SB, LB, UB'),
(18, '17', 1, 3, 0, 'SB, LB, UB'),
(18, '18', 1, 3, 0, 'SB, LB, UB'),
(18, '19', 1, 3, 0, 'SB, LB, UB'),
(18, '20', 1, 3, 0, 'SB, LB, UB'),
(18, '21', 1, 3, 0, 'SB, LB, UB'),
(18, '22', 1, 3, 0, 'SB, LB, UB'),
(18, '23', 1, 3, 0, 'SB, LB, UB'),
(18, '24', 1, 4, 1, 'LB, UB, LB, UB');
