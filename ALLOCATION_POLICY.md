# Allocation Policy

This document is the authoritative record of the hostel-allocation policy
implemented in FairMedAlloc.  Keep it in sync with `ml_models/allocate.py`
and `ALLOCATION_POLICY.md` whenever a policy change is made.

---

## 1. Payment Gate

Only students with cleared payment are eligible for allocation.

Payment is recognised in either of these ways:

- imported university portal data with `student_profiles.is_paid = 1`
- a local pay-simulator record with `payments.status = 'paid'`

---

## 2. Urgency Bands

| Band   | Score Range                                                              |
|--------|--------------------------------------------------------------------------|
| High   | ≥ `urgency_threshold_proximal` (default 75)                              |
| Medium | ≥ `urgency_threshold_medium` (default 40) and below the High threshold   |
| Low    | Below the Medium threshold                                               |

Thresholds are configurable in **Settings → Core Parameters**.

Both the medical **condition** and the **mobility status** feed the XGBoost
urgency score.  A wheelchair or crutch declaration **alone** (with no
separate diagnosis) is sufficient to generate a medical record and trigger
priority scoring.

---

## 3. High-Urgency — Clinic-Proximal Placement

Students in the **High** urgency band receive a +5,000,000 objective-weight
bonus for clinic-proximal rooms, guaranteeing they are seated there before
any other student.  If those rooms are completely full, the solver falls
through to the next best available room rather than failing the allocation.

### Male clinic-proximal space

| Block | Hostel             | Rule                                                                     |
|-------|--------------------|--------------------------------------------------------------------------|
| 1     | Prophet Moses Hall | **Hard-reserved exclusively for High-urgency males — never backfilled** |
| 2     | Prophet Moses Hall | High males first (+5 M), then Medium Group A (+1.2 M), then Low backfill |

### Female clinic-proximal space

| Block | Hostel                      | Rule                                                     |
|-------|-----------------------------|----------------------------------------------------------|
| 39    | Queen Esther Extension Hall  | High females first (+5 M), then Low backfill             |

---

## 4. Faculty-Proximal Hostel Mapping

Each student is mapped to one or more **faculty-proximal** hostels based on
their faculty and gender.  This mapping drives Medium-urgency placement (§5)
and provides bonus weight for Low-urgency proximity tie-breaking.

### Group A — Humanities, Management, Natural & Social Sciences, Computing

| Gender | Faculty-Proximal Hostels                          |
|--------|---------------------------------------------------|
| Male   | Prophet Moses Hall · Prophet Moses Extension Hall |
| Female | Queen Esther Hall · Queen Esther Extension Hall   |

**Faculties in this group:**
- Faculty of Humanities
- Faculty of Management Sciences
- Faculty of Natural Sciences
- Faculty of Social Sciences
- Faculty of Computing and Digital Technology

### Group B — Engineering, Law, Built Environment Studies

| Gender | Faculty-Proximal Hostels |
|--------|--------------------------|
| Male   | Joshua Hall              |
| Female | Deborah Hall             |

**Faculties in this group:**
- Faculty of Engineering
- Faculty of Law
- Faculty of Built Environment Studies

### Group C — Basic Medical Sciences

| Gender | Faculty-Proximal Hostels          |
|--------|-----------------------------------|
| Male   | Joshua Hall                       |
| Female | Deborah Hall · Queen Esther Hall  |

---

## 5. Medium-Urgency — Faculty-Proximal Placement

Students in the **Medium** urgency band receive graded objective-weight
bonuses that steer them to the best available faculty-proximal room:

| Room                                                   | Bonus     | Rationale                                             |
|--------------------------------------------------------|-----------|-------------------------------------------------------|
| First block of faculty-proximal hostel (Block 1 side)  | +1,500,000 | Ideal — closest to porters' lodge                   |
| Prophet Moses Hall Block 2 (Group A males)             | +1,200,000 | Block 1 is hard-reserved; Block 2 is the next best  |
| Any later block of the faculty-proximal hostel         | +400,000   | Same hostel, further from porters' lodge             |
| Any other room                                         | +0         | Backfill / spill-over only                           |

**First-block rule:** the first block is always the numerically lowest block
number of the hostel — it sits beside the porters' lodge.

---

## 6. Low-Urgency Students

Low-urgency students receive no placement bonus.  The solver places them in
whatever gender-matched capacity remains after all High and Medium students
have been seated.

**Key point:** Low-urgency students are *not* banned from priority blocks.
Once all High/Medium students are placed, remaining capacity in clinic
blocks, first-blocks, or any other block is freely available to Low
students.  This ensures no bed is ever left empty while eligible students
still need housing.

The **only** block from which Low-urgency students are permanently excluded
is **Prophet Moses Hall Block 1** (hard-reserved for High urgency males).

---

## 7. Backfill Rule — Option B (Soft Constraints)

The solver uses **weight-based soft preferences** rather than hard bans for
all priority rules except gender matching and Block 1 reservation.

### Weight ladder

| Condition                                         | Bonus      |
|---------------------------------------------------|------------|
| High → matching clinic room                       | +5,000,000 |
| Medium → first block of faculty-proximal hostel   | +1,500,000 |
| Medium → Prophet Moses Hall Block 2 (Group A M)   | +1,200,000 |
| Medium → any later block of faculty-proximal hostel | +400,000  |
| Low → any remaining capacity (backfill)           | +0         |

The weight gap between levels is so large that a Low student's maximum
score contribution (`score × 100 ≈ 10,000`) cannot compete with any
priority bonus.  Priority students will therefore always be placed first.
After that, the solver fills every remaining bed with Low students — zero
wasted capacity.

### Hard constraints (only two remain)

1. **Gender match** — absolute, enforced for every (student, room) pair.
2. **Prophet Moses Hall Block 1** — exclusively for High-urgency males.
   Partially empty beds here are *not* backfilled.

---

## 8. Scoring Model

The XGBoost `.pkl` model expects a fixed 9-feature vector:

| Feature                 | Source                                                    |
|-------------------------|-----------------------------------------------------------|
| `has_asthma`            | `condition_category` == "Asthma" or "Respiratory"        |
| `has_epilepsy`          | `condition_category` == "Epilepsy"                       |
| `has_ulcer`             | `condition_category` == "Ulcer"                          |
| `has_sickle_cell`       | `condition_category` == "Sickle Cell Disease"            |
| `has_cardiac_issue`     | `condition_category` == "Cardiovascular" / "Cardiac"     |
| `has_visual_impairment` | `condition_category` == "Visual Impairment"              |
| `has_physical_disability` | `condition_category` == "Physical Disability" / "Orthopaedic" |
| `mobility_score`        | `mobility_status` mapped 0–3                             |
| `severity_score`        | `severity_level` mapped Low=1 / Medium=2 / High=3        |

The model is **never mutated** by the application.  `predict.py` acts as a
pure adapter, mapping web-app inputs to this fixed schema.  If the model
cannot score a record, `UrgencyScoreService::calculateFallbackScore()`
applies rule-based weights as a fallback.

---

## 9. Implementation Entry Points

| Layer                   | File                                   |
|-------------------------|----------------------------------------|
| PHP orchestration       | `includes/AllocationEngine.php`        |
| OR-Tools CP-SAT solver  | `ml_models/allocate.py`                |
| XGBoost scoring adapter | `ml_models/predict.py`                 |
| PHP scoring fallback    | `includes/UrgencyScoreService.php`     |
| Admin settings          | `settings.php`                         |
| Student registration    | `signup.php`                           |
| Admin explanation       | `help.php`                             |
