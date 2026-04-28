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
    """
    Prophet Moses Hall Block 1.
    This is the ONLY room that remains a hard-reserved space (High males only).
    It is the block physically closest to the clinic — no backfill ever happens here.
    """
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
    """Queen Esther Extension Hall Block 39 — female clinic-proximal space."""
    return (
        room.get('hostel_name', '') == CLINIC_PROXIMAL_FEMALE_HOSTEL
        and room.get('block_name', '') == '39'
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


def build_first_blocks(rooms):
    """
    Maps (hostel_name, gender) → the numerically lowest block number in that hostel.
    Identifies the 'first block' (porters' lodge side) for the first-block rule.
    """
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
# Weight calculator — the heart of Option B
# ---------------------------------------------------------------------------

def placement_bonus(student, room, first_blocks):
    """
    Soft-preference bonus added to the base weight for each (student, room) pair.

    Priority ladder (descending bonus):
      5 000 000  High student → matching clinic room
      1 500 000  Medium student → first block of their faculty-proximal hostel
      1 200 000  Medium student → Prophet Moses Block 2
                 (Block 1 is hard-reserved; Block 2 is the effective first
                 available clinic/faculty-proximal space for Group A males)
        400 000  Medium student → any other block of their faculty-proximal hostel
              0  Any student filling remaining capacity (backfill)

    The gap between levels means the solver will always seat priority students
    in their preferred rooms first. Once all priority students are placed, any
    remaining capacity — in ANY block — is freely available for backfill by
    Low-urgency students (who receive no bonus but face no hard ban either).
    This ensures no bed is left empty when eligible students still need housing.
    """
    is_high   = student_is_high(student)
    is_medium = student_is_medium(student)

    # ── High urgency ────────────────────────────────────────────────────────
    if is_high and clinic_room_matches_gender(student, room):
        return 5_000_000

    # ── Medium urgency ───────────────────────────────────────────────────────
    if is_medium and room_in_faculty_proximal_hostel(student, room):
        # Block 1 of Prophet Moses is hard-reserved (High only); skip bonus.
        if is_primary_male_high_room(room):
            return 0

        # Prophet Moses Block 2: medium backfill for Group A males.
        if (room.get('hostel_name', '') == 'Prophet Moses Hall'
                and room.get('block_name', '') == '2'):
            return 1_200_000

        # Ideal placement: first block of any other faculty-proximal hostel.
        if room_is_first_block(room, first_blocks):
            return 1_500_000

        # Acceptable: later blocks of the same faculty-proximal hostel.
        return 400_000

    # ── Low urgency / backfill ───────────────────────────────────────────────
    # No bonus — the solver fills remaining capacity naturally after all
    # priority students are seated.  No hard ban prevents this.
    return 0


# ---------------------------------------------------------------------------
# Main solver
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

    model        = cp_model.CpModel()
    rng          = random.Random()
    first_blocks = build_first_blocks(rooms)

    x                = {}
    random_tie_break = {}
    for s_idx in range(len(students)):
        for r_idx in range(len(rooms)):
            x[(s_idx, r_idx)]                = model.NewBoolVar(f'x_s{s_idx}_r{r_idx}')
            random_tie_break[(s_idx, r_idx)] = rng.randint(0, 99)

    # -----------------------------------------------------------------------
    # Hard constraints — only TWO absolute rules
    # -----------------------------------------------------------------------
    for s_idx, student in enumerate(students):
        gender = student.get('gender', '')
        is_high = student_is_high(student)

        for r_idx, room in enumerate(rooms):

            # Rule 1 — Gender match is always required.
            if gender != room.get('gender', ''):
                model.Add(x[(s_idx, r_idx)] == 0)
                continue

            # Rule 2 — Prophet Moses Block 1 is exclusively for High-urgency
            # males. Even partially empty, this block is never backfilled —
            # it must remain a true medical-priority reserve at all times.
            if is_primary_male_high_room(room) and not is_high:
                model.Add(x[(s_idx, r_idx)] == 0)

    # Capacity: rooms cannot be overfilled.
    for r_idx, room in enumerate(rooms):
        cap = int(float(room.get('available_capacity', 0)))
        model.Add(
            sum(x[(s_idx, r_idx)] for s_idx in range(len(students))) <= cap
        )

    # Each student is assigned to at most one room.
    for s_idx in range(len(students)):
        model.Add(
            sum(x[(s_idx, r_idx)] for r_idx in range(len(rooms))) <= 1
        )

    # -----------------------------------------------------------------------
    # Objective
    # -----------------------------------------------------------------------
    obj_terms = []
    for s_idx, student in enumerate(students):
        score = float(student.get('score', 0))
        for r_idx, room in enumerate(rooms):
            base   = 1_000_000 + int(score * 100)
            bonus  = placement_bonus(student, room, first_blocks)
            weight = base + bonus + random_tie_break[(s_idx, r_idx)]
            obj_terms.append(x[(s_idx, r_idx)] * weight)

    model.Maximize(sum(obj_terms))

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 60.0
    solver.parameters.random_seed = rng.randint(1, 1_000_000)

    status = solver.Solve(model)

    with open(output_csv, 'w', newline='') as f:
        writer = csv.writer(f)
        writer.writerow(['student_id', 'room_id'])

        if status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
            assigned = set()
            for s_idx, student in enumerate(students):
                for r_idx, room in enumerate(rooms):
                    if solver.Value(x[(s_idx, r_idx)]):
                        writer.writerow([student['id'], room['id']])
                        assigned.add(s_idx)

            for s_idx, student in enumerate(students):
                if s_idx not in assigned:
                    logging.error(
                        "Student %s (band=%s, gender=%s, faculty=%s) unassigned — "
                        "no valid room available after full backfill pass.",
                        student.get('id', '?'),
                        student.get('urgency_band', '?'),
                        student.get('gender', '?'),
                        student.get('faculty', '?'),
                    )
            print(f"Success: wrote to {output_csv}")
        else:
            print("No feasible allocation found by OR-Tools.")


if __name__ == "__main__":
    if len(sys.argv) == 4:
        allocate(sys.argv[1], sys.argv[2], sys.argv[3])
    else:
        print("Usage: python allocate.py <students.csv> <rooms.csv> <output.csv>")
