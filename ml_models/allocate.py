import sys
import csv
import logging
from ortools.sat.python import cp_model

# Set up basic logging level for error handling
logging.basicConfig(level=logging.ERROR)

def parse_csv(filepath):
    """
    Helper function to parse a CSV file and return a list of dictionaries.
    Each dictionary represents a row where keys are the column headers.
    """
    data = []
    with open(filepath, 'r', newline='') as f:
        reader = csv.reader(f)
        headers = next(reader)
        for row in reader:
            data.append(dict(zip(headers, row)))
    return data

def allocate(students_csv, rooms_csv, output_csv):
    """
    Core allocation logic using Google OR-Tools Constraint Programming (CP-SAT) Solver.
    It reads students and rooms from CSV files, formulates the allocation problem as a
    Constraint Satisfaction Problem, solves it, and writes the results to an output CSV.
    """
    try:
        # 1. Parse Data
        students = parse_csv(students_csv)
        rooms = parse_csv(rooms_csv)
    except Exception as e:
        print(f"Error parsing CSVs: {e}")
        return

    if not students or not rooms:
        print("Missing students or rooms data")
        return

    # Initialize the CP-SAT model
    model = cp_model.CpModel()
    
    # === 1. Decision Variables ===
    # x[(s_idx, r_idx)] will be 1 if student s is assigned to room r, else 0
    x = {}
    for s_idx, s in enumerate(students):
        for r_idx, r in enumerate(rooms):
            x[(s_idx, r_idx)] = model.NewBoolVar(f'x_s{s_idx}_r{r_idx}')

    # === 2. Hard Constraints ===
    # These are strict rules that must be satisfied for a valid allocation.

    # ── FACULTY–HOSTEL ROUTING ──────────────────────────────────────────────
    # Students from these 4 faculties must ONLY go to their gender-matching
    # Engineering Hall.  All other students must NOT go to Engineering Halls.
    TARGET_FACULTIES = {
        'Faculty of Basic Medical Sciences',
        'Faculty of Engineering',
        'Faculty of Law',
        'Faculty of Built Environment Studies',
    }

    for s_idx, s in enumerate(students):
        s_faculty    = s.get('faculty', '')
        is_target    = s_faculty in TARGET_FACULTIES

        for r_idx, r in enumerate(rooms):
            is_eng_hall = r.get('hostel_name', '') in ['Joshua Hall', 'Deborah Hall']
            is_pmh_block_1 = (r.get('hostel_name', '') == 'Prophet Moses Hall' and r.get('block_name', '') == '1')
            s_severity = s.get('severity', '')
            
            # High urgency students are exempt from the restriction for PMH Block 1
            exempt_from_target_rule = (s_severity == 'High' and is_pmh_block_1)

            if is_target and not is_eng_hall and not exempt_from_target_rule:
                # Target-faculty students: forbidden from non-Engineering halls
                model.Add(x[(s_idx, r_idx)] == 0)
            elif not is_target and is_eng_hall:
                # Non-target students: forbidden from Engineering halls
                model.Add(x[(s_idx, r_idx)] == 0)
    # ───────────────────────────────────────────────────────────────────────

    # 2a. Room Capacity Constraint
    # The total number of students assigned to a room must not exceed its capacity.
    for r_idx, r in enumerate(rooms):
        cap = int(float(r.get('available_capacity', 0)))
        model.Add(sum(x[(s_idx, r_idx)] for s_idx in range(len(students))) <= cap)

    # 2b. Single Assignment Constraint
    # A student can be assigned to at most 1 room (<= 1).
    for s_idx in range(len(students)):
        model.Add(sum(x[(s_idx, r_idx)] for r_idx in range(len(rooms))) <= 1)

    # 2c. Gender & Mobility Match Constraints
    for s_idx, s in enumerate(students):
        s_gender  = s.get('gender')
        s_mobility = s.get('mobility', 'Normal')

        for r_idx, r in enumerate(rooms):
            r_gender = r.get('gender')

            # Prevent assigning a student to a room meant for the opposite gender
            if s_gender != r_gender:
                model.Add(x[(s_idx, r_idx)] == 0)

    # === 3. Objective Function ===
    # Maximise value of allocations: urgency scores + faculty-room match bonuses.
    obj_terms = []

    for s_idx, s in enumerate(students):
        score       = float(s.get('score', 0))   # Predictive score 0-100
        s_faculty   = s.get('faculty', '')
        is_high_urgency = score >= 75.0

        for r_idx, r in enumerate(rooms):
            r_faculty_target = r.get('faculty_target', 'General')
            r_is_proximal    = bool(int(r.get('is_proximal', 0)))

            # Base weight for any successful allocation
            weight = 1_000_000
            # Fractional bonus for urgency score (higher priority = slightly more weight)
            weight += int(score * 100)

            # High-urgency students prefer proximal rooms
            if is_high_urgency and r_is_proximal:
                weight += 50_000

            # Prefer rooms explicitly designated for the student's faculty
            if s_faculty == r_faculty_target:
                weight += 10_000
                
            r_hostel_name = r.get('hostel_name', '')
            r_block_name = r.get('block_name', '')
            is_pmh_block_1 = (r_hostel_name == 'Prophet Moses Hall' and r_block_name == '1')
            s_severity = s.get('severity', '')
            
            # Huge bonus to assign High urgency to Prophet Moses Hall Block 1
            if s_severity == 'High' and is_pmh_block_1:
                weight += 5_000_000

            obj_terms.append(x[(s_idx, r_idx)] * weight)

    # Instruct the solver to maximize the overall score
    model.Maximize(sum(obj_terms))

    # === 4. Solvers & Execution ===
    solver = cp_model.CpSolver()
    # Limit solver time to prevent long hangs on difficult cases
    solver.parameters.max_time_in_seconds = 60.0 
    
    # Run the solver
    status = solver.Solve(model)
    
    # 5. Write out results
    with open(output_csv, 'w', newline='') as f:
        writer = csv.writer(f)
        writer.writerow(['student_id', 'room_id'])
        
        # If the solver found an optimal or feasible combination
        if status == cp_model.OPTIMAL or status == cp_model.FEASIBLE:
            for s_idx, s in enumerate(students):
                for r_idx, r in enumerate(rooms):
                    # Check the boolean decision variable
                    if solver.Value(x[(s_idx, r_idx)]):
                        writer.writerow([s['id'], r['id']])
            print(f"Success: wrote to {output_csv}")
        else:
            print("No feasible allocation found by OR-Tools.")

if __name__ == "__main__":
    # Ensure correct arguments are passed when running from the command line
    if len(sys.argv) == 4:
        allocate(sys.argv[1], sys.argv[2], sys.argv[3])
    else:
        print("Usage: python allocate.py <students.csv> <rooms.csv> <output.csv>")
