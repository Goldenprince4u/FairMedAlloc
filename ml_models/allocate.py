import csv
import logging
import random
import signal
import sys

# NOTE: Do NOT import cp_model here — CP-SAT is not used and the import
# adds significant cold-start latency on production (Render) servers.
# from ortools.sat.python import cp_model  ← removed

logging.basicConfig(level=logging.DEBUG)

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

import array

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

    # ── Build arc lists ───────────────────────────────────────────────────────
    # Arc 1: source → student (capacity 1, cost 0)
    src_tails = array.array('i', [source] * num_students)
    src_heads = array.array('i', range(1, num_students + 1))
    src_caps  = array.array('i', [1] * num_students)
    src_costs = array.array('q', [0] * num_students)

    # Arc 2: student → room (filtered by hard constraints)
    arc_tails = array.array('i')
    arc_heads = array.array('i')
    arc_caps  = array.array('i')
    arc_costs = array.array('q')
    # Memory-efficient alternative to arc_to_assignment dict:
    # Two parallel lists indexed by arc position (arc index - arc_offset).
    arc_s_ids = array.array('l')   # student_id for each student→room arc (int64 — avoids overflow)
    arc_r_ids = array.array('l')   # room_id    for each student→room arc (int64 — avoids overflow)

    # Arc 3: student → waitlist
    wl_tails = array.array('i', range(1, num_students + 1))
    wl_heads  = array.array('i', [waitlist_node] * num_students)
    wl_caps   = array.array('i', [1] * num_students)
    wl_costs  = array.array('q', [0] * num_students)

    print(f"Running OR-Tools solver: building arcs for {num_students} students x {num_rooms} rooms...", flush=True)

    # Track students that get zero room arcs (can happen when mobility filter is
    # too restrictive and all eligible beds are full). We need a second pass so
    # those students still have *some* room option, otherwise the flow graph has
    # an isolated source→student arc with no onward path, making it INFEASIBLE.
    students_with_no_arcs = []  # list of (s_idx, student) tuples

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
        s_node = s_idx + 1

        arcs_added_for_student = 0
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

            arc_tails.append(s_node)
            arc_heads.append(num_students + 1 + r_idx)
            arc_caps.append(1)
            arc_costs.append(-weight)
            arc_s_ids.append(int(student['id']))
            arc_r_ids.append(int(room['id']))
            arcs_added_for_student += 1

        if arcs_added_for_student == 0 and (student_has_mobility_priority(student) or student_has_combined_mobility_and_medical(student)):
            # No eligible arcs — queue for relaxed fallback pass so the flow stays feasible.
            students_with_no_arcs.append((s_idx, student))

    # ── Relaxed-fallback pass for students with zero arcs ────────────────────
    # These students will land on the waitlist (via the wl arc), but we must give
    # them at least one room arc so the graph remains feasible for the solver.
    # We assign a very low weight so they only get a real room if one is truly free.
    if students_with_no_arcs:
        print(f"Relaxed-fallback pass: {len(students_with_no_arcs)} mobility students had zero eligible arcs. Opening gender-matching fallback arcs (lowest priority).", flush=True)
        for s_idx, student in students_with_no_arcs:
            gender = student.get('gender', '')
            s_node = s_idx + 1
            is_high   = student_is_high(student)
            is_medium = student_is_medium(student)
            if is_high:
                band_base = 100_000_000
            elif is_medium:
                band_base = 50_000_000
            else:
                band_base = 10_000_000
            base_score = band_base + int(float(student.get('score', 0)) * 100)
            for r_idx, room in enumerate(rooms):
                if gender != room.get('gender', ''):
                    continue
                # Fallback arcs carry the minimum possible weight — solver will
                # prefer the waitlist arc over these unless there is genuinely
                # spare capacity.
                weight = base_score + rng.randint(0, 9)  # no bonus
                arc_tails.append(s_node)
                arc_heads.append(num_students + 1 + r_idx)
                arc_caps.append(1)
                arc_costs.append(-weight)
                arc_s_ids.append(int(student['id']))
                arc_r_ids.append(int(room['id']))

    print(f"Graph built: {len(arc_tails)} student-to-room arcs. Adding to solver...", flush=True)

    # Arc 4: room → sink
    room_tails = array.array('i')
    room_heads = array.array('i')
    room_caps  = array.array('i')
    room_costs = array.array('q')
    for r_idx, room in enumerate(rooms):
        cap = int(float(room.get('available_capacity', 0)))
        if cap > 0:
            room_tails.append(num_students + 1 + r_idx)
            room_heads.append(sink)
            room_caps.append(cap)
            room_costs.append(0)

    # ── Bulk-add all arc groups ───────────────────────────────────────────────
    # Order defines arc indices. Layout:
    #   [0  .. ns-1      ] source → student   (src,  ns arcs)
    #   [ns .. ns+na-1   ] student → room     (arc,  na arcs) ← we track these via arc_s/r_ids
    #   [ns+na..2ns+na-1 ] student → waitlist (wl,   ns arcs)
    #   [2ns+na ..       ] room → sink        (room, nr arcs)
    #   [last            ] waitlist → sink    (1 arc)
    smcf.add_arcs_with_capacity_and_unit_cost(src_tails,  src_heads,  src_caps,  src_costs)
    smcf.add_arcs_with_capacity_and_unit_cost(arc_tails,  arc_heads,  arc_caps,  arc_costs)
    smcf.add_arcs_with_capacity_and_unit_cost(wl_tails,   wl_heads,   wl_caps,   wl_costs)
    smcf.add_arcs_with_capacity_and_unit_cost(room_tails, room_heads, room_caps, room_costs)
    smcf.add_arc_with_capacity_and_unit_cost(waitlist_node, sink, num_students, 0)

    # Free the large arc lists — OR-Tools has copied them into C++ memory.
    # Releasing here cuts Python heap by ~100-300 MB before the solve starts.
    del src_tails, src_heads, src_caps, src_costs
    del arc_tails, arc_heads, arc_caps, arc_costs
    del wl_tails, wl_heads, wl_caps, wl_costs
    del room_tails, room_heads, room_caps, room_costs

    smcf.set_node_supply(source, num_students)
    smcf.set_node_supply(sink,  -num_students)

    print("Solving...", flush=True)

    # On Linux, install a 30-minute watchdog so a degenerate graph can't
    # block the worker indefinitely. signal.alarm is a no-op on Windows.
    _solver_timed_out = False
    if hasattr(signal, 'SIGALRM'):
        def _timeout_handler(signum, frame):
            raise TimeoutError('OR-Tools solver exceeded the 1800-second limit.')
        signal.signal(signal.SIGALRM, _timeout_handler)
        signal.alarm(1800)  # 30 minutes

    try:
        status = smcf.solve()
    except TimeoutError as te:
        _solver_timed_out = True
        print(f"Solver watchdog triggered: {te}", flush=True)
        status = smcf.INFEASIBLE  # treat as infeasible so the worker logs a clean failure
    finally:
        if hasattr(signal, 'SIGALRM'):
            signal.alarm(0)  # cancel watchdog on normal completion

    print(f"Solver finished with status: {status}", flush=True)

    assignments  = {}
    status_name  = 'INFEASIBLE'
    arc_offset   = num_students   # student→room arcs begin here

    if status == smcf.OPTIMAL:
        status_name = 'OPTIMAL'
        na = len(arc_s_ids)
        for i in range(na):
            arc = arc_offset + i
            if smcf.flow(arc) > 0:
                assignments[arc_s_ids[i]] = arc_r_ids[i]
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
