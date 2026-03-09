"""
FairMedAlloc - Urgency Score Prediction
========================================
Predicts a student's urgency score based on their medical/mobility conditions.
It uses a trained XGBoost ML model if available, otherwise defaults to a hard-coded
rule-based scoring fallback to ensure the system never breaks.

Usage:
    python predict.py '{"id": "1", "condition": "Asthma", "severity": 3}'
    python predict.py input_file.json
"""

import sys
import json
import logging
import os

logging.basicConfig(level=logging.ERROR)

# Setup fundamental paths for locating the ML model
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
MODEL_PATH = os.path.join(SCRIPT_DIR, 'urgency_model.json')
ENCODERS_PATH = os.path.join(SCRIPT_DIR, 'label_encoders.json')

# Global variables to cache model in memory 
_model = None
_encoders = None
_use_ml_model = False


def load_ml_model():
    """Attempt to load the trained XGBoost model and dictionary encoders.
    Returns: bool indicating if loading was successful."""
    global _model, _encoders, _use_ml_model
    
    # If the files don't exist, we will have to use the rule-based fallback
    if not os.path.exists(MODEL_PATH) or not os.path.exists(ENCODERS_PATH):
        return False
    
    try:
        import xgboost as xgb
        _model = xgb.XGBRegressor()
        _model.load_model(MODEL_PATH)
        
        # Load the JSON encoders to map text classifications to integers
        with open(ENCODERS_PATH, 'r') as f:
            _encoders = json.load(f)
        
        _use_ml_model = True
        return True
    except Exception as e:
        logging.error(f"Failed to load ML model: {e}")
        return False


def encode_value(column, value):
    """Helper method to convert categorical strings (like 'Asthma') into the numerical IDs expected by XGBoost."""
    if _encoders and column in _encoders:
        classes = _encoders[column]['classes']
        value_str = str(value) if value else ('None' if column == 'condition' else 'Normal')
        if value_str in classes:
            return classes.index(value_str)
        return 0
    return 0


def calculate_score_ml(student):
    """Predict urgency score strictly utilizing the ML Regression Model."""
    try:
        # Create the feature list in the exact order the model expects
        features = [
            encode_value('condition', student.get('condition', 'None')),
            encode_value('mobility', student.get('mobility', 'Normal')),
            int(student.get('severity', 0)),
            int(student.get('academic_level', 100)),
            float(student.get('distance_from_campus', 0)),
            int(student.get('has_special_needs', 0))
        ]
        
        prediction = _model.predict([features])[0]
        # Restrict score to boundaries between 0.0 and 100.0
        return max(0.0, min(float(prediction), 100.0))
    except Exception as e:
        # If prediction fails randomly, default back to rule-based fallback
        logging.error(f"ML prediction failed: {e}")
        return calculate_score_fallback(student)


def calculate_score_fallback(student):
    """Hard-coded rule-based scoring module. This is heavily engaged if the ML Model hasn't been trained yet."""
    try:
        # If a score was explicitly passed in the student dict, honor it
        if 'urgency_score' in student and student['urgency_score'] is not None:
            val = float(student['urgency_score'])
            if val > 0:
                return val

        condition = student.get('condition', 'None')
        mobility = student.get('mobility', 'Normal')
        
        # Mapping rules: Normalize mobility items appearing as primary conditions
        mobility_types = ['Wheelchair User', 'Crutches/Walker', 'Artificial Limb']
        if condition in mobility_types:
            mobility = condition

        severity = int(student.get('severity', 0))
        
        # Start everyone with at least a low baseline score
        score = 10.0
        
        # Weights matrix defining how much boost each condition gets
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
        
        # Mobility Logic: Delineates between Tier 1 (Requested by specialized logic) vs Tier 2
        mobility_score = 0.0
        is_requested = student.get('is_requested', False)
        
        if mobility in ['Wheelchair User', 'Crutches/Walker', 'Artificial Limb']:
            if is_requested:
                mobility_score = 90.0 # Tier 1 -> Highly Urgently requires Clinic Proximal + Ground
            else:
                mobility_score = 75.0 # Tier 2 -> Proximal needed, maybe not heavily urgent
        
        # Prevent double dipping logic, take highest risk score
        score = max(score, mobility_score)
        
        # Slight severity bumping
        score += (severity * 5.0)
        
        return min(float(score), 100.0)
    except:
        return 0.0


def calculate_score(student):
    """Main generic routing function that calculates scores dynamically."""
    # Pre-calculated scores act as an override
    if 'urgency_score' in student and student['urgency_score'] is not None:
        try:
            val = float(student['urgency_score'])
            if val > 0:
                return val
        except:
            pass
    
    # Forward calculation to ML or Fallback mechanism
    if _use_ml_model:
        return calculate_score_ml(student)
    return calculate_score_fallback(student)


def process_batch(data_input):
    """Handles an entire batch (list) of dictionaries and returns an evaluated scored map."""
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


# Load model continuously on initial Python import
load_ml_model()


if __name__ == "__main__":
    # When run via the CLI (e.g. executed by PHP shell_exec)
    mode = "ML Model" if _use_ml_model else "Rule-Based (Fallback)"
    
    if len(sys.argv) > 1:
        try:
            arg = sys.argv[1]
            
            # Allow reading entirely from file paths or inline JSON dicts
            if os.path.isfile(arg):
                with open(arg, 'r') as f:
                    input_data = json.load(f)
            else:
                input_data = json.loads(arg)
                
            scores = process_batch(input_data)
            # Dump JSON output to STDOUT cleanly for bridging with PHP scripts
            print(json.dumps({"status": "success", "mode": mode, "results": scores}))
            
        except Exception as e:
            # Fatal crash logging bridge
            print(json.dumps({"status": "error", "message": str(e)}))
    else:
        print(json.dumps({"status": "info", "mode": mode, "message": "No input provided"}))
