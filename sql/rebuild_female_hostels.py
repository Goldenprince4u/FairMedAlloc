import os
import re

FILES = [
    r'c:\xampp\htdocs\FairMedAlloc\setup.sql',
    r'c:\xampp\htdocs\FairMedAlloc\master_document.txt'
]

def generate_hostels_sql():
    sql = []
    sql.append("-- Queen Esther Hall (Female, blocks 1-32) hostel_ids 34-65")
    hostel_id = 34
    for block in range(1, 33):
        sql.append(f"({hostel_id}, 'Queen Esther Hall', '{block}', 'Female', NULL, FALSE, FALSE, FALSE, 80),")
        hostel_id += 1

    sql.append("-- Queen Esther Extension Hall (Female, blocks 1-5) hostel_ids 66-70")
    for block in range(1, 6):
        sql.append(f"({hostel_id}, 'Queen Esther Extension Hall', '{block}', 'Female', NULL, FALSE, FALSE, FALSE, 80),")
        hostel_id += 1

    sql.append("-- Deborah Hall (Female, blocks 1-5) hostel_ids 71-75")
    for block in range(1, 6):
        if block == 5:
            sql.append(f"({hostel_id}, 'Deborah Hall', '{block}', 'Female', 2, TRUE, FALSE, FALSE, 116);")
        else:
            sql.append(f"({hostel_id}, 'Deborah Hall', '{block}', 'Female', 2, TRUE, FALSE, FALSE, 116),")
        hostel_id += 1
    return "\n".join(sql)


def generate_rooms_sql():
    sql = []
    
    # Queen Esther Hall (34-65) & Extension (66-70)
    # Both have 24 rooms. Rooms 1, 24 = 4 beds. Rooms 12, 13 = 6 beds. Others = 3 beds.
    def get_qe_room_inserts(h_id):
        inserts = []
        for r in range(1, 25):
            if r in (1, 24):
                inserts.append(f"({h_id}, '{r}', 4, 1, 'LB,UB,LB,UB')")
            elif r in (12, 13):
                inserts.append(f"({h_id}, '{r}', 6, 1, 'LB,UB,LB,UB,LB,UB')")
            else:
                inserts.append(f"({h_id}, '{r}', 3, 0, 'SB,UB,LB')")
        return inserts

    sql.append("\n-- Queen Esther Hall (Female, blocks 1-32)")
    for h_id in range(34, 66):
        inserts = get_qe_room_inserts(h_id)
        sql.append("INSERT INTO rooms (hostel_id, room_number, capacity, is_corner, bed_config) VALUES")
        sql.append(",\n".join(inserts) + ";")

    sql.append("\n-- Queen Esther Extension Hall (Female, blocks 1-5)")
    for h_id in range(66, 71):
        inserts = get_qe_room_inserts(h_id)
        sql.append("INSERT INTO rooms (hostel_id, room_number, capacity, is_corner, bed_config) VALUES")
        sql.append(",\n".join(inserts) + ";")

    # Deborah Hall (71-75)
    # 28 rooms. Room 1 = 8 beds. Others = 4 beds.
    def get_qe_eng_room_inserts(h_id):
        inserts = []
        for r in range(1, 29):
            if r == 1:
                inserts.append(f"({h_id}, '{r}', 8, 1, 'LB,UB,LB,UB,LB,UB,LB,UB')")
            else:
                inserts.append(f"({h_id}, '{r}', 4, 0, 'LB,UB,LB,UB')")
        return inserts

    sql.append("\n-- Deborah Hall (Female, blocks 1-5)")
    for h_id in range(71, 76):
        inserts = get_qe_eng_room_inserts(h_id)
        sql.append("INSERT INTO rooms (hostel_id, room_number, capacity, is_corner, bed_config) VALUES")
        sql.append(",\n".join(inserts) + ";")

    return "\n".join(sql)

def process_file(filepath):
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()

    # 1. Replace Hostels section
    # Find the start of Queen Esther Hall in hostels
    hostel_start_idx = content.find("-- Queen Esther Hall (Female, blocks 1-")
    if hostel_start_idx == -1:
        print(f"Could not find female hostels start in {filepath}")
        return

    # Find the end of hostels INSERT (the semicolon before SEED: ROOMS)
    seed_rooms_idx = content.find("-- SEED: ROOMS", hostel_start_idx)
    if seed_rooms_idx == -1:
        print("Could not find SEED: ROOMS")
        return
        
    # The end of the hostels insert statement is the last semicolon before SEED: ROOMS
    hostels_end_idx = content.rfind(";", hostel_start_idx, seed_rooms_idx) + 1

    new_hostels_sql = generate_hostels_sql()
    content = content[:hostel_start_idx] + new_hostels_sql + "\n\n" + content[hostels_end_idx:]

    # 2. Replace Rooms section
    # Find the start of female rooms. We can look for `INSERT INTO rooms (hostel_id` where hostel_id is 34.
    # A reliable way is to find the first insert for hostel 34.
    rooms_start_idx = content.find("(34, '1'")
    if rooms_start_idx == -1:
         rooms_start_idx = content.find("INSERT INTO rooms", content.find("-- Queen Esther Hall", seed_rooms_idx))
    else:
         rooms_start_idx = content.rfind("INSERT INTO rooms", seed_rooms_idx, rooms_start_idx)

    if rooms_start_idx == -1:
        print(f"Could not find start of female rooms in {filepath}")
        # fallback: find where male rooms end. Male rooms end at hostel 33 room 54 or similar.
        end_of_male = content.find("(33, '54'")
        if end_of_male != -1:
            rooms_start_idx = content.find("INSERT INTO rooms", end_of_male)
    
    if rooms_start_idx == -1:
        print("Still couldn't find rooms_start_idx")
        return

    # Find the end of all room inserts. The end of the rooms section is the last semicolon before allocations/audit logs or end of file.
    # Usually, `INSERT INTO rooms` is the last section in the file.
    # We can just replace from rooms_start_idx to the end of the file.
    new_rooms_sql = generate_rooms_sql()
    content = content[:rooms_start_idx] + new_rooms_sql + "\n"

    with open(filepath, 'w', encoding='utf-8') as f:
        f.write(content)
    print(f"Successfully processed {filepath}")

for f in FILES:
    process_file(f)

