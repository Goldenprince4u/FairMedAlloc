"""
fix_female_blocks.py
Re-labels all female hostel block_names to restart from 1 independently:
  Queen Esther Hall (hostel_ids 34-51):         block_name '1'-'18'  (already correct)
  Queen Esther Extension Hall (hostel_ids 52-68): block_name '1'-'17'  (was '19'-'35')
  Deborah Hall (hostel_ids 69-73): block_name '1'-'5'  (was '36'-'40')

Then runs setup.sql against XAMPP MySQL.
"""

import re

SETUP_FILE = r'c:\xampp\htdocs\FairMedAlloc\setup.sql'

content = open(SETUP_FILE, 'r', encoding='utf-8').read()

# ── Fix QE Extension Hall block_names: '19'->'1', '20'->'2', ..., '35'->'17' ──
# Pattern in hostel INSERT: ('Queen Esther Extension Hall', '19', ... )
for old_blk in range(19, 36):
    new_blk = old_blk - 18   # 19->1, 20->2, ..., 35->17
    content = content.replace(
        f"'Queen Esther Extension Hall', '{old_blk}',",
        f"'Queen Esther Extension Hall', '{new_blk}',"
    )

# ── Fix QE Engineering Hall block_names: '36'->'1', ..., '40'->'5' ──
for old_blk in range(36, 41):
    new_blk = old_blk - 35   # 36->1, ..., 40->5
    content = content.replace(
        f"'Deborah Hall', '{old_blk}',",
        f"'Deborah Hall', '{new_blk}',"
    )

open(SETUP_FILE, 'w', encoding='utf-8').write(content)
print("Female block_names updated:")
print("  QE Extension Hall:    blocks 1-17 (was 19-35)")
print("  QE Engineering Hall:  blocks 1-5  (was 36-40)")
print("  QE Hall:              blocks 1-18 (unchanged)")
