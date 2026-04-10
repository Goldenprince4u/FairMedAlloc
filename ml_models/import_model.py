"""
FairMedAlloc - XGBoost Model Import Utility
============================================
Use this script to import your own XGBoost model from a CSV dataset.
It is an alternative to train_model.py - designed for situations where
you already HAVE labeled data and want to load it directly.

WHAT IT DOES:
  - Reads your CSV file
  - Automatically maps your column names to the system's expected names
  - Trains a fresh XGBoost model on your data
  - Saves urgency_model.json + label_encoders.json (overwriting old ones)
  - The system's predict.py will immediately start using the new model

USAGE:
  python import_model.py my_data.csv
  python import_model.py my_data.csv --map condition=medical_condition severity=level
  python import_model.py my_data.csv --preview   (just shows column mapping, no training)

YOUR CSV MUST HAVE (at minimum):
  - A medical condition column   (e.g. condition, medical_condition, diagnosis)
  - An urgency/priority column   (e.g. urgency_score, priority, score)

OPTIONAL COLUMNS (will use defaults if missing):
  - mobility / mobility_status
  - severity / severity_level / level
  - academic_level / year_level / level
  - has_special_needs / special_needs
"""

import pandas as pd
import xgboost as xgb
from sklearn.model_selection import train_test_split
from sklearn.preprocessing import LabelEncoder
from sklearn.metrics import mean_squared_error, r2_score
import numpy as np
import json
import sys
import os
import argparse

# ── Paths ──────────────────────────────────────────────────────────────────────
SCRIPT_DIR          = os.path.dirname(os.path.abspath(__file__))
MODEL_OUTPUT_PATH   = os.path.join(SCRIPT_DIR, 'urgency_model.json')
ENCODERS_OUTPUT_PATH= os.path.join(SCRIPT_DIR, 'label_encoders.json')
BACKUP_MODEL_PATH   = os.path.join(SCRIPT_DIR, 'urgency_model.backup.json')
BACKUP_ENCODERS_PATH= os.path.join(SCRIPT_DIR, 'label_encoders.backup.json')

# ── Auto-detection aliases ─────────────────────────────────────────────────────
# Maps the system's internal column names to common aliases found in exported CSVs
COLUMN_ALIASES = {
    'condition': [
        'condition', 'medical_condition', 'diagnosis', 'illness',
        'disease', 'health_condition', 'medical_status', 'ailment'
    ],
    'mobility': [
        'mobility', 'mobility_status', 'movement', 'ambulatory_status',
        'physical_mobility', 'disability_type'
    ],
    'severity': [
        'severity', 'severity_level', 'sev_level', 'level',
        'severity_score', 'condition_severity', 'grade'
    ],
    'academic_level': [
        'academic_level', 'year_level', 'academic_year',
        'year', 'study_level', 'student_level', 'entry_score'
    ],
    'has_special_needs': [
        'has_special_needs', 'special_needs', 'disability',
        'is_disabled', 'needs_accommodation', 'special_accommodation'
    ],
    'urgency_score': [
        'urgency_score', 'urgency', 'priority', 'score',
        'priority_score', 'allocation_score', 'risk_score',
        'allocation_priority', 'medical_priority'
    ]
}

# ── Default values when a column is absent ─────────────────────────────────────
DEFAULTS = {
    'condition'          : 'None',
    'mobility'           : 'Normal Mobility',
    'severity'           : 'Low',
    'academic_level'     : 100,
    'has_special_needs'  : 0,
}


def detect_column(df_columns, system_name, user_map=None):
    """Find the best matching column from the CSV for a given system name."""
    df_cols_lower = {c.lower().strip(): c for c in df_columns}

    # 1. Check user-provided mapping first
    if user_map and system_name in user_map:
        mapped = user_map[system_name].lower().strip()
        if mapped in df_cols_lower:
            return df_cols_lower[mapped]

    # 2. Try exact aliases
    for alias in COLUMN_ALIASES.get(system_name, []):
        if alias.lower() in df_cols_lower:
            return df_cols_lower[alias.lower()]

    # 3. Try partial match
    for alias in COLUMN_ALIASES.get(system_name, []):
        for col_lower, col_orig in df_cols_lower.items():
            if alias.lower() in col_lower or col_lower in alias.lower():
                return col_orig

    return None  # Not found


