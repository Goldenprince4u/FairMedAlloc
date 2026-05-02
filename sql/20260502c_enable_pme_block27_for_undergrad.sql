-- Keep Prophet Moses Extension Hall Block 26 as foundation-only and open
-- Block 27 for the medium-priority undergraduate path.

UPDATE hostels
   SET is_foundation = CASE
       WHEN block_name = '26' THEN 1
       WHEN block_name = '27' THEN 0
       ELSE is_foundation
   END
 WHERE name = 'Prophet Moses Extension Hall'
   AND block_name IN ('26', '27');
