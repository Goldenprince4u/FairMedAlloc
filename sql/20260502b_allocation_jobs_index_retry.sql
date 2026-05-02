-- ============================================================================
-- Migration: 20260502b — Allocation Jobs: Performance Index + Retry Support
-- ============================================================================
-- Adds composite index for stale-job recovery query and retry_count column.
-- All statements use IF NOT EXISTS guards (safe to run multiple times).
-- ============================================================================

-- 1. Composite index used by resetStaleRunningJobs() in the worker
--    Query pattern: WHERE status = 'running' AND updated_at < DATE_SUB(...)
ALTER TABLE allocation_jobs
    ADD INDEX IF NOT EXISTS idx_status_updated (status, updated_at);

-- 2. retry_count column for exponential-backoff retry logic
ALTER TABLE allocation_jobs
    ADD COLUMN IF NOT EXISTS retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0;

-- 3. max_retries column (configurable per job type, default 3)
ALTER TABLE allocation_jobs
    ADD COLUMN IF NOT EXISTS max_retries TINYINT UNSIGNED NOT NULL DEFAULT 3;

-- Verify indexes
SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'allocation_jobs'
ORDER BY INDEX_NAME, SEQ_IN_INDEX;
