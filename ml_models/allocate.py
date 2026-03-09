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

    # 2a. Room Capacity Constraint
    # The total number of students assigned to a room must not exceed its capacity.
    for r_idx, r in enumerate(rooms):
        cap = int(float(r.get('available_capacity', 0)))
        model.Add(sum(x[(s_idx, r_idx)] for s_idx in range(len(students))) <= cap)

    # 2b. Single Assignment Constraint
    # A student can be assigned to at most 1 room (<= 1).
    for s_idx in range(len(students)):
        model.Add(sum(x[(s_idx, r_idx)] for r_idx in range(len(rooms))) <= 1)

    # 2c. General & Mobility Match Constraints
    for s_idx, s in enumerate(students):
        s_gender = s.get('gender')
        s_mobility = s.get('mobility', 'Normal')
        
        # Check if the student relies on mobility aids
        needs_ground = False
        if any(keyword in str(s_mobility) for keyword in ['Wheelchair', 'Crutches', 'Walker']):
            needs_ground = True

        for r_idx, r in enumerate(rooms):
            r_gender = r.get('gender')
            r_floor = int(float(r.get('floor_level', 1)))
            r_elevator = bool(int(r.get('has_elevator', 0)))
            
            # Prevent assigning a student to a room meant for the opposite gender
            if s_gender != r_gender:
                model.Add(x[(s_idx, r_idx)] == 0)
                
            # If the student needs a ground floor (due to mobility issues),
            # prevent assigning them to non-ground floors unless there is an elevator.
            if needs_ground:
                if r_floor != 0 and not r_elevator:
                    model.Add(x[(s_idx, r_idx)] == 0)

    # === 3. Objective Function ===
    # Formulate weights to maximize the value of the allocations (e.g., prioritize high urgency, match faculties).
    obj_terms = []
    
    for s_idx, s in enumerate(students):
        score = float(s.get('score', 0)) # Predictive score out of 100
        s_faculty = s.get('faculty', '')
        
        # Determine if this student requires urgent accommodation
        is_high_urgency = score >= 75.0
        
        for r_idx, r in enumerate(rooms):
            r_faculty_target = r.get('faculty_target', 'General')
            r_is_proximal = bool(int(r.get('is_proximal', 0)))
            
            # Base weight for a successful allocation
            weight = 1000000 
            # Sub-weight based on the student's predictive score (higher score = slightly more weight)
            weight += int(score * 100) 
            
            # Bonus weight: High-urgency students should ideally be placed in proximal rooms
            if is_high_urgency and r_is_proximal:
                weight += 50000
                
            # Bonus weight: Prefer assigning students to rooms designated for their faculty
            if s_faculty == r_faculty_target:
                weight += 10000
                
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
