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
    """Prophet Moses Hall Block 1 or 2 — male clinic-proximal space."""
    return (
        room.get('hostel_name', '') == CLINIC_PROXIMAL_MALE_HOSTEL
        and room.get('block_name', '') in {'1', '2'}
        and room.get('gender', '') == 'Male'
    )


def is_female_clinic_room(room):
    """Queen Esther Extension Hall Blocks 38, 39 — female clinic-proximal space."""
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
    """Maps (hostel_name, gender) → the numerically lowest block number."""
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
    """
    Soft-preference bonus for each (student, room) pair.

    Priority ladder (descending bonus):
      5 000 000  High student -> matching clinic-proximal room
      2 200 000  Mobility-priority student -> Joshua/Deborah first-block ground floor
      1 600 000  Medium male -> Prophet Moses Extension Hall Block 27
      1 550 000  Medium student -> Joshua/Deborah first-block ground floor
      1 500 000  Medium student -> first block of faculty-proximal hostel
        900 000  Low student -> primary faculty-proximal hostel
        450 000  Low student -> secondary faculty-proximal hostel
        400 000  Medium student -> any other block of faculty-proximal hostel
        150 000  Medium or Low -> clinic-proximal room (overflow when proximal full)
              0  Any other gender-matching room (last resort)

    Only Prophet Moses Hall Block 1 is hard-excluded for non-High students
    (enforced as a hard filter in run_ortools, not here).
    All other rooms — including clinic-proximal — are reachable by any student
    when their preferred halls are at capacity.
    """
    is_high = student_is_high(student)
    is_medium = student_is_medium(student)
    is_low = student_is_low(student)
    rank = faculty_proximal_rank(student, room)

    if is_high and clinic_room_matches_gender(student, room):
        return 5_000_000

    if room_is_mobility_ground_floor_target(student, room, first_blocks):
        return 2_200_000

    if is_medium and room_in_faculty_proximal_hostel(student, room):
        if is_primary_male_high_room(room):
            return 0
        if room_is_medium_male_access_target(student, room):
            return 1_600_000
        if room_is_medium_first_block_ground_floor_target(student, room, first_blocks):
            return 1_550_000
        if room_is_first_block(room, first_blocks):
            return 1_500_000
        return 400_000

    if is_low and rank is not None:
        return 900_000 if rank == 0 else 450_000

    # Overflow: Medium or Low student reaching a clinic-proximal room because
    # all faculty-proximal blocks are full. Preferred over any other room.
    if (is_medium or is_low) and is_clinic_room(room) and not is_primary_male_high_room(room):
        return 150_000

    return 0


# ---------------------------------------------------------------------------
# OR-Tools CP-SAT solver — runs for ALL students
# ---------------------------------------------------------------------------

