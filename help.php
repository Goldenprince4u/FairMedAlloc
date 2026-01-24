<?php
/**
 * Help Center
 * Role-based support and documentation.
 */
session_start();
require_once 'db_config.php';
$page_title = "Help Center | FairMedAlloc";
require_once 'includes/header.php';

$role = $_SESSION['role'] ?? 'guest';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <h1 class="serif mb-2">Help Center</h1>
        <p class="text-muted mb-8">Guides and FAQs for <?php echo ucfirst($role); ?>s.</p>

        <div class="grid-help">
            
            <!-- FAQ Section -->
            <div class="card">
                <h3 class="serif mb-6 text-primary">Frequently Asked Questions</h3>
                
                <?php if ($role === 'admin'): ?>
                    <!-- Admin FAQs -->
                    <details class="mb-4 group">
                        <summary class="fw-700 cursor-pointer flex justify-between items-center mb-2 list-none">
                            How do I run the allocation algorithm?
                            <i class="fa-solid fa-chevron-down text-muted"></i>
                        </summary>
                        <p class="text-muted text-sm pl-4 border-l-2 border-primary">
                            Navigate to <strong>Run Allocation</strong>. Ensure current data is uploaded. Click "Start Allocation Engine". The system will clear previous records and re-assign rooms based on urgency scores.
                        </p>
                    </details>
                    <div class="w-full h-px bg-gray-100 mb-4"></div>

                    <details class="mb-4 group">
                        <summary class="fw-700 cursor-pointer flex justify-between items-center mb-2 list-none">
                            How are urgency scores calculated?
                            <i class="fa-solid fa-chevron-down text-muted"></i>
                        </summary>
                        <p class="text-muted text-sm pl-4 border-l-2 border-primary">
                            The system uses a weighted formula: Base Score (10) + Condition Severity (Asthma: +40, Orthopedic: +60) + Mobility (Wheelchair: +30) + Gender Requirements.
                        </p>
                    </details>
                     <div class="w-full h-px bg-gray-100 mb-4"></div>

                    <details class="mb-4 group">
                         <summary class="fw-700 cursor-pointer flex justify-between items-center mb-2 list-none">
                             Can I manually override an allocation?
                             <i class="fa-solid fa-chevron-down text-muted"></i>
                         </summary>
                         <p class="text-muted text-sm pl-4 border-l-2 border-primary">
                             Yes. Go to the <strong>Allocation Matrix</strong>, search for the student, and click "Edit". You can manually assign a Hostel and Room ID.
                         </p>
                     </details>

                <?php else: ?>
                    <!-- Student FAQs -->
                    <details class="mb-4 group">
                        <summary class="fw-700 cursor-pointer flex justify-between items-center mb-2 list-none">
                            My allocation is 'Pending'. Why?
                            <i class="fa-solid fa-chevron-down text-muted"></i>
                        </summary>
                        <p class="text-muted text-sm pl-4 border-l-2 border-primary">
                            The allocation process runs in batches. If you recently registered or updated your profile, please wait for the next administrative cycle.
                        </p>
                    </details>
                    <div class="w-full h-px bg-gray-100 mb-4"></div>

                    <details class="mb-4 group">
                        <summary class="fw-700 cursor-pointer flex justify-between items-center mb-2 list-none">
                            How do I declare a medical condition?
                            <i class="fa-solid fa-chevron-down text-muted"></i>
                        </summary>
                        <p class="text-muted text-sm pl-4 border-l-2 border-primary">
                            Go to <strong>Update Profile</strong>. Under the medical section, select your condition category and provide details. This will update your Urgency Score.
                        </p>
                    </details>
                    <div class="w-full h-px bg-gray-100 mb-4"></div>

                    <details class="mb-4 group">
                        <summary class="fw-700 cursor-pointer flex justify-between items-center mb-2 list-none">
                            Can I change my assigned room?
                            <i class="fa-solid fa-chevron-down text-muted"></i>
                        </summary>
                        <p class="text-muted text-sm pl-4 border-l-2 border-primary">
                            Room swaps are strict. You must visit the Student Affairs Division with valid medical proof if your current room exacerbates a health condition.
                        </p>
                    </details>
                <?php endif; ?>

            </div>

            <!-- Contact / Resources -->
            <div class="flex flex-col gap-4">
                <div class="card bg-slate-50 border border-slate-100">
                    <h3 class="mb-2 serif">Support Contacts</h3>
                    <p class="text-sm text-muted mb-4">Available Mon-Fri, 9am-4pm</p>
                    
                    <div class="flex items-center gap-3 mb-3">
                        <div class="h-8 w-8 rounded bg-blue-50 flex items-center justify-center"><i class="fa-solid fa-phone text-primary"></i></div>
                        <div class="text-sm fw-700 text-primary">+234 800 FAIR MED</div>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="h-8 w-8 rounded bg-blue-50 flex items-center justify-center"><i class="fa-solid fa-envelope text-primary"></i></div>
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