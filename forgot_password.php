<?php
/**
 * forgot_password.php
 * Temporary password-recovery landing page.
 *
 * The email/token reset flow is intentionally disabled for now.
 * Users are directed to the administration team for an issued temporary password.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';

$page_title = "Recover Access | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="auth-container">
    <div class="auth-left">
        <div class="brand-content">
             <i class="fa-solid fa-shield-halved text-4xl text-accent mb-6" style="font-size: 4rem; color: var(--c-accent); margin-bottom: 1.5rem;"></i>
             <h1 style="font-size: 2.5rem; line-height: 1.1; margin-bottom: 1rem; font-weight: 700;">Assisted Recovery</h1>
             <p style="font-size: 1.1rem; opacity: 0.9; font-weight: 400;">Password recovery is currently handled by an authorized administrator.</p>
        </div>
    </div>

    <div class="auth-right">
        <div class="auth-box">
            <a href="login.php" class="mb-6 inline-block text-muted" style="font-size: 0.85rem;"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>

            <h2 class="mb-2" style="font-size: 2rem; color: var(--c-primary);">Forgot Password?</h2>
            <p class="text-muted mb-6">For this project build, temporary passwords are issued by the administration team instead of email reset links.</p>

            <div class="alert alert-info mb-6">
                <i class="fa-solid fa-key"></i>
                Contact the Student Affairs Division or system administrator with your matric number or username to receive a temporary password.
            </div>

            <div class="card" style="padding:1.25rem;background:var(--c-bg-subtle);border:1px solid var(--c-border);box-shadow:none;">
                <div style="display:flex;flex-direction:column;gap:0.9rem;">
                    <div>
                        <div class="text-xs text-muted" style="text-transform:uppercase;letter-spacing:0.06em;font-weight:700;">What to provide</div>
                        <div class="text-body" style="margin-top:0.25rem;">Your matric number or username and your full name.</div>
                    </div>
                    <div>
                        <div class="text-xs text-muted" style="text-transform:uppercase;letter-spacing:0.06em;font-weight:700;">Where to go</div>
                        <div class="text-body" style="margin-top:0.25rem;">Student Affairs Division, Admin Block.</div>
                    </div>
                    <div>
                        <div class="text-xs text-muted" style="text-transform:uppercase;letter-spacing:0.06em;font-weight:700;">Support</div>
                        <div class="text-body" style="margin-top:0.25rem;">support@fairmed.edu.ng</div>
                    </div>
                </div>
            </div>

            <div class="mt-6" style="display:flex;gap:0.75rem;flex-wrap:wrap;">
                <a href="help.php" class="btn btn-outline">
                    <i class="fa-solid fa-circle-question"></i> Help &amp; FAQs
                </a>
                <a href="login.php" class="btn btn-primary">
                    <i class="fa-solid fa-right-to-bracket"></i> Return to Login
                </a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
