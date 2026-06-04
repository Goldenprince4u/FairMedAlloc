-- Migration: 20260604_audit_improvements.sql
-- ===================================================================
-- Add scoring_mode column to algorithm_audit_logs
-- When the XGBoost HTTP service is unavailable, the system falls back to
-- the local predict.py rule-based scorer. Without this column, audit logs
-- contain no indication of which model was used for a given allocation,
-- making post-hoc fairness audits unreliable.
-- ===================================================================

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'algorithm_audit_logs'
      AND COLUMN_NAME  = 'scoring_mode'
);

SET @sql = IF(@col_exists = 0,
    "ALTER TABLE algorithm_audit_logs
        ADD COLUMN scoring_mode ENUM('XGBoost','Rule-Based Fallback','Stored Score') DEFAULT NULL
        COMMENT 'Scoring model used for this allocation; NULL = pre-migration record'
        AFTER allocation_decision",
    'SELECT ''scoring_mode column already exists — skipping'' AS migration_note'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