def run_ortools(students, rooms, remaining_cap, first_blocks, rng):
    """
    Runs the CP-SAT solver on ALL students (High, Medium, and Low urgency).

    Uses SPARSE variable creation:
      - Variables are only created for gender-compatible pairs.
      - Variables for Block-1-Prophet-Moses are skipped for non-High students
        (the only absolute hard exclusion).
      - Mobility-priority students in Joshua/Deborah Hall are restricted to
        floor 0 only (the only two-floor hostels; no elevator, stairs only).
      - All other students may reach any gender-matching room; placement bonuses
        in the objective function steer them to preferred halls first.
      - Variables for rooms with 0 remaining capacity are skipped.
    """
    if not students:
        return {}, 'INFEASIBLE'

    model = cp_model.CpModel()

    x                = {}   # (s_idx, r_idx) -> BoolVar
    random_tie_break = {}

    for s_idx, student in enumerate(students):
        gender  = student.get('gender', '')
        is_high = student_is_high(student)
        is_low  = student_is_low(student)
        proximal_hostels = get_faculty_proximal_hostels(student)

        for r_idx, room in enumerate(rooms):
            # Hard filter 1: gender must match
            if gender != room.get('gender', ''):
                continue
            # Hard filter 2: Block 1 Prophet Moses only for High males
            if is_primary_male_high_room(room) and not is_high:
                continue
            # Hard filter 3: Mobility constraint on ground floor for Joshua/Deborah
            if student_has_mobility_priority(student):
                if room.get('hostel_name', '') in ('Joshua Hall', 'Deborah Hall'):
                    if str(room.get('floor_level', '-1')) != '0':
                        continue
            # Skip rooms already at capacity
            if remaining_cap.get(room['id'], 0) <= 0:
                continue

            x[(s_idx, r_idx)]                = model.NewBoolVar(f'x_s{s_idx}_r{r_idx}')
            random_tie_break[(s_idx, r_idx)] = rng.randint(0, 99)

    # Capacity constraints (per room, only over compatible students)
    for r_idx, room in enumerate(rooms):
        cap   = remaining_cap.get(room['id'], 0)
        terms = [x[(s, r_idx)] for s in range(len(students)) if (s, r_idx) in x]
        if terms:
            model.Add(sum(terms) <= cap)

    # Each student is assigned to at most one room
    for s_idx in range(len(students)):
        terms = [x[(s_idx, r)] for r in range(len(rooms)) if (s_idx, r) in x]
        if terms:
            model.Add(sum(terms) <= 1)

    # Objective: maximise weighted placement
    obj_terms = []
    for s_idx, student in enumerate(students):
        score = float(student.get('score', 0))
        for r_idx, room in enumerate(rooms):
            if (s_idx, r_idx) not in x:
                continue
            base   = 1_000_000 + int(score * 100)
            bonus  = placement_bonus(student, room, first_blocks)
            weight = base + bonus + random_tie_break[(s_idx, r_idx)]
            obj_terms.append(x[(s_idx, r_idx)] * weight)

    if not obj_terms:
        return {}, 'INFEASIBLE'

    model.Maximize(sum(obj_terms))

    solver = cp_model.CpSolver()
    # For 15k students, solver may need up to 300s to reach OPTIMAL.
    # The solver returns early as OPTIMAL if it can prove the best solution before the limit.
    # For large scale allocations (5k+), FEASIBLE solutions are still valid and fair.
    solver.parameters.max_time_in_seconds = 300.0
    solver.parameters.random_seed = rng.randint(1, 1_000_000)

    status = solver.Solve(model)
    status_name = solver.StatusName(status)

    assignments = {}
    if status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        for s_idx, student in enumerate(students):
            for r_idx, room in enumerate(rooms):
                if (s_idx, r_idx) in x and solver.Value(x[(s_idx, r_idx)]):
                    assignments[student['id']] = room['id']
                    break   # each student assigned to at most one room

    # Log unassigned students (should be rare — only if rooms truly full)
    assigned_ids = set(assignments.keys())
    for student in students:
        if student['id'] not in assigned_ids:
            logging.error(
                "Student %s (band=%s, gender=%s, faculty=%s) unassigned.",
                student.get('id', '?'), student.get('urgency_band', '?'),
                student.get('gender', '?'), student.get('faculty', '?'),
            )

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

    # Track remaining capacity by room['id']
    remaining_cap = {
        room['id']: int(float(room.get('available_capacity', 0)))
        for room in rooms
    }

    print(f"Total students to allocate: {len(students)} using OR-Tools CP-SAT")

    # OR-Tools CP-SAT handles ALL students (High, Medium, Low) in one pass.
    # Placement bonuses in the objective function guarantee priority ordering
    # without a separate greedy phase.
    all_assignments, solver_status = run_ortools(students, rooms, remaining_cap, first_blocks, rng)
    print(f"Solver status: {solver_status}")

    with open(output_csv, 'w', newline='') as f:
        writer = csv.writer(f)
        writer.writerow(['student_id', 'room_id'])
        for student in students:
            sid = student['id']
            if sid in all_assignments:
                writer.writerow([sid, all_assignments[sid]])

    total_assigned = len(all_assignments)
    print(
        f"Success: {total_assigned}/{len(students)} students assigned. "
        f"Wrote to {output_csv}"
    )


if __name__ == "__main__":
    if len(sys.argv) == 4:
        allocate(sys.argv[1], sys.argv[2], sys.argv[3])
    else:
        print("Usage: python allocate.py <students.csv> <rooms.csv> <output.csv>")
