This document consolidates all the business rules and constraints regarding medical and mobility allocations.

-----
# General Allocation Policy

# FairMedAlloc Allocation Policy

This document is my master rulebook for how FairMedAlloc decides where a student sleeps. If an admin ever asks "Why was this student put in this room?", the answer is mathematically defined here.

## The TL;DR Process
1. **The AI Base Score:** The XGBoost model reads the medical data and generates a raw urgency score (0-100).
2. **The Human Check (Calibration):** My script intercepts the AI score and calibrates it against strict university rules (e.g., forcing physical disabilities to Ground Floor without wasting a scarce clinic bed).
3. **The Graph Matcher:** The calibrated scores are fed into the Min-Cost Flow engine, which solves the massive 3,000-student 3D puzzle in about 1.5 seconds.
4. **The Bed Assigner:** The PHP orchestrator takes the chosen room and strictly assigns the `LB` (Lower Bunk) to anyone with a mobility issue.

## Eligibility
Only students who have actually paid their hostel fees are allowed into the solver.
The system checks `student_profiles.is_paid = 1` or looks for a matching `status = 'paid'` in the `payments` table. If they haven't paid, they don't even enter the graph.

## Urgency Bands

| Band | Calibrated Score | What it means |
|---|---:|---|
| **High** | 75-100 | Absolute priority. Give them a clinic-proximal bed immediately. |
| **Medium** | 40-74 | Placed first in their faculty's target hostel. |
| **Low** | 0-39 | Fill whatever is left. |

## The Calibration Rules
The AI doesn't know about stairs, and it doesn't know about the Student Affairs manual override process. So I built these hard overrides:
- **Mobility-only (e.g. broken leg):** Stay in the Medium band. They don't need a clinic bed, they just need to avoid stairs.
- **High-severity illness:** Automatically bumped into the High band.
- **Illness + Mobility:** Automatically bumped to High.

## Room Placement & Accessibility (The Cool Part)

### Clinic-Proximal Targeting (High Band)
If you're in the High band, the Min-Cost Flow engine gives you a massive `+5,000,000` point bonus to get placed in the designated clinic rooms:
- **Males:** Prophet Moses Hall Blocks 1 & 2
- **Females:** Queen Esther Extension Hall Blocks 38 & 39

### Faculty-Proximal Targeting (Medium & Low)
If you're Medium or Low, you are grouped by your Faculty. 
- **Group A (Humanities, Management, Natural Sciences, Computing):**
  - Males go to Prophet Moses & Prophet Moses Extension.
  - Females go to Queen Esther & Queen Esther Extension.
- **Group B & C (Engineering, Law, Built Environment, Basic Medical Sciences):**
  - Males go to Joshua Hall.
  - Females go to Deborah Hall.

### The Accessibility Lock (Strict Enforcement)
I hardcoded strict accessibility constraints into both the Min-Cost Flow engine and the PHP assigning script:
1. **The Ground Floor Rule:** If a student is flagged with a physical disability (`Wheelchair User`, `Crutches/Walker`, `Artificial Limb`) AND their faculty puts them in a two-storey building (Joshua or Deborah), the engine mathematically **severs the paths** to the upper floors. They *must* be placed on `floor_level = 0`.
2. **The Lower Bunk (LB) Rule:** Once the engine gives them a Ground Floor room, my PHP orchestrator steps in and aggressively searches the room's bed configuration (e.g., `LB, UB, LB, UB`). It will **force** the disabled student into an `LB` bed before anyone else can claim it.

## The Math Behind the Magic (Weight Ladder)
When 3,000 students are competing for beds, the graph relies on these insane bonuses to guarantee that priority rules are never broken by sheer volume:

