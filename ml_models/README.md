# FairMedAlloc ML Models

Machine learning components for calculating student urgency scores.

## Quick Start

```bash
# Install dependencies
pip install pandas xgboost scikit-learn

# Train with sample data
python train_model.py

# Or use your own labeled data
python train_model.py your_training_data.csv
```

## Files

| File | Description |
|------|-------------|
| `predict.py` | Prediction script (called by PHP) |
| `train_model.py` | Training script with stratified sampling |
| `training_data_template.csv` | Sample CSV format |
| `urgency_model.json` | Trained model (auto-generated) |
| `label_encoders.json` | Feature encoders (auto-generated) |

## Training Data Format

CSV columns required:
- `student_id` — Unique identifier
- `condition` — Asthma, Sickle Cell, Visual Impairment, Orthopaedic, None
- `mobility` — Normal, Wheelchair User, Crutches/Walker
- `severity` — 0-5 scale
- `academic_level` — 100, 200, 300, 400, 500
- `distance_from_campus` — Distance in km
- `has_special_needs` — 0 or 1
- `urgency_score` — **Target** (0-100)

## Stratification

The training script analyzes strata distribution to ensure balanced representation across:
- Medical conditions
- Mobility levels
- Severity levels

This prevents the model from being biased toward over-represented categories.

## Usage Flow

1. **No trained model** → Uses rule-based fallback scoring
2. **After training** → Automatically uses XGBoost predictions
