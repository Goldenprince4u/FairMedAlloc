<?php
/**
 * Help Center
 * Role-based support and documentation.
 */
session_start();

// Auth guard — must be logged in to view help
if (!isset($_SESSION['logged_in'])) { header('Location: login.php'); exit(); }

$page_title = "Help Center | FairMedAlloc";
require_once 'includes/header.php';

$role = $_SESSION['role'] ?? 'student';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <h1 class="serif mb-2">Help Center</h1>
        <p class="text-muted mb-8">Guides and FAQs for <?php echo ucfirst($role); ?>s.</p>

        <div class="grid-help">
            <!-- FAQ Section -->
            <div class="glass-card p-8">
                <h3 class="serif mb-6 text-primary">Frequently Asked Questions</h3>
                
                <?php if ($role === 'admin'): ?>
                    <!-- Admin FAQs -->
                    <details class="mb-4">
                        <summary class="fw-700" style="cursor:pointer; padding:0.5rem 0; list-style:none; display:flex; justify-content:space-between; align-items:center;">
                            How do I run the allocation algorithm?
                            <i class="fa-solid fa-chevron-down text-muted"></i>
                        </summary>
                        <p class="text-muted text-sm mt-2" style="padding-left:1rem; border-left:2px solid var(--c-primary);">
                            Navigate to <strong>Run Allocation</strong>. Ensure current data is uploaded. Click "Start Allocation Engine". The system will clear previous records and re-assign rooms based on urgency scores.
                        </p>
                    </details>
                    <div style="height:1px; background:var(--c-border); margin-bottom:1rem;"></div>

                    <details class="mb-4">
                        <summary class="fw-700" style="cursor:pointer; padding:0.5rem 0; list-style:none; display:flex; justify-content:space-between; align-items:center;">
                            How are urgency scores calculated?
                            <i class="fa-solid fa-chevron-down text-muted"></i>
                        </summary>
                        <p class="text-muted text-sm mt-2" style="padding-left:1rem; border-left:2px solid var(--c-primary);">
                            The system uses a weighted formula: <strong>Base (10)</strong> + <strong>Condition Weight</strong>
                            (Sickle Cell/Epilepsy/Diabetes/Cardiovascular: +90, Neurological: +70, Physical Disability: +65,
                            Visual Impairment: +60, Asthma/Respiratory: +50, Ulcer: +30, Other: +20)
                            + <strong>Severity</strong> (Low: +5, Medium: +10, High: +15). Capped at 100.
                            High-mobility-need students (wheelchair) receive an additional +10 boost.
                        </p>
                    </details>
                    <div style="height:1px; background:var(--c-border); margin-bottom:1rem;"></div>

                    <details class="mb-4">
                         <summary class="fw-700" style="cursor:pointer; padding:0.5rem 0; list-style:none; display:flex; justify-content:space-between; align-items:center;">
                             Can I manually override an allocation?
                             <i class="fa-solid fa-chevron-down text-muted"></i>
                         </summary>
                         <p class="text-muted text-sm mt-2" style="padding-left:1rem; border-left:2px solid var(--c-primary);">
                             Yes. Go to the <strong>Allocation Matrix</strong>, search for the student, and click "Edit". You can manually assign a Hostel and Room ID.
                         </p>
                     </details>

                <?php else: ?>
                    <!-- Student FAQs -->
                    <details class="mb-4">
                        <summary class="fw-700" style="cursor:pointer; padding:0.5rem 0; list-style:none; display:flex; justify-content:space-between; align-items:center;">
                            My allocation is 'Pending'. Why?
                            <i class="fa-solid fa-chevron-down text-muted"></i>
                        </summary>
                        <p class="text-muted text-sm mt-2" style="padding-left:1rem; border-left:2px solid var(--c-primary);">
                            The allocation process runs in batches. If you recently registered or updated your profile, please wait for the next administrative cycle.
                        </p>
                    </details>
                    <div style="height:1px; background:var(--c-border); margin-bottom:1rem;"></div>

                    <details class="mb-4">
                        <summary class="fw-700" style="cursor:pointer; padding:0.5rem 0; list-style:none; display:flex; justify-content:space-between; align-items:center;">
                            How do I declare a medical condition?
                            <i class="fa-solid fa-chevron-down text-muted"></i>
                        </summary>
                        <p class="text-muted text-sm mt-2" style="padding-left:1rem; border-left:2px solid var(--c-primary);">
                            Go to <strong>Update Profile</strong>. Under the medical section, select your condition category and provide details. This will update your Urgency Score.
                        </p>
                    </details>
                    <div style="height:1px; background:var(--c-border); margin-bottom:1rem;"></div>

                    <details class="mb-4">
                        <summary class="fw-700" style="cursor:pointer; padding:0.5rem 0; list-style:none; display:flex; justify-content:space-between; align-items:center;">
                            Can I change my assigned room?
                            <i class="fa-solid fa-chevron-down text-muted"></i>
                        </summary>
                        <p class="text-muted text-sm mt-2" style="padding-left:1rem; border-left:2px solid var(--c-primary);">
                            Room swaps are strict. You must visit the Student Affairs Division with valid medical proof if your current room exacerbates a health condition.
                        </p>
                    </details>
                <?php endif; ?>

            </div>

            <!-- Contact / Resources -->
            <div class="flex flex-col gap-4">
                <div class="glass-card surface-inset">
                    <h3 class="mb-2 serif">Support Contacts</h3>
                    <p class="text-sm text-muted mb-4">Available Mon–Fri, 9am–4pm</p>
                    
                    <div class="flex items-center gap-3 mb-3">
                        <div style="width:32px;height:32px;border-radius:8px;background:rgba(59,130,246,0.08);display:flex;align-items:center;justify-content:center;">
                            <i class="fa-solid fa-phone text-primary"></i>
                        </div>
                        <div class="text-sm fw-700 text-primary">+234 800 FAIR MED</div>
                    </div>
                    <div class="flex items-center gap-3">
                        <div style="width:32px;height:32px;border-radius:8px;background:rgba(59,130,246,0.08);display:flex;align-items:center;justify-content:center;">
                            <i class="fa-solid fa-envelope text-primary"></i>
                        </div>
                        <div class="text-sm">support@fairmed.edu.ng</div>
                    </div>
                </div>

                <div class="card text-center">
                    <div class="icon-box blue mx-auto mb-4"><i class="fa-solid fa-file-pdf"></i></div>
                    <h4 class="mb-2">User Manual</h4>
                    <p class="text-xs text-muted mb-4">Download the official usage guide.</p>
                    <button class="btn btn-outline w-full text-sm">Download PDF</button>
                </div>
            </div>

        </div>
    </main>
</div>
</body>
</html>