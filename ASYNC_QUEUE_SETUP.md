# Async Allocation Queue — Deployment Guide

## What Changed

The allocation system now uses **background job queues** instead of synchronous HTTP requests. This eliminates timeouts and allows allocations to run reliably for 15,000+ students.

### Key Benefits
✅ **No timeouts** — Jobs run in background, timeout limits removed  
✅ **Safe to close** — Navigate away from page, progress resumes  
✅ **Professional UX** — Real-time progress bar shows actual stage & percent  
✅ **Fault tolerant** — Network drops don't restart the process  
✅ **Scalable** — Handles 15k students without browser timeout  

---

## Architecture

```
Frontend (run_allocation.php)
    ↓ queue_allocation()
    ↓ POST /api/admin_api.php?action=queue_allocation
    ↓
Database (allocation_jobs table) ← stores job status
    ↓
Background Worker (worker_allocation.php or worker_launcher.php)
    ↓ processes queue
    ↓
Frontend polls /api/admin_api.php?action=job_status&job_id=123 (every 2.5s)
    ↓
Real-time progress bar updates
```

---

## Setup Steps (One-Time)

### 1. Create the Database Table

**Option A: Using PHP migration (recommended)**

Start your MySQL server first, then:

```bash
php sql/run_queue_migrations.php
```

**Option B: Using MySQL Workbench or phpMyAdmin**

1. Open phpMyAdmin → FairMedAlloc database
2. Click "SQL" tab
3. Paste this SQL:

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

CREATE EVENT IF NOT EXISTS cleanup_old_allocation_jobs
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
    DELETE FROM allocation_jobs 
    WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

4. Click "Go"

**Option C: Via command line**

```bash
# Unix/macOS
mysql -h 127.0.0.1 -P 3307 -u root -p fairmedalloc < sql/20260501_allocation_jobs_queue.sql

# Windows (XAMPP MySQL prompt)
mysql -u root fairmedalloc < sql\20260501_allocation_jobs_queue.sql
```

### 2. Verify Table Creation

### 2. Verify Table Creation

In phpMyAdmin, run:

```sql
SHOW TABLES LIKE 'allocation_jobs';
DESCRIBE allocation_jobs;
```

Should show the allocation_jobs table with all columns.

### 3. Start the Background Worker

**Option A: Manual (for testing)**

```bash
php worker_allocation.php
```

Runs once, processes one queued job, exits. Safe to run manually or from cron.

**Option B: Continuous (recommended for production)**

```bash
php worker_launcher.php
```

Runs forever, polls every 2 seconds for new jobs. Keep this running in a terminal or as a service.

**Windows (XAMPP) — QUICK START:**

1. Open Command Prompt (or PowerShell)
2. Navigate to the project:
   ```
   cd C:\xampp\htdocs\FairMedAlloc
   ```
3. Start the worker:
   ```
   php worker_launcher.php
   ```
4. **Keep this terminal open** while using the allocation system
   - You'll see log messages like: "Launcher alive", "Spawning worker for job #123"
   - Don't close it unless you want to stop processing jobs

**For persistent background execution (Windows):**

Create a batch file `start_worker.bat` in your FairMedAlloc folder:
```batch
@echo off
cd /d C:\xampp\htdocs\FairMedAlloc
php worker_launcher.php
```

Then use Windows Task Scheduler to run it at startup.

**Linux/macOS (production):**

Add to supervisord config or systemd service:

```ini
[program:fairmed-worker]
command=/usr/bin/php /var/www/fairmedalloc/worker_launcher.php
autostart=true
autorestart=true
stderr_logfile=/var/log/fairmed-worker.err.log
stdout_logfile=/var/log/fairmed-worker.out.log
```

Or via cron (every minute):

```cron
* * * * * php /var/www/fairmedalloc/worker_allocation.php >/dev/null 2>&1
```

---

## Usage (Admin Perspective)

1. Click **"Start Allocation Engine"** on `/admin/run_allocation.php`
   - ✓ Immediate response: "Job #123 queued"
   
2. See real-time progress:
   - Progress bar: 0% → 100%
   - Stage: "Fetching students" → "Scoring" → "Solving" → "Complete"
   - Elapsed time counter
   
3. Safe to:
   - Close the tab
   - Refresh the page  
   - Navigate away (progress resumes)
   - Keep the page open to watch

4. Completion:
   - Shows final statistics: "Allocated 12,500 of 15,000 students"
   - Shows solver status: "OPTIMAL" or "FEASIBLE"
   - Buttons re-enable for next run

---

## Monitoring

### Check Job Status

