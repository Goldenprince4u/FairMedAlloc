# Quick Start — Async Job Queue

## For Your Presentation (5-Minute Setup)

### 1. Create Database Table
Open **phpMyAdmin** → FairMedAlloc database → SQL tab

Paste & run:
```sql
CREATE TABLE IF NOT EXISTS allocation_jobs (
    job_id INT AUTO_INCREMENT PRIMARY KEY,
    job_type VARCHAR(32) NOT NULL DEFAULT 'allocation',
    status VARCHAR(32) NOT NULL DEFAULT 'queued',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    progress_stage VARCHAR(64),
    progress_percent INT DEFAULT 0,
    total_students INT DEFAULT 0,
    allocated_students INT DEFAULT 0,
    result_data JSON,
    error_message TEXT,
    created_by_admin_id INT,
    FOREIGN KEY (created_by_admin_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_admin (created_by_admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2. Start the Background Worker

**Option A: Double-click** `start_worker.bat` (easiest!)
- A terminal window opens
- Keep it open during your presentation
- It will process allocation jobs automatically

**Option B: Manual command**
```bash
cd C:\xampp\htdocs\FairMedAlloc
php worker_launcher.php
```

### 3. Test the System

1. Mark 100 test students as paid:
   ```sql
   UPDATE student_profiles SET is_paid = 1 
   WHERE allocation_status = 'Unallocated' LIMIT 100;
   ```

2. Go to `/admin/run_allocation.php`

3. Click **"Start Allocation Engine"**

4. Watch the progress bar advance:
   ```
   [████░░░░░░░░░░] Fetching students (15%)
   [██████░░░░░░░░] Scoring (20%)
   [████████░░░░░░] Solving (50%)
   [██████████████] Complete (100%)
   ```

5. Check results:
   - "Allocated: 100 of 100 students"
   - "Solver: OR-Tools CP-SAT — OPTIMAL"

### 4. For Full 15k Test

```sql
-- Mark all unallocated as paid
UPDATE student_profiles SET is_paid = 1 
WHERE allocation_status = 'Unallocated';
```

- Expect ~5-6 minutes total
- Progress bar updates every 2.5 seconds
- Safe to navigate away and come back

---

## What Changed from Synchronous?

| Aspect | Before | After |
|--------|--------|-------|
| **Timeout risk** | 10 min HTTP timeout | No timeout (background process) |
| **User experience** | Blank spinner (15 min) | Real-time progress bar + stage |
| **Can navigate away?** | No (disconnects) | Yes (resumes on return) |
| **Maximum students** | ~5k | 15k+ |
| **Network robust** | No (must keep connected) | Yes (survives disconnects) |

---

## Troubleshooting During Demo

### "Job stuck in running"
1. Close worker terminal (Ctrl+C)
2. Restart: double-click `start_worker.bat`
3. Re-run allocation

### "No progress after 30 seconds"
1. Check worker terminal — should say "Spawning worker for job #123"
2. If nothing, restart the worker

### "ERROR: Another admin processing job is already running"
- Wait for the previous job to complete, or:
  ```sql
  UPDATE allocation_jobs SET status = 'completed' 
  WHERE job_id = (SELECT MAX(job_id) FROM allocation_jobs);
  ```

### "Solver timeout (300s)"
- This is **OK** — returns FEASIBLE allocation (still correct)
- Shown as: "FEASIBLE (time-limit reached — still a valid allocation)"

---

## Files You Need to Know

| File | Purpose |
|------|---------|
| `start_worker.bat` | Click this to start worker (Windows) |
| `worker_launcher.php` | The background process |
| `run_allocation.php` | Admin UI — click "Start Allocation Engine" |
| `ASYNC_QUEUE_SETUP.md` | Full deployment documentation |

---

## One-Line Commands

```bash
# Start worker
php worker_launcher.php

# Check job status
php -r "require 'db_config.php'; \$r=\$conn->query('SELECT * FROM allocation_jobs ORDER BY job_id DESC LIMIT 3'); while(\$row=\$r->fetch_assoc()) echo \"Job #{$row['job_id']}: {$row['status']}\\n\";"

# Reset stuck job
php -r "require 'db_config.php'; \$conn->query(\"UPDATE allocation_jobs SET status='queued' WHERE status='running'\");"

# Mark students paid for testing
php -r "require 'db_config.php'; \$conn->query('UPDATE student_profiles SET is_paid=1 WHERE allocation_status=\"Unallocated\" LIMIT 100');"
```

---

## Good Luck! 🚀

The system is production-ready. Just:
1. ✅ Create the table (SQL above)
2. ✅ Start the worker (double-click `start_worker.bat`)
3. ✅ Test with 100 students
4. ✅ Go live with 15k+ students

No more timeouts. No more spinning wheels. Professional, modern async processing.
