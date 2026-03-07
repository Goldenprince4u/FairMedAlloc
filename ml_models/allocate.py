import sys
import csv
import logging
from ortools.sat.python import cp_model

logging.basicConfig(level=logging.ERROR)

def parse_csv(filepath):
    data = []
    with open(filepath, 'r', newline='') as f:
        reader = csv.reader(f)
        headers = next(reader)
        for row in reader:
            data.append(dict(zip(headers, row)))
    return data

def allocate(students_csv, rooms_csv, output_csv):
    try:
        students = parse_csv(students_csv)
        rooms = parse_csv(rooms_csv)
    except Exception as e:
        print(f"Error parsing CSVs: {e}")
        return

    if not students or not rooms:
        print("Missing students or rooms data")
        return

    model = cp_model.CpModel()
    
    # 1. Variables
    x = {}
    for s_idx, s in enumerate(students):
        for r_idx, r in enumerate(rooms):
            x[(s_idx, r_idx)] = model.NewBoolVar(f'x_s{s_idx}_r{r_idx}')

    # 2. Hard Constraints

    # Capacity constraint
    for r_idx, r in enumerate(rooms):
        cap = int(float(r.get('available_capacity', 0)))
        model.Add(sum(x[(s_idx, r_idx)] for s_idx in range(len(students))) <= cap)

    # Single assignment
    for s_idx in range(len(students)):
        model.Add(sum(x[(s_idx, r_idx)] for r_idx in range(len(rooms))) <= 1)

    # Gender & Mobility Constraints
    for s_idx, s in enumerate(students):
        s_gender = s.get('gender')
        s_mobility = s.get('mobility', 'Normal')
        
        needs_ground = False
        if any(keyword in str(s_mobility) for keyword in ['Wheelchair', 'Crutches', 'Walker']):
            needs_ground = True

        for r_idx, r in enumerate(rooms):
            r_gender = r.get('gender')
            r_floor = int(float(r.get('floor_level', 1)))
            r_elevator = bool(int(r.get('has_elevator', 0)))
            
            if s_gender != r_gender:
                model.Add(x[(s_idx, r_idx)] == 0)
                
            if needs_ground:
                if r_floor != 0 and not r_elevator:
                    model.Add(x[(s_idx, r_idx)] == 0)

    # 3. Objective Function (Maximize weight)
    obj_terms = []
    
    for s_idx, s in enumerate(students):
        score = float(s.get('score', 0))
        s_faculty = s.get('faculty', '')
        
        is_high_urgency = score >= 75.0
        
        for r_idx, r in enumerate(rooms):
            r_faculty_target = r.get('faculty_target', 'General')
            r_is_proximal = bool(int(r.get('is_proximal', 0)))
            
            weight = 1000000 # Base weight
            weight += int(score * 100) 
            
            if is_high_urgency and r_is_proximal:
                weight += 50000
                
            if s_faculty == r_faculty_target:
                weight += 10000
                
            obj_terms.append(x[(s_idx, r_idx)] * weight)

    model.Maximize(sum(obj_terms))

    # 4. Solvers
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 60.0 
    
    status = solver.Solve(model)
    
    with open(output_csv, 'w', newline='') as f:
        writer = csv.writer(f)
        writer.writerow(['student_id', 'room_id'])
        
        if status == cp_model.OPTIMAL or status == cp_model.FEASIBLE:
            for s_idx, s in enumerate(students):
                for r_idx, r in enumerate(rooms):
                    if solver.Value(x[(s_idx, r_idx)]):
                        writer.writerow([s['id'], r['id']])
            print(f"Success: wrote to {output_csv}")
        else:
            print("No feasible allocation found by OR-Tools.")

if __name__ == "__main__":
    if len(sys.argv) == 4:
        allocate(sys.argv[1], sys.argv[2], sys.argv[3])
    else:
        print("Usage: python allocate.py <students.csv> <rooms.csv> <output.csv>")

