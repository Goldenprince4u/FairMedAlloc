# Codebase Scan Report — Async Queue Implementation
Generated: 2026-05-02

---

## 🚨 CRITICAL ISSUES (Must Fix)

### 1. Missing `updated_at` Column in Database Schema
**Severity:** CRITICAL  
**Location:** `sql/20260501_allocation_jobs_queue.sql`  
**Problem:** Worker code uses `updated_at` extensively (stale job recovery, progress updates) but the column is not defined in the CREATE TABLE statement.

**Evidence:**
```php
// worker_allocation.php line 134
AND updated_at < DATE_SUB(NOW(), INTERVAL $minutes MINUTE)"

// worker_allocation.php line 156, 182, 214, 226, 242
SET progress_stage = '$stage', progress_percent = $percent, updated_at = NOW()
```

**Impact:** Database queries will fail with "Unknown column 'updated_at'"

**Fix Required:**
```sql
ALTER TABLE allocation_jobs ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
```

**Or recreate table:**
```sql
DROP TABLE allocation_jobs;
-- Then rerun migration with corrected schema
```

---

### 2. Missing Job ID Parameter in Worker Launcher
**Severity:** CRITICAL  
**Location:** `worker_launcher.php` line 88  
**Problem:** The worker launcher spawns workers but doesn't pass the `--job-id` parameter.

**Current Code:**
```php
$cmd = 'start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
     . ' > NUL 2>&1';  // ❌ Missing --job-id=$job_id
```

**Expected:**
```php
$cmd = 'start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
     . ' --job-id=' . $job_id . ' > NUL 2>&1';  // ✓ Fixed
```

**Impact:** Workers won't process any jobs, queue stays stuck in "queued" status

**Fix:** Add `--job-id=$job_id` to both Windows and Unix/Linux command builders

---

### 3. SQL Injection Risk in Worker Launcher
**Severity:** CRITICAL  
**Location:** `worker_launcher.php` line 47

**Problem:** Using unescaped `$max_jobs` directly in SQL query:
```php
LIMIT $max_jobs"  // ❌ Should use prepared statement
```

**Fix:** Use prepared statement or validate type:
```php
$max_jobs = (int)$_SERVER['FAIRMED_WORKER_MAX_JOBS'] ?? 1;  // Already done, but verify
$result = $conn->query("SELECT job_id FROM allocation_jobs WHERE status = 'queued' ORDER BY created_at ASC LIMIT " . (int)$max_jobs);
```

---

## ⚠️ IMPORTANT ISSUES (Should Fix)

### 4. SQL Injection Risk in worker_allocation.php
**Severity:** HIGH  
**Location:** `worker_allocation.php` lines 173, 193, 219, 234  
**Problem:** Using `real_escape_string()` instead of prepared statements.

**Current:**
```php
$stage = $conn->real_escape_string((string)($progress['stage'] ?? ''));
$conn->query("UPDATE allocation_jobs SET progress_stage = '$stage' WHERE job_id = $job_id");
```

**Better:**
```php
$stmt = $conn->prepare("UPDATE allocation_jobs SET progress_stage = ? WHERE job_id = ?");
$stmt->bind_param('si', $stage, $job_id);
$stmt->execute();
$stmt->close();
```

**Files to Update:**
- `worker_allocation.php` (multiple queries)

---

### 5. Missing Index on `updated_at`
**Severity:** MEDIUM  
**Problem:** The stale job recovery query scans all jobs:
```sql
WHERE status = 'running' AND updated_at < DATE_SUB(NOW(), INTERVAL $minutes MINUTE)
```

**Add Index:**
```sql
CREATE INDEX idx_status_updated ON allocation_jobs(status, updated_at);
```

---

### 6. Missing Connection Validation
**Severity:** MEDIUM  
**Locations:** `worker_allocation.php`, `worker_launcher.php`  
**Problem:** No check if database connection is valid before executing queries.

**Add:**
```php
if (!$conn || $conn->connect_error) {
    Logger::error("Database connection failed: " . ($conn->connect_error ?? 'Unknown error'));
    exit(1);
}
```

---

### 7. No Timeout on Long-Running Operations
**Severity:** MEDIUM  
**Problem:** Database queries have no timeout. If MySQL is hanging, worker blocks forever.

