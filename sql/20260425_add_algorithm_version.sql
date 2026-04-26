ALTER TABLE allocations
    ADD COLUMN IF NOT EXISTS algorithm_version VARCHAR(64) DEFAULT NULL AFTER allocation_method;

UPDATE allocations
SET algorithm_version = CASE
    WHEN allocation_method = 'manual' THEN 'manual_override_legacy'
    ELSE 'allocation_engine_legacy'
END
WHERE algorithm_version IS NULL;
