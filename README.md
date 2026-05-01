# FairMedAlloc — ML-Driven Medical Hostel Allocation System

[![PHP](https://img.shields.io/badge/PHP-8.x-blue)](https://php.net)
[![Python](https://img.shields.io/badge/Python-3.8+-yellow)](https://python.org)
[![MySQL](https://img.shields.io/badge/MariaDB-10.4-orange)](https://mariadb.org)
[![OR-Tools](https://img.shields.io/badge/OR--Tools-CP--SAT-lightblue)](https://developers.google.com/optimization)
[![XGBoost](https://img.shields.io/badge/XGBoost-Model-green)](https://xgboost.readthedocs.io)
[![License](https://img.shields.io/badge/License-Academic-purple)](#-license)

> **FairMedAlloc** is a fairness-aware medical hostel allocation system built for
> Redeemer's University, Ede, Nigeria. It uses a trained **XGBoost model** to score
> each student's medical urgency and a **Google OR-Tools CP-SAT constraint solver**
> to assign hostel rooms in a way that is fair, medically-prioritised, and
> policy-compliant — ensuring wheelchair users, students with sickle cell disease,
> epilepsy, and other conditions are never placed at a disadvantage.

---

## Table of Contents

1. [Current Allocation Policy](#1-current-allocation-policy)
2. [Hostel Architecture](#2-hostel-architecture)
3. [Features](#3-features)
4. [Quick Setup](#4-quick-setup)
5. [Project Structure](#5-project-structure)
6. [How the Allocation Works](#6-how-the-allocation-works)
7. [CSV Import Format](#7-csv-import-format)
8. [ML Model Setup](#8-ml-model-setup)
9. [Database Migrations](#9-database-migrations)
10. [Security Notes](#10-security-notes)
11. [Troubleshooting](#11-troubleshooting)
12. [License](#12-license)

---

## 1. Current Allocation Policy

> Full policy detail is in [ALLOCATION_POLICY.md](ALLOCATION_POLICY.md). System internals are in [SYSTEM_OVERVIEW.md](SYSTEM_OVERVIEW.md).

### Eligibility
Only students with confirmed fee payment (`is_paid = 1` or a `payments.status = 'paid'` record) are eligible for allocation.

### Urgency Bands

| Band | Score | Placement |
|------|-------|-----------|
| **High** | ≥ 75 | Clinic-proximal rooms (Prophet Moses Hall Blks 1–2 for males; Queen Esther Extension Blks 38–39 for females) |
| **Medium** | 40–74 | First block of their faculty-proximal hostel; PM Hall Block 2 for Group A males |
| **Low** | < 40 | Faculty-proximal halls first, then clinic-proximal overflow, then any valid room |

### Mobility Priority (Hard Rule)
Students with `Wheelchair User`, `Crutches/Walker`, or `Artificial Limb` mobility status receive:
- A **minimum urgency score of 76.0** (guaranteed High band) regardless of XGBoost output
- Placement restricted to **ground floor (floor 0)** within **Joshua Hall** (male) and **Deborah Hall** (female) — the only two two-storey hostels (staircase access only, no elevator)

### Clinic-Proximal Overflow
When all faculty-proximal blocks are full, **Medium and Low** students flow into clinic-proximal rooms (+150,000 bonus) rather than sitting unallocated. No bed is ever left empty while an eligible student needs housing.

### Solver
All students (High, Medium, Low) are handled in a **single OR-Tools CP-SAT pass**. There is no separate greedy phase. The only two absolute hard exclusions are:
1. **Gender match** — always enforced
2. **Prophet Moses Hall Block 1** — hard-reserved for High-urgency males only, never backfilled

---

## 2. Hostel Architecture

### Male Hostels (Blocks 1–33)

| Block(s) | Hostel | Rooms/Block | Notes |
|----------|--------|-------------|-------|
| 1–18 | Prophet Moses Hall | 24 | Corners (1,12,13,24) = 4 beds; others = 3 beds. **Blks 1–2 = clinic-proximal** |
| 19–20 | PM Extension Hall | 24 | 2 beds/room (postgrad). **Excluded from undergrad allocation** |
| 21 | PM Extension Hall | 24 | Rm 12,13 = 6 beds; Rm 1,24 = 4 beds; others = 3 beds |
| 22–25 | PM Extension Hall | 24 | Corners (1,12,13,24) = 4 beds; others = 3 beds |
| 26 | PM Extension Hall | 27 | Rm 26 = 8 beds; all others = 4 beds |
| 27 | PM Extension Hall | 27 | Same as Blk 26. **Foundation year — excluded from undergrad allocation** |
| 28–33 | Joshua Hall | 54 | **Two floors** (Rms 1–27 = ground, 28–54 = upper). Blk 28 Rms 1,27 reserved. Rm 1 & 54 = 8 beds; others = 4 beds. Faculty-proximal (Engineering/Law/Built Env/Basic Med) |

**Undergraduate male bedspaces:** ~3,196

### Female Hostels (Blocks 1–42 QE; Blks 1–5 Deborah)

| Block(s) | Hostel | Rooms/Block | Notes |
|----------|--------|-------------|-------|
| 1–32 | Queen Esther Hall | 24 | Rm 1,24 = 4 beds; Rm 12,13 = 6 beds; others = 3 beds |
| 33–37 | Queen Esther Hall | 28 | Rm 1 = 8 beds; all others = 4 beds. Capacity 116/block |
| 38–39 | QE Extension Hall | 24 | **Clinic-proximal** — same room pattern as Blks 1–32 |
| 40–42 | QE Extension Hall | 24 | Standard. Same room pattern as Blks 1–32 |
| 1–5 | Deborah Hall | 28 | **Two floors** (Rms 1–14 = ground, 15–28 = upper). Rm 1 = 8 beds; others = 4 beds. Faculty-proximal (Engineering/Law/Built Env/Basic Med) |

**Total female rooms:** 1,168

---

## 3. Features

| Feature | Description |
|---------|-------------|
| 🏥 **Medical Urgency Scoring** | XGBoost model assigns scores 0–100 based on condition, severity, and mobility; PHP fallback ensures scoring always works even without Python |
| ♿ **Mobility Hard-Lock** | Wheelchair/Crutches/Artificial Limb students are guaranteed High urgency (score ≥ 76) and restricted to ground-floor rooms in two-storey hostels (no elevator) |
| 🤖 **OR-Tools CP-SAT Solver** | Single-pass constraint solver handles all urgency bands simultaneously using a weighted objective function — no greedy fallback phase |
| 🏠 **Faculty-Proximal Placement** | Each faculty maps to specific hostels; Medium and Low students are steered there via large placement bonuses |
| 🔁 **Clinic-Proximal Overflow** | Medium/Low students overflow into clinic-proximal rooms when proximal halls are full, ensuring zero wasted beds |
| 💳 **Fee-Gated Allocation** | Students only receive a room after fee payment is confirmed (imported CSV or Pay Simulator) |
| 📋 **CSV Bulk Import** | Admin imports hundreds of students at once with a structured 10-column CSV |
| 🔐 **Role-Based Access** | Separate admin and student portals; auth guards on every route and API endpoint |
| 🛡️ **CSRF Protection** | All state-mutating POST forms are CSRF-token validated |
| 🔒 **Account Lockout** | 5 failed login attempts triggers a 15-minute lockout |
| 📊 **Analytics Dashboard** | Real-time Chart.js charts for allocation progress, medical distribution, hostel occupancy |
| 🖨️ **Printable Slip** | Students download an official allocation certificate once assigned |
| 🔔 **Notifications** | Per-student in-app notifications generated automatically on allocation |
| 🔄 **Pay Simulator** | Admin can simulate fee payment for individual students for testing |

---

## 4. Quick Setup

### Prerequisites

| Requirement | Version | Notes |
|-------------|---------|-------|
| XAMPP / WAMP | Any recent | Apache + MariaDB/MySQL must both be running |
| PHP | 8.0+ | `shell_exec` must be enabled |
| Python | 3.8+ | Optional — for live XGBoost scoring |
| Python packages | — | `pip install ortools xgboost pandas scikit-learn` |

### Step 1 — Clone / Copy Files
```
C:\xampp\htdocs\FairMedAlloc\
```

### Step 2 — Configure Environment
Create `.env` in the project root (never commit this file):
```ini
DB_HOST=127.0.0.1
DB_PORT=3307
DB_USER=root
DB_PASS=
DB_NAME=fairmedalloc
ML_SERVICE_URL=http://127.0.0.1:5051
ML_SERVICE_TIMEOUT=5
PYTHON_BIN=C:/Users/YourUser/AppData/Local/Programs/Python/Python311/python.exe
ML_MODEL_PICKLE_PATH=ml_models/xgboost_hostel_model.pkl
```

> ⚠️ **XAMPP uses port 3307 by default** (not 3306). Check `C:\xampp\mysql\bin\my.ini` if you get connection errors.

### Step 3 — Create the Database
```sql
-- In phpMyAdmin or mysql CLI:
CREATE DATABASE fairmedalloc CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
Then import `sql/schema.sql` to create all tables.

### Step 4 — Seed Hostel & Faculty Data
```bash
php sql/seed.php
```
This inserts all hostels, rooms, faculties, departments, and default settings. The seed is **idempotent-safe** — it will abort if any table already has data.

### Step 5 — Apply Database Migrations
Run these SQL files **in order** against the `fairmedalloc` database if setting up after the initial seed:
```bash
# In XAMPP MySQL CLI (adjust port as needed):
mysql -u root -h 127.0.0.1 --port=3307 fairmedalloc < sql/migrate_qe_extension_blocks.sql
mysql -u root -h 127.0.0.1 --port=3307 fairmedalloc < sql/migrate_qe_hall_blocks_33_37_fix.sql
mysql -u root -h 127.0.0.1 --port=3307 fairmedalloc < sql/migrate_qe_cleanup.sql
```

### Step 6 — Create the First Admin
1. Visit `http://localhost/FairMedAlloc/admin_signup.php` from localhost
2. Create the initial administrator account
3. Sign in at `http://localhost/FairMedAlloc/admin_login.php`

> ⚠️ **Change your password immediately** after first login via Admin Profile → Security Settings.

---

## 5. Project Structure

```
FairMedAlloc/
│
├── 📄 index.php                  # Landing page
├── 📄 login.php                  # Student login
├── 📄 signup.php                 # Student registration (medical profile)
├── 📄 student_dashboard.php      # Student: allocation status, notifications, pay fees
├── 📄 profile.php                # Student: update medical profile & photo
├── 📄 print_slip.php             # Printable allocation certificate
├── 📄 help.php                   # Role-aware FAQ and support contacts
├── 📄 forgot_password.php        # Password recovery request
├── 📄 reset_password.php         # Token-based password reset
├── 📄 change_password.php        # In-session password change
│
├── 📄 admin_login.php            # Admin authentication
├── 📄 admin_dashboard.php        # Admin command centre (stats, modules, quick actions)
├── 📄 admin_reports.php          # Analytics: occupancy, allocation breakdowns, audit logs
├── 📄 admin_profile.php          # Admin credential management
├── 📄 admin_signup.php           # Create additional admin accounts
├── 📄 admin_reset_password.php   # Admin password reset flow
├── 📄 run_allocation.php         # Trigger the full ML allocation engine
├── 📄 view_table.php             # Allocation matrix (search, CSV export, manual assign)
├── 📄 settings.php               # Configure session, thresholds, solver backend, lock status
├── 📄 upload_data.php            # Bulk CSV student import
│
├── api/
│   ├── admin_api.php             # Admin AJAX endpoints (rooms, manual assign, analytics)
│   ├── pay_simulation.php        # Fee payment simulation → triggers auto-allocation
│   └── get_departments.php       # AJAX: cascading faculty → department dropdown
│
├── includes/
│   ├── AllocationEngine.php      # PHP orchestration: fetches data, calls Python, writes DB
│   ├── UrgencyScoreService.php   # PHP fallback scorer (rule-based, used if Python unavailable)
│   ├── Student.php               # Student model (profile, allocation status, payment)
│   ├── NotificationManager.php   # Unread notification badge counts
│   ├── security_helper.php       # CSRF, audit log, session validation helpers
│   ├── header.php                # HTML <head>, fonts, CSS links
│   └── nav.php                   # Sidebar navigation (role-aware)
│
├── ml_models/
│   ├── predict.py                # XGBoost .pkl inference bridge + mobility score floor
│   ├── allocate.py               # OR-Tools CP-SAT solver (single-pass, all urgency bands)
│   ├── ml_service.py             # Optional persistent HTTP scoring microservice
│   ├── start_ml_service.bat      # Launcher for ml_service.py (Windows)
│   ├── xgboost_hostel_model.pkl  # Trained XGBoost model (binary, not in VCS)
│   └── hostel_medical_dataset.csv # Training data reference (audit/retrain use)
│
├── sql/
│   ├── schema.sql                        # Full DB table definitions
│   ├── seed.php                          # Initial hostel, room, faculty, settings data
│   ├── SETUP.md                          # DB setup instructions
│   ├── 20260425_add_algorithm_version.sql
│   ├── 20260430_accessible_ground_floor_policy.sql
│   ├── migrate_qe_extension_blocks.sql   # QE Extension Hall block renaming (1-5 → 38-42)
│   ├── migrate_qe_hall_blocks_33_37_fix.sql  # QE Hall blks 33-37 room config correction
│   └── migrate_qe_cleanup.sql           # QE Hall Blk 1 ghost room removal
│
├── css/
│   ├── main.css                  # Design system (glassmorphism, navy/gold palette, responsive)
│   └── print.css                 # Print-specific styles for allocation slip
│
├── js/
│   ├── allocation_matrix.js      # View table: modal, room fetch, CSV export, toasts
│   └── student_dashboard.js      # Pay fees AJAX flow
│
├── assets/                       # Static images, icons
├── uploads/                      # Student profile photos (gitignored except .gitkeep)
│
├── db_config.php                 # DB connection singleton (reads .env — gitignored)
├── .env                          # Local credentials (NEVER committed)
├── .gitignore
├── ALLOCATION_POLICY.md          # Authoritative policy rules (keep in sync with allocate.py)
├── SYSTEM_OVERVIEW.md            # Full architecture and flow documentation
└── README.md                     # This file
```

---

## 6. How the Allocation Works

```
Admin clicks "Run Allocation"
         │
         ▼
AllocationEngine.php
  1. Fetch eligible students (paid + unallocated)
  2. For each student → predict.py (XGBoost score + mobility floor)
  3. Write students.csv + rooms.csv to /tmp
  4. Invoke allocate.py (OR-Tools CP-SAT, 120 s limit)
  5. Read output.csv assignments
  6. Bulk-insert: allocations + audit_logs + notifications
  7. Return JSON result to browser
```

### Urgency Scoring (`predict.py`)
- Loads `xgboost_hostel_model.pkl` and maps 9 medical features to a 0–100 score
- **Mobility floor override:** If mobility = `Wheelchair User / Crutches/Walker / Artificial Limb` → score is raised to at least **76.0** (High band guaranteed)
- DB cache is bypassed for mobility-priority students to catch late-disclosed status
- Falls back to PHP rule-based scoring if Python/model unavailable

### Solver Weight Ladder (`allocate.py`)

| Condition | Bonus |
|-----------|-------|
| High → matching clinic-proximal room | +5,000,000 |
| Mobility-priority → Joshua/Deborah ground floor | +2,200,000 |
| Medium → first block of faculty-proximal hostel | +1,500,000 |
| Medium → Prophet Moses Hall Block 2 | +1,200,000 |
| Low → primary faculty-proximal hostel | +900,000 |
| Low → secondary faculty-proximal hostel | +450,000 |
| Medium → any later block of faculty-proximal hostel | +400,000 |
| Medium or Low → clinic-proximal room (overflow) | +150,000 |
| Any other gender-matching room | +0 |

### Faculty → Hostel Mapping

| Faculty Group | Male | Female |
|---------------|------|--------|
| Humanities, Management, Natural, Social Sciences, Computing | Prophet Moses Hall | Queen Esther Hall |
| Engineering, Law, Built Environment | Joshua Hall | Deborah Hall |
| Basic Medical Sciences | Joshua Hall | Deborah Hall + Queen Esther Hall |
| **Clinic-proximal** | Prophet Moses Hall Blks 1–2 | QE Extension Hall Blks 38–39 |

---

## 7. CSV Import Format

Column order for `upload_data.php` (row 1 = header, skipped automatically):

| Col | Field | Example | Notes |
|-----|-------|---------|-------|
| A | Matric Number | `RUN/CMP/22/001` | Used as username; lowercase = default password |
| B | Full Name | `Jane Doe` | |
| C | Level | `200` | 100–500 |
| D | Faculty | `Faculty of Engineering` | Must match exactly as stored in DB |
| E | Department | `Computer Engineering` | |
| F | Gender | `Male` or `Female` | |
| G | Medical Condition | `Sickle Cell Disease` or `None` | |
| H | Severity | `Low`, `Medium`, or `High` | Defaults to `Low` if blank |
| I | Mobility Status | `Normal Mobility` or `Wheelchair User` | Defaults to `Normal Mobility` |
| J | **Paid Status** | **`1`** (paid) or **`0`** | Controls eligibility for allocation |

> Default student password = lowercase matric number (e.g. `run/cmp/22/001`). Students must change this on first login.

---

## 8. ML Model Setup

Place the `.pkl` model in `ml_models/` as `xgboost_hostel_model.pkl` and point `ML_MODEL_PICKLE_PATH` in `.env` to it.

Install required Python packages:
```bash
pip install ortools xgboost pandas scikit-learn
```

The system uses the same XGBoost pipeline during:
- Student signup and profile updates
- CSV bulk import (batch scoring)
- Admin rescore runs
- Allocation engine execution

If Python or the model is unavailable, the system gracefully falls back to the PHP rule-based scorer in `includes/UrgencyScoreService.php`.

### Optional Local ML API (Persistent Service)

Run to avoid the per-request subprocess overhead:
```bash
cd ml_models
py -3 ml_service.py
```

Binds to `http://127.0.0.1:5051` and exposes:
- `GET  /health`
- `POST /ml/score-batch`

Example:
```bash
curl -X POST http://127.0.0.1:5051/ml/score-batch \
  -H "Content-Type: application/json" \
  -d "[{\"id\":1,\"condition\":\"Sickle Cell Disease\",\"mobility\":\"Wheelchair User\",\"severity\":\"High\",\"academic_level\":300}]"
```

---

## 9. Database Migrations

All schema migrations live in `sql/` and must be applied in chronological order after the initial seed.

| File | Purpose | Run After |
|------|---------|-----------|
| `schema.sql` | Creates all tables | Fresh DB only |
| `seed.php` | Inserts hostels, rooms, faculties, settings | schema.sql |
| `20260425_add_algorithm_version.sql` | Adds `algorithm_version` column to audit logs | seed.php |
| `20260430_accessible_ground_floor_policy.sql` | Adds ground-floor accessibility policy settings | Above |
| `migrate_qe_extension_blocks.sql` | Renames QE Extension Hall blocks 1–5 → 38–42; adds QE Hall blocks 33–37 skeletons | Above |
| `migrate_qe_hall_blocks_33_37_fix.sql` | Corrects QE Hall blocks 33–37 to 28-room layout (capacity 116/block) | migrate_qe_extension_blocks.sql |
| `migrate_qe_cleanup.sql` | Removes ghost rooms from QE Hall Block 1 (legacy test data) | migrate_qe_hall_blocks_33_37_fix.sql |

### Applying a migration (XAMPP)
```bash
# Windows PowerShell / CMD
cmd /c "C:\xampp\mysql\bin\mysql.exe -u root -h 127.0.0.1 --port=3307 fairmedalloc < sql\<migration_file>.sql"
```

---

## 10. Security Notes

- All DB interactions use **prepared statements** — zero raw SQL interpolation
- **CSRF tokens** on every state-mutating POST form and AJAX call
- **Account lockout** after 5 failed login attempts (15-minute lock, logged to audit trail)
- **Role + session checks** on every protected page and API endpoint
- **File type + MIME validation** on profile picture uploads
- **Output buffering** (`ob_clean()`) enforced before every JSON API response to prevent header corruption from PHP warnings
- `.env` file is **gitignored** — credentials never leave the local machine
- `db_config.php` is **gitignored**

---

## 11. Troubleshooting

| Issue | Fix |
|-------|-----|
| `Database Connection Failed` | Ensure XAMPP MySQL is running (green in control panel); check port in `.env` matches `my.ini` (usually **3307** for XAMPP, not 3306) |
| `Table 'X' doesn't exist` | Import `sql/schema.sql` then run `php sql/seed.php` |
| `Allocated: 0 of 0 eligible students` | No students have `is_paid = 1`. Re-import CSV with `1` in column J, or use Pay Simulator |
| `Solver status: FEASIBLE` (not OPTIMAL) | Normal for large cohorts. All hard constraints are satisfied; only soft preferences may be suboptimal |
| Allocation takes too long | OR-Tools is capped at 120 s and always returns. Total for 7,000 students ≈ 2–3 minutes — do not reload the page |
| `shell_exec` disabled | Enable in `php.ini` (`disable_functions` line). ML scoring falls back to rule-based in the meantime |
| Processing lock stuck | A previous run crashed mid-execution. Lock auto-releases after 15 minutes, or run: `UPDATE settings SET setting_value='0' WHERE setting_key='admin_processing_lock';` |
| Python not found | Check `PYTHON_BIN` in `.env` points to the exact Python executable |
| Mobility student placed on upper floor | Their urgency_score in DB may be stale. Run Admin → Rescore All, or clear the stored score — `predict.py` will recompute with the mobility floor applied |

---

## 12. License

Academic project — Redeemer's University, Ede, Osun State, Nigeria. All rights reserved.
