import csv
import logging
import random
import sys

from ortools.sat.python import cp_model

logging.basicConfig(level=logging.ERROR)

# ---------------------------------------------------------------------------
# Faculty → Hostel proximity mappings
# ---------------------------------------------------------------------------
MALE_FACULTY_PROXIMAL = {
    'Faculty of Humanities':                       ['Prophet Moses Hall', 'Prophet Moses Extension Hall'],
    'Faculty of Management Sciences':              ['Prophet Moses Hall', 'Prophet Moses Extension Hall'],
    'Faculty of Natural Sciences':                 ['Prophet Moses Hall', 'Prophet Moses Extension Hall'],
    'Faculty of Social Sciences':                  ['Prophet Moses Hall', 'Prophet Moses Extension Hall'],
    'Faculty of Computing and Digital Technology': ['Prophet Moses Hall', 'Prophet Moses Extension Hall'],
    'Faculty of Engineering':                      ['Joshua Hall'],
    'Faculty of Law':                              ['Joshua Hall'],
    'Faculty of Built Environment Studies':        ['Joshua Hall'],
    'Faculty of Basic Medical Sciences':           ['Joshua Hall'],
}

FEMALE_FACULTY_PROXIMAL = {
    'Faculty of Humanities':                       ['Queen Esther Hall', 'Queen Esther Extension Hall'],
    'Faculty of Management Sciences':              ['Queen Esther Hall', 'Queen Esther Extension Hall'],
    'Faculty of Natural Sciences':                 ['Queen Esther Hall', 'Queen Esther Extension Hall'],
    'Faculty of Social Sciences':                  ['Queen Esther Hall', 'Queen Esther Extension Hall'],
    'Faculty of Computing and Digital Technology': ['Queen Esther Hall', 'Queen Esther Extension Hall'],
    'Faculty of Engineering':                      ['Deborah Hall'],
    'Faculty of Law':                              ['Deborah Hall'],
    'Faculty of Built Environment Studies':        ['Deborah Hall'],
    'Faculty of Basic Medical Sciences':           ['Deborah Hall', 'Queen Esther Hall'],
}

CLINIC_PROXIMAL_MALE_HOSTEL   = 'Prophet Moses Hall'
CLINIC_PROXIMAL_FEMALE_HOSTEL = 'Queen Esther Extension Hall'
MOBILITY_PRIORITY_STATUSES    = {'Wheelchair User', 'Crutches/Walker', 'Artificial Limb'}
MEDIUM_MALE_ACCESS_BLOCK      = '27'
GROUND_FLOOR_MEDIUM_HOSTELS   = {'Joshua Hall', 'Deborah Hall'}


# ---------------------------------------------------------------------------
# CSV helpers
# ---------------------------------------------------------------------------

def parse_csv(filepath):
    data = []
    with open(filepath, 'r', newline='') as f:
        reader = csv.reader(f)
        headers = next(reader)
        for row in reader:
            data.append(dict(zip(headers, row)))
    return data


# ---------------------------------------------------------------------------
# Room classification helpers
# ---------------------------------------------------------------------------

def block_number(room):
    try:
        return int(str(room.get('block_name', '')).strip())
    except (TypeError, ValueError):
        return 10_000


def is_primary_male_high_room(room):
    """Prophet Moses Hall Block 1 — hard-reserved for High-urgency males only."""
    return (
        room.get('hostel_name', '') == 'Prophet Moses Hall'
        and room.get('block_name', '') == '1'
        and room.get('gender', '') == 'Male'
    )


def is_male_clinic_room(room):
    return (
        room.get('hostel_name', '') == CLINIC_PROXIMAL_MALE_HOSTEL
        and room.get('block_name', '') in {'1', '2'}
        and room.get('gender', '') == 'Male'
    )


def is_female_clinic_room(room):
    return (
        room.get('hostel_name', '') == CLINIC_PROXIMAL_FEMALE_HOSTEL
        and room.get('block_name', '') in {'38', '39'}
        and room.get('gender', '') == 'Female'
    )


def is_clinic_room(room):
    return is_male_clinic_room(room) or is_female_clinic_room(room)


# ---------------------------------------------------------------------------
# Student band helpers
# ---------------------------------------------------------------------------

def student_is_high(student):
    return student.get('urgency_band', 'Low') == 'High'


def student_is_medium(student):
    return student.get('urgency_band', 'Low') == 'Medium'


def student_is_low(student):
    return student.get('urgency_band', 'Low') == 'Low'


def student_has_mobility_priority(student):
    return student.get('mobility', 'Normal Mobility') in MOBILITY_PRIORITY_STATUSES


def clinic_room_matches_gender(student, room):
    g = student.get('gender', '')
    if g == 'Male':   return is_male_clinic_room(room)
    if g == 'Female': return is_female_clinic_room(room)
    return False


# ---------------------------------------------------------------------------
# Faculty-proximal helpers
# ---------------------------------------------------------------------------

