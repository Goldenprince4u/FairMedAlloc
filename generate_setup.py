"""
Generates setup.sql with flat INSERT statements (no stored procedures).
Compatible with: mysql CLI pipe, phpMyAdmin, any MySQL client.
"""

# ── Room generators ────────────────────────────────────────────────────────────

def std_block_rooms(hostel_id):
    """
    Standard flat block: 24 rooms, ALL on floor 0 (single ground floor).
    Layout: two side-by-side corridors of 12 rooms each.
      Corner rooms (capacity=4, LB,UB,LB,UB): 1, 12, 13, 24
      Normal rooms (capacity=3, SB,LB,UB)   : 2-11, 14-23
    Total: 4×4 + 20×3 = 76 students
    """
    rows = []
    for i in range(1, 25):
        if i in (1, 12, 13, 24):
            cap, corner, cfg = 4, 1, 'LB,UB,LB,UB'
        else:
            cap, corner, cfg = 3, 0, 'SB,UB,LB'
        rows.append(f"({hostel_id}, '{i}', 0, {cap}, {corner}, '{cfg}')")
    return (
        "INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES\n"
        + ",\n".join(rows) + ";"
    )


def eng_block_rooms(hostel_id):
    """
    Engineering duplex block: 60 rooms, 2 floors.
      Floor 0 (Ground): rooms  1-30
      Floor 1 (First) : rooms 31-60
    All rooms: capacity=4, LB,UB,LB,UB (2 bunks)
    Total: 60×4 = 240 students
    """
    rows = []
    for i in range(1, 61):
        flr = 0 if i <= 30 else 1
        rows.append(f"({hostel_id}, '{i}', {flr}, 4, 0, 'LB,UB,LB,UB')")
    return (
        "INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, bed_config) VALUES\n"
        + ",\n".join(rows) + ";"
    )


# ── Hostel header generators ───────────────────────────────────────────────────

def hostel_row(hid, name, block_num, gender, htype, prox_fac, is_prox, desc, cap):
    prox = 'NULL' if prox_fac is None else str(prox_fac)
    isp  = 'TRUE' if is_prox else 'FALSE'
    return f"({hid}, '{name}', 'Block {block_num}', '{gender}', '{htype}', {prox}, {isp}, '{desc}', {cap})"


# ── Main SQL builder ───────────────────────────────────────────────────────────

lines = []

def w(*args):
    lines.extend(args)
    if args and args[-1] != '':
        pass  # keep going
    else:
        lines.append('')

# ── Header ────────────────────────────────────────────────────────────────────
lines += [
    "-- ============================================================",
    "-- Database: fairmedalloc",
    "-- Project : Machine Learning-Driven Hostel Allocation System",
    "-- Updated : 2026-04-07 (Flat INSERTs, no stored procedures)",
    "-- ============================================================",
    "",
    "CREATE DATABASE IF NOT EXISTS fairmedalloc;",
    "USE fairmedalloc;",
    "",
    "SET FOREIGN_KEY_CHECKS = 0;",
    "DROP TABLE IF EXISTS algorithm_audit_logs;",
    "DROP TABLE IF EXISTS admin_audit_logs;",
    "DROP TABLE IF EXISTS allocations;",
    "DROP TABLE IF EXISTS rooms;",
    "DROP TABLE IF EXISTS hostels;",
    "DROP TABLE IF EXISTS medical_records;",
    "DROP TABLE IF EXISTS student_profiles;",
    "DROP TABLE IF EXISTS departments;",
    "DROP TABLE IF EXISTS faculties;",
    "DROP TABLE IF EXISTS users;",
    "DROP TABLE IF EXISTS settings;",
    "DROP TABLE IF EXISTS payments;",
    "DROP TABLE IF EXISTS faqs;",
    "DROP TABLE IF EXISTS notifications;",
    "DROP TABLE IF EXISTS password_resets;",
    "SET FOREIGN_KEY_CHECKS = 1;",
    "",
]

