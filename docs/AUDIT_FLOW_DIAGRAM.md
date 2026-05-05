# FairMedAlloc — Architectural Flow Diagram & Audit Findings

## Complete Data Flow: Student to Room Allocation

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        STUDENT DATA INGESTION                               │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  users (user_id, role)                                                      │
│    ├─ student_profiles (gender, level, department, is_paid)                 │
│    ├─ medical_records (condition_category, mobility_status, severity_level) │
│    ├─ payments (status: paid/pending)                                       │
│    └─ allocations (IF EXISTS)                                               │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                     STAGE 1: PHP ORCHESTRATION                              │
│                   (includes/AllocationEngine.php)                           │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  1. Sync Room Occupancy                                                      │
│     UPDATE rooms SET occupied_count = (SELECT COUNT(*) FROM allocations)    │
│     ⚠️ RACE CONDITION: Concurrent jobs can read stale counts                │
│                                                                              │
│  2. Fetch Eligible Students (WHERE allocated_status='Unallocated' AND paid) │
│     ✓ Correct filtering                                                     │
│                                                                              │
│  3. Score via ML Service                                                     │
│     - Try XGBoost: predictBatchScores()                                     │
│     - Fallback: PHP rule-based (calculateFallbackScore)                     │
│     ⚠️ NO AUDIT: Which students used fallback not logged                    │
│                                                                              │
│  4. Classify Urgency Bands                                                   │
│     High:   score >= 75.0                                                   │
│     Medium: score >= 40.0                                                   │
│     Low:    score < 40.0                                                    │
│                                                                              │
│  5. Build CSV Payloads (temp files)                                         │
│     students.csv: [id, gender, faculty, score, mobility, severity, band]    │
│     rooms.csv:    [id, hostel_id, gender, capacity, hostel_name, block]    │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                STAGE 2: PYTHON SOLVER (ml_models/allocate.py)               │
│                      OR-Tools Min-Cost Flow Graph                           │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  Build Weighted Bipartite Graph:                                            │
│                                                                              │
│    Student Nodes (0-N)  ──┐  Hard Constraints:                              │
│    ↓                      │  • Gender matching                              │
│  Room Nodes (N-2N)   ─────┼  • Mobility ground-floor access                 │
│    ↓                      │  • Combined-condition clinic-ONLY ✓             │
│  Waitlist Node ───────────┘                                                 │
│    ↓                                                                         │
│  Sink                                                                        │
│                                                                              │
│  Arc Weights = base_score + placement_bonus + random(0,99)                  │
│                                                                              │
│  placement_bonus:                                                            │
│  • Combined condition in clinic: 4,500,000 ✓                                │
│  • High urgency in clinic: 5,000,000                                        │
│  • Mobility ground-floor target: 2,200,000                                  │
│  • Other faculty-proximal: 400k-1.5M                                        │
│                                                                              │
│  OR-Tools solver outputs:                                                    │
│  [student_id → room_id] mapping (CSV)                                       │
│  Status: OPTIMAL | FEASIBLE | INFEASIBLE                                    │
│                                                                              │
│  ✓ Correctly identifies combined-condition students                         │
│  ✓ Enforces clinic-only allocation in constraint graph                      │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│              STAGE 3: PHP ALLOCATION WRITING (CRITICAL GAP)                 │
│                 (includes/AllocationEngine.php:280-350)                     │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  For each assignment from OR-Tools:                                         │
│                                                                              │
│    1. Fetch room capacity & bed config                                      │
│    2. Find available bed slot                                               │
│       ├─ If mobility student: Skip SB/UB (can't climb ladder) ✓             │
│       └─ Else: Take any available bed                                       │
│    3. Generate allocation record                                            │
│    4. Bulk insert to allocations table                                      │
│                                                                              │
│  🔴 CRITICAL GAPS:                                                          │
│    ✗ NO VALIDATION: Is this room in clinic proximity?                       │
│    ✗ NO RE-CHECK: Does assignment satisfy combined-condition constraint?    │
│    ✗ NO FALLBACK: What if OR-Tools violates constraints?                    │
│                                                                              │
│  ⚠️ SCENARIO: OR-Tools bug or constraint slipped                            │
│     → Combined-condition student assigned to non-clinic room                │
│     → PHP blindly accepts and commits                                       │
│     → Constraint violation in database                                      │
│                                                                              │
│  TRANSACTION HANDLING:                                                       │
│    ✓ Begin transaction                                                      │
│    ✓ Bulk insert allocations (500 per query)                                │
│    ✓ Update student_profiles.allocation_status                              │
│    ✓ Insert audit logs                                                      │
│    ✓ Insert notifications                                                   │
│    ✓ Update rooms.occupied_count                                            │
│    ✓ Commit                                                                 │
│    ⚠️ Rollback error handling missing                                       │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                     STAGE 4: NOTIFICATIONS & AUDIT                          │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  Generate notification messages:                                            │
│  ✓ Success: "You have been allocated to [hostel_name]"                      │
│  ✓ Waitlist: "You have been placed on the waiting list"                     │
│                                                                              │
│  Write audit logs (algorithm_audit_logs):                                    │
│  ✓ student_id, severity, proximity_need, urgency_score                      │
│  ✓ allocation_decision (Allocated | Waitlisted | No Bed)                    │
│  ⚠️ Missing: Which students used fallback scoring                           │
│  ⚠️ Missing: Which students violated combined-condition constraint          │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Combined Condition Feature: Expected vs Actual

### ✅ WORKING: Python Solver Logic

```
STUDENT DATA:
├─ mobility_status = 'Wheelchair User'
├─ severity_level = 'Medium'
└─ urgency_band = 'High'

PYTHON PROCESSING:
├─ Identifies: has_combined_mobility_and_medical() = TRUE
├─ Hard constraint: ONLY clinic proximity rooms available
│  ├─ Males: Prophet Moses Hall, Blocks 1-2 only
│  └─ Females: Queen Esther Extension Hall, Blocks 38-39 only
├─ Placement bonus: 4,500,000 (highest priority)
└─ OR-Tools: Forces allocation to clinic room (or waitlist if full)

EXPECTED RESULT:
└─ room_id points to clinic-proximal hostel ✓
```

### ⚠️ BROKEN: PHP Validation

```
PHP RECEIVES: room_id from OR-Tools (assumed valid)

PHP PROCESSING:
├─ No check: Is this room in clinic proximity?
├─ No check: Does it match gender?
├─ No check: Does it satisfy constraints?
└─ BLINDLY COMMITS to database

RISK SCENARIO:
├─ OR-Tools has bug: outputs non-clinic room
├─ PHP doesn't catch it: proceeds to allocate
└─ Result: Constraint violation in database (undetected)

ADDITIONAL RISK: NO TRIGGER in DB to catch violation
├─ Manual insert can bypass validation
├─ No computed column flags violations
└─ No audit trail of what went wrong
```

---

## Issue Severity Matrix

```
┌──────────────────────────────────────────┬──────────┬──────────┐
│ Issue                                    │ Severity │ Fix Time │
├──────────────────────────────────────────┼──────────┼──────────┤
│ Post-solver validation gap               │ 🔴 HIGH │ 2h       │
│ No DB constraint for clinic-proximity    │ 🔴 HIGH │ 3h       │
│ Room occupancy race condition            │ 🔴 HIGH │ 4h       │
│ Severity encoding inconsistency          │ 🟡 MED  │ 2h       │
│ Bed constraint can waste capacity        │ 🟡 MED  │ 3h       │
│ Silent fallback scoring                  │ 🟡 MED  │ 2h       │
│ Transaction rollback error handling      │ 🟡 MED  │ 1h       │
│ CSV injection (admin-only)               │ 🟢 LOW  │ 1h       │
│ N+1 query pattern (mitigated)            │ 🟢 LOW  │ 2h       │
│ Field type inconsistencies               │ 🟢 LOW  │ 1h       │
├──────────────────────────────────────────┼──────────┼──────────┤
│ TOTAL CRITICAL (Week 1)                  │ 🔴      │ 9h       │
│ TOTAL MEDIUM (Week 2)                    │ 🟡      │ 8h       │
│ TOTAL LOW (Week 3+)                      │ 🟢      │ 4h       │
└──────────────────────────────────────────┴──────────┴──────────┘
```

---

## Database Integrity Verification Checklist

Run these queries to audit allocation correctness:

```sql
-- 1. Combined-condition students in WRONG hostels (CRITICAL)
SELECT COUNT(*) as violations
FROM allocations a
JOIN medical_records m ON a.student_id = m.student_id
JOIN rooms r ON a.room_id = r.room_id
JOIN hostels h ON r.hostel_id = h.hostel_id
WHERE m.mobility_status IN ('Wheelchair User','Crutches/Walker','Artificial Limb')
  AND m.severity_level IN ('Medium','High')
  AND (h.name NOT IN ('Prophet Moses Hall','Queen Esther Extension Hall')
   OR h.block_name NOT IN ('1','2','38','39','40','41','42'));
-- Expected: 0 violations

-- 2. Mobility students in non-accessible beds (SB/UB)
SELECT COUNT(*) as violations
FROM allocations a
JOIN medical_records m ON a.student_id = m.student_id
WHERE m.mobility_status IN ('Wheelchair User','Crutches/Walker','Artificial Limb')
  AND a.bed_label IN ('SB','UB');
-- Expected: 0 violations

-- 3. Room capacity exceeded
SELECT COUNT(*) as overbooking_violations
FROM rooms r
LEFT JOIN allocations a ON r.room_id = a.room_id
GROUP BY r.room_id
HAVING COUNT(a.allocation_id) > r.capacity;
-- Expected: 0 violations

-- 4. Student assigned to multiple rooms
SELECT student_id, COUNT(*) as assignment_count
FROM allocations
GROUP BY student_id
HAVING COUNT(*) > 1;
-- Expected: 0 violations

-- 5. Orphaned allocations (room deleted but allocation remains)
SELECT COUNT(*) as orphans
FROM allocations a
WHERE NOT EXISTS (SELECT 1 FROM rooms r WHERE r.room_id = a.room_id);
-- Expected: 0 violations
```

---

## Remediation Road Map

### Phase 1: Critical (Week 1) — System Compliance
- [ ] Add post-solver validation for clinic-proximity constraint
- [ ] Add database trigger for combined-condition constraint
- [ ] Fix concurrent room occupancy race condition
- [ ] Add fallback scoring audit trail

**Effort:** 9-10 hours
**Tests:** Run integrity verification queries above

### Phase 2: High Priority (Week 2) — Code Quality
- [ ] Unify severity encoding (Python + PHP)
- [ ] Improve bed assignment constraint handling
- [ ] Fix transaction rollback error handling
- [ ] Build unit/integration test suite

**Effort:** 10-12 hours
**Tests:** Add 20-30 unit/integration tests

### Phase 3: Medium Priority (Weeks 3-4) — Maintainability
- [ ] Move clinic-proximity definitions to configuration
- [ ] Add time-series audit trail for allocation decisions
- [ ] Implement row-level locking for concurrent safety
- [ ] Add explainability layer for fairness

**Effort:** 15-20 hours
**Tests:** End-to-end stress testing with 10k+ students

---

## Key Implementation Learnings

### ✅ What Works Well
1. **Python OR-Tools solver** correctly identifies combined-condition students
2. **Async queue architecture** prevents HTTP timeouts on large allocations
3. **Transaction isolation** prevents partial allocations
4. **Fallback scoring** gracefully degrades when ML service unavailable
5. **Bed configuration flexibility** handles various room types (4-8 beds)

### ⚠️ What Needs Redesign
1. **Policy enforcement** should be database-level, not code-level
2. **Validation** should be layered (Python → PHP → Database)
3. **Audit trails** should capture decision-making rationale
4. **Concurrency control** should use explicit locks, not assumptions
5. **Testing** needs comprehensive coverage of edge cases

### 🔴 What's Risky
1. **Post-solver validation gap** between Python and PHP
2. **Race conditions** in concurrent room allocation
3. **Silent fallbacks** without audit trail
4. **No database constraints** enforcing business rules
5. **Low test coverage** (< 20%) on critical paths

---

**Full Technical Audit:** [TECHNICAL_AUDIT_REPORT.md](TECHNICAL_AUDIT_REPORT.md)
**Executive Summary:** [AUDIT_SUMMARY.md](AUDIT_SUMMARY.md)
