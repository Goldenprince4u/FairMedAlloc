"""
FairMedAlloc - XGBoost Urgency Prediction
=========================================
Loads the provided XGBoost pickle model unchanged and adapts web-app inputs to
the fixed 9-feature vector it expects.

Primary model schema:
    [has_asthma, has_epilepsy, has_ulcer, has_sickle_cell,
     has_cardiac_issue, has_visual_impairment, has_physical_disability,
     mobility_score, severity_score]

The application never mutates the model. Any compatibility work happens here in
the adapter and in the PHP application.
"""

import json
import logging
import os
import sys
import warnings

logging.basicConfig(level=logging.WARNING)
warnings.filterwarnings(
    "ignore",
    message=".*If you are loading a serialized model.*",
    category=UserWarning,
)

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.dirname(SCRIPT_DIR)
DOTENV_PATH = os.path.join(PROJECT_ROOT, ".env")

PICKLE_FEATURE_KEYS = [
    "has_asthma",
    "has_epilepsy",
    "has_ulcer",
    "has_sickle_cell",
    "has_cardiac_issue",
    "has_visual_impairment",
    "has_physical_disability",
    "mobility_score",
    "severity_score",
]

HIGH_URGENCY_THRESHOLD = 75.0
MEDIUM_URGENCY_THRESHOLD = 40.0
MOBILITY_PRIORITY_STATUSES = {"Wheelchair User", "Crutches/Walker", "Artificial Limb"}
MEDICAL_MOBILITY_HIGH_FLOORS = {
    "Artificial Limb": 82.0,
    "Crutches/Walker": 84.0,
    "Wheelchair User": 88.0,
}
MOBILITY_ONLY_MEDIUM_FLOORS = {
    "Artificial Limb": {1: 46.0, 2: 52.0, 3: 60.0},
    "Crutches/Walker": {1: 52.0, 2: 60.0, 3: 68.0},
    "Wheelchair User": {1: 58.0, 2: 66.0, 3: 74.0},
}

_model = None
_use_ml_model = False
_model_source = None
_settings_cache = None


def load_local_settings():
    global _settings_cache

    if _settings_cache is not None:
        return _settings_cache

    settings = {}
    if os.path.exists(DOTENV_PATH):
        try:
            with open(DOTENV_PATH, "r", encoding="utf-8") as env_file:
                for raw_line in env_file:
                    line = raw_line.strip()
                    if not line or line.startswith("#") or "=" not in line:
                        continue
                    key, value = line.split("=", 1)
                    settings[key.strip()] = value.strip().strip("\"'")
        except OSError as exc:
            logging.error("Failed to read .env settings: %s", exc)

    _settings_cache = settings
    return settings


def get_setting(name, default=""):
    settings = load_local_settings()
    env_value = os.environ.get(name)
    if env_value not in (None, ""):
        return env_value
    return settings.get(name, default)


def resolve_candidate_path(candidate):
    if not candidate:
        return None

    candidate = str(candidate).strip().strip("\"'")
    if not candidate:
        return None

    if not os.path.isabs(candidate):
        project_relative = os.path.join(PROJECT_ROOT, candidate)
        script_relative = os.path.join(SCRIPT_DIR, candidate)
        if os.path.exists(project_relative):
            candidate = project_relative
        else:
            candidate = script_relative

    return candidate if os.path.exists(candidate) else None


def get_pickle_model_path():
    candidates = [
        get_setting("ML_MODEL_PICKLE_PATH", ""),
        "xgboost_hostel_model.pkl",
    ]
    for candidate in candidates:
        resolved = resolve_candidate_path(candidate)
        if resolved:
            return resolved
    return None


def model_descriptor():
    return "XGBoost .pkl Model" if _use_ml_model else "Rule-Based Fallback"


def calculate_tier(score):
    if score >= HIGH_URGENCY_THRESHOLD:
        return "High"
    if score >= MEDIUM_URGENCY_THRESHOLD:
        return "Medium"
    return "Low"


def normalize_text(value):
    if value is None:
        return ""
    return " ".join(str(value).strip().lower().split())