| Condition | Bonus Points |
|---|---:|
| High -> clinic-proximal room | +5,000,000 |
| Mobility-priority -> Joshua/Deborah first-block ground floor | +2,200,000 |
| Medium male -> Prophet Moses Extension Hall Block 27 | +1,600,000 |
| Medium -> Joshua/Deborah first-block ground floor | +1,550,000 |
| Medium -> first block of faculty-proximal hostel | +1,500,000 |
| Low -> primary faculty-proximal hostel | +900,000 |
| Low -> secondary faculty-proximal hostel | +450,000 |
| Medium -> later faculty-proximal block | +400,000 |
| Medium or Low -> clinic-proximal overflow | +150,000 |


-----
# Combined Condition Routing Logic

# Combined Mobility & Medical Condition Allocation Policy

## Overview
Students who have **BOTH** a mobility condition (e.g., wheelchair, crutches) **AND** a medical condition (e.g., sickle cell, when severity is Medium/High/Critical) are now allocated to **clinic proximity hostels** with **Lower Bunk (LB)** beds.

## Changes Made

### 1. Python Allocation Algorithm (`ml_models/allocate.py`)

#### New Helper Functions
- `student_has_medical_condition(student)`: Checks if severity is Medium, High, or Critical
- `student_has_combined_mobility_and_medical(student)`: Returns True if student has BOTH conditions
- Updated `placement_bonus()`: Gives 4.5M bonus weight for combined-condition students in clinic rooms

#### New Hard Constraint
Students with **BOTH conditions** can **ONLY** be allocated to clinic proximity rooms:
- **Males**: Prophet Moses Hall (blocks 1, 2)
- **Females**: Queen Esther Extension Hall (blocks 38, 39)

This ensures they get clinic proximity regardless of urgency band.

### 2. PHP Allocation Engine (`includes/AllocationEngine.php`)

#### Bed Assignment Logic Update
When assigning beds within a room:
- **Medical/Mobility Students**: Cannot be assigned SB (Single Bunk)
  - Searches for LB, UB, or TB beds (preferably LB)
  - SB is excluded due to inaccessibility
  
- **Standard Students**: Can take any available bed type

The logic checks:
- `mobility_status` != 'Normal Mobility'
- `severity_level` in {'Medium', 'High', 'Critical'}

## Clinic Proximity Hostels (LB Allocation Priority)

### For Males
- **Primary**: Prophet Moses Hall (blocks 1, 2)
- **Criteria**: Wheelchair User, Crutches/Walker, or Artificial Limb + Medical condition

### For Females  
- **Primary**: Queen Esther Extension Hall (blocks 38, 39)
- **Criteria**: Same as males

## Database Fields Used

1. **Student Medical Records Table** (`medical_records`)
   - `mobility_status`: 'Wheelchair User', 'Crutches/Walker', 'Artificial Limb', 'Normal Mobility'
   - `severity_level`: 'Low', 'Medium', 'High', 'Critical'

2. **Allocations Table** (`allocations`)
   - `bed_label`: 'LB' (Lower Bunk), 'TB' (Top Bunk), 'SB' (Single Bunk), 'UB' (Upper Bunk)
   - Excludes SB for medical/mobility students

## Allocation Flow

```
Student with wheelchair + sickle cell (Medium severity)
    ↓
Identified as combined_mobility_and_medical = True
    ↓
Hard constraint: Only clinic proximity rooms allowed
    ↓
Gets 4.5M bonus weight in clinic rooms
    ↓
Allocated to: Prophet Moses Hall (M) or Queen Esther Extension Hall (F)
    ↓
Assigned bed: LB (Lower Bunk, not SB)
    ↓
Result: Clinic proximity + Accessible bed
```

## Example Scenarios

### ✅ Scenario 1: Allocated Correctly
- **Student**: Male, wheelchair, sickle cell (severity=Medium)
- **Result**: Allocated to Prophet Moses Hall, Block 1 or 2, Bed A (LB)

### ✅ Scenario 2: Medical Condition Only
- **Student**: Female, no mobility issue, migraine (severity=High)  
- **Result**: NOT restricted to clinic (but may get bonus weight for clinic if high urgency)