def get_faculty_proximal_hostels(student):
    f = student.get('faculty', '')
    g = student.get('gender', '')
    if g == 'Male':   return MALE_FACULTY_PROXIMAL.get(f, [])
    if g == 'Female': return FEMALE_FACULTY_PROXIMAL.get(f, [])
    return []


def room_in_faculty_proximal_hostel(student, room):
    return room.get('hostel_name', '') in get_faculty_proximal_hostels(student)


def faculty_proximal_rank(student, room):
    hostels = get_faculty_proximal_hostels(student)
    hostel_name = room.get('hostel_name', '')
    try:
        return hostels.index(hostel_name)
    except ValueError:
        return None


def mobility_ground_floor_target(student):
    if not student_has_mobility_priority(student):
        return None
    hostels = get_faculty_proximal_hostels(student)
    gender = student.get('gender', '')
    preferred = 'Joshua Hall' if gender == 'Male' else 'Deborah Hall' if gender == 'Female' else None
    if preferred and preferred in hostels:
        return preferred
    return None


def room_is_mobility_ground_floor_target(student, room, first_blocks):
    return (
        mobility_ground_floor_target(student) == room.get('hostel_name', '')
        and str(room.get('floor_level', '-1')) == '0'
        and room_is_first_block(room, first_blocks)
    )


def room_is_medium_male_access_target(student, room):
    return (
        student_is_medium(student)
        and student.get('gender', '') == 'Male'
        and room.get('hostel_name', '') == 'Prophet Moses Extension Hall'
        and room.get('block_name', '') == MEDIUM_MALE_ACCESS_BLOCK
        and room_in_faculty_proximal_hostel(student, room)
    )


def room_is_medium_first_block_ground_floor_target(student, room, first_blocks):
    return (
        student_is_medium(student)
        and room.get('hostel_name', '') in GROUND_FLOOR_MEDIUM_HOSTELS
        and room_in_faculty_proximal_hostel(student, room)
        and room_is_first_block(room, first_blocks)
        and str(room.get('floor_level', '-1')) == '0'
    )


def build_first_blocks(rooms):
    first = {}
    for room in rooms:
        key   = (room.get('hostel_name', ''), room.get('gender', ''))
        block = block_number(room)
        if key not in first or block < first[key]:
            first[key] = block
    return first


def room_is_first_block(room, first_blocks):
    key = (room.get('hostel_name', ''), room.get('gender', ''))
    return block_number(room) == first_blocks.get(key, -1)


# ---------------------------------------------------------------------------
# Weight calculator
# ---------------------------------------------------------------------------

def placement_bonus(student, room, first_blocks):
    is_high   = student_is_high(student)
    is_medium = student_is_medium(student)
    is_low    = student_is_low(student)
    rank      = faculty_proximal_rank(student, room)

    if is_high and clinic_room_matches_gender(student, room):
        return 5_000_000

    if room_is_mobility_ground_floor_target(student, room, first_blocks):
        return 2_200_000

    if is_medium and room_in_faculty_proximal_hostel(student, room):
        if room_is_medium_male_access_target(student, room):
            return 1_600_000
        if room_is_medium_first_block_ground_floor_target(student, room, first_blocks):
            return 1_550_000
        if room_is_first_block(room, first_blocks):
            return 1_500_000
        return 400_000

    if is_low and rank is not None:
        return 900_000 if rank == 0 else 450_000

    if (is_medium or is_low) and is_clinic_room(room):
        return 150_000

    return 0


# ---------------------------------------------------------------------------
# OR-Tools CP-SAT solver — single band
# ---------------------------------------------------------------------------

def run_ortools_band(students, rooms, remaining_cap, first_blocks, rng, time_limit=120.0):
    """
    Solves one urgency band (High / Medium / Low) independently.
    Keeping each solve small ensures CP-SAT finishes well within the time limit
    even at 5 000+ total students.
    """
    if not students:
        return {}, 'OPTIMAL'

    # Build eligible (student, room) pairs up front — avoids O(S×R) inner loops later
    eligible = {}   # (s_idx, r_idx) -> True
    for s_idx, student in enumerate(students):
        gender = student.get('gender', '')
        for r_idx, room in enumerate(rooms):
            if gender != room.get('gender', ''):
                continue
            if student_has_mobility_priority(student):
                if room.get('hostel_name', '') in ('Joshua Hall', 'Deborah Hall'):
                    if str(room.get('floor_level', '-1')) != '0':
                        continue
            if remaining_cap.get(room['id'], 0) <= 0:
                continue
            eligible[(s_idx, r_idx)] = True

    if not eligible:
        return {}, 'INFEASIBLE'

    model = cp_model.CpModel()
    x = {pair: model.NewBoolVar(f'x_s{pair[0]}_r{pair[1]}') for pair in eligible}

    # Capacity constraints
    for r_idx, room in enumerate(rooms):
        cap   = remaining_cap.get(room['id'], 0)
        terms = [x[(s, r_idx)] for s in range(len(students)) if (s, r_idx) in x]
        if terms:
            model.Add(sum(terms) <= cap)

    # Each student assigned at most once
    for s_idx in range(len(students)):
        terms = [x[(s_idx, r)] for r in range(len(rooms)) if (s_idx, r) in x]
        if terms:
            model.Add(sum(terms) <= 1)

    # Objective
    obj_terms = []
    for (s_idx, r_idx), var in x.items():
        student = students[s_idx]
        room    = rooms[r_idx]
        score   = float(student.get('score', 0))
        base    = 1_000_000 + int(score * 100)
        bonus   = placement_bonus(student, room, first_blocks)
        weight  = base + bonus + rng.randint(0, 99)
        obj_terms.append(var * weight)

    model.Maximize(sum(obj_terms))

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = time_limit
    solver.parameters.random_seed = rng.randint(1, 1_000_000)

    # Enable parallel workers for faster solving on multi-core machines
    solver.parameters.num_search_workers = 4

    status      = solver.Solve(model)
    status_name = solver.StatusName(status)

    assignments = {}
    if status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        for s_idx, student in enumerate(students):
            for r_idx, room in enumerate(rooms):
                if (s_idx, r_idx) in x and solver.Value(x[(s_idx, r_idx)]):
                    assignments[student['id']] = room['id']
                    break

    return assignments, status_name


