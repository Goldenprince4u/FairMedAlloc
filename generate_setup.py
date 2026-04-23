"""
Generates setup.sql with flat INSERT statements (no stored procedures).
Compatible with: mysql CLI pipe, phpMyAdmin, any MySQL client.
"""

# ── Room generators ────────────────────────────────────────────────────────────

def std_block_rooms(hostel_id):
    """
    Standard hostel block: 24 rooms.
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
        rows.append(f"({hostel_id}, '{i}', {cap}, {corner}, '{cfg}')")
    return (
        "INSERT INTO rooms (hostel_id, room_number, capacity, is_corner, bed_config) VALUES\n"
        + ",\n".join(rows) + ";"
    )


def eng_block_rooms(hostel_id):
    """
    Engineering hostel block: 60 rooms, 2 levels.
      Rooms 1-30  (ground level)
      Rooms 31-60 (upper level)
    All rooms: capacity=4, LB,UB,LB,UB (2 bunks)
    Total: 60×4 = 240 students
    """
    rows = []
    for i in range(1, 61):
        rows.append(f"({hostel_id}, '{i}', 4, 0, 'LB,UB,LB,UB')")
    return (
        "INSERT INTO rooms (hostel_id, room_number, capacity, is_corner, bed_config) VALUES\n"
        + ",\n".join(rows) + ";"
    )


# ── Hostel header generators ───────────────────────────────────────────────────

def hostel_row(hid, name, block_num, gender, prox_fac, is_prox, cap):
    prox = 'NULL' if prox_fac is None else str(prox_fac)
    isp  = 'TRUE' if is_prox else 'FALSE'
    return f"({hid}, '{name}', 'Block {block_num}', '{gender}', {prox}, {isp}, {cap})"


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
    "    gender ENUM('Male','Female') NOT NULL,",
    "    level INT NOT NULL,",
    "    department_id INT NOT NULL,",
    "    allocation_status ENUM('Unallocated','Queued','Allocated') DEFAULT 'Unallocated',",
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
    "    severity_level ENUM('Low', 'Medium', 'High') DEFAULT 'Low',",
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
    "CREATE TABLE hostels (",
    "    hostel_id INT AUTO_INCREMENT PRIMARY KEY,",
    "    name VARCHAR(100) NOT NULL,",
    "    block_name VARCHAR(50) DEFAULT 'Main Block',",
    "    gender_allowed ENUM('Male','Female') NOT NULL,",
    "    proximal_faculty_id INT,",
    "    is_proximal BOOLEAN DEFAULT FALSE,",
    "    has_elevator BOOLEAN DEFAULT FALSE,",
    "    total_capacity INT NOT NULL,",
    "    FOREIGN KEY (proximal_faculty_id) REFERENCES faculties(faculty_id) ON DELETE SET NULL",
    ");",
    "",
    "-- 7. Rooms",
    "CREATE TABLE rooms (",
    "    room_id INT AUTO_INCREMENT PRIMARY KEY,",
    "    hostel_id INT NOT NULL,",
    "    room_number VARCHAR(10) NOT NULL,",
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
    "(1,'Faculty of Basic Medical Sciences'),",
    "(2,'Faculty of Engineering'),",
    "(3,'Faculty of Built Environment Studies'),",
    "(4,'Faculty of Humanities'),",
    "(5,'Faculty of Law'),",
    "(6,'Faculty of Management Sciences'),",
    "(7,'Faculty of Natural Sciences'),",
    "(8,'Faculty of Social Sciences'),",
    "(9,'Faculty of Computing and Digital Technology');",
    "",
    "INSERT INTO departments (faculty_id, name) VALUES",
    "(1,'Biochemistry'),(1,'Human Anatomy'),(1,'Human Physiology'),(1,'Public Health'),(1,'Nursing Science'),(1,'Physiotherapy'),(1,'Medical Laboratory Science'),",
    "(2,'Civil Engineering'),(2,'Computer Engineering'),(2,'Electrical & Electronic Engineering'),(2,'Mechanical Engineering'),",
    "(3,'Architecture'),(3,'Building Technology'),(3,'Estate Management'),(3,'Quantity Surveying'),(3,'Urban & Regional Planning'),",
    "(4,'Christian Religious Studies'),(4,'English'),(4,'French'),(4,'History & International Studies'),(4,'Philosophy'),(4,'Theatre Arts'),",
    "(5,'Law'),",
    "(6,'Accounting'),(6,'Banking & Finance'),(6,'Business Administration'),(6,'Public Administration'),(6,'Hospitality & Tourism Management'),(6,'Insurance'),(6,'Marketing'),(6,'Transport Management'),(6,'Actuarial Science'),",
    "(7,'Environmental Management & Toxicology'),(7,'Geology'),(7,'Industrial Chemistry'),(7,'Industrial Mathematics'),(7,'Industrial Mathematics and Computer Science'),(7,'Microbiology'),(7,'Petroleum Chemistry'),(7,'Physics with Electronics'),(7,'Statistics'),(7,'Statistics & Data Science'),",
    "(8,'Economics'),(8,'Mass Communication'),(8,'Political Science'),(8,'Psychology'),(8,'Sociology'),(8,'Social Work'),",
    "(9,'Computer Science'),(9,'Cyber Security'),(9,'Information Technology');",
    "",
]

# ── Hostel seed data ──────────────────────────────────────────────────────────
lines += [
    "-- ============================================================",
    "-- SEED: HOSTELS",
    "-- Hostel ID plan:",
    "--   1-18  : Prophet Moses Hall (Male, blocks 1-18)",
    "--  19-26  : Prophet Moses Extension Hall (Male, blocks 19-26)",
    "--  27-32  : Joshua Hall (Male, blocks 27-32)",
    "--  33-50  : Queen Esther Hall (Female, blocks 1-18)",
    "--  51-67  : Queen Esther Extension Hall (Female, blocks 19-35)",
    "--  68-72  : Deborah Hall (Female, blocks 36-40)",
    "-- Male total capacity : 3,416 beds",
    "-- Female total capacity: 3,860 beds",
    "-- ============================================================",
    "",
]

hostel_rows = []

# 1. Prophet Moses Hall: IDs 1-18, blocks 1-18 (continuous male sequence)
for b in range(1, 19):
    hostel_rows.append(hostel_row(b, 'Prophet Moses Hall', b, 'Male', None, False, 76))

# 2. Prophet Moses Extension Hall: IDs 19-26, blocks 19-26
for hid in range(19, 27):
    hostel_rows.append(hostel_row(hid, 'Prophet Moses Extension Hall', hid, 'Male', None, False, 76))

# 3. Joshua Hall: IDs 27-32, blocks 27-32
for hid in range(27, 33):
    hostel_rows.append(hostel_row(hid, 'Joshua Hall', hid, 'Male', 2, True, 240))

# 4. Queen Esther Hall: IDs 33-50, blocks 1-18 (continuous female sequence)
for b, hid in enumerate(range(33, 51), 1):
    hostel_rows.append(hostel_row(hid, 'Queen Esther Hall', b, 'Female', None, False, 76))

# 5. Queen Esther Extension Hall: IDs 51-67, blocks 19-35
for b, hid in enumerate(range(51, 68), 19):
    hostel_rows.append(hostel_row(hid, 'Queen Esther Extension Hall', b, 'Female', None, False, 76))

# 6. Deborah Hall: IDs 68-72, blocks 36-40
for b, hid in enumerate(range(68, 73), 36):
    hostel_rows.append(hostel_row(hid, 'Deborah Hall', b, 'Female', 2, True, 240))

lines.append("INSERT INTO hostels (hostel_id, name, block_name, gender_allowed, proximal_faculty_id, is_proximal, total_capacity) VALUES")
lines.append(",\n".join(hostel_rows) + ";")
lines.append("")

# ── Room seed data ─────────────────────────────────────────────────────────────
lines += ["", "-- ============================================================",
          "-- SEED: ROOMS", "-- ============================================================", ""]

# 1. Prophet Moses Hall: IDs 1-18, blocks 1-18
lines.append("-- 1. Prophet Moses Hall (Male, blocks 1-18)")
lines.append("-- Room layout: 24 rooms per block | corners: 1,12,13,24 (cap=4) | normal: 2-11,14-23 (cap=3)")
for hid in range(1, 19):
    lines.append(std_block_rooms(hid))
    lines.append("")

# 2. Prophet Moses Extension Hall: IDs 19-26, blocks 19-26
lines.append("-- 2. Prophet Moses Extension Hall (Male, blocks 19-26)")
for hid in range(19, 27):
    lines.append(std_block_rooms(hid))
    lines.append("")

# 3. Joshua Hall: IDs 27-32, blocks 27-32
lines.append("-- 3. Joshua Hall (Male, blocks 27-32)")
lines.append("-- Room layout: 60 rooms per block | rooms 1-30 ground level | rooms 31-60 upper level | all cap=4")
for hid in range(27, 33):
    lines.append(eng_block_rooms(hid))
    lines.append("")

# 4. Queen Esther Hall: IDs 33-50, blocks 1-18
lines.append("-- 4. Queen Esther Hall (Female, blocks 1-18)")
for hid in range(33, 51):
    lines.append(std_block_rooms(hid))
    lines.append("")

# 5. Queen Esther Extension Hall: IDs 51-67, blocks 19-35
lines.append("-- 5. Queen Esther Extension Hall (Female, blocks 19-35)")
for hid in range(51, 68):
    lines.append(std_block_rooms(hid))
    lines.append("")

# 6. Deborah Hall: IDs 68-72, blocks 36-40
lines.append("-- 6. Deborah Hall (Female, blocks 36-40)")
for hid in range(68, 73):
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
total_rooms = 18*24 + 8*24 + 6*60 + 18*24 + 17*24 + 5*60
male_beds   = 18*76 + 8*76 + 6*240
female_beds = 18*76 + 17*76 + 5*240
total_students = male_beds + female_beds
print(f"Total hostel rows   : 72")
print(f"Total room rows     : {total_rooms:,}")
print(f"Male bed capacity   : {male_beds:,}  (Prophet Moses Hall 1-18, Ext 19-26, Eng 27-32)")
print(f"Female bed capacity : {female_beds:,}  (Queen Esther Hall 1-18, Ext 19-35, Eng 36-40)")
print(f"Total bed capacity  : {total_students:,}")