**Suggestion:** Set connection timeout:
```php
$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
```

---

### 8. Missing Null Checks on Query Results
**Severity:** LOW  
**Locations:** `worker_launcher.php` line 48  
**Problem:**
```php
while ($row = $result->fetch_assoc()) {  // ❌ What if $result is null/false?
```

**Fix:**
```php
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $jobs[] = (int)$row['job_id'];
    }
}
```

---

## 🎯 SUGGESTIONS (Best Practices)

### 9. Add Retry Logic with Exponential Backoff
The worker should retry failed jobs instead of marking as failed immediately.

**Suggestion:**
```php
// Track retry count in allocation_jobs table
ALTER TABLE allocation_jobs ADD COLUMN retry_count INT DEFAULT 0;

// In processAllocationJob:
if ($failed && $job['retry_count'] < 3) {
    $newCount = $job['retry_count'] + 1;
    $conn->query("UPDATE allocation_jobs SET status = 'queued', retry_count = $newCount WHERE job_id = $job_id");
} else {
    // Mark as failed after 3 retries
    $conn->query("UPDATE allocation_jobs SET status = 'failed' WHERE job_id = $job_id");
}
```

---

### 10. Add Health Check Endpoint
**Benefit:** Monitor worker health without accessing database directly

**New API action:**
```php
case 'worker_health':
    handleWorkerHealth($conn);
    break;

function handleWorkerHealth($conn) {
    $result = $conn->query("SELECT COUNT(*) as queued FROM allocation_jobs WHERE status = 'queued'");
    $row = $result->fetch_assoc();
    $status = $row['queued'] > 0 ? 'busy' : 'idle';
    
    sendJsonResponse([
        'status' => 'ok',
        'worker_status' => $status,
        'queued_jobs' => (int)$row['queued'],
        'timestamp' => date('c')
    ]);
}
```

**Access:** `GET /api/admin_api.php?action=worker_health`

---

### 11. Add Dead Letter Queue
Jobs that fail after retries should be moveable to a dead letter queue for manual inspection.

**Suggestion:**
```sql
CREATE TABLE allocation_jobs_dlq LIKE allocation_jobs;

-- Move failed jobs to DLQ after analysis
ALTER TABLE allocation_jobs ADD COLUMN dlq_reason VARCHAR(255);
```

---

### 12. Add Graceful Shutdown Handler
Worker should complete current job before exiting if sent SIGTERM.

**Suggestion:**
```php
$shuttingDown = false;

pcntl_signal(SIGTERM, function() use (&$shuttingDown) {
    Logger::info("Worker received SIGTERM, graceful shutdown...");
    $shuttingDown = true;
});

// In main loop:
if ($shuttingDown && $job_completed) {
    exit(0);  // Exit cleanly after current job
}
```

---

### 13. Add Job Progress Metrics
Track timing for each stage for performance monitoring.

**Suggestion:**
```sql
ALTER TABLE allocation_jobs ADD COLUMN (
    stage_fetch_seconds INT,
    stage_score_seconds INT,
    stage_solve_seconds INT,
    stage_write_seconds INT
);

// In worker: record timing for each stage
$start = microtime(true);
// ... run allocation ...
$elapsed = (int)(microtime(true) - $start);
$conn->query("UPDATE allocation_jobs SET stage_solve_seconds = $elapsed WHERE job_id = $job_id");
```

---

### 14. Add Request Rate Limiting
Prevent one user from flooding the queue.

**Suggestion:**
```php
function checkQueueRateLimit($conn, $admin_id) {
    $result = $conn->query(
        "SELECT COUNT(*) as cnt FROM allocation_jobs 
         WHERE created_by_admin_id = $admin_id 
         AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) 
         AND status IN ('queued', 'running')"
    );
    $row = $result->fetch_assoc();
    
    if ((int)$row['cnt'] >= 5) {  // Max 5 jobs per hour
        return false;
    }
    return true;
}

// In queue_allocation():
if (!checkQueueRateLimit($conn, $admin_id)) {
    sendJsonResponse(['status' => 'error', 'message' => 'Rate limit exceeded (5 jobs/hour)'], 429);
}
```

---

### 15. Add Email Notifications
**Benefit:** Notify admins when allocations complete or fail.

