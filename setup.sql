-- Database: fairmedalloc
-- Project: Machine Learning-Driven Hostel Allocation System
-- Author: FairMedAlloc Team
-- Updated: 2026-01-14 (Robust Schema)

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
SET FOREIGN_KEY_CHECKS = 1;

-- 1. Users Table (Authentication & Roles)
-- Centralized identity management
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL, -- Matric No for students, 'admin' for admins
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('student', 'admin', 'medical_officer') NOT NULL DEFAULT 'student',
    profile_pic VARCHAR(255) DEFAULT 'default.png',
    last_login TIMESTAMP NULL,
    login_attempts INT DEFAULT 0,
    lock_until TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 1b. Payments Table (Normalization)
-- Tracks financial status independent of allocation
CREATE TABLE payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    amount DECIMAL(10, 2) DEFAULT 0.00,
    reference_no VARCHAR(50) UNIQUE NOT NULL,
    status ENUM('paid', 'pending', 'failed') DEFAULT 'paid',
    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- Default Admin (Password: fairmed2026)
INSERT INTO users (username, password_hash, role) 
VALUES ('AbdulQuadri', '$2y$10$8sA.N/e/P/x/R/w/y/z/0.1/2/3/4/5/6/7/8/9/0/1/2/3/4/5/6', 'admin'); 


-- 2. Student Profiles (Academic Data)
-- Linked 1:1 with users who have role='student'
CREATE TABLE student_profiles (
    profile_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNIQUE NOT NULL, -- Link to users table
    matric_no VARCHAR(20) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    gender ENUM('Male', 'Female') NOT NULL,
    level INT NOT NULL, -- 100, 200, 300, 400, 500
    faculty VARCHAR(50) NOT NULL,
    department VARCHAR(50),
    
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- 3. Medical Records (The "Fairness" Engine)
-- Granular tracking of medical claims for algorithm scoring
CREATE TABLE medical_records (
    record_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL, -- Link to users(user_id) or student_profiles? users(user_id) is safer for auth
    condition_category ENUM('None', 'Mobility', 'Respiratory', 'Visual', 'Other') DEFAULT 'None',
    mobility_status VARCHAR(50) DEFAULT 'Normal Mobility', -- New Column
    condition_details TEXT, -- Specifics like "Asthma", "Wheelchair user"
    severity_level INT DEFAULT 0, -- 1-10 Scale (10 = Critical/Life Threatening)
    urgency_score FLOAT DEFAULT 0, -- Calculated by Algorithm
    supporting_document_path VARCHAR(255), -- Path to uploaded medical report
    
    -- Verification Workflow
    verification_status ENUM('Pending', 'Verified', 'Rejected') DEFAULT 'Pending',
    verified_by INT, -- Link to admin/medical_officer user_id
    verified_at TIMESTAMP NULL,
    
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(user_id)
);

-- 4. Hostels (Building Inventory)
CREATE TABLE hostels (
    hostel_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    block_name VARCHAR(50) DEFAULT 'Main Block', -- New: Block Support
    gender_allowed ENUM('Male', 'Female') NOT NULL,
    proximal_faculty VARCHAR(100), -- New: Faculty Proximity (e.g. 'Science', 'Humanities')
    is_proximal BOOLEAN DEFAULT FALSE, -- Proximity to Clinic
    has_elevator BOOLEAN DEFAULT FALSE,
    total_capacity INT NOT NULL, -- Cached total
    description VARCHAR(255)
);

-- 5. Rooms (Granular Space Management)
-- Specific rooms allowing for Ground Floor logic
CREATE TABLE rooms (
    room_id INT AUTO_INCREMENT PRIMARY KEY,
    hostel_id INT NOT NULL,
    room_number VARCHAR(10) NOT NULL,
    floor_level INT NOT NULL, -- 0 = Ground, 1 = First, etc.
    capacity INT DEFAULT 4,
    occupied_count INT DEFAULT 0,
    is_corner BOOLEAN DEFAULT FALSE,
    bed_config VARCHAR(255) DEFAULT NULL,    
    -- Constraints
    UNIQUE(hostel_id, room_number),
    FOREIGN KEY (hostel_id) REFERENCES hostels(hostel_id) ON DELETE CASCADE
);

-- Seed Hostels & Rooms
-- Male Hostels
INSERT INTO hostels (name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES 
('Prophet Moses Hall', 'Block A', 'Male', 'Humanities', FALSE, 'General Male Hostel', 500), 
('Prophet Moses Hall', 'Block B', 'Male', 'Science', FALSE, 'Near Science Labs', 300),
('Daniel Hall', 'Main Block', 'Male', 'General', TRUE, 'Male Hostel near Clinic', 150);

-- Female Hostels
INSERT INTO hostels (name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES 
('Queen Esther Hall', 'Block A', 'Female', 'Social Sciences', FALSE, 'General Female Hostel', 600),
('Queen Esther Hall', 'Block C', 'Female', 'Science', FALSE, 'Near Labs', 400),
('Mary Hall', 'Main Block', 'Female', 'General', TRUE, 'Female Hostel near Clinic', 200);

-- Seed Rooms (Example)
-- Needs a stored procedure or loop in real life, but manual for now
-- Adding some Ground Floor rooms for "Daniel Hall" (id=3 since autoincrement)
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity) VALUES 
(3, 'G01', 0, 4), (3, 'G02', 0, 4), (3, '101', 1, 4);
-- Rooms for Prophet Moses Block B (Science) - ID 2
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity) VALUES
(2, '101', 1, 4), (2, '102', 1, 4);


-- 6. Allocations (Optimization Results)
CREATE TABLE allocations (
    allocation_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNIQUE NOT NULL, -- Linked to users
    room_id INT NOT NULL,
    bed_space VARCHAR(5), -- 'A', 'B', 'C', 'D' - Internal ID
    bed_label ENUM('LB', 'TB', 'SB', 'UB') DEFAULT 'LB', -- Lower Bunk, Top Bunk, Side Bunk, Upper Bunk
    
    -- Audit data
    allocated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    allocation_method ENUM('algorithm', 'manual') DEFAULT 'algorithm',
    
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(room_id)
);

-- 7. Algorithm Audit Logs (Fairness Verification)
-- Keeps track of why a student got a score
CREATE TABLE algorithm_audit_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    run_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Inputs Snapshot
    input_severity INT,
    input_proximity_need BOOLEAN,
    
    -- Output
    calculated_urgency_score FLOAT, -- The XGBoost Score
    allocation_decision ENUM('Allocated', 'Waitlisted', 'No Bed'),
    assigned_hostel_id INT,
    
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- 8. Global Settings
CREATE TABLE settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(255)
);

