# FairMedAlloc

FairMedAlloc is my capstone project: a highly optimized medical hostel allocation system designed for Redeemer's University. It bridges the gap between raw medical data and practical campus logistics by combining:

- A trained **XGBoost AI Model** for evaluating a student's raw medical urgency.
- A **Policy Calibration Layer** that takes those AI scores and aligns them with real-world administrative rules.
- An ultra-fast **Min-Cost Flow Graph Matcher (via Google OR-Tools)** to perfectly assign thousands of students to bedspaces in less than 2 seconds.

## How the Policy Works

### Urgency Bands
Students are grouped into three priority bands based on their calibrated scores:

| Band | Score Range | What it means for allocation |
|---|---:|---|
| **High** | 75-100 | Absolute priority. Sent straight to clinic-proximal hostels. |
| **Medium** | 40-74 | Placed first in their designated faculty-proximal halls. |
| **Low** | 0-39 | Fills out whatever valid capacity is left over, still respecting faculty rules. |

### The "Human" Rules (Policy Calibration)
The AI model is great at reading medical charts, but it doesn't know how our university works. So I added a calibration layer to enforce some strict admin rules before the allocator kicks in:
- Mobility-only cases (like a broken leg) are kept in the Medium band. They don't need the clinic, they just need the ground floor.
- High-severity illnesses (like sickle cell crisis) are instantly boosted into the High band.
- If a student has both a severe illness AND a mobility issue, they get bumped to High.

### Room & Bed Placement Constraints
This is where the Min-Cost Flow engine shines. It enforces strict architectural and accessibility rules:
- **High Urgency:** Pushed directly into clinic-proximal rooms.
- **Medium Urgency:** Funneled into the very first block of their faculty's target hostel.
- **Accessibility Guarantee:** Any student with a physical disability (Wheelchair, Crutches, etc.) is mathematically locked into a **Ground-Floor** room (specifically in Joshua or Deborah Hall).
- **Lower Bunk (LB) Protection:** As of the latest update, the engine actively scans the room's bed layout and will strictly assign **Lower Bunks (LB)** to any student with physical disabilities. 

For the complete breakdown, you can check out my [ALLOCATION_POLICY.md](ALLOCATION_POLICY.md) and [FINAL_POLICY_STATEMENT.md](FINAL_POLICY_STATEMENT.md).

## Why Min-Cost Flow?
In older versions of this project, I used Constraint Programming (CP-SAT) to solve the allocation like a massive Sudoku puzzle. But when trying to assign 3,000+ students, it took over 8 minutes and sometimes crashed completely due to the NP-hard complexity. 

By modeling the university as a directed flow network (think of water flowing through pipes based on gravity/costs), the new **Min-Cost Flow algorithm** processes 3,000 students and guarantees a mathematically perfect, 100% fair assignment in **about 1.5 seconds**. 

## Technical Features

- **Frontend Design:** Clean, dynamic UI built entirely with HTML5, **Vanilla CSS**, and asynchronous JavaScript (No CSS frameworks, zero bloat).
- **AI Scoring:** XGBoost urgency prediction evaluating severity matrices and physical conditions.
- **Graph Matching:** Google OR-Tools Min-Cost Flow mathematically guaranteeing fairness for all 6,736 undergraduate beds instantly.
- **Async Queue:** Non-blocking background worker system so the UI never freezes during 15k+ student batches.
- **Accessibility First:** Ground-floor and Lower Bunk (`LB`) enforcement for all mobility-priority students.
- **Smart Admin Panel:** Bulk CSV imports, manual overrides, and real-time dashboard analytics.

## Getting Started

### What you need:
- PHP 8+
- MySQL / MariaDB
- Python 3.8+
- Pip packages: `ortools`, `xgboost`, `pandas`, `scikit-learn`

### Setting up the Environment
Create a `.env` file in the root folder. Here's what mine looks like:

```ini
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USER=root
DB_PASS=
DB_NAME=fairmedalloc
ML_SERVICE_URL=http://127.0.0.1:5051
ML_SERVICE_TIMEOUT=5
# Point these to your local Python installation
PYTHON_BIN=C:/path/to/your/python.exe
FAIRMED_PYTHON_BIN=C:/path/to/your/python.exe
ML_MODEL_PICKLE_PATH=ml_models/xgboost_hostel_model.pkl
```

### Database Setup
1. Import `sql/schema.sql` into your database.
2. Run `php sql/seed.php` to populate the initial setup.
3. Apply any date-stamped migration files in the `sql/` folder.

## How to run the Allocation
Usually, the system runs allocations in the background asynchronously. If you're testing on Windows (like XAMPP), the UI will automatically fall back to an "inline" mode if it can't spawn a background worker, which works perfectly fine since the new Min-Cost Flow solver is so fast.

If you ever get a stuck job because you cancelled it mid-run, just hit the **Cancel Job** button on the dashboard to forcefully reset the MySQL database locks!

## A Note on the Code
Take a look at `ml_models/allocate.py` and `includes/AllocationEngine.php`. I've tried to keep the orchestration clean—PHP handles the database, UI, and async queue, while Python does the heavy mathematical lifting for the AI and Graph Matching. 

---
*Built for Redeemer's University.*
