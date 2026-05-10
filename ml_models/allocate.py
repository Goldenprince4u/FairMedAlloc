import csv
import logging
import random
import sys

# NOTE: Do NOT import cp_model here — CP-SAT is not used and the import
# adds significant cold-start latency on production (Render) servers.
# from ortools.sat.python import cp_model  ← removed

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


def student_has_medical_condition(student):
    """Check if student has a medical condition (not 'None' or 'Healthy')."""
    condition = student.get('severity', 'Low')
    # Severity > Low indicates a medical condition
    return condition in {'Medium', 'High', 'Critical'}


def student_has_combined_mobility_and_medical(student):
    """Check if student has BOTH mobility issue AND medical condition.
    These students should be prioritized for clinic proximity."""
    return student_has_mobility_priority(student) and student_has_medical_condition(student)


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

    # Special priority: Students with both mobility AND medical condition to clinic proximity
    # They should have the HIGHEST priority (higher than standard High urgency)
    if student_has_combined_mobility_and_medical(student) and clinic_room_matches_gender(student, room):
        return 5_500_000

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
# OR-Tools Min-Cost Flow solver (The new ultra-fast Graph Matcher!)
# ---------------------------------------------------------------------------

def run_min_cost_flow(students, rooms, first_blocks, rng):
    """
    Min-Cost Flow solver using OR-Tools SimpleMinCostFlow.
    Models the allocation as a directed graph: source → students → rooms → sink.
    Arcs are inserted in batches (not one-by-one) to avoid Python→C++ overhead
    on large datasets.
    """
    if not students:
        return {}, 'OPTIMAL'

    from ortools.graph.python import min_cost_flow
    smcf = min_cost_flow.SimpleMinCostFlow()

    num_students = len(students)
    num_rooms    = len(rooms)

    source        = 0
    sink          = num_students + num_rooms + 2
    waitlist_node = num_students + num_rooms + 1

    # ── Build arc lists (batched) ─────────────────────────────────────────────
    # Batching replaces O(n) individual add_arc_with_capacity_and_unit_cost()
    # calls (each a separate Python→C++ crossing) with 4 bulk calls.

    # Arc 1: source → each student node (capacity 1, cost 0)
    src_tails     = [source]       * num_students
    src_heads     = list(range(1, num_students + 1))
    src_caps      = [1]            * num_students
    src_costs     = [0]            * num_students

    # Arc 2: student → room arcs (filtered by hard constraints)
    arc_tails, arc_heads, arc_caps, arc_costs = [], [], [], []
    arc_to_assignment = {}          # arc_index → (student_id, room_id)

    # Arc 3: student → waitlist (one per student, built in the same pass)
    wl_tails, wl_heads, wl_caps, wl_costs = [], [], [], []

    print(f"Running OR-Tools solver: building arcs for {num_students} students × {num_rooms} rooms…", flush=True)

    for s_idx, student in enumerate(students):
        gender    = student.get('gender', '')
        is_high   = student_is_high(student)
        is_medium = student_is_medium(student)
        score     = float(student.get('score', 0))

        if is_high:
            band_base = 100_000_000
        elif is_medium:
            band_base = 50_000_000
        else:
            band_base = 10_000_000

        base_score = band_base + int(score * 100)

        for r_idx, room in enumerate(rooms):
            if gender != room.get('gender', ''):
                continue

            if student_has_combined_mobility_and_medical(student):
                if not clinic_room_matches_gender(student, room):
                    continue
            elif student_has_mobility_priority(student):
                if str(room.get('floor_level', '-1')) != '0':
                    continue
                if not is_high and room.get('hostel_name', '') not in ('Joshua Hall', 'Deborah Hall'):
                    continue

            bonus  = placement_bonus(student, room, first_blocks)
            weight = base_score + bonus + rng.randint(0, 99)

            arc_tails.append(s_idx + 1)
            arc_heads.append(num_students + 1 + r_idx)
            arc_caps.append(1)
            arc_costs.append(-weight)
            # Arc index will be: num_students (src arcs) + current position
            arc_to_assignment[num_students + len(arc_tails) - 1] = (student['id'], room['id'])

        # Student → waitlist
        wl_tails.append(s_idx + 1)
        wl_heads.append(waitlist_node)
        wl_caps.append(1)
        wl_costs.append(0)

    print(f"Graph built: {len(arc_tails)} student→room arcs. Adding to solver…", flush=True)

    # Arc 4: room → sink
    room_tails, room_heads, room_caps, room_costs = [], [], [], []
    for r_idx, room in enumerate(rooms):
        cap = int(float(room.get('available_capacity', 0)))
        if cap > 0:
            room_tails.append(num_students + 1 + r_idx)
            room_heads.append(sink)
            room_caps.append(cap)
            room_costs.append(0)

    # ── Bulk-add all arc groups ───────────────────────────────────────────────
    # Order matters — arc indices are assigned in insertion order.
    # Layout:
    #   [0  .. ns-1      ] = source → student         (src_tails,  ns  arcs)
    #   [ns .. ns+na-1   ] = student → room           (arc_tails,  na  arcs) ← we track these
    #   [ns+na .. 2ns+na-1] = student → waitlist      (wl_tails,   ns  arcs)
    #   [2ns+na ..       ] = room → sink              (room_tails, nr  arcs)
    #   [last            ] = waitlist → sink          (single arc)
    smcf.add_arcs_with_capacity_and_unit_cost(src_tails,  src_heads,  src_caps,  src_costs)
    smcf.add_arcs_with_capacity_and_unit_cost(arc_tails,  arc_heads,  arc_caps,  arc_costs)
    smcf.add_arcs_with_capacity_and_unit_cost(wl_tails,   wl_heads,   wl_caps,   wl_costs)
    smcf.add_arcs_with_capacity_and_unit_cost(room_tails, room_heads, room_caps, room_costs)
    # waitlist → sink MUST be last so it doesn't shift the student→room arc indices above
    smcf.add_arc_with_capacity_and_unit_cost(waitlist_node, sink, num_students, 0)

    # Build arc→assignment map.
    # student→room arcs begin at index num_students (after the ns source→student arcs).
    arc_offset = num_students
    arc_to_assignment = {}
    for i, (t, h) in enumerate(zip(arc_tails, arc_heads)):
        real_arc = arc_offset + i
        s_node   = t - 1                       # student index (0-based)
        r_node   = h - num_students - 1        # room index (0-based)
        arc_to_assignment[real_arc] = (students[s_node]['id'], rooms[r_node]['id'])

    smcf.set_node_supply(source, num_students)
    smcf.set_node_supply(sink,  -num_students)

    print("Solving…", flush=True)
    status = smcf.solve()
    print(f"Solver finished with status: {status}", flush=True)

    assignments  = {}
    status_name  = 'INFEASIBLE'

    if status == smcf.OPTIMAL:
        status_name = 'OPTIMAL'
        for arc in range(smcf.num_arcs()):
            if smcf.flow(arc) > 0 and arc in arc_to_assignment:
                s_id, r_id = arc_to_assignment[arc]
                assignments[s_id] = r_id
    elif status == smcf.FEASIBLE:
        status_name = 'FEASIBLE'

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

    total = len(students)
    high_count   = sum(1 for s in students if student_is_high(s))
    medium_count = sum(1 for s in students if student_is_medium(s))
    low_count    = sum(1 for s in students if student_is_low(s))
    
    print(f"Total students to allocate: {total} (High={high_count}, Medium={medium_count}, Low={low_count})")
    print("Solving allocation globally via Min-Cost Flow graph matching...")

    all_assignments, solver_status = run_min_cost_flow(students, rooms, first_blocks, rng)

    print(f"Solver status: {solver_status}")

    # Write output CSV
    with open(output_csv, 'w', newline='') as f:
        writer = csv.writer(f)
        writer.writerow(['student_id', 'room_id'])
        for student in students:
            sid = student['id']
            if sid in all_assignments:
                writer.writerow([sid, all_assignments[sid]])

    total_assigned = len(all_assignments)
    print(f"Success: {total_assigned}/{total} students assigned. Wrote to {output_csv}")

if __name__ == "__main__":
    if len(sys.argv) == 4:
        allocate(sys.argv[1], sys.argv[2], sys.argv[3])
    else:
        print("Usage: python allocate.py <students.csv> <rooms.csv> <output.csv>")