INSERT INTO settings (setting_key, setting_value) VALUES 
('current_session', '2025/2026'),
('urgency_threshold_proximal', '75'), -- Score > 75 gets Proximal Hostel
('urgency_threshold_ground_floor', '85'), -- Score > 85 gets Ground Floor
('allocation_status', 'open');

-- 9. FAQs (Dynamic Help Center)
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

-- Prophet Moses Hall Seeding
INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES 
(4, 'Prophet Moses Hall', 'Block 1', 'Male', 'General', 0, 'Prophet Moses Hostel Block', 76);
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
(5, 'Prophet Moses Hall', 'Block 2', 'Male', 'General', 0, 'Prophet Moses Hostel Block', 76);
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
(6, 'Prophet Moses Hall', 'Block 3', 'Male', 'General', 0, 'Prophet Moses Hostel Block', 76);
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
(7, 'Prophet Moses Hall', 'Block 4', 'Male', 'General', 0, 'Prophet Moses Hostel Block', 76);
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
(8, 'Prophet Moses Hall', 'Block 5', 'Male', 'General', 0, 'Prophet Moses Hostel Block', 76);
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
(9, 'Prophet Moses Hall', 'Block 6', 'Male', 'General', 0, 'Prophet Moses Hostel Block', 76);
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

INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES 
(10, 'Prophet Moses Hall', 'Block 7', 'Male', 'General', 0, 'Prophet Moses Hostel Block', 76);
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
(11, 'Prophet Moses Hall', 'Block 8', 'Male', 'General', 0, 'Prophet Moses Hostel Block', 76);
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
(12, 'Prophet Moses Hall', 'Block 9', 'Male', 'General', 0, 'Prophet Moses Hostel Block', 76);
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
(13, 'Prophet Moses Hall', 'Block 10', 'Male', 'General', 0, 'Prophet Moses Hostel Block', 76);
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
(14, 'Prophet Moses Hall', 'Block 11', 'Male', 'General', 0, 'Prophet Moses Hostel Block', 76);
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
(15, 'Prophet Moses Hall', 'Block 12', 'Male', 'General', 0, 'Prophet Moses Hostel Block', 76);
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
(16, 'Prophet Moses Hall', 'Block 13', 'Male', 'General', 0, 'Prophet Moses Hostel Block', 76);
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
(17, 'Prophet Moses Hall', 'Block 14', 'Male', 'General', 0, 'Prophet Moses Hostel Block', 76);
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
(18, 'Prophet Moses Hall', 'Block 15', 'Male', 'General', 0, 'Prophet Moses Hostel Block', 76);
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

INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES 
(19, 'Prophet Moses Hall', 'Block 16', 'Male', 'General', 0, 'Prophet Moses Hostel Block', 76);
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES 
(19, '1', 0, 4, 1, 'LB, UB, LB, UB'),
(19, '2', 0, 3, 0, 'SB, LB, UB'),
(19, '3', 0, 3, 0, 'SB, LB, UB'),
(19, '4', 0, 3, 0, 'SB, LB, UB'),
(19, '5', 0, 3, 0, 'SB, LB, UB'),
(19, '6', 0, 3, 0, 'SB, LB, UB'),
(19, '7', 0, 3, 0, 'SB, LB, UB'),
(19, '8', 0, 3, 0, 'SB, LB, UB'),
(19, '9', 0, 3, 0, 'SB, LB, UB'),
(19, '10', 0, 3, 0, 'SB, LB, UB'),
(19, '11', 0, 3, 0, 'SB, LB, UB'),
(19, '12', 0, 4, 1, 'LB, UB, LB, UB'),
(19, '13', 1, 4, 1, 'LB, UB, LB, UB'),
(19, '14', 1, 3, 0, 'SB, LB, UB'),
(19, '15', 1, 3, 0, 'SB, LB, UB'),
(19, '16', 1, 3, 0, 'SB, LB, UB'),
(19, '17', 1, 3, 0, 'SB, LB, UB'),
(19, '18', 1, 3, 0, 'SB, LB, UB'),
(19, '19', 1, 3, 0, 'SB, LB, UB'),
(19, '20', 1, 3, 0, 'SB, LB, UB'),
(19, '21', 1, 3, 0, 'SB, LB, UB'),
(19, '22', 1, 3, 0, 'SB, LB, UB'),
(19, '23', 1, 3, 0, 'SB, LB, UB'),
(19, '24', 1, 4, 1, 'LB, UB, LB, UB');

INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES 
(20, 'Prophet Moses Hall', 'Block 17', 'Male', 'General', 0, 'Prophet Moses Hostel Block', 76);
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES 
(20, '1', 0, 4, 1, 'LB, UB, LB, UB'),
(20, '2', 0, 3, 0, 'SB, LB, UB'),
(20, '3', 0, 3, 0, 'SB, LB, UB'),
(20, '4', 0, 3, 0, 'SB, LB, UB'),
(20, '5', 0, 3, 0, 'SB, LB, UB'),
(20, '6', 0, 3, 0, 'SB, LB, UB'),
(20, '7', 0, 3, 0, 'SB, LB, UB'),
(20, '8', 0, 3, 0, 'SB, LB, UB'),
(20, '9', 0, 3, 0, 'SB, LB, UB'),
(20, '10', 0, 3, 0, 'SB, LB, UB'),
(20, '11', 0, 3, 0, 'SB, LB, UB'),
(20, '12', 0, 4, 1, 'LB, UB, LB, UB'),
(20, '13', 1, 4, 1, 'LB, UB, LB, UB'),
(20, '14', 1, 3, 0, 'SB, LB, UB'),
(20, '15', 1, 3, 0, 'SB, LB, UB'),
(20, '16', 1, 3, 0, 'SB, LB, UB'),
(20, '17', 1, 3, 0, 'SB, LB, UB'),
(20, '18', 1, 3, 0, 'SB, LB, UB'),
(20, '19', 1, 3, 0, 'SB, LB, UB'),
(20, '20', 1, 3, 0, 'SB, LB, UB'),
(21, '21', 1, 3, 0, 'SB, LB, UB'),
(20, '22', 1, 3, 0, 'SB, LB, UB'),
(20, '23', 1, 3, 0, 'SB, LB, UB'),
(20, '24', 1, 4, 1, 'LB, UB, LB, UB');

INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty, is_proximal, description, total_capacity) VALUES 
(21, 'Prophet Moses Hall', 'Block 18', 'Male', 'General', 0, 'Prophet Moses Hostel Block', 76);
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES 
(21, '1', 0, 4, 1, 'LB, UB, LB, UB'),
(21, '2', 0, 3, 0, 'SB, LB, UB'),
(21, '3', 0, 3, 0, 'SB, LB, UB'),
(21, '4', 0, 3, 0, 'SB, LB, UB'),
(21, '5', 0, 3, 0, 'SB, LB, UB'),
(21, '6', 0, 3, 0, 'SB, LB, UB'),
(21, '7', 0, 3, 0, 'SB, LB, UB'),
(21, '8', 0, 3, 0, 'SB, LB, UB'),
(21, '9', 0, 3, 0, 'SB, LB, UB'),
(21, '10', 0, 3, 0, 'SB, LB, UB'),
(21, '11', 0, 3, 0, 'SB, LB, UB'),
(21, '12', 0, 4, 1, 'LB, UB, LB, UB'),
(21, '13', 1, 4, 1, 'LB, UB, LB, UB'),
(21, '14', 1, 3, 0, 'SB, LB, UB'),
(21, '15', 1, 3, 0, 'SB, LB, UB'),
(21, '16', 1, 3, 0, 'SB, LB, UB'),
(21, '17', 1, 3, 0, 'SB, LB, UB'),
(21, '18', 1, 3, 0, 'SB, LB, UB'),
(21, '19', 1, 3, 0, 'SB, LB, UB'),
(21, '20', 1, 3, 0, 'SB, LB, UB'),
(21, '21', 1, 3, 0, 'SB, LB, UB'),
(21, '22', 1, 3, 0, 'SB, LB, UB'),
(21, '23', 1, 3, 0, 'SB, LB, UB'),
(21, '24', 1, 4, 1, 'LB, UB, LB, UB');