### ✅ Scenario 3: Mobility Only
- **Student**: Male, wheelchair, no medical condition
- **Result**: Can be allocated to ground floor in Joshua Hall (current mobility rule) or clinic proximity if high urgency

### ✅ Scenario 4: Both Conditions, SB Bed Avoided
- **Student**: Female, crutches, asthma (severity=Medium)
- **Result**: Clinic proximity room with LB/TB/UB bed (NOT SB)

## Testing Recommendations

1. Test allocation with a student having:
   - mobility_status = 'Wheelchair User'
   - severity_level = 'Medium' or higher
   - Verify assigned to clinic proximity hostel

2. Test bed assignment:
   - Ensure SB is skipped for medical/mobility students
   - Verify LB is preferred

3. Verify database:
   - Check `allocations.bed_label` != 'SB' for medical students
   - Check `allocations.room_id` points to clinic proximity hostel

## Configuration

No additional settings required. The allocation follows these priorities:
1. **Hard constraints** are enforced first (gender, mobility ground floor, combined-condition clinic proximity)
2. **Soft preferences** (bonus weights) guide selection within constraints
3. **Bed assignment** respects medical/mobility exclusions

## Related Files
- `ml_models/allocate.py`: Python allocation algorithm
- `includes/AllocationEngine.php`: PHP allocation orchestrator
- `sql/schema.sql`: Database schema with bed_label enum
- `medical_records` table: Stores mobility_status and severity_level


-----
# Policy Enforcement for Combined Conditions

# Combined Mobility & Medical Condition Allocation Policy

## Overview
Students who have **BOTH** a mobility condition (e.g., wheelchair, crutches) **AND** a medical condition (e.g., sickle cell, when severity is Medium/High/Critical) are now allocated to **clinic proximity hostels** with **Lower Bunk (LB)** beds.

## Changes Made

### 1. Python Allocation Algorithm (`ml_models/allocate.py`)

#### New Helper Functions
- `student_has_medical_condition(student)`: Checks if severity is Medium, High, or Critical
- `student_has_combined_mobility_and_medical(student)`: Returns True if student has BOTH conditions
- Updated `placement_bonus()`: Gives 4.5M bonus weight for combined-condition students in clinic rooms

#### New Hard Constraint
Students with **BOTH conditions** can **ONLY** be allocated to clinic proximity rooms:
- **Males**: Prophet Moses Hall (blocks 1, 2)
- **Females**: Queen Esther Extension Hall (blocks 38, 39)

This ensures they get clinic proximity regardless of urgency band.

### 2. PHP Allocation Engine (`includes/AllocationEngine.php`)

#### Bed Assignment Logic Update
When assigning beds within a room:
- **Mobility Students**: Cannot be assigned SB (Single Bunk) or UB (Upper Bunk)
  - These require climbing a ladder, which is inaccessible for students with mobility issues
  - Gets other bed types instead (typically LB or other ground-level beds)
  
- **Medical Condition Only**: Can take any available bed type
  
- **Standard Students**: Can take any available bed type

## Clinic Proximity Hostels (LB Allocation Priority)

### For Males
- **Primary**: Prophet Moses Hall (blocks 1, 2)
- **Criteria**: Wheelchair User, Crutches/Walker, or Artificial Limb + Medical condition

### For Females  
- **Primary**: Queen Esther Extension Hall (blocks 38, 39)
- **Criteria**: Same as males

## Database Fields Used

1. **Student Medical Records Table** (`medical_records`)
   - `mobility_status`: 'Wheelchair User', 'Crutches/Walker', 'Artificial Limb', 'Normal Mobility'
   - `severity_level`: 'Low', 'Medium', 'High', 'Critical'

2. **Allocations Table** (`allocations`)
   - `bed_label`: 'LB' (Lower Bunk), 'SB' (Single Bunk), 'UB' (Upper Bunk)
   - SB and UB are excluded for students with mobility issues (requires ladder climbing)

## Allocation Flow

