# FairMedAlloc — ML-Driven Medical Hostel Allocation System

[![PHP](https://img.shields.io/badge/PHP-8.x-blue)](https://php.net) [![MySQL](https://img.shields.io/badge/MySQL-8.x-orange)](https://mysql.com) [![License](https://img.shields.io/badge/License-Academic-green)](#)

> A fairness-aware hostel allocation system for Redeemer's University that prioritises students with medical conditions and disabilities, placing them in clinic-proximal residences using an XGBoost-backed urgency scoring engine.

---

## ✨ Features

| Feature | Description |
|---|---|
| 🏥 **Medical Urgency Scoring** | Assigns scores 0–100 based on condition severity (Asthma, Sickle Cell, Orthopedic, Visual Impairment, etc.) |
| 🤖 **ML Allocation Engine** | Python scoring pipeline that uses the provided `.pkl` XGBoost model and falls back to rule-based scoring when the model cannot be used |
| 🏠 **Proximal Hostel Logic** | High-risk students automatically placed nearest the health centre |
| 💳 **Fee-Gated Allocation** | Students only receive a room after school fee payment is confirmed |
| 📋 **CSV Bulk Import** | Admin can import hundreds of students at once via structured CSV |
| 🔐 **Role-Based Access** | Separate admin and student portals with auth guards on every route |
| 🛡️ **CSRF Protection** | All state-mutating POST forms are CSRF-token validated |
| 🔒 **Account Lockout** | 5 failed login attempts triggers a 15-minute lockout |
| 🖨️ **Printable Slip** | Students can print an official allocation slip once assigned |
| 📊 **Analytics Dashboard** | Real-time charts (Chart.js) for allocation progress, medical distribution, hostel occupancy |

---

## 🚀 Quick Setup

### Prerequisites
- XAMPP / WAMP with **Apache + MySQL** running
- PHP **8.0+**
- Python **3.8+** *(optional — only for live ML scoring)*

### 1. Clone / Copy Files
```
Place the FairMedAlloc folder inside: C:\xampp\htdocs\
```

### 2. Configure Environment
Create a `.env` file in the project root:
```ini
DB_HOST=127.0.0.1
DB_USER=root
DB_PASS=
DB_NAME=fairmedalloc
ML_SERVICE_URL=http://127.0.0.1:5051
ML_SERVICE_TIMEOUT=5
PYTHON_BIN=C:/Users/quadr/AppData/Local/Programs/Python/Python311/python.exe
ML_MODEL_PICKLE_PATH=ml_models/xgboost_hostel_model.pkl
```

### 3. Create the Database
Open **phpMyAdmin** → create a database named `fairmedalloc` → import `setup.sql`.

### 4. Create the First Admin

1. Visit `http://localhost/FairMedAlloc/create_admin.php` from the same machine hosting the app.
2. Create the initial administrator account.
3. Sign in at `http://localhost/FairMedAlloc/admin_login.php`.

> ⚠️ **Change your password immediately** after first login via Admin Profile → Security Settings.

---

## 📁 Project Structure

```
FairMedAlloc/
├── admin_login.php          # Admin authentication
├── admin_dashboard.php      # Admin command centre (stats + modules)
├── admin_reports.php        # Analytics: charts, hostel occupancy
├── admin_profile.php        # Admin credential management
├── admin_signup.php         # Create additional admin accounts
├── view_table.php           # Allocation matrix (search, CSV export, manual assign)
├── run_allocation.php       # Trigger the ML allocation engine
├── settings.php             # Configure session, thresholds, lock status
├── upload_data.php          # Bulk CSV student import
│
├── login.php                # Student login
├── signup.php               # Student registration
├── student_dashboard.php    # Student: allocation status + pay fees
├── profile.php              # Student: update medical profile
├── print_slip.php           # Printable allocation document
├── help.php                 # Role-aware FAQ and support contacts
├── forgot_password.php      # Password recovery request
├── reset_password.php       # Token-based password reset
│
├── api/
│   ├── admin_api.php        # Admin AJAX endpoints (rooms, manual assign, analytics, hostel stats)
│   ├── pay_simulation.php   # Fee payment simulation (allocates on next admin cycle)
│   └── get_departments.php  # AJAX: cascading faculty → department dropdown
│
├── includes/
│   ├── AllocationEngine.php    # Core allocation logic (PHP-side)
│   ├── Student.php             # Student model (profile, allocation, payment)
│   ├── NotificationManager.php # Unread notifications
│   ├── security_helper.php     # CSRF, audit log, session helpers
│   ├── header.php              # HTML <head>, fonts, CSS links
│   └── nav.php                 # Sidebar navigation (role-aware)
│
├── css/
│   └── main.css             # Design system (glassmorphism, navy/gold, responsive)
│
├── js/
│   ├── allocation_matrix.js # View table: modal, room fetch, CSV export, toasts
│   └── student_dashboard.js # Pay fees AJAX flow
│
├── ml_models/
│   ├── predict.py           # XGBoost .pkl inference bridge (called by PHP)
│   ├── ml_service.py        # Optional local HTTP wrapper around predict.py
│   ├── xgboost_hostel_model.pkl
│   └── hostel_medical_dataset.csv
│
├── setup.sql                # Full DB schema + seed data (hostels, departments, admin user)
├── db_config.php            # DB connection (reads from .env)
└── README.md
```

---

## 📤 CSV Import Format

Column order for `upload_data.php`:

| # | Column | Example |
|---|---|---|
| 1 | Matric Number | `RUN/CMP/22/001` |
| 2 | Full Name | `Jane Doe` |
| 3 | Level | `200` |
| 4 | Faculty | `Natural Sciences` |
| 5 | Department | `Biochemistry` |
| 6 | Gender | `Female` |
| 7 | Medical Condition | `Asthma` (or `None`) |
| 8 | Severity | `Low`, `Medium`, or `High` *(optional, defaults to Low)* |
| 9 | Mobility Status | `Normal Mobility` *(optional)* |
| 10 | Paid Status | `1` or `0` *(optional, defaults to `0`)* |

> Default student password = lowercase matric number (e.g. `run/cmp/22/001`). Students should change this on first login.

---

## 🤖 ML Model Setup *(Optional)*

Place the provided `.pkl` model in `ml_models/` as `xgboost_hostel_model.pkl`, keep `hostel_medical_dataset.csv` alongside it for audit/reference purposes, and point `ML_MODEL_PICKLE_PATH` in `.env` to the exact file if you move it.

The app now uses the same XGBoost scoring pipeline during signup, profile updates, CSV import, admin rescoring, and allocation runs.

### Optional Local ML API

Run the local-only scoring service from `ml_models/`:

```bash
py -3 ml_service.py
```

It binds to `http://127.0.0.1:5051` by default and exposes:

- `GET /health`
- `POST /ml/score-batch`

Example request:

```bash
curl -X POST http://127.0.0.1:5051/ml/score-batch ^
  -H "Content-Type: application/json" ^
  -d "[{\"id\":1,\"condition\":\"Asthma\",\"mobility\":\"Normal Mobility\",\"severity\":\"High\",\"academic_level\":400,\"has_special_needs\":0}]"
```

---

## 🔐 Security Notes

- All DB interactions use **prepared statements** (zero raw interpolation)
- **CSRF tokens** on all POST forms
- **Account lockout** after 5 failed login attempts (15-minute lock)
- **Role + session checks** on every protected page and API endpoint
- **File type + MIME validation** on profile picture uploads

---

## 🛠️ Troubleshooting

| Issue | Fix |
|---|---|
| `Database Connection Failed` | Ensure XAMPP MySQL module is running (green) |
| `Table 'X' doesn't exist` | Re-import `setup.sql` via phpMyAdmin |
| No admin account exists yet | Visit `http://localhost/FairMedAlloc/create_admin.php` locally to create the first admin |
| `shell_exec` disabled | ML scoring falls back to rule-based; enable in `php.ini` if needed |
| Allocation not running | Check `settings.php` — ensure "Allocation Status" is set to **Open** |

---

## 📜 License

Academic project — Redeemer's University, Ede, Osun State, Nigeria. All rights reserved.
