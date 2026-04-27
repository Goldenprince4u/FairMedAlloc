# Allocation Policy

This document records the current hostel-allocation policy implemented in the
FairMedAlloc system.

## 1. Payment Gate

Only students with cleared payment are eligible for allocation.

Payment can be recognised in either of these ways:

- imported university portal data with `student_profiles.is_paid = 1`
- a local pay-simulator record with `payments.status = 'paid'`

## 2. Urgency Bands

- `High`: urgency score greater than or equal to the configured proximal threshold
- `Medium`: urgency score greater than or equal to the configured medium threshold and below the high threshold
- `Low`: urgency score below the configured medium threshold

## 3. Clinic-Proximal Rule

### Hard Constraint

Any student in the `High` urgency band must be allocated to clinic-proximal
space regardless of faculty.

### Current Male Clinic Block

- `Prophet Moses Hall Block 1`

### Current Female Clinic Space

Until a dedicated female clinic block is chosen, the system uses the existing
female proximal hostel inventory as female clinic-proximal space.

## 4. Clinic-Proximal Faculties

Students from the following faculties are treated as clinic-proximal for the
temporary `Medium` rule and for clinic-space backfill:

- Faculty of Humanities
- Faculty of Management Sciences
- Faculty of Natural Sciences
- Faculty of Social Sciences
- Faculty of Computing and Digital Technology

The following faculties are **not** clinic-proximal:

- Faculty of Basic Medical Sciences
- Faculty of Engineering
- Faculty of Law
- Faculty of Built Environment Studies

## 5. Medium-Urgency Temporary Rule

Students in the `Medium` urgency band are sent to clinic proximity only if they
belong to one of the clinic-proximal faculties listed above.

This remains a temporary policy until a dedicated medium-urgency block is
specified.

## 6. Remaining Students

After payment and urgency rules are applied:

- other students are allocated to the remaining valid halls
- randomness is used only as a tie-break between equally valid options

## 7. Unfilled Clinic Rooms

If clinic-proximal rooms remain unfilled after `High` and eligible `Medium`
students are placed, those rooms may be backfilled by students from the
clinic-proximal faculties.

## 8. Implementation Notes

Current implementation entry points:

- PHP orchestration: `includes/AllocationEngine.php`
- solver rules: `ml_models/allocate.py`
- admin-facing explanation: `help.php`
