<?php
/**
 * Help Center
 * Role-based support and documentation.
 */
session_start();
if (!isset($_SESSION['logged_in'])) { header('Location: login.php'); exit(); }

require_once 'db_config.php';

$page_title = "Help Center | FairMedAlloc";
require_once 'includes/header.php';
$role = $_SESSION['role'] ?? 'student';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-info">
                <h1>Help &amp; Support</h1>
                <p class="text-muted">Guides and FAQs for <?php echo ucfirst($role); ?>s.</p>
            </div>
        </div>

        <div class="grid-help">

            <!-- ── FAQ Section ── -->
            <div>
                <div class="card" style="padding:2rem;">
                    <h3 style="margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:1px solid var(--c-border);display:flex;align-items:center;gap:0.625rem;">
                        <i class="fa-solid fa-circle-question" style="color:var(--c-primary);font-size:1rem;"></i>
                        Frequently Asked Questions
                    </h3>

                    <?php if ($role === 'admin'): ?>
                        <!-- Admin FAQs -->
                        <details class="faq-item" id="faq-admin-run">
                            <summary>How do I run the allocation algorithm?</summary>
                            <div class="faq-answer">
                                Navigate to <strong>Run Allocation</strong> in the sidebar. Ensure the student batch has been uploaded through <strong>Data Import</strong> and the session is set to <em>Open</em>. Click <strong>"Start Allocation Engine"</strong>. The system will process eligible imported students together with students whose portal payment has been confirmed through the pay simulator.
                            </div>
                        </details>

                        <details class="faq-item" id="faq-admin-policy">
                            <summary>What is the current allocation policy?</summary>
                            <div class="faq-answer">
                                <strong>High</strong> urgency students remain clinic-priority regardless of faculty. <strong>Medium</strong> urgency students are steered to their faculty-proximal halls, while <strong>Low</strong> urgency students are now kept inside their faculty-proximal hall set instead of being sent to arbitrary spare halls. For mobility-priority students mapped to <strong>Joshua Hall</strong> or <strong>Deborah Hall</strong>, the allocator targets the <strong>ground floor</strong> because those halls are stair-access, not elevator-access.
                            </div>
                        </details>

                        <details class="faq-item" id="faq-admin-score">
                            <summary>How are urgency scores calculated?</summary>
                            <div class="faq-answer">
                                The system uses a weighted formula:<br>
                                <strong>Base (10)</strong> + <strong>Condition Weight</strong> (Sickle Cell/Epilepsy/Diabetes/Cardiovascular: +90, Neurological: +70, Physical Disability: +65, Visual Impairment: +60, Asthma/Respiratory: +50, Ulcer: +30, Other: +20) + <strong>Severity</strong> (Low: +5, Medium: +10, High: +15). Capped at 100. Wheelchair users receive an additional +10 boost.
                            </div>
                        </details>

                        <details class="faq-item" id="faq-admin-override">
                            <summary>Can I manually override an allocation?</summary>
                            <div class="faq-answer">
                                Yes. Navigate to the <strong>Allocation Matrix</strong> via the sidebar, search for the student, and click <strong>Edit</strong>. You can manually assign a Hostel and Room ID. This bypasses the algorithm for that student.
                            </div>
                        </details>

                        <details class="faq-item" id="faq-admin-csv">
                            <summary>How do I import student records via CSV?</summary>
                            <div class="faq-answer">
                                Go to <strong>Data Import</strong>. Download the CSV template, fill it with student records, and upload the file. The system validates each row, preserves imported payment status from the university export, stores the student batch, and includes eligible records the next time the admin runs allocation.
                            </div>
                        </details>

                        <details class="faq-item" id="faq-admin-reset">
                            <summary>How do I reset a user's password?</summary>
                            <div class="faq-answer">
                                Open <strong>Reset Password</strong> from the sidebar, enter the user's matric number or username, and issue a temporary password. Share it directly with the user and ask them to change it after signing in.
                            </div>
                        </details>

                        <details class="faq-item" id="faq-admin-lock">
                            <summary>How do I close the allocation session?</summary>
                            <div class="faq-answer">
                                Go to <strong>System Settings</strong> and change the <em>Allocation Status</em> to <strong>Locked</strong>. This prevents the algorithm from being re-run and freezes all current allocations.
                            </div>
                        </details>

                    <?php else: ?>
                        <!-- Student FAQs -->
                        <details class="faq-item" id="faq-student-pending">
                            <summary>My allocation shows "Pending". Why?</summary>
                            <div class="faq-answer">
                                The allocation process runs in admin-managed batches. If you recently registered or updated your profile, please wait for the next allocation batch. Ensure you have also completed your portal payment through the pay simulator.
                            </div>
                        </details>

                        <details class="faq-item" id="faq-student-condition">
                            <summary>How do I declare a medical condition?</summary>
                            <div class="faq-answer">
                                Go to <strong>My Profile</strong> in the sidebar. Under the <em>Medical &amp; Health Status</em> section, select your condition category and severity level, then click <strong>Save Changes</strong>. This will update your urgency score for the next allocation run.
                            </div>
                        </details>

                        <details class="faq-item" id="faq-student-score">
                            <summary>What is an urgency score?</summary>
                            <div class="faq-answer">
                                Your urgency score (0–100) determines your priority in the allocation queue. Students with higher scores (e.g. Sickle Cell, Epilepsy) are placed in hostels closest to the university health centre. Your score is calculated from your medical condition, its severity, and your mobility status.
                            </div>
                        </details>

                        <details class="faq-item" id="faq-student-noalloc">
                            <summary>I paid my fees but have no allocation. What do I do?</summary>
                            <div class="faq-answer">
                                First, confirm that your payment was captured either from the university portal export or through the pay simulator and that your profile is complete (level, department, gender, medical status). If all is correct, the admin may not have run the next allocation batch yet. Contact the Student Affairs Division with your payment reference if the delay persists.
                            </div>
                        </details>

                        <details class="faq-item" id="faq-student-reset">
                            <summary>I forgot my password. What should I do?</summary>
                            <div class="faq-answer">
                                Password recovery is currently handled by the administration team. Contact the Student Affairs Division or system administrator with your matric number so an admin can issue you a temporary password.
                            </div>
                        </details>

                        <details class="faq-item" id="faq-student-print">
                            <summary>How do I print my allocation slip?</summary>
                            <div class="faq-answer">
                                Once allocated, go to <strong>Allocation Slip</strong> in the sidebar. Click <strong>"Print Official Slip"</strong>. Your browser print dialog will open. The slip contains your name, matric number, hostel, block, room, and bed assignment.
                            </div>
                        </details>

                        <details class="faq-item" id="faq-student-swap">
                            <summary>Can I change my assigned room?</summary>
                            <div class="faq-answer">
                                Room swaps are strictly managed. If your current room exacerbates a medical condition, visit the <strong>Student Affairs Division</strong> with valid medical documentation. The administrator can manually update your allocation via the management console.
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Right Column: Support & Resources ── -->
            <div class="flex flex-col gap-4">

                <!-- Support Contacts -->
                <div class="card" style="padding:1.75rem;">
                    <h4 style="margin-bottom:0.25rem;">
                        <i class="fa-solid fa-headset" style="color:var(--c-primary);margin-right:0.5rem;font-size:0.9rem;"></i>
                        Support Contacts
                    </h4>
                    <p class="text-muted" style="font-size:0.8rem;margin-bottom:1.25rem;">Available Mon–Fri, 9am–4pm</p>

                    <div style="display:flex;flex-direction:column;gap:0.875rem;">
                        <div style="display:flex;align-items:center;gap:0.875rem;">
                            <div style="width:36px;height:36px;border-radius:6px;background:rgba(37,99,235,0.07);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fa-solid fa-phone" style="color:var(--c-info);font-size:0.85rem;"></i>
                            </div>
                            <div>
                                <div style="font-size:0.72rem;color:var(--c-text-muted);text-transform:uppercase;letter-spacing:0.05em;font-weight:600;">Phone</div>
                                <div style="font-size:0.875rem;font-weight:600;color:var(--c-text-head);">+234 800 FAIR MED</div>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:0.875rem;">
                            <div style="width:36px;height:36px;border-radius:6px;background:rgba(37,99,235,0.07);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fa-solid fa-envelope" style="color:var(--c-info);font-size:0.85rem;"></i>
                            </div>
                            <div>
                                <div style="font-size:0.72rem;color:var(--c-text-muted);text-transform:uppercase;letter-spacing:0.05em;font-weight:600;">Email</div>
                                <div style="font-size:0.875rem;color:var(--c-text-head);">support@fairmed.edu.ng</div>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:0.875rem;">
                            <div style="width:36px;height:36px;border-radius:6px;background:rgba(37,99,235,0.07);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fa-solid fa-location-dot" style="color:var(--c-info);font-size:0.85rem;"></i>
                            </div>
                            <div>
                                <div style="font-size:0.72rem;color:var(--c-text-muted);text-transform:uppercase;letter-spacing:0.05em;font-weight:600;">Office</div>
                                <div style="font-size:0.875rem;color:var(--c-text-head);">Student Affairs Division, Admin Block</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Links -->
                <div class="card" style="padding:1.75rem;">
                    <h4 style="margin-bottom:1.25rem;">
                        <i class="fa-solid fa-link" style="color:var(--c-primary);margin-right:0.5rem;font-size:0.9rem;"></i>
                        Quick Links
                    </h4>
                    <div style="display:flex;flex-direction:column;gap:0.5rem;">
                        <?php if ($role === 'student'): ?>
                            <a href="student_dashboard.php" class="text-primary" style="font-size:0.875rem;display:flex;align-items:center;gap:0.5rem;padding:0.5rem 0;border-bottom:1px solid var(--c-border);">
                                <i class="fa-solid fa-gauge-high" style="width:16px;text-align:center;font-size:0.8rem;"></i> Dashboard
                            </a>
                            <a href="profile.php" class="text-primary" style="font-size:0.875rem;display:flex;align-items:center;gap:0.5rem;padding:0.5rem 0;border-bottom:1px solid var(--c-border);">
                                <i class="fa-solid fa-user" style="width:16px;text-align:center;font-size:0.8rem;"></i> My Profile
                            </a>

                            <a href="print_slip.php" class="text-primary" style="font-size:0.875rem;display:flex;align-items:center;gap:0.5rem;padding:0.5rem 0;">
                                <i class="fa-solid fa-print" style="width:16px;text-align:center;font-size:0.8rem;"></i> Allocation Slip
                            </a>
                        <?php else: ?>
                            <a href="admin_dashboard.php" class="text-primary" style="font-size:0.875rem;display:flex;align-items:center;gap:0.5rem;padding:0.5rem 0;border-bottom:1px solid var(--c-border);">
                                <i class="fa-solid fa-gauge-high" style="width:16px;text-align:center;font-size:0.8rem;"></i> Dashboard
                            </a>
                            <a href="view_table.php" class="text-primary" style="font-size:0.875rem;display:flex;align-items:center;gap:0.5rem;padding:0.5rem 0;border-bottom:1px solid var(--c-border);">
                                <i class="fa-solid fa-table-cells" style="width:16px;text-align:center;font-size:0.8rem;"></i> Allocation Matrix
                            </a>
                            <a href="run_allocation.php" class="text-primary" style="font-size:0.875rem;display:flex;align-items:center;gap:0.5rem;padding:0.5rem 0;">
                                <i class="fa-solid fa-wand-magic-sparkles" style="width:16px;text-align:center;font-size:0.8rem;"></i> Run Allocation
                            </a>
                            <a href="admin_signup.php" class="text-primary" style="font-size:0.875rem;display:flex;align-items:center;gap:0.5rem;padding:0.5rem 0;">
                                <i class="fa-solid fa-user-plus" style="width:16px;text-align:center;font-size:0.8rem;"></i> Create Admin
                            </a>
                            <a href="admin_reset_password.php" class="text-primary" style="font-size:0.875rem;display:flex;align-items:center;gap:0.5rem;padding:0.5rem 0;">
                                <i class="fa-solid fa-key" style="width:16px;text-align:center;font-size:0.8rem;"></i> Reset Password
                            </a>
                            <a href="ALLOCATION_POLICY.md" class="text-primary" style="font-size:0.875rem;display:flex;align-items:center;gap:0.5rem;padding:0.5rem 0;">
                                <i class="fa-solid fa-file-lines" style="width:16px;text-align:center;font-size:0.8rem;"></i> Allocation Policy
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- System Info -->
                <div class="card" style="padding:1.75rem;">
                    <h4 style="margin-bottom:1rem;">
                        <i class="fa-solid fa-circle-info" style="color:var(--c-primary);margin-right:0.5rem;font-size:0.9rem;"></i>
                        System Info
                    </h4>
                    <div style="display:flex;flex-direction:column;gap:0.5rem;font-size:0.82rem;">
                        <div style="display:flex;justify-content:space-between;">
                            <span class="text-muted">Version</span>
                            <span style="font-weight:600;color:var(--c-text-head);">1.0.0</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span class="text-muted">Institution</span>
                            <span style="font-weight:600;color:var(--c-text-head);">Redeemer's University</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span class="text-muted">Role</span>
                            <span style="font-weight:600;color:var(--c-text-head);"><?php echo ucfirst($role); ?></span>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </main>
</div>

<!-- FAQ accordion styles -->
<style>
.faq-item {
    border-bottom: 1px solid var(--c-border);
    margin-bottom: 0;
}
.faq-item:last-child { border-bottom: none; }
.faq-item summary {
    cursor: pointer;
    padding: 1rem 0;
    list-style: none;
    font-weight: 600;
    font-size: 0.9rem;
    color: var(--c-text-head);
    display: flex;
    justify-content: space-between;
    align-items: center;
    user-select: none;
}
.faq-item summary::after {
    content: '\f078';
    font-family: 'Font Awesome 6 Free';
    font-weight: 900;
    font-size: 0.7rem;
    color: var(--c-text-muted);
    transition: transform 0.2s;
    flex-shrink: 0;
    margin-left: 1rem;
}
.faq-item[open] summary::after {
    transform: rotate(180deg);
}
.faq-item[open] summary {
    color: var(--c-primary);
}
.faq-answer {
    padding: 0 0 1.25rem;
    font-size: 0.875rem;
    line-height: 1.7;
    color: var(--c-text-body);
    border-left: 3px solid var(--c-primary);
    padding-left: 1rem;
    margin-left: 0;
}
</style>

</body>
</html>