# ── Table definitions ─────────────────────────────────────────────────────────
lines += [
    "-- 1. Users",
    "CREATE TABLE users (",
    "    user_id INT AUTO_INCREMENT PRIMARY KEY,",
    "    username VARCHAR(50) UNIQUE NOT NULL,",
    "    full_name VARCHAR(100),",
    "    email VARCHAR(100),",
    "    password_hash VARCHAR(255) NOT NULL,",
    "    role ENUM('student','admin','medical_officer') NOT NULL DEFAULT 'student',",
    "    profile_pic VARCHAR(255) DEFAULT 'default.png',",
    "    last_login TIMESTAMP NULL,",
    "    login_attempts INT DEFAULT 0,",
    "    lock_until TIMESTAMP NULL,",
    "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
    ");",
    "",
    "-- 1b. Payments",
    "CREATE TABLE payments (",
    "    payment_id INT AUTO_INCREMENT PRIMARY KEY,",
    "    student_id INT NOT NULL,",
    "    amount DECIMAL(10,2) DEFAULT 0.00,",
    "    reference_no VARCHAR(50) UNIQUE NOT NULL,",
    "    status ENUM('paid','pending','failed') DEFAULT 'paid',",
    "    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,",
    "    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE",
    ");",
    "",
    "-- Default admin (password: Admin@2026)",
    "INSERT INTO users (username, full_name, email, password_hash, role)",
    "VALUES ('AbdulQuadri','Admin Default','admin@fairmedalloc.com',",
    "        '$2y$10$y70s17lPl9im2LEN17zvFORoJSaH7tDAtcmX3CIlzETGuXLYdaeQ2','admin');",
    "",
    "-- 2. Faculties",
    "CREATE TABLE faculties (",
    "    faculty_id INT AUTO_INCREMENT PRIMARY KEY,",
    "    name VARCHAR(100) UNIQUE NOT NULL",
    ");",
    "",
    "-- 3. Departments",
    "CREATE TABLE departments (",
    "    department_id INT AUTO_INCREMENT PRIMARY KEY,",
    "    faculty_id INT NOT NULL,",
    "    name VARCHAR(100) NOT NULL,",
    "    FOREIGN KEY (faculty_id) REFERENCES faculties(faculty_id) ON DELETE CASCADE,",
    "    UNIQUE(faculty_id, name)",
    ");",
    "",
    "-- 4. Student Profiles",
    "CREATE TABLE student_profiles (",
    "    profile_id INT AUTO_INCREMENT PRIMARY KEY,",
    "    user_id INT UNIQUE NOT NULL,",
    "    matric_no VARCHAR(20) UNIQUE NOT NULL,",
    "    gender ENUM('Male','Female') NOT NULL,",
    "    level INT NOT NULL,",
    "    department_id INT NOT NULL,",
    "    allocation_status ENUM('Unallocated','Queued','Allocated') DEFAULT 'Unallocated',",
    "    distance_from_campus FLOAT DEFAULT 0.0,",
    "    has_special_needs BOOLEAN DEFAULT FALSE,",
    "    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,",
    "    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE RESTRICT",
    ");",
    "",
    "-- 5. Medical Records",
    "CREATE TABLE medical_records (",
    "    record_id INT AUTO_INCREMENT PRIMARY KEY,",
    "    student_id INT NOT NULL,",
    "    condition_category ENUM('None','Mobility','Respiratory','Visual','Neurological',",
    "        'Cardiovascular','Asthma','Ulcer','Epilepsy','Sickle Cell',",
    "        'Visual Impairment','Physical Disability','Other') DEFAULT 'None',",
    "    mobility_status ENUM('Normal Mobility','Wheelchair User','Crutches/Walker','Artificial Limb') DEFAULT 'Normal Mobility',",
    "    condition_details TEXT,",
    "    severity_level INT DEFAULT 0,",
    "    urgency_score FLOAT DEFAULT 0,",
    "    supporting_document_path VARCHAR(255),",
    "    verification_status ENUM('Pending','Verified','Rejected') DEFAULT 'Pending',",
    "    is_requested_mobility BOOLEAN DEFAULT FALSE,",
    "    verified_by INT,",
    "    verified_at TIMESTAMP NULL,",
    "    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE,",
    "    INDEX idx_student_id (student_id),",
    "    INDEX idx_condition (condition_category)",
    ");",
    "",
    "-- 6. Hostels",
    "-- hostel_type: 'flat'=single ground floor | 'duplex'=2 floors",
    "CREATE TABLE hostels (",
    "    hostel_id INT AUTO_INCREMENT PRIMARY KEY,",
    "    name VARCHAR(100) NOT NULL,",
    "    block_name VARCHAR(50) DEFAULT 'Main Block',",
    "    gender_allowed ENUM('Male','Female') NOT NULL,",
    "    hostel_type ENUM('flat','duplex') DEFAULT 'flat',",
    "    proximal_faculty_id INT,",
    "    is_proximal BOOLEAN DEFAULT FALSE,",
    "    has_elevator BOOLEAN DEFAULT FALSE,",
    "    total_capacity INT NOT NULL,",
    "    description VARCHAR(255),",
    "    FOREIGN KEY (proximal_faculty_id) REFERENCES faculties(faculty_id) ON DELETE SET NULL",
    ");",
    "",
    "-- 7. Rooms",
    "-- floor_level: 0=Ground, 1=First (Duplex only has 0 and 1)",
    "CREATE TABLE rooms (",
    "    room_id INT AUTO_INCREMENT PRIMARY KEY,",
    "    hostel_id INT NOT NULL,",
    "    room_number VARCHAR(10) NOT NULL,",
    "    floor_level INT NOT NULL,",
    "    capacity INT DEFAULT 4,",
    "    occupied_count INT DEFAULT 0,",
    "    is_corner BOOLEAN DEFAULT FALSE,",
    "    bed_config VARCHAR(255) DEFAULT NULL,",
    "    UNIQUE(hostel_id, room_number),",
    "    FOREIGN KEY (hostel_id) REFERENCES hostels(hostel_id) ON DELETE CASCADE",
    ");",
    "",
    "-- 8. Allocations",
    "CREATE TABLE allocations (",
    "    allocation_id INT AUTO_INCREMENT PRIMARY KEY,",
    "    student_id INT UNIQUE NOT NULL,",
    "    room_id INT NOT NULL,",
    "    bed_space VARCHAR(5),",
    "    bed_label ENUM('LB','TB','SB','UB') DEFAULT 'LB',",
    "    academic_session VARCHAR(20) NOT NULL,",
    "    allocated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,",
    "    allocation_method ENUM('algorithm','manual') DEFAULT 'algorithm',",
    "    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE,",
    "    FOREIGN KEY (room_id) REFERENCES rooms(room_id),",
    "    INDEX idx_room_id (room_id),",
    "    INDEX idx_session (academic_session)",
    ");",
    "",
    "-- 9. Audit Logs",
    "CREATE TABLE algorithm_audit_logs (",
    "    log_id INT AUTO_INCREMENT PRIMARY KEY,",
    "    student_id INT NOT NULL,",
    "    run_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,",
    "    input_severity INT,",
    "    input_proximity_need BOOLEAN,",
    "    calculated_urgency_score FLOAT,",
    "    allocation_decision ENUM('Allocated','Waitlisted','No Bed'),",
    "    assigned_hostel_id INT,",
    "    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE",
    ");",
    "",
    "CREATE TABLE admin_audit_logs (",
    "    log_id INT AUTO_INCREMENT PRIMARY KEY,",
    "    admin_id INT NOT NULL,",
    "    action_description VARCHAR(255) NOT NULL,",
    "    ip_address VARCHAR(45),",
    "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,",
    "    FOREIGN KEY (admin_id) REFERENCES users(user_id) ON DELETE CASCADE",
    ");",
    "",
    "-- 10. Settings",
    "CREATE TABLE settings (",
    "    setting_key VARCHAR(50) PRIMARY KEY,",
    "    setting_value VARCHAR(255)",
    ");",
    "INSERT INTO settings (setting_key, setting_value) VALUES",
    "('current_session','2025/2026'),",
    "('urgency_threshold_proximal','75'),",
    "('urgency_threshold_ground_floor','85'),",
    "('allocation_status','open');",
    "",
    "-- 11. FAQs",
    "CREATE TABLE faqs (",
    "    faq_id INT AUTO_INCREMENT PRIMARY KEY,",
    "    question VARCHAR(255) NOT NULL,",
    "    answer TEXT NOT NULL,",
    "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
    ");",
    r"""INSERT INTO faqs (question, answer) VALUES""",
    r"""('How is the urgency score calculated?','The system uses XGBoost trained on medical data. It considers condition, mobility, and severity to assign a priority score (0-100).'),""",
    r"""('What if my allocation is pending?','Allocations run in batches. Ensure your profile is complete.'),""",
    r"""('How do I correct a wrong medical entry?','Edit your profile via Student Dashboard. False claims are verified at the University Health Center.');""",
    "",
    "-- 12. Notifications",
    "CREATE TABLE notifications (",
    "    id INT AUTO_INCREMENT PRIMARY KEY,",
    "    user_id INT NOT NULL,",
    "    message TEXT NOT NULL,",
    "    is_read BOOLEAN DEFAULT FALSE,",
    "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,",
    "    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE",
    ");",
    "",
    "-- 13. Password Resets",
    "CREATE TABLE password_resets (",
    "    id INT AUTO_INCREMENT PRIMARY KEY,",
    "    user_id INT NOT NULL,",
    "    token_hash VARCHAR(64) NOT NULL,",
    "    expires_at TIMESTAMP NOT NULL,",
    "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,",
    "    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,",
    "    UNIQUE KEY uniq_user_token (user_id, token_hash),",
    "    INDEX idx_token (token_hash)",
    ");",
    "",
]

