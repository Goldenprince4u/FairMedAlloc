-- Align solver defaults and floor metadata for the April 30, 2026
-- faculty-proximal / ground-floor accessibility policy update.

INSERT INTO settings (setting_key, setting_value)
VALUES
    ('allocation_algorithm_version', 'allocation_engine_v3'),
    ('allocation_solver_backend', 'ortools')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

UPDATE hostels
SET has_elevator = 0
WHERE name IN ('Joshua Hall', 'Deborah Hall');

UPDATE rooms r
JOIN hostels h ON h.hostel_id = r.hostel_id
SET r.floor_level = CASE
    WHEN CAST(r.room_number AS UNSIGNED) <= 27 THEN 0
    ELSE 1
END
WHERE h.name = 'Joshua Hall';

UPDATE rooms r
JOIN hostels h ON h.hostel_id = r.hostel_id
SET r.floor_level = CASE
    WHEN CAST(r.room_number AS UNSIGNED) <= 14 THEN 0
    ELSE 1
END
WHERE h.name = 'Deborah Hall';
