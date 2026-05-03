# FairMedAlloc

FairMedAlloc is a medical hostel allocation system for Redeemer's University. It combines:

- an XGBoost model for raw urgency scoring
- a policy calibration layer for final urgency scores
- an OR-Tools CP-SAT allocator for room placement

The model gives the base score. The allocator uses the final score after policy calibration.

## Current Policy

### Urgency bands

| Band | Score | Allocation effect |
|---|---:|---|
| High | 75-100 | Clinic-proximal placement |
| Medium | 40-74 | Faculty-proximal placement |
| Low | 0-39 | Remaining valid capacity after proximal priorities |

### Final scoring policy

The final score is not the raw XGBoost output alone.

1. XGBoost produces the base score from condition flags, mobility score, and severity score.
2. A policy calibration layer adjusts that score so the live system behaves as intended.
3. The allocator bands students from the calibrated score.

### Calibration rules

- Mobility-only cases stay in the Medium band by default.
- High-severity medical cases are lifted into the High band.
- Medical plus mobility cases are lifted into the High band.
- Mobility-only cases are intentionally capped below the High threshold unless Student Affairs later approves a manual relocation.

### Placement rules

- High students are strongly prioritized for clinic-proximal rooms.
- Medium students are sent to the first target in their faculty-proximal hall set.
- Group A medium males are steered to Prophet Moses Extension Hall Block 27 instead of Prophet Moses Hall Block 1.
- Medium students whose faculty-proximal hall is Joshua or Deborah are steered to the first-block ground floor.
- Mobility-priority students may only use ground-floor rooms inside Joshua Hall and Deborah Hall.
- Prophet Moses Hall Block 1 remains hard-reserved for High-urgency males only.

The short policy note is in [FINAL_POLICY_STATEMENT.md](FINAL_POLICY_STATEMENT.md). The detailed rules are in [ALLOCATION_POLICY.md](ALLOCATION_POLICY.md). The architecture is in [SYSTEM_OVERVIEW.md](SYSTEM_OVERVIEW.md).

## Why This Policy

This build separates two concerns:

- the model estimates medical urgency from structured inputs
- the allocator enforces campus layout and accessibility rules

That matters because the model does not know about:

- clinic-proximal hostels
- Prophet Moses Extension Hall Block 27
- Joshua/Deborah staircase constraints
- Student Affairs manual override workflows

Those are operational rules, so they belong in policy calibration and allocation logic.

## Key Behaviors

- Mobility-only does not automatically mean High anymore.
- High-severity medical cases do reach clinic-proximal allocation reliably.
- Medical plus mobility cases also reach clinic-proximal allocation reliably.
- The score band a student lands in is now aligned with the allocator's thresholds.

## Hostel Notes

### Clinic-proximal space

- Male: Prophet Moses Hall Blocks 1 and 2
- Female: Queen Esther Extension Hall Blocks 38 and 39

### Medium-priority accessibility targets

- Prophet Moses Extension Hall Block 26 remains foundation-only.
- Group A males: Prophet Moses Extension Hall Block 27
- Joshua Hall: first block ground floor
- Deborah Hall: first block ground floor

### Two-storey halls

- Joshua Hall
- Deborah Hall

These are the only halls where the allocator enforces a ground-floor mobility rule.

## Features

- XGBoost-based urgency scoring
- PHP fallback scoring path
- Policy calibration after model scoring
- OR-Tools CP-SAT allocation
- Async allocation queue with worker launcher
- Faculty-proximal medium placement
- Clinic-proximal high placement
- Ground-floor accessibility protection for Joshua and Deborah
- Bulk CSV student import
- Manual admin assignment override
- Audit logging and notifications

## Setup

### Requirements

- PHP 8+
- MySQL or MariaDB
- Python 3.8+
- Python packages: `ortools`, `xgboost`, `pandas`, `scikit-learn`

### Environment

Create `.env` in the project root:

```ini
DB_HOST=127.0.0.1
DB_PORT=3307
DB_USER=root
DB_PASS=
DB_NAME=fairmedalloc
ML_SERVICE_URL=http://127.0.0.1:5051
ML_SERVICE_TIMEOUT=5
PYTHON_BIN=C:/Users/YourUser/AppData/Local/Programs/Python/Python311/python.exe
FAIRMED_PYTHON_BIN=C:/Users/YourUser/AppData/Local/Programs/Python/Python311/python.exe
ML_MODEL_PICKLE_PATH=ml_models/xgboost_hostel_model.pkl
```

### Database

1. Import `sql/schema.sql`
2. Run `php sql/seed.php`
3. Run migrations in `sql/` in date order

Important migration for this policy:

- `sql/20260502c_enable_pme_block27_for_undergrad.sql`

That migration enables Prophet Moses Extension Hall Block 27 for undergraduate allocation, which is required for the new medium-priority male policy.

## Worker / Queue

The admin UI queues allocation jobs in `allocation_jobs`. On Windows, start the worker with:

```bat
start_worker.bat
```

Or manually:

```powershell
php worker_launcher.php
```

The queue dispatch path now resolves a CLI PHP binary more defensively and launches workers through `cmd /c start` on Windows.

## Project Structure

```text
FairMedAlloc/
├── api/
├── includes/
├── js/
├── css/
├── ml_models/
├── sql/
├── README.md
├── ALLOCATION_POLICY.md
├── SYSTEM_OVERVIEW.md
└── FINAL_POLICY_STATEMENT.md
```

Important files:

- `ml_models/predict.py`: raw XGBoost inference plus policy calibration
- `ml_models/allocate.py`: OR-Tools weighted allocator
- `includes/UrgencyScoreService.php`: PHP scoring bridge and fallback
- `includes/AllocationEngine.php`: orchestration, persistence, OR-Tools allocation pipeline with an emergency PHP fallback
- `api/admin_api.php`: admin queue and worker dispatch endpoints
- `worker_allocation.php`: background job processor
- `worker_launcher.php`: continuous queue poller

## How Allocation Works

1. Fetch eligible paid, unallocated students.
2. Score them through the model path.
3. Apply policy calibration to produce final urgency scores.
4. Convert final scores into High, Medium, and Low bands.
5. Feed students and rooms into OR-Tools.
6. Write allocations, audit logs, and notifications.

### Solver bonus ladder

| Rule | Bonus |
|---|---:|
| High -> matching clinic-proximal room | 5,000,000 |
| Mobility-priority -> Joshua/Deborah first-block ground floor | 2,200,000 |
| Medium male -> Prophet Moses Extension Hall Block 27 | 1,600,000 |
| Medium -> Joshua/Deborah first-block ground floor | 1,550,000 |
| Medium -> first block of faculty-proximal hostel | 1,500,000 |
| Low -> primary faculty-proximal hostel | 900,000 |
| Low -> secondary faculty-proximal hostel | 450,000 |
| Medium -> later block of faculty-proximal hostel | 400,000 |
| Medium or Low -> clinic-proximal overflow | 150,000 |

## Troubleshooting

| Issue | Fix |
|---|---|
| Run Allocation stays queued | Start `worker_launcher.php` or `start_worker.bat` |
| Python scoring unavailable | Check `PYTHON_BIN` and `FAIRMED_PYTHON_BIN` in `.env` |
| Mobility student on upper floor | Run Admin -> Rescore All, then rerun allocation if needed |
| Block 27 never receives students | Apply `sql/20260502c_enable_pme_block27_for_undergrad.sql` |
| No students allocated | Confirm payment data and available room capacity |

## License

Academic project. All rights reserved.
