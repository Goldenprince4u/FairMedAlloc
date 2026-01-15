# FYP Integration Guide: Frontend & Allocation System

**Target Audience**: ML Model Developer & Database Administrator.
**Purpose**: explaining how to connect your independent modules to the Main Web Application (PHP/MySQL).

## 1. Database Schema Alignment
The Frontend expects the following table structure. Please ensure your backend syncs to these tables:

### Table: `students`
| Column | Type | Description |
| :--- | :--- | :--- |
| `matric_no` | VARCHAR | **Primary Key** (Linkage ID) |
| `urgency_score` | FLOAT | **Output from ML Model** (0-100) |
| `medical_condition` | TEXT | Raw input for ML |
| `mobility_status` | TEXT | Raw input for ML |
| `has_paid` | BOOL | 1=Paid, 0=Unpaid (Fee Check) |

> **Critical**: Your ML Model should read `medical_condition` and write the result to `urgency_score`.

## 2. Connecting the ML Model
Since the ML model is likely Python (XGBoost), you have two options to update the frontend:

### Option A: Direct Database Connection (Recommended)
Your Python script connects directly to the MySQL database:
```python
import mysql.connector

# Connect to the Frontend DB
db = mysql.connector.connect(
  host="localhost", user="root", password="", database="fairmedalloc"
)

# 1. Read Data
cursor.execute("SELECT matric_no, medical_condition FROM students")
students = cursor.fetchall()

# 2. Predict Score
for s in students:
    score = MyXGBoostModel.predict(s['medical_condition'])
    
    # 3. Update Frontend
    cursor.execute("UPDATE students SET urgency_score = %s WHERE matric_no = %s", (score, s['matric_no']))

db.commit()
```

### Option B: REST API (If on different servers)
I have created an endpoint at: `http://[host]/FairMedAlloc/api/update_score.php`
*   **Method**: POST
*   **Payload**: `{"matric": "RUN/2026/001", "score": 85.5}`

## 3. Allocation Logic
The Frontend handles the **Room Assignment** based on the scores you provide.
*   **Logic**: `ORDER BY urgency_score DESC`
*   **Threshold**: Configurable in Admin Dashboard (Default: 70).
*   **Output**: Updates `allocations` table.

## 4. Frontend "Demo Mode"
Until the real ML model is connected, the `run_allocation.php` file contains a **Rule-Based Fallback** (PHP logic mimicking XGBoost) so the UI serves as a fully functional prototype for project defense.
