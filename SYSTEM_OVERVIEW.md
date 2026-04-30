# FairMedAlloc — System Overview

> **FairMedAlloc** is a fairness-aware medical hostel allocation system built for
> university medical students. It uses machine learning (XGBoost) to score each
> student's medical urgency and a constraint-programming solver (Google OR-Tools
> CP-SAT) to assign hostel rooms in a way that is fair, medically-prioritised,
> and policy-compliant.

---

## Table of Contents

1. [Purpose](#1-purpose)
2. [Who Uses It](#2-who-uses-it)
3. [System Architecture](#3-system-architecture)
4. [How the Allocation Works (Step-by-Step)](#4-how-the-allocation-works-step-by-step)
5. [The XGBoost Urgency Model](#5-the-xgboost-urgency-model)
6. [The OR-Tools CP-SAT Solver](#6-the-or-tools-cp-sat-solver)
7. [Allocation Policy Rules](#7-allocation-policy-rules)
8. [Student Eligibility](#8-student-eligibility)
9. [Key Pages & Features](#9-key-pages--features)
10. [Database Tables](#10-database-tables)
11. [Expected Run Times](#11-expected-run-times)
12. [CSV Import Format](#12-csv-import-format)
13. [Troubleshooting](#13-troubleshooting)

---

## 1. Purpose

Traditional hostel allocation treats all students equally, which puts medically
vulnerable students (e.g. sickle cell disease, epilepsy, wheelchair users) at a
disadvantage if they are assigned to rooms far from the clinic or without
elevator access.

FairMedAlloc solves this by:

- **Scoring** each student's medical urgency using a trained XGBoost model.
- **Prioritising** high-urgency students for clinic-proximal rooms.
- **Applying** faculty-proximity rules for medium-urgency students.
- **Ensuring** gender and capacity constraints are never violated.
- **Leaving** low-urgency students to fill remaining valid capacity, so no bed
  is wasted.

---

## 2. Who Uses It

| Role | What they do |
|---|---|
| **Admin** | Imports student data, triggers allocation, overrides assignments manually, views reports |
| **Student** | Registers/logs in, views their allocation status and room details, downloads a print slip |

---

## 3. System Architecture

```
┌─────────────────────────────────────────────────────────┐
│                     Web Browser (Admin)                 │
│          run_allocation.php  →  admin_api.php           │
└────────────────────────┬────────────────────────────────┘
                         │ POST /api/admin_api.php?action=run_algorithm
                         ▼
┌─────────────────────────────────────────────────────────┐
│               PHP – AllocationEngine.php                │
│                                                         │
│  1. Query eligible students (paid + unallocated)        │
│  2. Call UrgencyScoreService → predict.py (XGBoost)     │
│  3. Write students.csv + rooms.csv to /tmp              │
│  4. Call allocate.py (OR-Tools CP-SAT)                  │
│  5. Read output.csv assignments                         │
│  6. Bulk-insert allocations + audit logs + notifications│
│  7. Return JSON result to browser                       │
└──────────┬───────────────────────────┬──────────────────┘
           │                           │
           ▼                           ▼
┌──────────────────┐       ┌──────────────────────────────┐
│  Python predict.py│       │   Python allocate.py         │
│  XGBoost .pkl     │       │   OR-Tools CP-SAT Solver     │
│  → urgency scores │       │   → room assignments (CSV)   │
└──────────────────┘       └──────────────────────────────┘
           │                           │
           └──────────┬────────────────┘
                      ▼
              ┌────────────────┐
              │   MySQL DB     │
              │  fairmedalloc  │
              └────────────────┘
```

---

## 4. How the Allocation Works (Step-by-Step)

### Step 1 — Fetch Eligible Students
The engine queries `student_profiles` for students who are:
- `allocation_status = 'Unallocated'`
- **AND** either `is_paid = 1` (imported/confirmed payment) OR have a row in
  `payments` with `status = 'paid'` (pay simulator confirmation)

Students who have not paid are **not** considered — allocation is a benefit tied
to fee payment.

### Step 2 — Score via XGBoost
Each eligible student's medical data is sent to `predict.py`, which loads
`xgboost_hostel_model.pkl` and produces an **urgency score from 0–100**.

Inputs to the model:
- Medical condition (Sickle Cell, Epilepsy, Diabetes, Cardiac, etc.)
- Severity level (Low / Medium / High)
- Mobility status (Normal / Crutches/Walker / Wheelchair User / Artificial Limb)
- Academic level (100–500)
- Has special needs flag
- Is requested mobility flag

A **stabilization floor** is applied after the model score to ensure clinically
critical conditions are never scored below a minimum threshold (e.g. a student
with Sickle Cell Disease + High severity always scores ≥ 90).

If the Python/model path fails, the system falls back to a PHP rule-based scorer.

### Step 3 — Classify Urgency Bands
| Band | Score Range | Allocation Policy |
|---|---|---|
| **High** | ≥ 75 | Clinic-proximal rooms (Prophet Moses Blk 1–2 for males, Queen Esther Extension Blk 39 for females) |
| **Medium** | 40–74 | First block of their faculty-proximal hostel |
| **Low** | < 40 | Any valid remaining room of matching gender |

### Step 4 — OR-Tools CP-SAT Solver
The engine passes all eligible students and all available rooms to `allocate.py`,
which builds a **Constraint Programming model**:

- **Hard constraint 1:** Gender must match (Male → Male hostel, Female → Female hostel).
- **Hard constraint 2:** Prophet Moses Hall Block 1 is exclusively reserved for
  High-urgency males — no backfill ever occurs in this block.
- **Capacity constraint:** No room can be assigned more students than its available beds.
- **Each student** is assigned to at most one room.

The solver **maximises a weighted objective** where:
- High-urgency students in their matching clinic room get +5,000,000
- Medium-urgency in first block of faculty-proximal hostel get +1,500,000
- Medium-urgency in Prophet Moses Block 2 get +1,200,000
- Medium-urgency in any other block of faculty-proximal hostel get +400,000
- Low-urgency students get no bonus but face no hard ban — they freely fill
  remaining capacity after priority students are placed.

A small random tie-break (0–99) is added to each variable so equally-weighted
options are varied across runs.

### Step 5 — Write Results to Database
For each assigned student the engine:
1. Finds the next free bed slot in the room (A, B, C… based on `bed_config`)
2. Inserts a row in `allocations` (student_id, room_id, bed_space, bed_label,
   academic_session, allocation_method, algorithm_version)
3. Updates `student_profiles.allocation_status = 'Allocated'`
4. Increments `rooms.occupied_count`
5. Writes an `algorithm_audit_log` row (score, severity, hostel assigned)
6. Sends a `notification` to the student

All writes happen inside a **single database transaction** — if anything fails,
the entire batch is rolled back.

---

## 5. The XGBoost Urgency Model

| File | `ml_models/xgboost_hostel_model.pkl` |
|---|---|
| Script | `ml_models/predict.py` |
| Training data | `ml_models/hostel_medical_dataset.csv` |
| Output | Urgency score 0–100 per student |

The model was trained on simulated medical hostel data that maps condition +
severity + mobility combinations to urgency scores that reflect clinical priority.

The PHP layer (`UrgencyScoreService.php`) also implements a **fallback PHP scorer**
used if Python is unavailable, ensuring the system degrades gracefully.

An **ML microservice** (`ml_models/ml_service.py`) can optionally be run as a
persistent HTTP server (`start_ml_service.bat`) — when active, it is tried first
before falling back to the subprocess `predict.py` approach.

---

## 6. The OR-Tools CP-SAT Solver

| File | `ml_models/allocate.py` |
|---|---|
| Library | `ortools.sat.python.cp_model` (Google OR-Tools) |
| Input | `students.csv` + `rooms.csv` (written to `/tmp` by PHP) |
| Output | `output.csv` (student_id, room_id pairs) |
| Time limit | **120 seconds** (returns best valid solution found so far) |

For cohorts of 7,000 students with 2,000+ rooms, the model has ~14 million
boolean variables. The solver typically returns `FEASIBLE` (a valid, good-quality
allocation) within the time limit. `OPTIMAL` means no better assignment exists.

If OR-Tools is unavailable or the output file is not produced, the engine
automatically falls back to the **PHP Greedy Fallback** allocator, which
implements the same priority rules in a deterministic greedy pass.

The active solver backend is controlled by:
```sql
SELECT setting_value FROM settings WHERE setting_key = 'allocation_solver_backend';
-- 'ortools' → uses OR-Tools | 'php' → uses PHP Greedy Fallback
```

---

## 7. Allocation Policy Rules

| Rule | Detail |
|---|---|
| **Prophet Moses Hall Block 1 (Male)** | Hard reserve — High-urgency males ONLY. Never backfilled. |
| **Prophet Moses Hall Block 2 (Male)** | High-urgency males first; Medium males may use this block once Block 1 is full |
| **Queen Esther Extension Block 39 (Female)** | Clinic-proximal — High-urgency females first |
| **Faculty Proximal (Medium urgency)** | Assigned to first block of their faculty's designated hostel |
| **Low urgency** | Fill any remaining valid room with matching gender |
| **Postgrad/Foundation rooms** | Completely excluded from undergraduate allocation |

### Faculty → Hostel Mapping (Medium urgency)

| Faculty | Male Hostel | Female Hostel |
|---|---|---|
| Humanities, Management, Natural, Social Sciences, Computing | Prophet Moses Hall | Queen Esther Hall |
| Engineering, Law, Built Environment | Joshua Hall | Deborah Hall |
| Basic Medical Sciences | Joshua Hall | Deborah Hall / Queen Esther Hall |

---

## 8. Student Eligibility

A student is **eligible** for allocation if:

1. `student_profiles.allocation_status = 'Unallocated'`  
   **AND**
2. `student_profiles.is_paid = 1` (set during CSV import when column J = 1)  
   **OR** a record in `payments` with `status = 'paid'` (from the Pay Simulator)

> ⚠️ **Important:** Students with `is_paid = 0` and no payment record are
> intentionally excluded. To allow a student to be allocated, either:
> - Import their CSV row with column J = `1`
> - Or use the Pay Simulator in the admin panel to simulate a successful payment

---

## 9. Key Pages & Features

| Page | URL | Description |
|---|---|---|
| Admin Dashboard | `admin_dashboard.php` | Overview of allocations, payments, analytics |
| Run Allocation | `run_allocation.php` | Trigger the full allocation engine + rescore urgency |
| Data Import | `upload_data.php` | Bulk-import students via CSV |
| Admin Reports | `admin_reports.php` | Hostel occupancy, allocation breakdowns, audit logs |
| View Tables | `view_table.php` | Browse any DB table (students, rooms, hostels, etc.) |
| Settings | `settings.php` | Adjust urgency thresholds, session, solver backend |
| Student Dashboard | `student_dashboard.php` | Student sees their allocation status |
| Print Slip | `print_slip.php` | Printable allocation certificate for allocated students |
| Pay Simulator | *(within student flow)* | Simulates payment confirmation for a student |

---

## 10. Database Tables

| Table | Purpose |
|---|---|
| `users` | Login credentials for all users (admin + student) |
| `student_profiles` | Student demographics, payment flag, allocation status |
| `departments` | Department names linked to faculties |
| `faculties` | Faculty names |
| `medical_records` | Condition, severity, mobility, urgency score per student |
| `hostels` | Hostel metadata (gender, proximal flags, block names) |
| `rooms` | Individual rooms with capacity, bed config, occupancy count |
| `allocations` | Final assignments (student ↔ room ↔ bed) |
| `payments` | Pay simulator payment records |
| `notifications` | Per-student notification messages |
| `algorithm_audit_logs` | Per-run audit trail of every allocation decision |
| `admin_audit_logs` | Admin action log |
| `settings` | Key-value system configuration |

---

## 11. Expected Run Times

The solver uses a **hybrid approach** — OR-Tools CP-SAT runs on High + Medium urgency
students only (the medically important subset), while a fast greedy algorithm fills
remaining capacity for Low urgency students. This cuts the variable count from
~14 million (full cohort) down to ~500 K, dramatically reducing runtime.

| Cohort Size | Eligible High/Medium | XGBoost Scoring | OR-Tools Phase | Greedy Phase | DB Writes | **Total** |
|---|---|---|---|---|---|---|
| 100 students | ~12 | ~3s | ~5s (**OPTIMAL**) | <1s | ~1s | **~15s** ✅ measured |
| 500 students | ~60 | ~5s | ~15s | ~1s | ~3s | **~25s** |
| 1,000 students | ~120 | ~8s | ~30s | ~2s | ~5s | **~45s** |
| 7,000 students | ~800 | ~20s | ~60s | ~5s | ~15s | **~2 min** |

- The browser keeps the connection alive for up to **10 minutes**.
- PHP has **no execution time limit** (`set_time_limit(0)`) for this endpoint.
- OR-Tools is capped at **120 seconds** for the priority cohort — the greedy phase has no cap.
- A "still running…" ping appears every 30 seconds so the admin knows it hasn't hung.
- For 7,000 students, expect **~2 minutes** total — well within the browser window.

---

## 12. CSV Import Format

The Data Import page (`upload_data.php`) accepts a 10-column CSV:

| Col | Field | Example | Notes |
|---|---|---|---|
| A | Matric No | `RUN/CMP/22/001` | Used as username + default password |
| B | Full Name | `John Doe` | |
| C | Level | `200` | 100–500 |
| D | Faculty | `Faculty of Engineering` | Must match exactly |
| E | Department | `Computer Engineering` | |
| F | Gender | `Male` or `Female` | |
| G | Medical Condition | `Sickle Cell` or `None` | |
| H | Severity | `Low` / `Medium` / `High` | |
| I | Mobility | `Normal Mobility` / `Wheelchair User` | |
| J | **Paid Status** | **`1`** (paid) or **`0`** (not paid) | Controls eligibility for allocation |

> Row 1 must be a header row — it is skipped automatically.

---

## 13. Troubleshooting

### "Allocated: 0 of 0 eligible students"
**Cause:** No students have `is_paid = 1` or a paid payment record.  
**Fix:** Re-import your CSV with `1` in column J for paid students, OR use the
Pay Simulator to mark students as paid before running allocation.

### "Solver finished: FEASIBLE" (not OPTIMAL)
This is **normal** for large cohorts. The solver ran for 120 seconds and returned
the best allocation it found. All hard constraints (gender, capacity, Block 1
reserve) are guaranteed to be satisfied. Only the soft preference bonuses may be
suboptimal.

### Allocation engine hangs / takes too long
- The solver is time-limited to 120 seconds — it will always return.
- Total expected time for 7,000 students: **2–3 minutes**.
- Do not reload the page — use the "still running…" ping as confirmation it is active.

### Python / XGBoost errors
Check that the Python binary in `.env` is correct:
```
PYTHON_BIN=C:/Users/quadr/AppData/Local/Programs/Python/Python311/python.exe
```
Verify packages are installed: `python -m pip install ortools xgboost pandas scikit-learn`

### Processing lock stuck
If a previous run crashed mid-execution, the processing lock may be stuck.
The lock auto-releases after 15 minutes. Or run:
```sql
UPDATE settings SET setting_value = '0' WHERE setting_key = 'admin_processing_lock';
```

