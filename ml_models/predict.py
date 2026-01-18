import sys
import json
import logging
import os

# Configure logging
logging.basicConfig(level=logging.ERROR)

def calculate_score(student):
    """
    Core scoring logic for a single student.
    """
    try:
        # Check for manual/pre-set score first
        if 'urgency_score' in student and student['urgency_score'] is not None:
            val = float(student['urgency_score'])
            if val > 0:
                return val

        condition = student.get('condition', 'None')
        mobility = student.get('mobility', 'Normal')
        severity = int(student.get('severity', 0))
        
        # Base Score
        score = 10.0
        
        # Feature Mapping (XGBoost weights simulation)
        weights = {
            'Asthma': 40.0,
            'Sickle Cell': 55.0,
            'Visual Impairment': 60.0,
            'Orthopaedic': 65.0,
            'Wheelchair User': 30.0,
            'Crutches/Walker': 20.0
        }
        
        # Apply Weights
        score += weights.get(condition, 0.0)
        score += weights.get(mobility, 0.0)
        score += (severity * 5.0)
        
        return min(float(score), 100.0)
    except:
        return 0.0

def process_batch(data_input):
    """
    Process input which can be a single dict or a list of dicts.
    Returns: Dict mapping {student_id: score}
    """
    results = {}
    
    # Normalize to list
    if isinstance(data_input, dict):
        batch = [data_input]
    elif isinstance(data_input, list):
        batch = data_input
    else:
        return {}
        
    for student in batch:
        sid = student.get('id', 'unknown')
        score = calculate_score(student)
        results[sid] = score
        
    return results

if __name__ == "__main__":
    if len(sys.argv) > 1:
        try:
            arg = sys.argv[1]
            input_data = None
            
            # Check if argument is a file path
            if os.path.isfile(arg):
                with open(arg, 'r') as f:
                    input_data = json.load(f)
            else:
                # Assume raw JSON string
                input_data = json.loads(arg)
                
            # Process
            scores = process_batch(input_data)
            
            # Output Result (JSON string of id->score map)
            print(json.dumps({"status": "success", "results": scores}))
            
        except Exception as e:
            # Fallback error JSON
            print(json.dumps({"status": "error", "message": str(e)}))
    else:
        print(json.dumps({"status": "error", "message": "No input provided"}))
