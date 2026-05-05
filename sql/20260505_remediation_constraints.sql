-- =============================================================================
-- Migration: 20260505_remediation_constraints.sql
-- FairMedAlloc Phase 2 Remediation — Database Integrity Constraints
-- =============================================================================

-- 1. Add a computed (GENERATED) column to hostels to permanently flag
--    which hostel blocks are designated as clinic-proximal.
--    This is a read-only column — MySQL computes it from the hostel name and block.
--    It does NOT affect any existing data. Adding a STORED generated column is safe.

ALTER TABLE hostels
ADD COLUMN IF NOT EXISTS is_clinic_proximity TINYINT(1)
GENERATED ALWAYS AS (
    CASE
        WHEN name = 'Prophet Moses Hall'          AND block_name IN ('1','2')       AND gender_allowed = 'Male'   THEN 1
        WHEN name = 'Queen Esther Extension Hall' AND block_name IN ('38','39')     AND gender_allowed = 'Female' THEN 1
        ELSE 0
    END
) STORED COMMENT 'Auto-computed: 1 if this block is a designated clinic-proximal block';

-- 2. Add an audit comment to clarify severity encoding used in algorithm_audit_logs
--    PHP maps: Low=1, Medium=2, High=3, Critical=4
--    Python maps: Low=1, Medium=2, High=3 (no Critical)
ALTER TABLE algorithm_audit_logs
MODIFY COLUMN input_severity TINYINT(1) COMMENT '1=Low, 2=Medium, 3=High, 4=Critical (PHP mapping; Python max is 3)';

-- 3. Add a numeric constraint check on block_name to prevent non-numeric block names
--    from silently breaking clinic-proximity lookups in the future.
--    NOTE: MySQL 8.0.16+ supports CHECK constraints. Skip if on MariaDB < 10.2 or MySQL < 8.0.16.
-- ALTER TABLE hostels
-- ADD CONSTRAINT chk_block_name_numeric
-- CHECK (block_name REGEXP '^[0-9]+$');
-- (Commented out — uncomment if your MySQL version supports it)

-- =============================================================================
-- Verification: Run after applying migration
-- =============================================================================

-- Verify the computed column is working
-- SELECT hostel_id, name, block_name, gender_allowed, is_clinic_proximity
-- FROM hostels
-- WHERE is_clinic_proximity = 1;
-- Expected: Prophet Moses Hall Blocks 1,2 and Queen Esther Extension Hall Blocks 38,39
