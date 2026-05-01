-- ============================================================
-- Migration: Add is_test column to rooms table
-- Purpose: Track which rooms were created for testing so they
--          can be easily identified and cleaned up without
--          relying on room_number patterns.
-- ============================================================

ALTER TABLE rooms 
ADD COLUMN IF NOT EXISTS is_test BOOLEAN DEFAULT 0 AFTER is_reserved;

-- Mark any existing ghost rooms that were previously created
-- for testing (room_numbers > 24 are typically test data)
UPDATE rooms r
JOIN hostels h ON r.hostel_id = h.hostel_id
SET r.is_test = 1
WHERE (
    (h.name = 'Queen Esther Hall' AND h.block_name = '1' AND CAST(r.room_number AS UNSIGNED) > 24)
    OR (h.name = 'Prophet Moses Hall' AND h.block_name = '1' AND CAST(r.room_number AS UNSIGNED) > 24)
);

-- Verify: Show which rooms are marked as test data
SELECT h.name, h.block_name, COUNT(*) as test_room_count
FROM rooms r
JOIN hostels h ON r.hostel_id = h.hostel_id
WHERE r.is_test = 1
GROUP BY h.hostel_id, h.name, h.block_name;
