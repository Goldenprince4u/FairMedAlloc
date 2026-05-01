-- ============================================================================
-- Migration: 20260501 — Queen Esther Hall Restructure + Ghost Room Cleanup
-- ============================================================================
-- Consolidates four individual migrations applied on 2026-05-01:
--   • migrate_qe_extension_blocks.sql
--   • migrate_qe_hall_blocks_33_37_fix.sql
--   • migrate_qe_cleanup.sql
--   • migrate_pm_hall_block1_cleanup.sql
--
-- ALL operations are IDEMPOTENT — safe to re-run on an already-migrated DB.
-- Run AFTER: 20260430_accessible_ground_floor_policy.sql
-- ============================================================================

START TRANSACTION;

-- ── Part 1: Renumber QE Extension Hall blocks 1–5 → 38–42 ──────────────────
-- Run in reverse order to avoid transient unique-key collisions.

UPDATE hostels SET block_name = '42' WHERE name = 'Queen Esther Extension Hall' AND block_name = '5';
UPDATE hostels SET block_name = '41' WHERE name = 'Queen Esther Extension Hall' AND block_name = '4';
UPDATE hostels SET block_name = '40' WHERE name = 'Queen Esther Extension Hall' AND block_name = '3';
UPDATE hostels SET block_name = '39' WHERE name = 'Queen Esther Extension Hall' AND block_name = '2';
UPDATE hostels SET block_name = '38' WHERE name = 'Queen Esther Extension Hall' AND block_name = '1';

-- ── Part 2: Extend QE Hall skeleton from block 32 to block 37 ──────────────
-- WHERE NOT EXISTS makes this safe to run if blocks already exist.

INSERT INTO hostels (name, block_name, gender_allowed, proximal_faculty_id, is_proximal, is_postgrad, is_foundation, total_capacity)
SELECT 'Queen Esther Hall', new_block, 'Female', NULL, 0, 0, 0, 116
FROM (
    SELECT '33' AS new_block UNION ALL SELECT '34' UNION ALL
    SELECT '35' UNION ALL SELECT '36' UNION ALL SELECT '37'
) t
WHERE NOT EXISTS (
    SELECT 1 FROM hostels WHERE name = 'Queen Esther Hall' AND block_name = t.new_block
);

-- ── Part 3: Correct QE Hall blocks 33–37 to 28-room, 116-bed layout ────────
-- Remove any previously inserted rooms (standard 24-room pattern) and
-- replace with the correct 28-room pattern: Room 1 = 8 beds, others = 4 beds.

DELETE r FROM rooms r
JOIN hostels h ON r.hostel_id = h.hostel_id
WHERE h.name = 'Queen Esther Hall'
  AND h.block_name IN ('33','34','35','36','37');

-- Re-set total_capacity in case the skeleton row had the wrong value.
UPDATE hostels SET total_capacity = 116
WHERE name = 'Queen Esther Hall' AND block_name IN ('33','34','35','36','37');

INSERT INTO rooms (hostel_id, room_number, floor_level, capacity, is_corner, is_reserved, bed_config)
SELECT
    h.hostel_id, r.room_number, 0,
    CASE WHEN r.room_number = 1 THEN 8 ELSE 4 END,
    CASE WHEN r.room_number = 1 THEN 1 ELSE 0 END,
    0,
    CASE WHEN r.room_number = 1 THEN 'LB,UB,LB,UB,LB,UB,LB,UB' ELSE 'LB,UB,LB,UB' END
FROM hostels h
JOIN (
    SELECT 1 AS room_number UNION ALL SELECT 2  UNION ALL SELECT 3  UNION ALL
    SELECT 4  UNION ALL SELECT 5  UNION ALL SELECT 6  UNION ALL SELECT 7  UNION ALL
    SELECT 8  UNION ALL SELECT 9  UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL
    SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15 UNION ALL
    SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19 UNION ALL
    SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL
    SELECT 24 UNION ALL SELECT 25 UNION ALL SELECT 26 UNION ALL SELECT 27 UNION ALL
    SELECT 28
) r ON 1 = 1
WHERE h.name = 'Queen Esther Hall'
  AND h.block_name IN ('33','34','35','36','37')
  AND NOT EXISTS (
      SELECT 1 FROM rooms WHERE hostel_id = h.hostel_id AND room_number = r.room_number
  );

-- ── Part 4: Ghost room cleanup — QE Hall Block 1 ───────────────────────────
-- Removes test allocations attached to ghost rooms (room_number > 24),
-- then removes the ghost rooms themselves.

DELETE a FROM allocations a
JOIN rooms r ON a.room_id = r.room_id
JOIN hostels h ON r.hostel_id = h.hostel_id
WHERE h.name = 'Queen Esther Hall' AND h.block_name = '1' AND r.room_number > 24;

DELETE r FROM rooms r
JOIN hostels h ON r.hostel_id = h.hostel_id
WHERE h.name = 'Queen Esther Hall' AND h.block_name = '1' AND r.room_number > 24;

-- ── Part 5: Ghost room cleanup — Prophet Moses Hall Block 1 ────────────────

DELETE a FROM allocations a
JOIN rooms r ON a.room_id = r.room_id
JOIN hostels h ON r.hostel_id = h.hostel_id
WHERE h.name = 'Prophet Moses Hall' AND h.block_name = '1' AND r.room_number > 24;

DELETE r FROM rooms r
JOIN hostels h ON r.hostel_id = h.hostel_id
WHERE h.name = 'Prophet Moses Hall' AND h.block_name = '1' AND r.room_number > 24;

COMMIT;

-- ── Verification ────────────────────────────────────────────────────────────
SELECT h.name, h.block_name, COUNT(r.room_id) AS rooms, SUM(r.capacity) AS beds
FROM hostels h
LEFT JOIN rooms r ON r.hostel_id = h.hostel_id
WHERE h.name IN ('Queen Esther Hall', 'Queen Esther Extension Hall', 'Prophet Moses Hall')
  AND h.block_name IN ('1','33','34','35','36','37','38','39','40','41','42')
GROUP BY h.hostel_id
ORDER BY h.name, CAST(h.block_name AS UNSIGNED);
-- Expected:
--   QE Extension  38-42 : 24 rooms, 80 beds each
--   QE Hall        1    : 24 rooms, 80 beds
--   QE Hall        33-37: 28 rooms, 116 beds each
--   PM Hall        1    : 24 rooms, 76 beds