def normalize_condition_value(value):
    text = normalize_text(value)
    aliases = {
        "": "None",
        "none": "None",
        "none / healthy": "None",
        "healthy": "None",
        "asthma": "Asthma",
        "respiratory": "Respiratory",
        "epilepsy": "Epilepsy",
        "ulcer": "Ulcer",
        "sickle cell": "Sickle Cell",
        "sickle cell disease": "Sickle Cell",
        "cardiac": "Cardiac",
        "cardiac issue": "Cardiac",
        "cardiovascular": "Cardiovascular",
        "visual impairment": "Visual Impairment",
        "physical disability": "Physical Disability",
        "orthopaedic": "Orthopaedic",
        "orthopedic": "Orthopaedic",
        "neurological": "Neurological",
        "diabetes": "Diabetes",
        "other": "Other",
        "mobility": "Mobility",
        "wheelchair user": "Wheelchair User",
        "crutches/walker": "Crutches/Walker",
        "crutches / walker": "Crutches/Walker",
        "artificial limb": "Artificial Limb",
    }
    return aliases.get(text, str(value).strip() if value is not None else "None")


def normalize_mobility_value(value):
    if value is None:
        return "Normal Mobility"

    text = normalize_text(value)
    aliases = {
        "": "Normal Mobility",
        "0": "Normal Mobility",
        "normal": "Normal Mobility",
        "normal mobility": "Normal Mobility",
        "1": "Artificial Limb",
        "artificial limb": "Artificial Limb",
        "2": "Crutches/Walker",
        "crutches/walker": "Crutches/Walker",
        "crutches / walker": "Crutches/Walker",
        "crutches": "Crutches/Walker",
        "walker": "Crutches/Walker",
        "3": "Wheelchair User",
        "wheelchair user": "Wheelchair User",
        "wheelchair": "Wheelchair User",
    }
    return aliases.get(text, str(value).strip())


def normalize_severity_value(value):
    if value is None:
        return 1

    if isinstance(value, (int, float)):
        return max(0, min(int(value), 3))

    mapping = {
        "0": 0,
        "low": 1,
        "1": 1,
        "medium": 2,
        "2": 2,
        "high": 3,
        "3": 3,
    }
    return mapping.get(normalize_text(value), 1)


def normalize_score_value(value, default=0):
    try:
        return int(float(value))
    except (TypeError, ValueError):
        return default


def get_requested_mobility_flag(student):
    value = student.get("is_requested")
    if value is None:
        value = student.get("is_requested_mobility")
    if value is None:
        value = student.get("has_special_needs")
    return bool(value)


def split_condition_values(raw_condition):
    if raw_condition is None:
        return []

    if isinstance(raw_condition, (list, tuple, set)):
        return [normalize_condition_value(item) for item in raw_condition]

    normalized = normalize_condition_value(raw_condition)
    if normalized != str(raw_condition).strip():
        return [normalized]

    text = str(raw_condition)
    for delimiter in [",", ";", "|", "+"]:
        if delimiter in text:
            return [normalize_condition_value(part) for part in text.split(delimiter) if part.strip()]

    return [normalize_condition_value(raw_condition)]


def build_pickle_feature_vector(student):
    direct_flags_present = any(key in student for key in PICKLE_FEATURE_KEYS)
    features = {
        "has_asthma": normalize_score_value(student.get("has_asthma"), 0),
        "has_epilepsy": normalize_score_value(student.get("has_epilepsy"), 0),
        "has_ulcer": normalize_score_value(student.get("has_ulcer"), 0),
        "has_sickle_cell": normalize_score_value(student.get("has_sickle_cell"), 0),
        "has_cardiac_issue": normalize_score_value(student.get("has_cardiac_issue"), 0),
        "has_visual_impairment": normalize_score_value(student.get("has_visual_impairment"), 0),
        "has_physical_disability": normalize_score_value(student.get("has_physical_disability"), 0),
        "mobility_score": normalize_score_value(student.get("mobility_score"), -1),
        "severity_score": normalize_score_value(student.get("severity_score"), -1),
    }

    unsupported_conditions = []

    if not direct_flags_present:
        condition_map = {
            "Asthma": "has_asthma",
            "Respiratory": "has_asthma",
            "Epilepsy": "has_epilepsy",
            "Ulcer": "has_ulcer",
            "Sickle Cell": "has_sickle_cell",
            "Cardiac": "has_cardiac_issue",
            "Cardiovascular": "has_cardiac_issue",
            "Visual Impairment": "has_visual_impairment",
            # 'Physical Disability' and 'Orthopaedic' intentionally excluded:
            # physical disability is NOT a condition — it is captured by mobility_status.
            # has_physical_disability will always receive 0 from the condition path;
            # the mobility_score feature carries the full signal instead.
        }

        for condition in split_condition_values(student.get("condition")):
            if condition in ("None", "Mobility"):
                continue
            feature_key = condition_map.get(condition)
            if feature_key:
                features[feature_key] = 1
            else:
                unsupported_conditions.append(condition)

    if features["mobility_score"] < 0:
        mobility = normalize_mobility_value(student.get("mobility"))
        if mobility == "Normal Mobility":
            condition_as_mobility = normalize_condition_value(student.get("condition"))
            if condition_as_mobility in MOBILITY_PRIORITY_STATUSES:
                mobility = condition_as_mobility
        features["mobility_score"] = {
            "Normal Mobility": 0,
            "Artificial Limb": 1,
            "Crutches/Walker": 2,
            "Wheelchair User": 3,
        }.get(mobility, 0)

    if features["severity_score"] < 0:
        features["severity_score"] = normalize_severity_value(student.get("severity"))

    return [features[key] for key in PICKLE_FEATURE_KEYS], unsupported_conditions


