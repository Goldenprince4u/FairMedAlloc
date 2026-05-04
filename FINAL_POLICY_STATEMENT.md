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
