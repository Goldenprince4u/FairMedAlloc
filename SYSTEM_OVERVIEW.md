# FairMedAlloc System Overview

## Purpose

FairMedAlloc allocates hostel rooms using three layers:

1. raw urgency scoring from XGBoost
2. policy calibration for operational fairness
3. room assignment through OR-Tools

The key design decision is that the model does not directly decide building layout. It only estimates urgency. Hostel-specific rules stay in policy and allocation logic.

## Architecture

```text
Admin UI
  -> api/admin_api.php
  -> includes/AllocationEngine.php
      -> includes/UrgencyScoreService.php
          -> ml_models/predict.py
          -> ml_models/ml_service.py (optional)
      -> ml_models/allocate.py
  -> MySQL
```

## Scoring Flow

### Raw model

The pickle model is an `XGBRegressor` with 9 features:

- `has_asthma`
- `has_epilepsy`
- `has_ulcer`
- `has_sickle_cell`
- `has_cardiac_issue`
- `has_visual_impairment`
- `has_physical_disability`
- `mobility_score`
- `severity_score`

The training data reference is `ml_models/hostel_medical_dataset.csv`.

### Policy calibration

After XGBoost returns the raw score, the application calibrates it to match the live hostel policy:

- mobility-only cases remain Medium by default
- high-severity medical cases become High
- medical plus mobility cases become High
- mobility-only scores are capped below the High threshold

This keeps clinic-proximal space for stronger medical need while still protecting accessibility cases.

### Final bands

| Band | Score |
|---|---:|
| High | 75-100 |
| Medium | 40-74 |
| Low | 0-39 |

These are the same bands the allocator uses.

## Allocation Flow

1. Fetch paid, unallocated students.
2. Score each student through the model path.
3. Persist updated urgency scores.
4. Build student and room CSV payloads.
5. Solve placement through OR-Tools.
6. Write allocations, audit logs, and notifications.

The PHP fallback allocator mirrors the same weight policy when OR-Tools is unavailable.

## Placement Policy

### High

- High students are strongly prioritized for clinic-proximal rooms.
- Male clinic-proximal space is Prophet Moses Hall Blocks 1 and 2.
- Female clinic-proximal space is Queen Esther Extension Hall Blocks 38 and 39.
- Prophet Moses Hall Block 1 remains hard-reserved for High-urgency males only.

### Medium

- Medium students are directed to faculty-proximal halls.
- Group A medium males are steered to Prophet Moses Extension Hall Block 27.
- Medium students mapped to Joshua or Deborah are steered to the first-block ground floor.
- Other medium students are steered to the first block of their faculty-proximal hostel.

### Low

- Low students prefer their faculty-proximal halls first.
- If that space is exhausted, they can overflow into clinic-proximal rooms.

## Accessibility Rules

### Mobility-only

Mobility-only does not automatically mean clinic-proximal. These students remain Medium by default and can later be manually relocated by Student Affairs if an exceptional clinic-proximal need is confirmed.

### Two-storey halls

Only Joshua Hall and Deborah Hall are treated as two-storey mobility-sensitive halls. Any mobility-priority student placed there must be kept on ground floor.

### Prophet Moses Extension Hall Block 27

Block 27 is now part of the undergraduate allocation path for medium-priority Group A males. A migration enables this by clearing its former foundation-only exclusion.

## Weight Model

The OR-Tools objective uses large placement bonuses:

| Rule | Bonus |
|---|---:|
| High -> clinic-proximal match | 5,000,000 |
| Mobility-priority -> Joshua/Deborah first-block ground floor | 2,200,000 |
| Medium male -> Prophet Moses Extension Hall Block 27 | 1,600,000 |
| Medium -> Joshua/Deborah first-block ground floor | 1,550,000 |
| Medium -> first block of faculty-proximal hostel | 1,500,000 |
| Low -> primary faculty-proximal hostel | 900,000 |
| Low -> secondary faculty-proximal hostel | 450,000 |
| Medium -> later faculty-proximal block | 400,000 |
| Medium or Low -> clinic overflow | 150,000 |

These values are intentionally far larger than the raw score contribution, so placement rules remain stable even when students are close in score.

## Queue / Worker Model

The admin page queues allocation jobs into `allocation_jobs`. Processing happens in:

- `worker_allocation.php` for single-job execution
- `worker_launcher.php` for continuous queue polling

On Windows, worker dispatch now explicitly launches through `cmd /c start` and resolves a CLI PHP binary more defensively.

## Data / Bootstrap Notes

Files updated for this policy:

- `sql/seed.php`
- `sql/20260502c_enable_pme_block27_for_undergrad.sql`

These ensure Prophet Moses Extension Hall Block 27 is available to undergraduate allocation.

## Source Map

- Scoring bridge: [includes/UrgencyScoreService.php](/C:/xampp/htdocs/FairMedAlloc/includes/UrgencyScoreService.php)
- Model adapter: [ml_models/predict.py](/C:/xampp/htdocs/FairMedAlloc/ml_models/predict.py)
- Solver: [ml_models/allocate.py](/C:/xampp/htdocs/FairMedAlloc/ml_models/allocate.py)
- Engine: [includes/AllocationEngine.php](/C:/xampp/htdocs/FairMedAlloc/includes/AllocationEngine.php)
- Queue API: [api/admin_api.php](/C:/xampp/htdocs/FairMedAlloc/api/admin_api.php)
- Worker launcher: [worker_launcher.php](/C:/xampp/htdocs/FairMedAlloc/worker_launcher.php)