# ── Faculties & Departments ───────────────────────────────────────────────────
lines += [
    "-- ============================================================",
    "-- SEED: FACULTIES & DEPARTMENTS",
    "-- ============================================================",
    "INSERT INTO faculties (faculty_id, name) VALUES",
    "(1,'Faculty of Computing and Digital Technologies'),",
    "(2,'Natural Sciences'),",
    "(3,'Basic Medical Sciences'),",
    "(4,'Management Sciences'),",
    "(5,'Engineering'),",
    "(6,'Humanities'),",
    "(7,'Law');",
    "",
    "INSERT INTO departments (faculty_id, name) VALUES",
    "(1,'Computer Science'),(1,'Information Technology'),(1,'Cybersecurity'),",
    "(2,'Biochemistry'),(2,'Industrial Mathematics'),(2,'Microbiology'),(2,'Physics'),(2,'Chemistry'),",
    "(3,'Nursing Science'),(3,'Physiology'),(3,'Anatomy'),(3,'Medical Laboratory Science'),(3,'Basic Medical Biochemistry'),",
    "(4,'Accounting'),(4,'Business Administration'),(4,'Economics'),(4,'Transport Management'),",
    "(5,'Civil Engineering'),(5,'Mechanical Engineering'),(5,'Electrical Engineering'),",
    "(6,'English'),(6,'History'),(6,'Theatre Arts'),",
    "(7,'Law');",
    "",
]

