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
