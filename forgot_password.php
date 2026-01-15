<?php
/**
 * Forgot Password
 * Account recovery flow.
 */
session_start();
$page_title = "Recover Access | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="auth-container">
    <!-- Left: Brand -->
    <div class="auth-left">
        <div class="brand-content">
             <i class="fa-solid fa-shield-halved text-4xl text-accent mb-6" style="font-size: 4rem; color: var(--c-accent); margin-bottom: 1.5rem;"></i>
             <h1 style="font-size: 2.5rem; line-height: 1.1; margin-bottom: 1rem; font-weight: 700;">Secure Recovery</h1>
             <p style="font-size: 1.25rem; opacity: 0.9; font-weight: 300;">Identity verification is required to reset your access credentials.</p>
        </div>
    </div>

    <!-- Right: Form -->
    <div class="auth-right">
        <div class="auth-box glass-card" style="background: white; border: none; box-shadow: none;">
            <a href="login.php" class="mb-6 inline-block text-muted" style="font-size: 0.85rem;"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>
            
            <h2 class="mb-2" style="font-size: 2rem; color: var(--c-primary);">Forgot Password?</h2>
            <p class="text-muted mb-6">Enter your matriculation number to reset your access key.</p>

            <form method="post">
                <div class="form-group">
                    <label>Matric No / Username</label>
                    <input type="text" name="username" placeholder="RUN/..." required>
                </div>

                <button class="btn btn-primary w-full mb-6" style="width: 100%;">
                    Request Reset Link
                </button>
            </form>
            
            <div class="alert alert-info text-center" style="font-size: 0.8rem; background: #eff6ff; color: #1e3a8a; border: none;">
                Contact <strong style="color: var(--c-primary);">sysadmin@fairmed.edu.ng</strong> if you cannot access your student email.
            </div>
        </div>
    </div>
</div>
</body>
</html>