-- ============================================================================
-- Migration: 20260501 — Add Missing Performance Indexes
-- ============================================================================
-- The allocation engine's core query joins hostels on gender_allowed and
-- filters on is_postgrad/is_foundation for every allocation run.
-- These indexes eliminate full-table scans on the hostels table.
--
-- ALL statements use IF NOT EXISTS or are safe no-ops if indexes exist.
-- ============================================================================

-- Index: allocation engine gender + exclusion filter
-- Used by: AllocationEngine.php student-room candidate query
-- Used by: allocate.py CSV export of available rooms
ALTER TABLE hostels
    ADD INDEX IF NOT EXISTS idx_hostels_gender (gender_allowed),
    ADD INDEX IF NOT EXISTS idx_hostels_name (name),
    ADD INDEX IF NOT EXISTS idx_hostels_alloc_filter (gender_allowed, is_postgrad, is_foundation);

-- Index: rooms floor_level — used for Joshua/Deborah mobility-ground-floor filter
ALTER TABLE rooms
    ADD INDEX IF NOT EXISTS idx_rooms_floor (floor_level),
    ADD INDEX IF NOT EXISTS idx_rooms_reserved (is_reserved);

-- Index: algorithm_audit_logs — makes per-student audit lookups faster
ALTER TABLE algorithm_audit_logs
    ADD INDEX IF NOT EXISTS idx_audit_student (student_id),
    ADD INDEX IF NOT EXISTS idx_audit_ts (run_timestamp);

-- Index: notifications — unread-count badge query runs on every page load
ALTER TABLE notifications
    ADD INDEX IF NOT EXISTS idx_notif_user_read (user_id, is_read);

-- Index: admin_audit_logs — admin report queries filter by admin_id
ALTER TABLE admin_audit_logs
    ADD INDEX IF NOT EXISTS idx_admin_log_admin (admin_id),
    ADD INDEX IF NOT EXISTS idx_admin_log_ts (created_at);

-- Verify
SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('hostels','rooms','notifications','algorithm_audit_logs','admin_audit_logs')
ORDER BY TABLE_NAME, INDEX_NAME;