# ── Hostel seed data ──────────────────────────────────────────────────────────
lines += [
    "-- ============================================================",
    "-- SEED: HOSTELS",
    "-- Hostel ID plan:",
    "--   1-18  : Prophet Moses Hall (Male, Flat, 18 blocks)",
    "--  19-29  : Prophet Moses Extension Hall (Male, Flat, 11 blocks)",
    "--  30-37  : Prophet Moses Engineering Hall (Male, Duplex, 8 blocks)",
    "--  38-55  : Queen Esther Hall (Female, Flat, 18 blocks)",
    "--  56-66  : Queen Esther Extension Hall (Female, Flat, 11 blocks)",
    "--  67-74  : Queen Esther Engineering Hall (Female, Duplex, 8 blocks)",
    "-- ============================================================",
    "",
]

hostel_rows = []

# 1. Prophet Moses Hall: IDs 1-18
for b in range(1,19):
    hostel_rows.append(hostel_row(b,'Prophet Moses Hall',b,'Male','flat',None,False,'Male Hostel - Standard Flat',76))

# 2. Prophet Moses Extension Hall: IDs 19-29
for b,hid in enumerate(range(19,30),1):
    hostel_rows.append(hostel_row(hid,'Prophet Moses Extension Hall',b,'Male','flat',None,False,'Male Hostel - Extension Flat',76))

# 3. Prophet Moses Engineering Hall: IDs 30-37
for b,hid in enumerate(range(30,38),1):
    hostel_rows.append(hostel_row(hid,'Prophet Moses Engineering Hall',b,'Male','duplex',5,True,'Male Hostel - Engineering Duplex',240))

# 4. Queen Esther Hall: IDs 38-55
for b,hid in enumerate(range(38,56),1):
    hostel_rows.append(hostel_row(hid,'Queen Esther Hall',b,'Female','flat',None,False,'Female Hostel - Standard Flat',76))

