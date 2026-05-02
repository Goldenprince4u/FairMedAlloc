# Allocation Policy

This document is the authoritative description of the allocation policy implemented in FairMedAlloc.

## Final Policy Statement

1. The XGBoost model produces the base urgency score.
2. The application applies a policy calibration layer to that score.
3. The allocator bands students from the calibrated score and assigns rooms from those bands.
4. High-severity medical cases and medical plus mobility cases are clinic-proximal priorities.
5. Mobility-only cases remain Medium by default unless Student Affairs later approves a manual relocation.

## Eligibility

Only paid students who are still unallocated are considered.

Payment is recognized through:

- `student_profiles.is_paid = 1`
- or a `payments.status = 'paid'` record

## Urgency Bands

| Band | Score range |
|---|---:|
| High | 75-100 |
| Medium | 40-74 |
| Low | 0-39 |

## Scoring Policy

### Base score

The model scores students from structured medical features:

- medical-condition flags
- mobility score
- severity score

### Calibration layer

The application then calibrates the raw score using the following operational rules.

#### Mobility-only

- Mobility-only students remain in the Medium band by default.
- Their final score still reflects severity and mobility type, but is capped below the High threshold.
- They do not automatically receive clinic-proximal placement.

#### High-severity medical

- A student with a medical condition and `High` severity is lifted into the High band if the raw model score was lower.

#### Medical plus mobility

- A student with both a medical condition and a mobility-priority status is lifted into the High band.

### Why calibration exists

The model does not know about:

- clinic-proximal halls
- accessibility-safe block choices
- stairs in Joshua and Deborah
- Student Affairs manual review

Those are policy concerns, not model concerns.

## High-Band Placement

High-band students receive the strongest clinic-proximal priority.

### Male clinic-proximal space

- Prophet Moses Hall Block 1
- Prophet Moses Hall Block 2

Rule:

- Prophet Moses Hall Block 1 is High-only and never backfilled.

### Female clinic-proximal space

- Queen Esther Extension Hall Block 38
- Queen Esther Extension Hall Block 39

## Medium-Band Placement

Medium students are placed into faculty-proximal halls with additional accessibility-aware targeting.

### Standard medium rule

- Use the first block of the faculty-proximal hostel.

### Group A male override

For Humanities, Management Sciences, Natural Sciences, Social Sciences, and Computing:

- keep Prophet Moses Extension Hall Block 26 as foundation-only
- do not target Prophet Moses Hall Block 1
- steer medium males to Prophet Moses Extension Hall Block 27

This is the preferred medium-priority male target under the current accessibility policy.

### Joshua and Deborah rule

Where the faculty-proximal hall is Joshua Hall or Deborah Hall:

- target the first block ground floor

This applies because those halls are the stair-sensitive two-storey halls in the system.

## Low-Band Placement

Low-band students:

- prefer their faculty-proximal halls first
- may use clinic-proximal overflow if proximal capacity is exhausted
- may use any remaining valid room as a last resort

## Mobility Restrictions

Mobility-priority statuses:

- `Wheelchair User`
- `Crutches/Walker`
- `Artificial Limb`

Hard rule:

- if a mobility-priority student is placed in Joshua Hall or Deborah Hall, the room must be on ground floor

## Solver Weight Ladder

| Rule | Bonus |
|---|---:|
| High -> clinic-proximal room | 5,000,000 |
| Mobility-priority -> Joshua/Deborah first-block ground floor | 2,200,000 |
| Medium male -> Prophet Moses Extension Hall Block 27 | 1,600,000 |
| Medium -> Joshua/Deborah first-block ground floor | 1,550,000 |
| Medium -> first block of faculty-proximal hostel | 1,500,000 |
| Low -> primary faculty-proximal hostel | 900,000 |
| Low -> secondary faculty-proximal hostel | 450,000 |
| Medium -> later faculty-proximal block | 400,000 |
| Medium or Low -> clinic-proximal overflow | 150,000 |

## Hard Constraints

1. Gender must match the hostel.
2. Prophet Moses Hall Block 1 is High-only.
3. Mobility-priority students in Joshua Hall and Deborah Hall must stay on ground floor.

## Faculty Mapping

### Group A

Faculties:

- Faculty of Humanities
- Faculty of Management Sciences
- Faculty of Natural Sciences
- Faculty of Social Sciences
- Faculty of Computing and Digital Technology

Targets:

- Male: Prophet Moses Hall, Prophet Moses Extension Hall
- Female: Queen Esther Hall, Queen Esther Extension Hall

### Group B

Faculties:

- Faculty of Engineering
- Faculty of Law
- Faculty of Built Environment Studies

Targets:

- Male: Joshua Hall
- Female: Deborah Hall

### Group C

Faculty:

- Faculty of Basic Medical Sciences

Targets:

- Male: Joshua Hall
- Female: Deborah Hall, Queen Esther Hall

## Data and Migration Notes

To make the medium-priority male override reachable, Prophet Moses Extension Hall Block 27 must be available to undergraduate allocation.

Relevant files:

- `sql/seed.php`
- `sql/20260502c_enable_pme_block27_for_undergrad.sql`

## Implementation Entry Points

- Scoring bridge: `includes/UrgencyScoreService.php`
- Raw model adapter: `ml_models/predict.py`
- Solver: `ml_models/allocate.py`
- PHP fallback allocator: `includes/AllocationEngine.php`
