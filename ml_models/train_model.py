"""
FairMedAlloc - XGBoost Model Training Script
=============================================
Trains an XGBoost model using labeled training data from a CSV file.
Supports stratified sampling to ensure balanced representation.

Usage:
    python train_model.py training_data.csv

Requirements:
    pip install pandas xgboost scikit-learn
"""

import pandas as pd
import xgboost as xgb
from sklearn.model_selection import train_test_split, StratifiedKFold
from sklearn.preprocessing import LabelEncoder
from sklearn.metrics import mean_squared_error, r2_score
import numpy as np
import json
import sys
import os

# Configuration paths for saving the trained model and label encoders
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
MODEL_OUTPUT_PATH = os.path.join(SCRIPT_DIR, 'urgency_model.json')
ENCODERS_OUTPUT_PATH = os.path.join(SCRIPT_DIR, 'label_encoders.json')

# Define feature columns expected in the dataset
CATEGORICAL_FEATURES = ['condition', 'mobility']
NUMERICAL_FEATURES = ['severity', 'academic_level', 'distance_from_campus', 'has_special_needs']
TARGET_COLUMN = 'urgency_score'


def load_and_preprocess_data(csv_path):
    """Load CSV into a Pandas DataFrame and handle missing values."""
    print(f"[1/6] Loading data from: {csv_path}")
    df = pd.read_csv(csv_path)
    print(f"      Loaded {len(df)} rows")
    
    # Fill missing values with sensible defaults
    df['condition'] = df['condition'].fillna('None')
    df['mobility'] = df['mobility'].fillna('Normal')
    df['severity'] = df['severity'].fillna(0)
    df['academic_level'] = df['academic_level'].fillna(100)
    df['distance_from_campus'] = df['distance_from_campus'].fillna(0)
    df['has_special_needs'] = df['has_special_needs'].fillna(0)
    
    return df


def analyze_strata(df):
    """Analyze data distribution across categories (strata) to ensure balanced splits."""
    print("[2/6] Analyzing strata distribution...")
    
    # Print condition statistics
    print("\n      Condition Distribution:")
    for cond, count in df['condition'].value_counts().items():
        pct = (count / len(df)) * 100
        print(f"        {cond}: {count} ({pct:.1f}%)")
    
    # Print mobility statistics
    print("\n      Mobility Distribution:")
    for mob, count in df['mobility'].value_counts().items():
        pct = (count / len(df)) * 100
        print(f"        {mob}: {count} ({pct:.1f}%)")
    
    # Print severity statistics
    print("\n      Severity Distribution:")
    for sev, count in df['severity'].value_counts().sort_index().items():
        pct = (count / len(df)) * 100
        print(f"        Level {sev}: {count} ({pct:.1f}%)")
    
    # Create stratification column for balanced data splitting during training
    df['strata'] = df['condition'] + '_' + df['mobility']
    return df


def encode_categorical_features(df):
    """Convert text-based categorical features into numeric formats and save encoders to JSON."""
    print("\n[3/6] Encoding categorical features...")
    
    encoders = {}
    
    # Iterate through categorical features and map them to integers
    for col in CATEGORICAL_FEATURES:
        le = LabelEncoder()
        df[f'{col}_encoded'] = le.fit_transform(df[col].astype(str))
        encoders[col] = {'classes': le.classes_.tolist()}
        print(f"      {col}: {le.classes_.tolist()}")
    
    # Save mapping dictionary to disk so the prediction script can reuse the same integer mappings
    with open(ENCODERS_OUTPUT_PATH, 'w') as f:
        json.dump(encoders, f, indent=2)
    print(f"      Saved encoders to: {ENCODERS_OUTPUT_PATH}")
    
    return df, encoders


def prepare_features(df):
    """Prepare feature matrix (X) and target array (y) for XGBoost training."""
    print("\n[4/6] Preparing feature matrix...")
    
    feature_columns = [
        'condition_encoded',
        'mobility_encoded',
        'severity',
        'academic_level',
        'distance_from_campus',
        'has_special_needs'
    ]
    
    X = df[feature_columns].values
    y = df[TARGET_COLUMN].values
    strata = df['strata'].values
    
    print(f"      Features shape: {X.shape}")
    print(f"      Target range: {y.min():.1f} - {y.max():.1f}")
    
    return X, y, strata, feature_columns


def train_model(X, y, strata):
    """Train XGBoost regression model using a train/test split."""
    print("\n[5/6] Training XGBoost model...")
    
    try:
        # Stratified split ensures all sub-categories (strata) exist in both train and test sets
        X_train, X_test, y_train, y_test = train_test_split(
            X, y, test_size=0.2, random_state=42, stratify=strata
        )
        print("      Using stratified split")
    except ValueError:
        # Fallback to random split if a category has too few samples
        X_train, X_test, y_train, y_test = train_test_split(
            X, y, test_size=0.2, random_state=42
        )
        print("      Using random split (insufficient samples for stratification)")
    
    print(f"      Training samples: {len(X_train)}")
    print(f"      Testing samples: {len(X_test)}")
    
    # Configure and fit the XGBoost Regression model
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
    
    # Evaluate model accuracy on the test set
    y_pred = model.predict(X_test)
    mse = mean_squared_error(y_test, y_pred)
    r2 = r2_score(y_test, y_pred)
    
    print(f"      Mean Squared Error: {mse:.2f}")
    print(f"      R² Score: {r2:.4f}")
    
    return model


def save_model(model, feature_columns):
    """Save the trained XGBoost model and display feature importance."""
    print("\n[6/6] Saving model...")
    
    model.save_model(MODEL_OUTPUT_PATH)
    print(f"      Model saved to: {MODEL_OUTPUT_PATH}")
    
    # Calculate and display which features strongly influenced the model's decisions
    importance = dict(zip(feature_columns, model.feature_importances_.tolist()))
    print("\n      Feature Importance:")
    for feat, imp in sorted(importance.items(), key=lambda x: -x[1]):
        bar = '█' * int(imp * 20)
        print(f"        {feat:25s} {bar} {imp:.4f}")


def main():
    """Main execution flow."""
    # Read CLI args
    if len(sys.argv) < 2:
        csv_path = os.path.join(SCRIPT_DIR, 'training_data_template.csv')
        print(f"No CSV specified, using: {csv_path}")
    else:
        csv_path = sys.argv[1]
    
    if not os.path.exists(csv_path):
        print(f"Error: File not found: {csv_path}")
        sys.exit(1)
    
    print("=" * 55)
    print("  FairMedAlloc - XGBoost Model Training")
    print("  With Stratified Sampling Support")
    print("=" * 55)
    
    # Execute training pipeline step-by-step
    df = load_and_preprocess_data(csv_path)
    df = analyze_strata(df)
    df, encoders = encode_categorical_features(df)
    X, y, strata, feature_columns = prepare_features(df)
    model = train_model(X, y, strata)
    save_model(model, feature_columns)
    
    print("\n" + "=" * 55)
    print("  Training Complete!")
    print("=" * 55)
    print("\nNext steps:")
    print("  1. Add more labeled data (aim for 100+ samples)")
    print("  2. Ensure balanced representation across strata")
    print("  3. Re-run: python train_model.py your_data.csv")
    print("  4. The predict.py script will auto-use the trained model")


if __name__ == "__main__":
    main()
