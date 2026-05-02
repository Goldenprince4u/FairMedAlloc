# Final Policy Statement

This is the short operational policy for FairMedAlloc.

1. The XGBoost model gives the base urgency score.
2. The system applies a policy calibration layer to produce the final urgency score.
3. The allocator uses that final score, not the raw model score alone.
4. Any student in the High band is prioritized for clinic-proximal placement.
5. A student with a high-severity medical condition is High.
6. A student with both a medical condition and a mobility issue is High.
7. A student with only a mobility issue is Medium by default.
8. Mobility-only cases do not automatically receive clinic-proximal rooms.
9. Medium students go to the first target in their faculty-proximal hall set.
10. Group A medium males are steered to Prophet Moses Extension Hall Block 27.
11. Medium students mapped to Joshua Hall or Deborah Hall are steered to first-block ground-floor rooms.
12. Mobility-priority students placed in Joshua Hall or Deborah Hall must stay on ground floor.
13. Student Affairs may still use manual reassignment where a special clinic-proximal need is later justified.
