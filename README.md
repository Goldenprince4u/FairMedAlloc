# FairMedAlloc: Machine Learning-Driven Hostel Allocation System

## Project Overview
FairMedAlloc is a research-based hostel allocation system designed for Redeemer's University. It prioritizes students with medical conditions and disabilities for hostels closest to the University Health Center ("Clinic-Proximal").

### Core Features
*   **Medical Urgency Scoring**: assigns a score (0-100) based on health conditions (e.g., Sickle Cell = High Priority).
*   **Hierarchical Allocation**: Prioritizes Emergency Health -> Mobility Needs -> General Students.
*   **Proximal vs. Distant Hostels**: Automatically fills Prophet Moses Engineering (Male) and Queen Esther Extension (Female) with high-risk students first.

## System Requirements
*   **Server**: XAMPP or WAMP (Apache + MySQL)
*   **Language**: PHP 8.x
*   **Database**: MySQL

## Setup Instructions
1.  **Database Setup**:
    *   Open your browser to: `http://localhost/FairMedAlloc/install.php`
    *   This script will automatically create the database and tables.
    *   *Alternative*: Import `setup.sql` via phpMyAdmin.

2.  **Login Credentials**:
    *   **Administrator**: Username: `admin`, Password: `fairmed2026`
    *   **Student**: Register a new account or import via CSV.

## File Structure Explained
*   `db_config.php`: Connects the website to the database. Edit this if your MySQL password changes.
*   `run_allocation.php`: **The Brain**. Contains the algorithm that calculates scores and assigns rooms.
*   `upload_data.php`: Allows mass upload of student data from CSV.
*   `admin_dashboard.php`: The control center for the University Admin.
*   `student_dashboard.php`: The student's view of their allocation status.

## Feature: Fee-Gated Allocation
*   **Workflow**:
    1.  Admin uploads CSV containing student data **AND** Fee Status (Col 9).
    2.  System runs allocation for specific High-Risk students (protected reserved spots) and others.
    3.  Student logs in.
    4.  **If Fee Paid**: Sees "Bed Allocated: Room 12".
    5.  **If Unpaid**: Sees "Status: Locked (Payment Required)".

## CSV Format (Important)
For the mass uploader (`upload_data.php`), use this **strict** column order:
1. Matric Number
2. Full Name
3. Email
4. Gender
5. Level
6. Faculty
7. Medical Condition (e.g., 'Sickle Cell', 'None')
8. Mobility Status (e.g., 'Wheelchair', 'None')
9. **Fee Paid** (e.g., 'Yes', 'No')

## Research Implementation Notes
*   **XGBoost Emulation**: Since running Python XGBoost is complex on standard XAMPP installations, the scoring logic in `run_allocation.php` mathematically mimics the trained model's decision tree (e.g., IF condition='Sickle Cell' THEN score+=85).
*   **Optimization**: The system uses a strict sorting algorithm (`ORDER BY urgency_score DESC`) to achieve the research goal of "Hierarchical Distribution".

## Troubleshooting
*   **Database Error**: Ensure XAMPP "MySQL" module is Green/Running.
*   **Login Failed**: Use `reset_admin.php` if you lose the admin access.