```
Student with wheelchair + sickle cell (Medium severity)
    ↓
Identified as combined_mobility_and_medical = True
    ↓
Hard constraint: Only clinic proximity rooms allowed
    ↓
Gets 4.5M bonus weight in clinic rooms
    ↓
Allocated to: Prophet Moses Hall (M) or Queen Esther Extension Hall (F)
    ↓
Assigned bed: NOT SB or UB (cannot climb ladder)
             Assigned to LB or other accessible bed type
    ↓
Result: Clinic proximity + Accessible (ladder-free) bed
```

## Example Scenarios

### ✅ Scenario 1: Allocated Correctly
- **Student**: Male, wheelchair, sickle cell (severity=Medium)
- **Result**: Allocated to Prophet Moses Hall, Block 1 or 2, Bed with accessible type (not SB/UB)

### ✅ Scenario 2: Medical Condition Only
- **Student**: Female, no mobility issue, migraine (severity=High)  
- **Result**: NOT restricted to clinic (but may get bonus weight for clinic if high urgency)

### ✅ Scenario 3: Mobility Only
- **Student**: Male, wheelchair, no medical condition
- **Result**: Can be allocated to ground floor in Joshua Hall (current mobility rule) or clinic proximity if high urgency

### ✅ Scenario 4: Both Conditions, SB/UB Avoided (for mobility)
- **Student**: Female, crutches, asthma (severity=Medium)
- **Result**: Clinic proximity room with LB or other ground-level bed (NOT SB or UB due to ladder climbing)

## Testing Recommendations

1. Test allocation with a student having:
   - mobility_status = 'Wheelchair User'
   - severity_level = 'Medium' or higher
   - Verify assigned to clinic proximity hostel

2. Test bed assignment for mobility students:
   - Ensure SB and UB are skipped (cannot climb ladder)
   - Verify other bed types (like LB) are assigned

3. Test bed assignment for medical-only students:
   - Verify all bed types can be assigned (no restrictions)

4. Verify database:
   - Check `allocations.bed_label` != 'SB' and != 'UB' for mobility students
   - Check `allocations.room_id` points to clinic proximity hostel for combined-condition students

## Configuration

No additional settings required. The allocation follows these priorities:
1. **Hard constraints** are enforced first (gender, mobility ground floor, combined-condition clinic proximity)
2. **Soft preferences** (bonus weights) guide selection within constraints
3. **Bed assignment** respects medical/mobility exclusions

## Related Files
- `ml_models/allocate.py`: Python allocation algorithm
- `includes/AllocationEngine.php`: PHP allocation orchestrator
- `sql/schema.sql`: Database schema with bed_label enum
- `medical_records` table: Stores mobility_status and severity_level


-----
# Final Policy Statement

# Final Policy Statement

This is the short operational policy for FairMedAlloc.

1. **The XGBoost AI** generates the base urgency score.
2. **The Calibration Layer** adjusts that score to enforce administrative rules.
3. **The Min-Cost Flow Allocator** uses that final score to perfectly match students to beds.
4. Any student in the High band is immediately prioritized for clinic-proximal placement.
5. A student with a high-severity medical condition is placed in the High band.
6. A student with both a medical condition and a mobility issue is placed in the High band.
7. A student with *only* a mobility issue is Medium by default.
8. Mobility-only cases do not automatically receive clinic-proximal rooms (to save space for severe medical cases).
9. Medium students go to the first target block in their faculty-proximal hall set.
10. Group A medium males are steered to Prophet Moses Extension Hall Block 27.
11. Medium students mapped to Joshua Hall or Deborah Hall are mathematically steered to first-block ground-floor rooms.
12. **The Ground Floor Rule:** Any mobility-priority student placed in Joshua Hall or Deborah Hall *must* stay on the ground floor.
13. **The Lower Bunk (LB) Rule:** Any mobility-priority student must be strictly assigned a Lower Bunk (LB) if one is available.
14. Student Affairs may still use the manual reassignment dashboard where a special clinic-proximal need is later justified.


