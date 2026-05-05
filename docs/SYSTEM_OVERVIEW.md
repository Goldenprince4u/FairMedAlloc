# FairMedAlloc System Overview

## Purpose
FairMedAlloc is an automated hostel allocation system that I built to solve a very specific problem: fairly distributing university bedspaces based on medical urgency without breaking campus layout rules. 

It works in three distinct layers:
1. **Raw Medical Scoring** via a trained XGBoost AI model.
2. **Policy Calibration**, where I adjust those raw scores to make sure they follow administrative rules (like keeping mobility students safely on the ground floor without taking up scarce clinic beds).
3. **Room Assignment** via a highly optimized Min-Cost Flow graph matching algorithm.

I specifically separated the AI from the Room Assignment because the AI shouldn't know or care about which blocks are under renovation or where the stairs are. The AI just says "this student needs help," and the allocator figures out the best architectural fit.

## Architecture Stack

```text
Admin Dashboard (PHP/JS)
  -> api/admin_api.php (Handles Async Queue)
  -> includes/AllocationEngine.php (The Orchestrator)
      -> includes/UrgencyScoreService.php (Connects to Python AI)
          -> ml_models/predict.py (XGBoost Inference & Calibration)
      -> ml_models/allocate.py (Min-Cost Flow Graph Matcher)
  -> MySQL Database
```

## How the Scoring Works

### The Raw Model
I trained an `XGBRegressor` on historical student data to look at 9 specific medical features (like `has_sickle_cell`, `mobility_score`, `severity_score`, etc.). It spits out a base score between 0 and 100.

### Policy Calibration
The raw score isn't enough on its own. After XGBoost returns the score, my Python script calibrates it so that the live system actually makes sense:
- If a student just has a broken leg (mobility-only), they stay in the **Medium** band. They don't need a clinic bed, they just need to avoid stairs.
- High-severity medical cases (or combinations of medical + mobility) get bumped to the **High** band.

### The Final Bands
| Band | Score | Where they end up |
|---|---:|---|
| **High** | 75-100 | Clinic-proximal rooms |
| **Medium** | 40-74 | Faculty-proximal rooms (Ground floor priority) |
| **Low** | 0-39 | Whatever is left in their Faculty-proximal rooms |

## How the Allocation Works (The Min-Cost Flow Engine)

This is the biggest upgrade I made to the system. Originally, I used a Constraint Programming (CP-SAT) solver. It worked for small batches, but when I hit it with 3,000+ students, it took 8 minutes to run and often crashed because it's mathematically "NP-Hard".

So, I rewrote `allocate.py` to use Google's **Min-Cost Flow** algorithm. I modeled the students and beds as a massive network of pipes. By assigning massive "negative costs" (bonuses) to good matches, the system pushes all the students through the pipes and finds the absolute mathematically perfect assignment in **under 1.5 seconds**.

### The Weight Model (How the engine decides)
I used ridiculously large numbers here on purpose. It guarantees that the priority rules physically cannot be broken by a cluster of slightly higher-scoring normal students.

| Rule | Bonus Points |
|---|---:|
| High -> clinic-proximal match | +5,000,000 |
| Mobility-priority -> Joshua/Deborah first-block ground floor | +2,200,000 |
| Medium male -> Prophet Moses Extension Hall Block 27 | +1,600,000 |
| Medium -> Joshua/Deborah first-block ground floor | +1,550,000 |
| Medium -> first block of faculty-proximal hostel | +1,500,000 |
| Low -> primary faculty-proximal hostel | +900,000 |
| Low -> secondary faculty-proximal hostel | +450,000 |
| Medium -> later faculty-proximal block | +400,000 |
| Medium or Low -> clinic overflow | +150,000 |

### Bedspace Accessibility (The LB Rule)
Once the Python graph assigns a student to a room, the PHP `AllocationEngine` steps in to assign the exact bed (A, B, C, D). 
I added a strict loop here: if a student has *any* mobility issue, the engine actively scans the room's configuration (like `LB, UB, LB, UB`) and will strictly lock them into a **Lower Bunk (LB)** before checking anything else.

## The Async Queue

Because assigning 3,000 students can technically take a few seconds (mostly the database inserts and AI scoring), I built an asynchronous worker queue. 
When an admin clicks "Start Allocation", it drops a job in the `allocation_jobs` table and tells `worker_launcher.php` to run it in the background. If you're running this on Windows XAMPP and the background launch fails, I built a safe "inline fallback" that just runs it on the spot so the UI doesn't break.

## Source Map
If you want to read the code, here's where everything lives:
- **Scoring bridge:** `includes/UrgencyScoreService.php`
- **Model adapter:** `ml_models/predict.py`
- **Solver (Min-Cost Flow):** `ml_models/allocate.py`
- **Engine (PHP Orchestrator & LB logic):** `includes/AllocationEngine.php`
- **Queue API & Manual Assign:** `api/admin_api.php`
- **Worker launcher:** `worker_launcher.php`