# ---------------------------------------------------------------------------
# Main entry point
# ---------------------------------------------------------------------------

def allocate(students_csv, rooms_csv, output_csv):
    try:
        students = parse_csv(students_csv)
        rooms    = parse_csv(rooms_csv)
    except Exception as e:
        print(f"Error parsing CSVs: {e}")
        return

    if not students or not rooms:
        print("Missing students or rooms data")
        return

    rng          = random.Random()
    first_blocks = build_first_blocks(rooms)

    # Track remaining capacity — decremented after each band is solved
    remaining_cap = {
        room['id']: int(float(room.get('available_capacity', 0)))
        for room in rooms
    }

    # Split students into urgency bands — solved in priority order
    high_students   = [s for s in students if student_is_high(s)]
    medium_students = [s for s in students if student_is_medium(s)]
    low_students    = [s for s in students if student_is_low(s)]

    total = len(students)
    print(f"Total students to allocate: {total} (High={len(high_students)}, Medium={len(medium_students)}, Low={len(low_students)})")

    all_assignments = {}
    worst_status    = 'OPTIMAL'

    def update_capacity(band_assignments):
        for room_id in band_assignments.values():
            if room_id in remaining_cap and remaining_cap[room_id] > 0:
                remaining_cap[room_id] -= 1

    def merge_status(current, new):
        # INFEASIBLE > UNKNOWN > FEASIBLE > OPTIMAL (worst wins for reporting)
        rank = {'OPTIMAL': 0, 'FEASIBLE': 1, 'UNKNOWN': 2, 'INFEASIBLE': 3}
        return new if rank.get(new, 2) > rank.get(current, 0) else current

    # --- Band 1: High urgency (clinic-proximal priority) ---
    print(f"Solving High band ({len(high_students)} students)…")
    h_assignments, h_status = run_ortools_band(high_students, rooms, remaining_cap, first_blocks, rng, time_limit=90.0)
    all_assignments.update(h_assignments)
    update_capacity(h_assignments)
    worst_status = merge_status(worst_status, h_status)
    print(f"  High band: {len(h_assignments)}/{len(high_students)} assigned — {h_status}")

    # --- Band 2: Medium urgency (faculty-proximal priority) ---
    print(f"Solving Medium band ({len(medium_students)} students)…")
    m_assignments, m_status = run_ortools_band(medium_students, rooms, remaining_cap, first_blocks, rng, time_limit=120.0)
    all_assignments.update(m_assignments)
    update_capacity(m_assignments)
    worst_status = merge_status(worst_status, m_status)
    print(f"  Medium band: {len(m_assignments)}/{len(medium_students)} assigned — {m_status}")

    # --- Band 3: Low urgency (faculty-proximal preference, any room as fallback) ---
    print(f"Solving Low band ({len(low_students)} students)…")
    l_assignments, l_status = run_ortools_band(low_students, rooms, remaining_cap, first_blocks, rng, time_limit=120.0)
    all_assignments.update(l_assignments)
    worst_status = merge_status(worst_status, l_status)
    print(f"  Low band: {len(l_assignments)}/{len(low_students)} assigned — {l_status}")

    # Report overall solver status (worst across all bands)
    print(f"Solver status: {worst_status}")

    # Write output CSV
    with open(output_csv, 'w', newline='') as f:
        writer = csv.writer(f)
        writer.writerow(['student_id', 'room_id'])
        for student in students:
            sid = student['id']
            if sid in all_assignments:
                writer.writerow([sid, all_assignments[sid]])

    total_assigned = len(all_assignments)
    print(
        f"Success: {total_assigned}/{total} students assigned. "
        f"Wrote to {output_csv}"
    )


if __name__ == "__main__":
    if len(sys.argv) == 4:
        allocate(sys.argv[1], sys.argv[2], sys.argv[3])
    else:
        print("Usage: python allocate.py <students.csv> <rooms.csv> <output.csv>")
