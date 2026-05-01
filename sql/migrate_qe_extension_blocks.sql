-- =============================================================================
-- Migration: Queen Esther Hall & Extension Hall Block Renumbering
-- =============================================================================
-- Queen Esther Hall:          blocks 1–32 (existing) → extend to 1–37
-- Queen Esther Extension Hall: blocks 1–5  (existing) → renumber to 38–42
--   Clinic-proximal blocks: 38 and 39 (first two of the extension)
--
-- Run ONCE against the live fairmedalloc database.
-- Safe to re-run: the WHERE clauses scope each statement precisely.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------
-- Step 1: Renumber existing Queen Esther Extension Hall blocks 1–5 → 38–42
-- We go in reverse order to avoid a unique-key collision during the update
-- (block_name 5 → 42 first, then 4 → 41, ... 1 → 38).
-- -----------------------------------------------------------------------
UPDATE hostels
SET block_name = '42'
WHERE name = 'Queen Esther Extension Hall' AND block_name = '5';

UPDATE hostels
SET block_name = '41'
WHERE name = 'Queen Esther Extension Hall' AND block_name = '4';

UPDATE hostels
SET block_name = '40'
WHERE name = 'Queen Esther Extension Hall' AND block_name = '3';

UPDATE hostels
SET block_name = '39'
WHERE name = 'Queen Esther Extension Hall' AND block_name = '2';

UPDATE hostels
SET block_name = '38'
WHERE name = 'Queen Esther Extension Hall' AND block_name = '1';

-- -----------------------------------------------------------------------
-- Step 2: Verify the renumbering before the INSERT of new QE Hall blocks.
-- (This SELECT is informational — the transaction will still proceed.)
-- -----------------------------------------------------------------------
SELECT name, block_name
FROM hostels
WHERE name = 'Queen Esther Extension Hall'
ORDER BY CAST(block_name AS UNSIGNED);

-- -----------------------------------------------------------------------
-- Step 3: Extend Queen Esther Hall from block 32 to block 37.
--         Each new block is identical to the existing QE Hall blocks
--         (24 rooms, same bed configs).
-- -----------------------------------------------------------------------

-- We insert one hostel row per new block; rooms are inserted separately
-- via the application seed logic or a follow-up script.
-- For now we insert the hostel skeleton so allocation can reference blocks 33–37.

INSERT INTO hostels
    (name, block_name, gender_allowed, proximal_faculty_id,
     is_proximal, is_postgrad, is_foundation, total_capacity)
SELECT
    'Queen Esther Hall',
    new_block,
    'Female',
    NULL,
    0, 0, 0,
    80
FROM (
    SELECT '33' AS new_block UNION ALL
    SELECT '34'              UNION ALL
    SELECT '35'              UNION ALL
    SELECT '36'              UNION ALL
    SELECT '37'
) AS new_blocks
WHERE NOT EXISTS (
    SELECT 1 FROM hostels
    WHERE name = 'Queen Esther Hall' AND block_name = new_blocks.new_block
);

-- -----------------------------------------------------------------------
-- Step 4: Insert rooms for each new QE Hall block (33–37).
--         Pattern mirrors existing QE Hall blocks:
--           rooms 1,24      → capacity 4, corner, LB,UB,LB,UB
--           rooms 12,13     → capacity 6, corner, LB,UB,LB,UB,LB,UB
--           all others      → capacity 3, not corner, SB,UB,LB
-- -----------------------------------------------------------------------
INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, is_reserved, bed_config)
SELECT
    h.hostel_id,
    r.room_number,
    0              AS floor_level,
    CASE
        WHEN r.room_number IN (1, 24)    THEN 4
        WHEN r.room_number IN (12, 13)   THEN 6
        ELSE 3
    END            AS capacity,
    CASE
        WHEN r.room_number IN (1, 12, 13, 24) THEN 1
        ELSE 0
    END            AS is_corner,
    0              AS is_reserved,
    CASE
        WHEN r.room_number IN (1, 24)    THEN 'LB,UB,LB,UB'
        WHEN r.room_number IN (12, 13)   THEN 'LB,UB,LB,UB,LB,UB'
        ELSE 'SB,UB,LB'
    END            AS bed_config
FROM hostels h
JOIN (
    SELECT 1 AS room_number UNION ALL SELECT 2  UNION ALL SELECT 3  UNION ALL
    SELECT 4  UNION ALL SELECT 5  UNION ALL SELECT 6  UNION ALL SELECT 7  UNION ALL
    SELECT 8  UNION ALL SELECT 9  UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL
    SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15 UNION ALL
    SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19 UNION ALL
    SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL
    SELECT 24
) r ON 1=1
WHERE h.name = 'Queen Esther Hall'
  AND h.block_name IN ('33','34','35','36','37')
  AND NOT EXISTS (
      SELECT 1 FROM rooms
      WHERE hostel_id = h.hostel_id AND room_number = r.room_number
  );

COMMIT;

-- -----------------------------------------------------------------------
-- Verification queries (run after the migration)
-- -----------------------------------------------------------------------
SELECT name, block_name, total_capacity
FROM hostels
WHERE name IN ('Queen Esther Hall','Queen Esther Extension Hall')
ORDER BY name, CAST(block_name AS UNSIGNED);

SELECT h.name, h.block_name, COUNT(r.room_id) AS room_count
FROM hostels h
LEFT JOIN rooms r ON r.hostel_id = h.hostel_id
WHERE h.name IN ('Queen Esther Hall','Queen Esther Extension Hall')
GROUP BY h.hostel_id
ORDER BY h.name, CAST(h.block_name AS UNSIGNED);