# 5. Queen Esther Extension Hall: IDs 56-66
for b,hid in enumerate(range(56,67),1):
    hostel_rows.append(hostel_row(hid,'Queen Esther Extension Hall',b,'Female','flat',None,False,'Female Hostel - Extension Flat',76))

# 6. Queen Esther Engineering Hall: IDs 67-74
for b,hid in enumerate(range(67,75),1):
    hostel_rows.append(hostel_row(hid,'Queen Esther Engineering Hall',b,'Female','duplex',5,True,'Female Hostel - Engineering Duplex',240))

lines.append("INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, hostel_type, proximal_faculty_id, is_proximal, description, total_capacity) VALUES")
lines.append(",\n".join(hostel_rows) + ";")
lines.append("")

# ── Room seed data ─────────────────────────────────────────────────────────────
lines += ["", "-- ============================================================",
          "-- SEED: ROOMS", "-- ============================================================", ""]

# 1. Prophet Moses Hall: IDs 1-18 (flat)
lines.append("-- 1. Prophet Moses Hall (Male, Flat, 18 blocks)")
lines.append("-- Room layout: 24 rooms, all floor 0 | corners: 1,12,13,24 (cap=4) | normal: 2-11,14-23 (cap=3)")
for hid in range(1,19):
    lines.append(std_block_rooms(hid))
    lines.append("")

# 2. Prophet Moses Extension Hall: IDs 19-29 (flat)
lines.append("-- 2. Prophet Moses Extension Hall (Male, Flat, 11 blocks)")
for hid in range(19,30):
    lines.append(std_block_rooms(hid))
    lines.append("")

# 3. Prophet Moses Engineering Hall: IDs 30-37 (duplex)
lines.append("-- 3. Prophet Moses Engineering Hall (Male, Duplex, 8 blocks)")
lines.append("-- Room layout: 60 rooms | floor 0: rooms 1-30 | floor 1: rooms 31-60 | all cap=4")
for hid in range(30,38):
    lines.append(eng_block_rooms(hid))
    lines.append("")

# 4. Queen Esther Hall: IDs 38-55 (flat)
lines.append("-- 4. Queen Esther Hall (Female, Flat, 18 blocks)")
for hid in range(38,56):
    lines.append(std_block_rooms(hid))
    lines.append("")

# 5. Queen Esther Extension Hall: IDs 56-66 (flat)
lines.append("-- 5. Queen Esther Extension Hall (Female, Flat, 11 blocks)")
for hid in range(56,67):
    lines.append(std_block_rooms(hid))
    lines.append("")

# 6. Queen Esther Engineering Hall: IDs 67-74 (duplex)
lines.append("-- 6. Queen Esther Engineering Hall (Female, Duplex, 8 blocks)")
for hid in range(67,75):
    lines.append(eng_block_rooms(hid))
    lines.append("")

# ── System admin & settings ───────────────────────────────────────────────────
lines += [
    "-- ============================================================",
    "-- SEED: SYSTEM ADMIN",
    "-- username: admin | password: Admin@2026",
    "-- ============================================================",
    "INSERT INTO users (username, full_name, password_hash, role, login_attempts)",
    "VALUES ('admin','System Administrator','$2y$10$vraEsXmryy.Mkj2xxoGlLOhjYI7rpdXUciQbDUprC82sIk2lXR5Om','admin',0);",
    "",
    "INSERT IGNORE INTO settings (setting_key, setting_value) VALUES",
    "('current_session','2025/2026'),",
    "('allocation_status','open'),",
    "('medical_threshold','50'),",
    "('max_students','500');",
    "",
]

# ── Write output ──────────────────────────────────────────────────────────────
output = "\n".join(lines)
with open("setup.sql", "w", encoding="utf-8") as f:
    f.write(output)

print(f"setup.sql generated: {len(lines)} lines, {len(output):,} bytes")

# Quick stats
total_rooms = 18*24 + 11*24 + 8*60 + 18*24 + 11*24 + 8*60
total_students = 18*76 + 11*76 + 8*240 + 18*76 + 11*76 + 8*240
print(f"Total hostel rows : 74")
print(f"Total room rows   : {total_rooms:,}")
print(f"Total bed capacity: {total_students:,}")
