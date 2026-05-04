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