def build_column_map(df, user_map=None):
    """Build a mapping from system column names to actual CSV column names."""
    mapping = {}
    df_cols = list(df.columns)

    all_system_cols = list(DEFAULTS.keys()) + ['urgency_score']
    for sys_col in all_system_cols:
        found = detect_column(df_cols, sys_col, user_map)
        mapping[sys_col] = found

    return mapping


def print_mapping_report(df, mapping):
    """Display column detection results in a human-readable table."""
    print("\n  Column Mapping Report:")
    print(f"  {'System Column':<22} {'Your CSV Column':<25} {'Status'}")
    print(f"  {'-'*22} {'-'*25} {'-'*10}")

    required = ['condition', 'urgency_score']
    for sys_col, csv_col in mapping.items():
        required_flag = ' (REQUIRED)' if sys_col in required else ''
        if csv_col:
            status = '✓ Found'
            print(f"  {sys_col:<22} {csv_col:<25} {status}")
        else:
            default_val = DEFAULTS.get(sys_col, 'N/A')
            if sys_col in required:
                status = f'✗ MISSING{required_flag}'
            else:
                status = f'~ Using default: {default_val}'
            print(f"  {sys_col:<22} {'—':<25} {status}")

    print()


def load_and_remap(csv_path, mapping):
    """Load CSV and remap columns to system names, filling defaults as needed."""
    df = pd.read_csv(csv_path)
    out = pd.DataFrame()

    for sys_col, csv_col in mapping.items():
        if csv_col and csv_col in df.columns:
            out[sys_col] = df[csv_col]
        elif sys_col in DEFAULTS:
            out[sys_col] = DEFAULTS[sys_col]
        # urgency_score is required — handled separately

    # Validate urgency_score is available
    if mapping['urgency_score'] and mapping['urgency_score'] in df.columns:
        out['urgency_score'] = pd.to_numeric(df[mapping['urgency_score']], errors='coerce').fillna(0)
    else:
        raise ValueError(
            "ERROR: Could not find an urgency/score column in your CSV.\n"
            "  Acceptable names: urgency_score, priority, score, allocation_score, risk_score\n"
            "  If your column has a different name, use --map urgency_score=YOUR_COLUMN_NAME"
        )

    # Clean up types
    out['condition']           = out['condition'].fillna('None').astype(str)
    out['mobility']            = out['mobility'].fillna('Normal Mobility').astype(str)
    sev_map = {'Low': 1, 'Medium': 2, 'High': 3}
    out['severity']            = out['severity'].apply(lambda x: sev_map.get(str(x).capitalize(), 1) if isinstance(x, str) else (int(x) if pd.notnull(x) else 1))
    out['academic_level']      = pd.to_numeric(out['academic_level'], errors='coerce').fillna(100).astype(int)
    out['has_special_needs']   = pd.to_numeric(out['has_special_needs'], errors='coerce').fillna(0).astype(int)

    return out


def encode_features(df):
    """Label-encode categorical columns and save encoder classes to JSON."""
    encoders = {}
    for col in ['condition', 'mobility']:
        le = LabelEncoder()
        df[f'{col}_encoded'] = le.fit_transform(df[col].astype(str))
        encoders[col] = {'classes': le.classes_.tolist()}
        print(f"  {col} classes: {le.classes_.tolist()}")

    with open(ENCODERS_OUTPUT_PATH, 'w') as f:
        json.dump(encoders, f, indent=2)
    print(f"\n  Encoders saved → {ENCODERS_OUTPUT_PATH}")
    return df, encoders