**Suggestion:**
```php
function notifyAdminCompletion($conn, $job) {
    $admin_id = (int)$job['created_by_admin_id'];
    
    $admin = $conn->query("SELECT email FROM users WHERE user_id = $admin_id")->fetch_assoc();
    if (!$admin) return;
    
    $subject = "Allocation Complete: Job #{$job['job_id']}";
    $body = "Allocated {$job['allocated_students']} of {$job['total_students']} students.";
    
    // Use your email service
    mail($admin['email'], $subject, $body);
}
```

---

### 16. Add Web Dashboard for Monitoring
Track all past/current allocations and their results.

**Suggested Page:** `/admin/allocation_history.php`
- List of all allocation jobs (paginated)
- Status badges (queued/running/completed/failed)
- Timing statistics (duration, stages)
- Download results as CSV
- Retry buttons for failed jobs

---

### 17. Add Concurrent Worker Limit
Prevent too many workers from running simultaneously (system resource protection).

**Suggestion:**
```php
// worker_launcher.php
function countActiveWorkers() {
    if (DIRECTORY_SEPARATOR === '\\') {
        exec('tasklist /FI "IMAGENAME eq php.exe" | find /c /v ""', $output);
        return (int)($output[0] ?? 0);
    } else {
        exec("ps aux | grep worker_allocation.php | grep -v grep | wc -l", $output);
        return (int)($output[0] ?? 0);
    }
}

// In main loop:
if (countActiveWorkers() >= 3) {
    sleep($interval * 5);  // Back off if too many workers
    continue;
}
```

---

### 18. Add Job Cancellation Feature
**Benefit:** Allow admins to cancel stuck/unwanted jobs.

**Suggested API:**
```php
case 'cancel_job':
    handleCancelJob($conn);
    break;

function handleCancelJob($conn) {
    $job_id = (int)($_POST['job_id'] ?? 0);
    
    $conn->query("UPDATE allocation_jobs SET status = 'cancelled' WHERE job_id = $job_id");
    sendJsonResponse(['status' => 'success', 'message' => 'Job cancelled']);
}
```

---

### 19. Add Job Dependency Tracking
**Benefit:** Allow sequential allocations (e.g., "only allocate after students imported").

**Suggestion:**
```sql
ALTER TABLE allocation_jobs ADD COLUMN (
    depends_on_job_id INT,
    FOREIGN KEY (depends_on_job_id) REFERENCES allocation_jobs(job_id)
);
```

---

### 20. Improve Worker Launcher Logging
Add more visibility into what's happening.

**Suggestion:**
```php
// Log completed jobs
$completed = $conn->query(
    "SELECT COUNT(*) as cnt FROM allocation_jobs 
     WHERE status = 'completed' AND DATE(completed_at) = DATE(NOW())"
)->fetch_assoc();

Logger::info("Today's summary: {$completed['cnt']} allocations completed");
```

---

## 📊 Summary

| Category | Count | Priority |
|----------|-------|----------|
| Critical Issues | 3 | 🔴 MUST FIX |
| Important Issues | 5 | 🟠 SHOULD FIX |
| Best Practice Suggestions | 12 | 🟡 NICE TO HAVE |
| **Total** | **20** | |

---

## ✅ Recommended Action Plan

**Phase 1 (CRITICAL - Do Now):**
1. Add `updated_at` column to allocation_jobs table
2. Fix missing `--job-id` parameter in worker launcher
3. Add SQL prepared statements to worker_allocation.php

**Phase 2 (IMPORTANT - Before Presentation):**
4. Add connection validation
5. Add index on `updated_at`
6. Add null checks on query results
7. Add health check endpoint

**Phase 3 (NICE TO HAVE - Post-Presentation):**
8-20. Implement remaining best practices as time permits

---

## Files to Update

```
CRITICAL:
  - sql/20260501_allocation_jobs_queue.sql  (ADD updated_at column)
  - worker_launcher.php                      (FIX missing --job-id)
  - worker_allocation.php                    (Use prepared statements)

IMPORTANT:
  - worker_launcher.php                      (Add null checks, validation)
  - worker_allocation.php                    (Add connection checks)
  - api/admin_api.php                        (Add health endpoint)
```

---
