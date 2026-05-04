# CHAPTER 4: SYSTEM IMPLEMENTATION AND RESULT DISCUSSION

## 4.1 Introduction
This chapter discusses the implementation of the FairMedAlloc system, detailing the technologies, algorithmic strategies, and architectural decisions used to develop the application. Furthermore, it presents a discussion of the system's performance results, focusing on the optimization of the allocation engine, the adherence to accessibility constraints, and the overall efficiency of the hostel assignment process.

## 4.2 Choice of Development Technologies
The FairMedAlloc system was designed as a modern, decoupled web application. The core logic is distributed across different technology stacks to maximize performance and maintainability:
* **Frontend (User Interface):** Developed using HTML5, Vanilla CSS, and JavaScript. The administrative dashboard utilizes asynchronous JavaScript to allow non-blocking interactions with the server, providing real-time progress updates during massive allocation batches without freezing the browser.
* **Backend (Orchestration):** Developed using PHP 8 and MySQL. PHP acts as the "Traffic Cop" and primary orchestrator. It manages user authentication, database persistence, and the asynchronous job queue.
* **Artificial Intelligence (Urgency Scoring):** Implemented in Python 3 using the `scikit-learn` and `xgboost` libraries. An XGBoost regression model was trained on historical medical records to generate raw urgency scores based on nine distinct medical and mobility features.
* **Mathematical Solver (Allocation Engine):** Implemented using Google OR-Tools in Python. Specifically, the system utilizes the `SimpleMinCostFlow` graph matching algorithm to mathematically guarantee optimal room assignments based on the AI scores and university architectural constraints.

## 4.3 System Architecture and Implementation
The allocation pipeline is divided into three distinct layers to ensure that the AI model remains decoupled from the physical architectural constraints of the university.

### 4.3.1 Layer 1: XGBoost AI Scoring
The first phase of the system involves evaluating a student's medical urgency. The PHP orchestrator securely passes the student's medical profile to the Python backend. The trained XGBoost model evaluates binary flags (e.g., `has_sickle_cell`, `has_asthma`) alongside numerical severity matrices to generate a raw urgency score ranging from 0 to 100.

### 4.3.2 Layer 2: Policy Calibration
Because the AI model calculates medical necessity without awareness of physical campus constraints, a Policy Calibration layer was implemented. This script intercepts the AI’s raw score and enforces administrative rules:
* Students with high-severity illnesses are escalated into the **High Band (75-100)** to guarantee clinic-proximal placement.
* Students with mobility-only issues (e.g., a broken leg) who do not require clinic proximity are deliberately capped in the **Medium Band (40-74)**. This ensures that scarce clinic beds are reserved for severe medical emergencies, while still providing the mobility-impaired student with a mathematically guaranteed ground-floor room.

### 4.3.3 Layer 3: Min-Cost Flow Graph Matching
The calibrated scores and bands are subsequently fed into the allocation engine. In early prototypes, the system utilized a Constraint Programming (CP-SAT) solver. However, as the dataset scaled to thousands of students, the NP-Hard nature of CP-SAT resulted in severe performance degradation and timeout failures. 

To resolve this, the architecture was upgraded to utilize a **Min-Cost Flow** algorithm. The university's hostel infrastructure and the student body were modeled as a massive, directed flow network (bipartite graph). The engine connects "Student Nodes" to "Bedspace Nodes" using edges weighted by extreme mathematical bonuses. For example:
* A High-Band student matching with a clinic-proximal room is granted a `+5,000,000` edge weight.
* A mobility-impaired student matching with a guaranteed ground-floor room is granted a `+2,200,000` edge weight.

The algorithm pushes the student flow through the network to maximize the total weight. This transition resulted in a mathematically perfect allocation that bypasses NP-Hard complexity, resolving 3,000+ students in under 1.5 seconds.

## 4.4 Implementation of Accessibility Constraints
A primary objective of the FairMedAlloc system was to automate adherence to the university's strict accessibility policies. This was successfully implemented through a two-step "Accessibility Lock" mechanism:

1. **The Ground Floor Rule:** During the Min-Cost Flow graph generation, if a student is flagged with a physical disability (`Wheelchair User`, `Crutches/Walker`, `Artificial Limb`) and their faculty mapping directs them to a multi-storey hostel (e.g., Joshua Hall or Deborah Hall), the Python engine explicitly severs the network paths to any room where the `floor_level` is greater than 0.
2. **The Lower Bunk (LB) Rule:** Once the graph matcher assigns a student to a specific ground-floor room, control is returned to the PHP orchestration engine. The engine actively parses the room's precise bed configuration string (e.g., `LB, UB, LB, UB`). If the student possesses a mobility impairment, the system forcefully allocates them to a Lower Bunk (`LB`) bedspace before standard students are allowed to claim the remaining capacity.

## 4.5 System Robustness and Queue Management
To handle the potential load of processing up to 15,000 students simultaneously, an asynchronous job queue was implemented (`allocation_jobs` table). When an administrator triggers an allocation, the UI avoids freezing by dispatching the job to a background worker (`worker_launcher.php`). 

Furthermore, an **Inline Fallback** mechanism was engineered. If the application is deployed in a restrictive environment (such as a Windows XAMPP server where background process spawning is blocked), the system detects the failure and instantly runs the allocation synchronously. Because the Min-Cost Flow engine completes its calculations in under two seconds, this synchronous fallback executes flawlessly without triggering browser timeout errors.

## 4.6 Result Discussion and Evaluation

### 4.6.1 Performance Gains
The most significant result observed during the implementation phase was the reduction in computational processing time. 
* **Legacy System (CP-SAT):** Processing 3,000 students required between 8 to 15 minutes, frequently resulting in server timeouts, abandoned database locks, and incomplete assignments.
* **Upgraded System (Min-Cost Flow):** Processing the identical dataset of 3,000 students executes in an average of 1.5 seconds, representing a near-instantaneous optimization. The total end-to-end turnaround time—including database fetching, AI scoring, graph matching, and bulk database insertion—now averages 10 to 12 seconds.

### 4.6.2 Fairness and Optimality
The Min-Cost Flow engine consistently returned an `OPTIMAL` solver status across all testing scenarios. By separating the student body into strict bands and applying massive edge weights, the system proved resilient to priority inversion. A cluster of lower-scoring students can no longer mathematically override a severe medical case for a clinic bed, completely eliminating human bias and arbitrary room assignment errors.

### 4.6.3 Accessibility Compliance
Database audits of the generated allocations confirmed 100% compliance with the accessibility constraints. Out of the 6,736 total undergraduate bedspaces recorded in the system, a proportional number of Lower Bunks (`LB`) are located on the ground floor. The system successfully mapped every registered disabled student to these exact coordinates without manual administrative intervention, effectively fulfilling the project's core accessibility mandate.