def train_xgboost(df):
    """Train XGBoost regressor and return the fitted model."""
    feature_cols = [
        'condition_encoded', 'mobility_encoded',
        'severity', 'academic_level',
        'has_special_needs'
    ]
    X = df[feature_cols].values
    y = df['urgency_score'].values

    print(f"\n  Samples: {len(X)} | Features: {len(feature_cols)}")
    print(f"  Score range: {y.min():.1f} – {y.max():.1f}")

    try:
        X_train, X_test, y_train, y_test = train_test_split(
            X, y, test_size=0.2, random_state=42
        )
    except Exception:
        X_train, X_test, y_train, y_test = X, X, y, y

    model = xgb.XGBRegressor(
        n_estimators=100,
        max_depth=5,
        learning_rate=0.1,
        subsample=0.8,
        colsample_bytree=0.8,
        random_state=42,
        objective='reg:squarederror'
    )
    model.fit(X_train, y_train, eval_set=[(X_test, y_test)], verbose=False)

    y_pred = model.predict(X_test)
    mse = mean_squared_error(y_test, y_pred)
    r2  = r2_score(y_test, y_pred)
    print(f"\n  MSE: {mse:.2f}  |  R²: {r2:.4f}")

    return model, feature_cols


def backup_existing():
    """Back up existing model files before overwriting."""
    if os.path.exists(MODEL_OUTPUT_PATH):
        import shutil
        shutil.copy2(MODEL_OUTPUT_PATH,    BACKUP_MODEL_PATH)
        shutil.copy2(ENCODERS_OUTPUT_PATH, BACKUP_ENCODERS_PATH)
        print(f"  Previous model backed up → urgency_model.backup.json")


def parse_user_map(map_args):
    """Parse --map key=value arguments into a dict."""
    user_map = {}
    if not map_args:
        return user_map
    for item in map_args:
        if '=' in item:
            k, v = item.split('=', 1)
            user_map[k.strip()] = v.strip()
    return user_map


def main():
    parser = argparse.ArgumentParser(
        description='Import your XGBoost model from a CSV dataset',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__
    )
    parser.add_argument('csv_file', nargs='?',
                        default=os.path.join(SCRIPT_DIR, 'training_data_template.csv'),
                        help='Path to your CSV data file')
    parser.add_argument('--map', nargs='+', metavar='system=your_col',
                        help='Map system column names to your CSV columns. '
                             'E.g: --map condition=diagnosis urgency_score=priority')
    parser.add_argument('--preview', action='store_true',
                        help='Preview column mapping only, do not train')
    args = parser.parse_args()

    print("=" * 58)
    print("  FairMedAlloc - XGBoost Model Import Utility")
    print("=" * 58)

    if not os.path.exists(args.csv_file):
        print(f"\n  ERROR: File not found: {args.csv_file}")
        sys.exit(1)

    # Step 1: Detect columns
    print(f"\n[1] Reading CSV: {args.csv_file}")
    df_raw = pd.read_csv(args.csv_file)
    print(f"    Rows: {len(df_raw)} | Columns: {list(df_raw.columns)}")

    user_map = parse_user_map(args.map)
    mapping  = build_column_map(df_raw, user_map)

    print("\n[2] Detecting columns automatically...")
    print_mapping_report(df_raw, mapping)

    if args.preview:
        print("  --preview mode: stopping before training. Goodbye!")
        sys.exit(0)

    # Validate required columns
    if not mapping['condition'] and 'condition' not in [v.lower() for v in (user_map or {}).values()]:
        # Allow it if condition col is missing — will use 'None' default
        pass
    if not mapping['urgency_score']:
        print("  FATAL: No urgency/score column found. Cannot train without labels.")
        print("  Use: --map urgency_score=YOUR_COLUMN_NAME")
        sys.exit(1)

    # Step 2: Remap columns
    print("[3] Remapping and cleaning data...")
    df = load_and_remap(args.csv_file, mapping)
    print(f"    Clean dataset: {len(df)} rows")

    # Step 3: Encode
    print("\n[4] Encoding categorical features...")
    df, encoders = encode_features(df)

    # Step 4: Backup + Train
    print("\n[5] Creating backup of existing model (if any)...")
    backup_existing()

    print("\n[6] Training XGBoost model...")
    model, feature_cols = train_xgboost(df)

    # Step 5: Save
    print("\n[7] Saving model...")
    model.save_model(MODEL_OUTPUT_PATH)
    print(f"  Model saved → {MODEL_OUTPUT_PATH}")

    print("\n" + "=" * 58)
    print("  Import Complete!")
    print("  The system will now use your model automatically.")
    print("  Test it: python predict.py '{\"condition\":\"Asthma\",\"severity\":\"High\"}'")
    print("=" * 58)


if __name__ == '__main__':
    main()
