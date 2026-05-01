-- =============================================================================
-- Cleanup: Remove test allocations + ghost rooms from QE Hall Block 1
-- These are generated test data (room_numbers 25-161, bed_config=NULL).
-- =============================================================================

START TRANSACTION;

-- Step 1: Delete allocations referencing the ghost rooms
DELETE a
FROM allocations a
JOIN rooms r ON a.room_id = r.room_id
JOIN hostels h ON r.hostel_id = h.hostel_id
WHERE h.name = 'Queen Esther Hall'
  AND h.block_name = '1'
  AND r.room_number > 24;

-- Step 2: Now delete the ghost rooms (FK constraint cleared)
DELETE r
FROM rooms r
JOIN hostels h ON r.hostel_id = h.hostel_id
WHERE h.name = 'Queen Esther Hall'
  AND h.block_name = '1'
  AND r.room_number > 24;

COMMIT;

-- Verify: Block 1 should now have exactly 24 rooms
SELECT COUNT(*) AS room_count, SUM(capacity) AS bedspaces
FROM rooms r
JOIN hostels h ON r.hostel_id = h.hostel_id
WHERE h.name = 'Queen Esther Hall' AND h.block_name = '1';
