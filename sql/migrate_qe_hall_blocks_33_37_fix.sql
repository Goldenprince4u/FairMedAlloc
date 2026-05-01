-- =============================================================================
-- Migration: Correct Queen Esther Hall Blocks 33-37 Room Configuration
-- =============================================================================
-- The previous migration (migrate_qe_extension_blocks.sql) inserted blocks
-- 33-37 for Queen Esther Hall using the standard 24-room layout.
-- The correct layout is 28 rooms per block:
--   Room 1   → 8 beds (LB,UB,LB,UB,LB,UB,LB,UB), corner
--   Rooms 2-28 → 4 beds (LB,UB,LB,UB), standard
-- Capacity per block: 8 + (27 × 4) = 116
--
-- Run AFTER migrate_qe_extension_blocks.sql if that has already been applied.
-- Safe to re-run: DELETE scopes to the specific hostel rows.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------
-- Step 1: Remove the incorrectly inserted rooms for QE Hall blocks 33-37.
-- -----------------------------------------------------------------------
DELETE r
FROM rooms r
JOIN hostels h ON r.hostel_id = h.hostel_id
WHERE h.name = 'Queen Esther Hall'
  AND h.block_name IN ('33', '34', '35', '36', '37');

-- -----------------------------------------------------------------------
-- Step 2: Correct total_capacity on the hostel rows.
-- -----------------------------------------------------------------------
UPDATE hostels
SET total_capacity = 116
WHERE name = 'Queen Esther Hall'
  AND block_name IN ('33', '34', '35', '36', '37');

-- -----------------------------------------------------------------------
-- Step 3: Insert the correct 28-room layout for each of blocks 33-37.
--   Room 1   → 8 beds, corner, LB,UB,LB,UB,LB,UB,LB,UB, floor 0
--   Rooms 2-28 → 4 beds, standard, LB,UB,LB,UB, floor 0
-- -----------------------------------------------------------------------
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, is_reserved, bed_config)
SELECT
    h.hostel_id,
    r.room_number,
    0 AS floor_level,
    CASE WHEN r.room_number = 1 THEN 8 ELSE 4 END AS capacity,
    CASE WHEN r.room_number = 1 THEN 1 ELSE 0 END AS is_corner,
    0 AS is_reserved,
    CASE WHEN r.room_number = 1 THEN 'LB,UB,LB,UB,LB,UB,LB,UB' ELSE 'LB,UB,LB,UB' END AS bed_config
FROM hostels h
JOIN (
    SELECT 1  AS room_number UNION ALL SELECT 2  UNION ALL SELECT 3  UNION ALL
    SELECT 4  UNION ALL SELECT 5  UNION ALL SELECT 6  UNION ALL SELECT 7  UNION ALL
    SELECT 8  UNION ALL SELECT 9  UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL
    SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15 UNION ALL
    SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19 UNION ALL
    SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL
    SELECT 24 UNION ALL SELECT 25 UNION ALL SELECT 26 UNION ALL SELECT 27 UNION ALL
    SELECT 28
) r ON 1 = 1
WHERE h.name = 'Queen Esther Hall'
  AND h.block_name IN ('33', '34', '35', '36', '37');

COMMIT;

-- -----------------------------------------------------------------------
-- Verification
-- -----------------------------------------------------------------------
SELECT
    h.name,
    h.block_name,
    h.total_capacity,
    COUNT(r.room_id)   AS room_count,
    SUM(r.capacity)    AS total_bedspaces
FROM hostels h
LEFT JOIN rooms r ON r.hostel_id = h.hostel_id
WHERE h.name = 'Queen Esther Hall'
  AND h.block_name IN ('33','34','35','36','37')
GROUP BY h.hostel_id
ORDER BY CAST(h.block_name AS UNSIGNED);
-- Expected: room_count=28, total_bedspaces=116 for each block
