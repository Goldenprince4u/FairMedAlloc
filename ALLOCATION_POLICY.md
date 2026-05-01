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

| Block | Hostel                       | Rule                                                              |
|-------|------------------------------|-------------------------------------------------------------------|
| 38    | Queen Esther Extension Hall  | High females first (+5 M), then Medium/Low overflow (+150 K)      |
| 39    | Queen Esther Extension Hall  | High females first (+5 M), then Medium/Low overflow (+150 K)      |

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

Low-urgency students receive **proximity bonuses** that steer them to their
faculty-proximal halls before filling any other available space:

| Destination                                | Bonus    |
|--------------------------------------------|----------|
| Primary faculty-proximal hostel (rank 0)   | +900,000 |
| Secondary faculty-proximal hostel (rank 1) | +450,000 |
| Clinic-proximal room (overflow only)       | +150,000 |
| Any other gender-matching room             | +0       |

The overflow bonus (150 K) ensures that when a faculty-proximal hall is
completely full, Low students flow into clinic-proximal rooms rather than
sitting unallocated.  No bed is ever left empty while an eligible student
needs housing.

The **only** block from which Low-urgency students are permanently excluded
is **Prophet Moses Hall Block 1** (hard-reserved for High urgency males).

---

## 7. Soft-Constraint Weight Ladder

The solver uses **weight-based soft preferences** for all placement rules
except gender matching, Block 1 reservation, and the Joshua/Deborah
ground-floor mobility constraint.

### Full weight table

| Condition                                          | Bonus      |
|----------------------------------------------------|------------|
| High → matching clinic-proximal room               | +5,000,000 |
| Mobility-priority → Joshua/Deborah ground floor    | +2,200,000 |
| Medium → first block of faculty-proximal hostel    | +1,500,000 |
| Medium → Prophet Moses Hall Block 2 (Group A male) | +1,200,000 |
| Low → primary faculty-proximal hostel (rank 0)     | +900,000   |
| Low → secondary faculty-proximal hostel (rank 1)   | +450,000   |
| Medium → any later block of faculty-proximal hostel| +400,000   |
| Medium or Low → clinic-proximal room (overflow)    | +150,000   |
| Any other gender-matching room (last resort)       | +0         |

The gap between tiers is large enough that a student's base urgency
contribution (`score × 100 ≈ 10,000`) cannot override any placement bonus.
Priority students are always placed first; remaining capacity is filled by
Lower-band students — zero wasted beds.

### Hard constraints (three total)

1. **Gender match** — absolute for every (student, room) pair.
2. **Prophet Moses Hall Block 1** — exclusively for High-urgency males.  Never backfilled.
3. **Joshua Hall / Deborah Hall upper floor** — mobility-priority students (`Wheelchair User`, `Crutches/Walker`, `Artificial Limb`) may **only** be assigned to floor 0 (ground floor) in these two hostels.  All other hostels are single-storey so this constraint does not apply.

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

---

## 10. Policy Update History

### 2026-04-30
- Low-urgency students are now allocated inside their faculty-proximal halls instead of being sent to arbitrary spare halls.
- Joshua Hall and Deborah Hall are the only two-storey hostels (staircase access only; no elevator). All other hostels are single-storey ground-floor structures.
- Mobility-priority students (`Wheelchair User`, `Crutches/Walker`, `Artificial Limb`) are restricted to ground floor (floor 0) when placed in Joshua Hall or Deborah Hall.
- The main OR-Tools run now covers the **full eligible cohort** — the separate greedy backfill phase has been removed entirely.

### 2026-05-01
- **Mobility score floor:** `predict.py` now guarantees a minimum urgency score of **76.0** for any mobility-priority student, placing them in the **High** urgency band regardless of the XGBoost model output. The DB score cache is bypassed for these students to prevent stale Low scores from persisting after a mobility status update.
- **Clinic-proximal overflow:** Medium and Low students may now spill into clinic-proximal rooms (+150,000 bonus) when all faculty-proximal blocks are at capacity, ensuring zero empty beds.
- **Low-urgency proximity bonuses:** +900,000 for primary proximal hall, +450,000 for secondary — replacing the old hard filter that left Engineering/Law students unallocated when Joshua/Deborah was full.
- **Queen Esther Hall block numbering:** Blocks extended to 1–37 (adding blocks 33–37 with 28 rooms each, 116 beds/block).  Queen Esther Extension Hall renumbered to blocks 38–42; blocks 38 and 39 are clinic-proximal.
- `run_greedy()` deleted from `allocate.py`; duplicate `placement_bonus()` definition removed.