```bash
# List all jobs
php -r "
require 'db_config.php';
\$res = \$conn->query('SELECT job_id, status, progress_stage, progress_percent, created_at FROM allocation_jobs ORDER BY job_id DESC LIMIT 10');
while (\$r = \$res->fetch_assoc()) echo \"Job #{$r['job_id']}: {$r['status']} ({$r['progress_percent']}%) — {$r['progress_stage']}\n\";
"
```

### Check Worker Logs

```bash
# Tail the latest logs
tail -f var/logs/fairmedalloc.log | grep allocation

# Or check database directly
SELECT * FROM allocation_jobs WHERE status = 'running' OR status = 'failed' ORDER BY created_at DESC;
```

### Verify Worker is Running

```bash
# Process check (Linux/macOS)
ps aux | grep worker_launcher.php

# Or check for active jobs
php -r "
require 'db_config.php';
\$res = \$conn->query('SELECT COUNT(*) as cnt FROM allocation_jobs WHERE status IN (\"queued\", \"running\")');
\$row = \$res->fetch_assoc();
echo \"Jobs in queue: {$row['cnt']}\n\";
"
```

---

## Testing

### Test with Small Dataset

1. Create test data:
```sql
-- Temporarily mark 100 unallocated students as paid
UPDATE student_profiles SET is_paid = 1 WHERE allocation_status = 'Unallocated' LIMIT 100;
```

2. Click "Start Allocation Engine"

3. Watch the progress bar advance (should take ~30-60 seconds)

### Test with Full Dataset (15k+)

After small test succeeds:

```sql
-- Mark all unallocated as paid
UPDATE student_profiles SET is_paid = 1 WHERE allocation_status = 'Unallocated';
```

Expected timeline (with 15k students):
- Queue time: < 1 second
- Fetch students: 2-3 seconds
- XGBoost scoring: 5-10 seconds
- OR-Tools solver: up to 300 seconds
- Bulk writes: 10-15 seconds
- **Total: ~5-6 minutes** ✓ No timeout!

---

## Troubleshooting

### "Job stuck in 'running' status"

If a job is stuck:

1. **Check if worker crashed:**
   ```bash
   ps aux | grep php
   ```

2. **Restart the worker:**
   ```bash
   php worker_launcher.php
   ```

3. **Or manually reset the job:**
   ```sql
   UPDATE allocation_jobs SET status = 'queued' WHERE job_id = 123;
   ```

### "Worker not processing jobs"

1. **Check database table exists:**
   ```sql
   SHOW TABLES LIKE 'allocation_jobs';
   ```

2. **If table doesn't exist, run migration:**
   ```bash
   php sql/run_queue_migrations.php
   ```

3. **Check PHP can execute shell commands:**
   - Verify `proc_open` is not disabled
   - Check `php.ini`: `disable_functions` doesn't include `proc_open`

### "Failed to queue job: Another admin processing job is already running"

This is normal — prevents concurrent allocations. Wait for the current job to finish, or manually reset:

```sql
-- Force-clear any locks
DELETE FROM settings WHERE setting_key = 'admin_processing_lock';
```

### "Solver timeout (300 seconds reached)"

The solver hit 300s timeout. This is **fine** — it returns a FEASIBLE (valid, fair) allocation instead of OPTIMAL. Results are still correct.

To debug:
- Check solver output: `tail -f var/logs/fairmedalloc.log | grep "Solver status"`
- Increase timeout in `ml_models/allocate.py` line 274 if needed (not recommended)

---

## Files Modified/Added

| File | Purpose |
|------|---------|
| `sql/20260501_allocation_jobs_queue.sql` | Database schema for job queue |
| `sql/run_queue_migrations.php` | Migration runner |
| `worker_allocation.php` | Single-job background worker (new) |
| `worker_launcher.php` | Continuous polling launcher (new) |
| `includes/AllocationEngine.php` | Added progress callback support |
| `api/admin_api.php` | Queue handlers: `queue_allocation`, `job_status` |
| `run_allocation.php` | Updated UI for async mode |

---

## Performance Notes

- **Database queries:** Optimized with indexes on `status`, `created_at`
- **Worker concurrency:** Only one job runs at a time (mutex lock in `processAllocationJob`)
- **Poll frequency:** 2.5s default (adjustable via `POLL_INTERVAL_MS` in run_allocation.php)
- **Job retention:** Deleted after 30 days by database event

---

## Next Steps

1. ✅ Run migration: `php sql/run_queue_migrations.php`
2. ✅ Start worker: `php worker_launcher.php` (in background terminal)
3. ✅ Test with 100 students first
4. ✅ Test with 15k students
5. ✅ Verify completion status
6. ✅ Deploy to production with worker running continuously

---

## Support

If issues occur:
1. Check `var/logs/fairmedalloc.log` for errors
2. Verify worker process is running
3. Check database for stuck jobs
4. Inspect `allocation_jobs` table status/error_message

Good luck with the presentation! 🚀
