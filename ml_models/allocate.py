import csv
import logging
import random
import sys

from ortools.sat.python import cp_model

logging.basicConfig(level=logging.ERROR)

CLINIC_PROXIMAL_FACULTIES = {
    'Faculty of Humanities',
    'Faculty of Management Sciences',
    'Faculty of Natural Sciences',
    'Faculty of Social Sciences',
    'Faculty of Computing and Digital Technology',
}


def parse_csv(filepath):
    data = []
    with open(filepath, 'r', newline='') as f:
        reader = csv.reader(f)
        headers = next(reader)
        for row in reader:
            data.append(dict(zip(headers, row)))
    return data


def is_male_clinic_room(room):
    return (
        room.get('hostel_name', '') == 'Prophet Moses Hall'
        and room.get('block_name', '') == '1'
        and room.get('gender', '') == 'Male'
    )


def is_female_clinic_room(room):
    return (
        room.get('hostel_name', '') == 'Queen Esther Extension hall'
        and room.get('block_name', '') == '39'
        and room.get('gender', '') == 'Female'
    )


def is_clinic_room(room):
    return is_male_clinic_room(room) or is_female_clinic_room(room)


def needs_clinic_proximity(student):
    urgency_band = student.get('urgency_band', 'Low')
    return urgency_band == 'High'


def clinic_room_matches_student(student, room):
    gender = student.get('gender', '')
    if gender == 'Male':
        return is_male_clinic_room(room)
    if gender == 'Female':
        return is_female_clinic_room(room)
    return False


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
    rng = random.Random()

    x = {}
    random_tie_break = {}
    for s_idx, _student in enumerate(students):
        for r_idx, _room in enumerate(rooms):
            x[(s_idx, r_idx)] = model.NewBoolVar(f'x_s{s_idx}_r{r_idx}')
            random_tie_break[(s_idx, r_idx)] = rng.randint(0, 99)

    # Hard constraints
    for s_idx, student in enumerate(students):
        student_gender = student.get('gender', '')
        student_faculty = student.get('faculty', '')
        student_needs_clinic = needs_clinic_proximity(student)
        student_can_backfill_clinic = student_faculty in CLINIC_PROXIMAL_FACULTIES

        for r_idx, room in enumerate(rooms):
            room_gender = room.get('gender', '')
            room_is_clinic = is_clinic_room(room)

            if student_gender != room_gender:
                model.Add(x[(s_idx, r_idx)] == 0)
                continue

            if room_is_clinic and not (student_needs_clinic or student_can_backfill_clinic):
                model.Add(x[(s_idx, r_idx)] == 0)

    for r_idx, room in enumerate(rooms):
        cap = int(float(room.get('available_capacity', 0)))
        model.Add(sum(x[(s_idx, r_idx)] for s_idx in range(len(students))) <= cap)

    for s_idx in range(len(students)):
        model.Add(sum(x[(s_idx, r_idx)] for r_idx in range(len(rooms))) <= 1)

    # Objective
    obj_terms = []
    for s_idx, student in enumerate(students):
        score = float(student.get('score', 0))
        faculty = student.get('faculty', '')
        urgency_band = student.get('urgency_band', 'Low')
        student_needs_clinic = needs_clinic_proximity(student)
        student_can_backfill_clinic = faculty in CLINIC_PROXIMAL_FACULTIES

        for r_idx, room in enumerate(rooms):
            room_is_clinic = is_clinic_room(room)

            weight = 1_000_000
            weight += int(score * 100)

            # High urgency students massively prefer clinic rooms, but can go elsewhere if full.
            if student_needs_clinic and clinic_room_matches_student(student, room):
                weight += 5_000_000
            # Medium urgency students from clinic-proximal faculties prefer clinic rooms.
            elif urgency_band == 'Medium' and student_can_backfill_clinic and clinic_room_matches_student(student, room):
                weight += 250_000
            # Low urgency students from clinic-proximal faculties can backfill clinic rooms.
            elif room_is_clinic and student_can_backfill_clinic:
                weight += 50_000

            weight += random_tie_break[(s_idx, r_idx)]
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

            # Log any students who did not receive a room assignment so
            # administrators can diagnose constraint issues post-run.
            for s_idx, student in enumerate(students):
                if s_idx not in assigned:
                    logging.error(
                        "Student %s (band=%s, gender=%s, faculty=%s) was not assigned "
                        "a room — no valid room matched their constraints.",
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