def load_ml_model():
    global _model, _use_ml_model, _model_source

    _model = None
    _use_ml_model = False
    _model_source = None

    pickle_path = get_pickle_model_path()
    if not pickle_path:
        return False

    try:
        import joblib
        import xgboost  # noqa: F401  # Ensure the bundled XGBoost runtime is available.

        _model = joblib.load(pickle_path)
        _use_ml_model = True
        _model_source = pickle_path
        return True
    except Exception as exc:
        logging.error("Failed to load XGBoost pickle model: %s", exc)
        return False


def calculate_score_pickle(student):
    feature_vector, unsupported_conditions = build_pickle_feature_vector(student)
    if unsupported_conditions:
        raise ValueError(
            "Unsupported XGBoost condition(s): " + ", ".join(sorted(set(unsupported_conditions)))
        )

    import numpy as np

    score = _model.predict(np.array(feature_vector).reshape(1, -1))[0]
    return max(0.0, min(float(score), 100.0))


def calculate_score_fallback(student):
    try:
        if "urgency_score" in student and student["urgency_score"] is not None:
            val = float(student["urgency_score"])
            if val > 0:
                return val

        condition = normalize_condition_value(student.get("condition", "None"))
        mobility = normalize_mobility_value(student.get("mobility", "Normal Mobility"))

        if condition in ["Wheelchair User", "Crutches/Walker", "Artificial Limb"]:
            mobility = condition

        severity = normalize_severity_value(student.get("severity", "Low"))
        score = 10.0
        weights = {
            "Sickle Cell": 90.0,
            "Epilepsy": 90.0,
            "Diabetes": 90.0,
            "Cardiac": 90.0,
            "Cardiovascular": 90.0,
            "Neurological": 70.0,
            "Orthopaedic": 65.0,
            # "Physical Disability" removed — captured by mobility_status, not condition_category.
            "Visual Impairment": 60.0,
            "Asthma": 50.0,
            "Respiratory": 50.0,
            "Ulcer": 30.0,
            "Other": 20.0,
            "Mobility": 0.0,
            "Wheelchair User": 0.0,
            "Crutches/Walker": 0.0,
            "Artificial Limb": 0.0,
            "None": 0.0,
        }
        score += weights.get(condition, 0.0)

        mobility_score = 0.0
        if mobility in ["Wheelchair User", "Crutches/Walker", "Artificial Limb"]:
            mobility_score = 90.0 if get_requested_mobility_flag(student) else 75.0

        score = max(score, mobility_score)
        score += severity * 5.0
        return min(float(score), 100.0)
    except Exception:
        return 0.0


