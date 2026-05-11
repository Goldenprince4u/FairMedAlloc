# FairMedAlloc — Technical Audit Summary (Executive Brief)

## Overall Assessment
- **Score:** 65/100 — Operational but Risky
- **Status:** Combined condition feature 85% complete; critical validation gaps
- **Immediate Action:** Add post-solver validation + database constraints

## Critical Issues (Must Fix)

### 🔴 Issue #1: Missing Post-Solver Validation (HIGH)
**Location:** `includes/AllocationEngine.php` line ~280-350
**Problem:** PHP assumes OR-Tools output respects combined-condition clinic-only constraint; no re-validation
**Impact:** Combined-condition students could be allocated to non-clinic rooms
**Fix Time:** 2 hours
```php
// ADD VALIDATION AFTER LINE 280:
if ($this->hasCombinedConditions($student) && !$this->isClinicProximityRoom($room_id)) {
    throw new Exception("OR-Tools violated clinic-proximity constraint for student {$student_id}");
}
```

### 🔴 Issue #2: No Database Constraint for Clinic Proximity (HIGH)
**Location:** `schema.sql`, `hostels` table
**Problem:** Clinic-proximity enforced only in Python code; manual/API inserts bypass validation
**Impact:** Allocation integrity at database level is violated
**Fix Time:** 3 hours
**Solution:** Add trigger to prevent non-clinic allocations for combined-condition students

### 🔴 Issue #3: Race Condition in Room Occupancy (HIGH)
**Location:** `includes/AllocationEngine.php` line ~170 (`syncRoomOccupancy()`)
**Problem:** Concurrent allocation jobs can overbook rooms (occupied_count race condition)
**Impact:** Multiple students assigned to same room violates capacity
**Fix Time:** 4 hours
**Current Mitigation:** Async queue ensures sequential execution (good), but manual bypass possible

---

## Medium-Risk Issues

### 🟡 Issue #4: Severity Mapping Inconsistency
- **Python:** Returns severity as 0-3
- **PHP:** Maps to 1-4 including 'Critical'
- **Impact:** Audit logs have inconsistent severity encoding
- **Fix Time:** 2 hours

### 🟡 Issue #5: Bed Assignment Constraint Violation
- **Problem:** Mobility students forced to waitlist if only SB/UB beds available (wasted capacity)
- **Example:** Room with [LB-occupied, SB-empty, UB-empty, LB-occupied] → mobility student gets no bed
- **Fix Time:** 3 hours

### 🟡 Issue #6: Silent Fallback Scoring
- **Problem:** If XGBoost service down, silently uses stale stored scores (no audit flag)
- **Impact:** Allocation results use different models without logging
- **Fix Time:** 2 hours

---

## Test Coverage: **15%** (Critical Gaps)

### Missing Critical Tests
- ✗ Combined-condition clinic-only allocation validation
- ✗ Bed assignment mobility constraints (SB/UB exclusion)
- ✗ Concurrent allocation race condition detection
- ✗ Fallback score consistency vs. XGBoost
- ✗ Clinic-proximity block definition integrity

**Recommended:** Add 20-30 unit/integration tests (8 hours effort)

---

## Python-PHP Inconsistencies

| Data Type | Python | PHP | Issue |
|-----------|--------|-----|-------|
| Severity | Int (0-3) | String ('Low','Medium','High','Critical') | Type mismatch |
| Clinic Blocks | Hardcoded in code | Hardcoded in code | Logic duplication |
| Fallback Score | Rule-based model | Rule-based model | Slight algorithm differences |

---

## Database Integrity Warnings

### Missing Constraints
1. ✗ No check: `is_clinic_proximity` enforced at allocation insert
2. ✗ No trigger: Prevents combined-condition non-clinic assignments
3. ✗ No lock: Prevents concurrent room overbooking
4. ✗ No uniqueness: Clinic-proximity hostel definitions

### Queries to Run Regularly
```sql
-- Detect combined-condition students in wrong hostels
SELECT * FROM allocations a
JOIN medical_records m ON a.student_id = m.student_id
WHERE m.mobility_status IN ('Wheelchair User','Crutches/Walker','Artificial Limb')
  AND m.severity_level IN ('Medium','High')
  AND NOT (hostel like 'Prophet Moses%' OR hostel like 'Queen Esther%');

-- Detect overbooking
SELECT * FROM rooms WHERE occupied_count > capacity;

-- Detect mobility students in upper bunks
SELECT * FROM allocations a
JOIN medical_records m ON a.student_id = m.student_id
WHERE m.mobility_status IN ('Wheelchair User','Crutches/Walker','Artificial Limb')
  AND a.bed_label IN ('SB','UB');
```

---

## Remediation Priority (by Impact)

### Week 1 (Critical)
1. Add post-solver validation (2h)
2. Add database constraint trigger (3h)
3. Fix concurrent room occupancy race condition (4h)
4. Document clinic-proximity in PHP code (1h)
5. Track fallback scoring in audit logs (2h)

**Total: 12 hours**

### Week 2 (High)
6. Unify severity encoding (3h)
7. Build unit/integration tests (8h)
8. Fix transaction rollback error handling (1h)

**Total: 12 hours**

### Week 3+ (Medium)
9. Separate policy from code (configuration-driven)
10. Add time-series audit trail
11. Performance optimization (pagination, query optimization)

---

## Files to Review/Fix

**Priority:**
- [ ] [includes/AllocationEngine.php](includes/AllocationEngine.php) — Add validation
- [ ] [sql/schema.sql](sql/schema.sql) — Add constraints
- [ ] [ml_models/allocate.py](ml_models/allocate.py) — Document block definitions
- [ ] [includes/UrgencyScoreService.php](includes/UrgencyScoreService.php) — Fix fallback tracking

**Reference:**
- [TECHNICAL_AUDIT_REPORT.md](TECHNICAL_AUDIT_REPORT.md) — Full detailed audit

---

## Key Findings Summary

### ✅ What Works Well
1. Python solver correctly identifies and prioritizes combined-condition students
2. Bed assignment respects mobility constraints (excludes SB/UB for wheelchair users)
3. Async queue prevents timeout on large-scale allocations (15,000+ students)
4. Fallback scoring balances multiple priority signals
5. Transaction isolation prevents partial allocations

### ⚠️ What Needs Attention
1. **No post-allocation validation** — assumes OR-Tools always outputs valid assignments
2. **Policy enforcement in code only** — database has no constraints
3. **Race conditions possible** — concurrent allocations could overbook
4. **Missing test coverage** — < 20% of critical paths tested
5. **Inconsistent severity encoding** — PHP and Python use different formats

### 🔴 Critical Gaps
1. Combined-condition clinic-proximity constraint not re-validated in PHP
2. No database-level enforcement of allocation policy
3. Room capacity can be exceeded in concurrent scenarios
4. No audit trail of which students used fallback scoring

---

## Estimated Fix Timeline

- **Critical (Week 1):** 12-15 hours → System becomes compliant
- **High Priority (Week 2):** 10-12 hours → System becomes maintainable
- **Medium Priority (Weeks 3-4):** 15-20 hours → System becomes scalable

**Total Effort:** ~40-50 hours for complete remediation

---

**Report Generated:** May 11, 2026
**Full Report:** [TECHNICAL_AUDIT_REPORT.md](TECHNICAL_AUDIT_REPORT.md)
