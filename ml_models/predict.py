"""
FairMedAlloc - Urgency Score Prediction
========================================
Uses trained XGBoost model if available, otherwise falls back to rule-based scoring.

Usage:
    python predict.py '{"id": "1", "condition": "Asthma", "severity": 3}'
    python predict.py input_file.json
"""

import sys
import json
import logging
import os

logging.basicConfig(level=logging.ERROR)

# Paths
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
MODEL_PATH = os.path.join(SCRIPT_DIR, 'urgency_model.json')
ENCODERS_PATH = os.path.join(SCRIPT_DIR, 'label_encoders.json')

# Global state
_model = None
_encoders = None
_use_ml_model = False


def load_ml_model():
    """Load trained XGBoost model if available."""
    global _model, _encoders, _use_ml_model
    
    if not os.path.exists(MODEL_PATH) or not os.path.exists(ENCODERS_PATH):
        return False
    
    try:
        import xgboost as xgb
        _model = xgb.XGBRegressor()
        _model.load_model(MODEL_PATH)
        
        with open(ENCODERS_PATH, 'r') as f:
            _encoders = json.load(f)
        
        _use_ml_model = True
        return True
    except Exception as e:
        logging.error(f"Failed to load ML model: {e}")
        return False


def encode_value(column, value):
    """Encode categorical value using saved encoders."""
    if _encoders and column in _encoders:
        classes = _encoders[column]['classes']
        value_str = str(value) if value else ('None' if column == 'condition' else 'Normal')
        if value_str in classes:
            return classes.index(value_str)
        return 0
    return 0


def calculate_score_ml(student):
    """Calculate score using trained XGBoost model."""
    try:
        features = [
            encode_value('condition', student.get('condition', 'None')),
            encode_value('mobility', student.get('mobility', 'Normal')),
            int(student.get('severity', 0)),
            int(student.get('academic_level', 100)),
            float(student.get('distance_from_campus', 0)),
            int(student.get('has_special_needs', 0))
        ]
        
        prediction = _model.predict([features])[0]
        return max(0.0, min(float(prediction), 100.0))
    except Exception as e:
        logging.error(f"ML prediction failed: {e}")
        return calculate_score_fallback(student)


def calculate_score_fallback(student):
    """Rule-based scoring fallback when ML model unavailable."""
    try:
        if 'urgency_score' in student and student['urgency_score'] is not None:
            val = float(student['urgency_score'])
            if val > 0:
                return val

        condition = student.get('condition', 'None')
        mobility = student.get('mobility', 'Normal')
        
        # Mapping: If condition IS a mobility type, treat it as mobility
        mobility_types = ['Wheelchair User', 'Crutches/Walker', 'Artificial Limb']
        if condition in mobility_types:
            mobility = condition

        severity = int(student.get('severity', 0))
        
        score = 10.0
        
        weights = {
            'Sickle Cell': 90.0,      # Tier 1 - Chronic/Emergency
            'Epilepsy': 90.0,         # Tier 1 - Chronic/Emergency
            'Asthma': 50.0,           # Moderate
            'Diabetes': 90.0,         # Tier 1 - Insulin Dependent
            'Cardiac': 90.0,          # Tier 1
            'Visual Impairment': 60.0,
            'Orthopaedic': 65.0,
            'Wheelchair User': 0.0,   # Handled via is_requested check below
            'Crutches/Walker': 0.0    # Handled via is_requested check below
        }
        
        score += weights.get(condition, 0.0)
        
        # Mobility Logic: Tier 1 (Requested) vs Tier 2 (Unrequested)
        mobility_score = 0.0
        is_requested = student.get('is_requested', False)
        
        if mobility in ['Wheelchair User', 'Crutches/Walker', 'Artificial Limb']:
            if is_requested:
                mobility_score = 90.0 # Tier 1 -> Clinic Proximal + Ground
            else:
                mobility_score = 75.0 # Tier 2 -> Clinic Proximal
        
        score = max(score, mobility_score)
        
        score += (severity * 5.0)
        
        return min(float(score), 100.0)
    except:
        return 0.0


def calculate_score(student):
    """Main scoring function - uses ML if available."""
    if 'urgency_score' in student and student['urgency_score'] is not None:
        try:
            val = float(student['urgency_score'])
            if val > 0:
                return val
        except:
            pass
    
    if _use_ml_model:
        return calculate_score_ml(student)
    return calculate_score_fallback(student)


def process_batch(data_input):
    """Process single dict or list of dicts. Returns {id: score}."""
    results = {}
    
    if isinstance(data_input, dict):
        batch = [data_input]
    elif isinstance(data_input, list):
        batch = data_input
    else:
        return {}
        
    for student in batch:
        sid = student.get('id', 'unknown')
        results[sid] = calculate_score(student)
        
    return results


# Load model on import
load_ml_model()


if __name__ == "__main__":
    mode = "ML Model" if _use_ml_model else "Rule-Based (Fallback)"
    
    if len(sys.argv) > 1:
        try:
            arg = sys.argv[1]
            
            if os.path.isfile(arg):
                with open(arg, 'r') as f:
                    input_data = json.load(f)
            else:
                input_data = json.loads(arg)
                
            scores = process_batch(input_data)
            print(json.dumps({"status": "success", "mode": mode, "results": scores}))
            
        except Exception as e:
            print(json.dumps({"status": "error", "message": str(e)}))
    else:
        print(json.dumps({"status": "info", "mode": mode, "message": "No input provided"}))
