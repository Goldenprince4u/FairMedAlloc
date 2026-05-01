-- =============================================================================
-- Cleanup: Remove test allocations + ghost rooms from Prophet Moses Hall Block 1
-- Ghost rooms have room_number > 24 and bed_config = NULL (test/import data).
-- =============================================================================

START TRANSACTION;

-- Step 1: Delete allocations referencing the ghost rooms
DELETE a
FROM allocations a
JOIN rooms r ON a.room_id = r.room_id
JOIN hostels h ON r.hostel_id = h.hostel_id
WHERE h.name = 'Prophet Moses Hall'
  AND h.block_name = '1'
  AND r.room_number > 24;

-- Step 2: Delete the ghost rooms (FK constraint now cleared)
DELETE r
FROM rooms r
JOIN hostels h ON r.hostel_id = h.hostel_id
WHERE h.name = 'Prophet Moses Hall'
  AND h.block_name = '1'
  AND r.room_number > 24;

COMMIT;

-- Verify: Block 1 should now have exactly 24 rooms, 76 bedspaces
SELECT
    h.name,
    h.block_name,
    COUNT(r.room_id) AS room_count,
    SUM(r.capacity)  AS bedspaces
FROM hostels h
JOIN rooms r ON r.hostel_id = h.hostel_id
WHERE h.name = 'Prophet Moses Hall' AND h.block_name = '1'
GROUP BY h.hostel_id;
-- Expected: room_count=24, bedspaces=76