def calibrate_policy_score(score: float, student: dict) -> float:
    """
    Apply the hostel-policy calibration layer after the raw model score.

    Policy summary:
      - high-severity medical cases are guaranteed into the High band
      - medical + mobility cases are guaranteed into the High band
      - mobility-only cases are intentionally kept inside the Medium band,
        preserving their relative severity while reserving clinic-proximal
        space for stronger medical need
    """
    score = max(0.0, min(float(score), 100.0))
    condition = normalize_condition_value(student.get("condition", "None"))
    mobility = normalize_mobility_value(student.get("mobility", "Normal Mobility"))
    if condition in MOBILITY_PRIORITY_STATUSES and mobility == "Normal Mobility":
        mobility = condition

    severity = max(1, min(normalize_severity_value(student.get("severity", "Low")), 3))
    has_mobility_priority = mobility in MOBILITY_PRIORITY_STATUSES
    has_medical_condition = condition not in {
        "None",
        "Mobility",
        "Wheelchair User",
        "Crutches/Walker",
        "Artificial Limb",
    }

    if has_medical_condition and has_mobility_priority:
        score = max(score, MEDICAL_MOBILITY_HIGH_FLOORS.get(mobility, 82.0))
    elif has_medical_condition and severity >= 3:
        score = max(score, 78.0)
    elif has_mobility_priority:
        floor = MOBILITY_ONLY_MEDIUM_FLOORS.get(mobility, {}).get(severity, MEDIUM_URGENCY_THRESHOLD)
        score = max(score, floor)
        score = min(score, HIGH_URGENCY_THRESHOLD - 1.0)

    return max(0.0, min(score, 100.0))


def _student_has_mobility_priority(student: dict) -> bool:
    mobility = normalize_mobility_value(student.get("mobility"))
    if mobility in MOBILITY_PRIORITY_STATUSES:
        return True
    return normalize_condition_value(student.get("condition")) in MOBILITY_PRIORITY_STATUSES


def score_student(student):
    if not isinstance(student, dict):
        student = {}

    # For mobility-priority students we intentionally bypass the DB cache.
    # A student may have disclosed their mobility status after their first
    # scoring pass, so the stored urgency_score could be stale and miss the
    # latest policy calibration.
    has_mobility_priority = _student_has_mobility_priority(student)

    if not has_mobility_priority and "urgency_score" in student and student["urgency_score"] is not None:
        try:
            value = float(student["urgency_score"])
            condition = normalize_condition_value(student.get("condition", "None"))
            # Trust the stored score when it is positive OR when the student is
            # confirmed None/Healthy (a legitimate score of 0). Without this
            # check a healthy student with score=0 would trigger an XGBoost
            # call on every allocation run unnecessarily.
            if value > 0 or condition == "None":
                score = max(0.0, min(value, 100.0))
                score = calibrate_policy_score(score, student)
                return {"score": score, "tier": calculate_tier(score), "strategy": "stored"}
        except (TypeError, ValueError):
            pass

    if _use_ml_model:
        try:
            score = calculate_score_pickle(student)
            score = calibrate_policy_score(score, student)
            return {"score": score, "tier": calculate_tier(score), "strategy": "xgboost_model"}
        except Exception as exc:
            logging.error("XGBoost prediction failed, falling back to rules: %s", exc)

    score = calculate_score_fallback(student)
    score = calibrate_policy_score(score, student)
    return {"score": score, "tier": calculate_tier(score), "strategy": "fallback"}


def process_batch_verbose(data_input):
    if isinstance(data_input, dict):
        batch = [data_input]
    elif isinstance(data_input, list):
        batch = data_input
    else:
        batch = []

    detailed = {}
    for index, student in enumerate(batch):
        sid = student.get("id", f"unknown_{index}") if isinstance(student, dict) else f"unknown_{index}"
        detailed[sid] = score_student(student if isinstance(student, dict) else {})
    return detailed


def process_batch(data_input):
    return {sid: result["score"] for sid, result in process_batch_verbose(data_input).items()}


def describe_mode(detailed_results=None):
    if detailed_results and _use_ml_model:
        strategies = {item.get("strategy") for item in detailed_results.values()}
        if "fallback" in strategies and len(strategies) > 1:
            return "XGBoost .pkl Model + Compatibility Fallback"
    return model_descriptor()


load_ml_model()


if __name__ == "__main__":
    mode = model_descriptor()

    if len(sys.argv) > 1:
        try:
            arg = sys.argv[1]
            if os.path.isfile(arg):
                with open(arg, "r", encoding="utf-8") as input_file:
                    input_data = json.load(input_file)
            else:
                input_data = json.loads(arg)

            verbose_results = process_batch_verbose(input_data)
            scores = {sid: payload["score"] for sid, payload in verbose_results.items()}
            tiers = {sid: payload["tier"] for sid, payload in verbose_results.items()}
            print(
                json.dumps(
                    {
                        "status": "success",
                        "mode": describe_mode(verbose_results),
                        "results": scores,
                        "tiers": tiers,
                    }
                )
            )
        except Exception as exc:
            print(json.dumps({"status": "error", "message": str(exc)}))
    else:
        print(json.dumps({"status": "info", "mode": mode, "message": "No input provided"}))
