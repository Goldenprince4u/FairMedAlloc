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
    bed_label ENUM('LB', 'TB', 'SB') DEFAULT 'LB', -- Lower Bunk, Top Bunk, Side Bunk
    
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
