<?php
/**
 * FairMedAlloc - Landing Page
 * Clean institutional design — no glassmorphism.
 */
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FairMedAlloc | Redeemer's University Hostel Allocation System</title>
    <meta name="description" content="A fairness-aware, ML-driven medical hostel allocation system for Redeemer's University that prioritises students with medical conditions.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/main.css">
    <script src="js/theme.js"></script>
    <style>
        /* Landing-page-specific overrides */
        .lp-nav {
            position: fixed;
            top: 0; left: 0; right: 0;
            background: var(--c-primary);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding: 0 3rem;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 100;
        }
        .lp-nav-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            color: white;
            font-weight: 700;
            font-size: 1.1rem;
            font-family: 'Inter', sans-serif;
            text-decoration: none;
        }
        .lp-nav-brand img {
            width: 32px; height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(201,168,76,0.6);
        }
        .lp-nav-links { display: flex; gap: 1.5rem; align-items: center; }
        .lp-nav-links a {
            color: rgba(255,255,255,0.8);
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            transition: color 0.2s;
        }
        .lp-nav-links a:hover { color: #fff; }
        .lp-nav-links .btn { font-size: 0.82rem; padding: 0.45rem 1.1rem; }

        /* Hero */
        .lp-hero {
            min-height: 100vh;
            background: var(--c-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 6rem 2rem 4rem;
        }
        .lp-hero-inner {
            max-width: 760px;
            text-align: center;
        }
        .lp-hero-badge {
            display: inline-block;
            background: rgba(201,168,76,0.15);
            color: var(--c-accent);
            border-radius: 4px;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            padding: 0.35rem 0.9rem;
            margin-bottom: 1.75rem;
        }
        .lp-hero h1 { font-size: 3rem; font-weight: 800; line-height: 1.1; margin-bottom: 1.5rem; color: #fff; letter-spacing: -0.03em; }
        .lp-hero h1 span { color: var(--c-accent); }
        .lp-hero p {
            font-size: 1.05rem;
            color: rgba(255,255,255,0.65);
            margin-bottom: 2.5rem;
            max-width: 580px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.75;
        }
        .lp-hero-btns { display: flex; gap: 1rem; margin-bottom: 3rem; justify-content: center; }
        .lp-hero-btns .btn-primary { background: var(--c-accent); color: var(--c-primary); }
        .lp-hero-btns .btn-primary:hover { background: #b8973d; }
        .lp-hero-btns .btn-secondary-inv {
            background: transparent;
            border: 1.5px solid rgba(255,255,255,0.3);
            color: rgba(255,255,255,0.85);
            border-radius: 6px;
            padding: 0.65rem 1.25rem;
            font-size: 0.875rem;
            font-weight: 600;
            text-decoration: none;
            transition: border-color 0.2s, color 0.2s;
        }
        .lp-hero-btns .btn-secondary-inv:hover {
            border-color: rgba(255,255,255,0.6);
            color: #fff;
        }
        /* Divider strip */
        .lp-strip {
            background: #001229;
            border-top: 1px solid rgba(255,255,255,0.07);
            border-bottom: 1px solid rgba(255,255,255,0.07);
            padding: 1.25rem 3rem;
            display: flex;
            gap: 3rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        .lp-strip-item { display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: rgba(255,255,255,0.6); }
        .lp-strip-item i { color: var(--c-accent); }

        /* Features */
        .lp-features {
            background: var(--c-bg-body);
            padding: 5rem 2rem;
        }
        .lp-section-label {
            text-align: center;
            margin-bottom: 3rem;
        }
        .lp-section-tag {
            display: inline-block;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--c-accent);
            margin-bottom: 0.75rem;
        }
        .lp-section-label h2 { color: var(--c-text-head); margin-bottom: 0.5rem; }
        .lp-section-label p { color: var(--c-text-muted); max-width: 520px; margin: 0 auto; }

        .lp-features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            max-width: 1080px;
            margin: 0 auto;
        }
        .lp-feature-card {
            background: #fff;
            border: 1px solid var(--c-border);
            border-radius: 8px;
            padding: 2rem 1.75rem;
        }
        .lp-feature-icon {
            width: 44px; height: 44px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            margin-bottom: 1.25rem;
        }
        .lp-feature-card h3 { font-size: 1rem; margin-bottom: 0.5rem; }
        .lp-feature-card p { font-size: 0.875rem; color: var(--c-text-muted); line-height: 1.65; margin: 0; }

        /* CTA Section */
        .lp-cta {
            background: #002147;
            padding: 4.5rem 2rem;
            text-align: center;
            border-top: 3px solid var(--c-accent);
        }
        .lp-cta h2 { color: #fff; margin-bottom: 0.75rem; }
        .lp-cta p { color: rgba(255,255,255,0.6); margin-bottom: 2rem; }

        /* Footer */
        .lp-footer {
            background: #001229;
            padding: 1.5rem 3rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
            border-top: 1px solid rgba(255,255,255,0.07);
        }
        .lp-footer p { color: rgba(255,255,255,0.4); font-size: 0.8rem; margin: 0; }
        .lp-footer a { color: rgba(255,255,255,0.4); font-size: 0.8rem; }
        .lp-footer a:hover { color: rgba(255,255,255,0.7); }

        @media (max-width: 768px) {
            .lp-features-grid { grid-template-columns: 1fr; }
            .lp-nav { padding: 0 1.25rem; }
            .lp-strip { gap: 1.5rem; }
            .lp-footer { flex-direction: column; text-align: center; }
        }
        @media (max-width: 640px) {
            .lp-features-grid { grid-template-columns: 1fr; }
            .lp-hero-btns { flex-direction: column; align-items: center; }
        }
    </style>
</head>
<body>

    <!-- Navigation -->
    <nav class="lp-nav" role="navigation" aria-label="Main navigation">
        <a href="index.php" class="lp-nav-brand">
            <img src="assets/logo.jpeg" alt="Redeemer's University Logo">
            FairMedAlloc
        </a>
        <div class="lp-nav-links">
            <a href="#features">How It Works</a>
            <a href="login.php" class="btn btn-accent" style="border-radius:6px;padding:0.45rem 1.1rem;font-size:0.82rem;">Student Login</a>
            <a href="admin_login.php" style="font-size:0.8rem; color:rgba(255,255,255,0.5);">Admin</a>
        </div>
    </nav>

    <!-- Hero -->
    <section class="lp-hero" id="home">
        <div class="lp-hero-inner animate-fade-in">
            <div class="lp-hero-badge">
                <i class="fa-solid fa-graduation-cap"></i>
                Final Year Computer Science Project · 2025/2026
            </div>
            <img src="assets/logo.jpeg" alt="Redeemer's University"
                 style="width:90px;height:90px;border-radius:50%;object-fit:cover;margin-bottom:1.5rem;border:3px solid rgba(201,168,76,0.5);">
            <h1>Fair Allocation Based on<br><span>Medical Urgency</span></h1>
            <p>An intelligent system that uses Machine Learning to prioritise students with medical conditions and disabilities for proximal hostel placement at Redeemer's University.</p>
            <div class="lp-hero-btns">
                <a href="login.php" class="btn btn-primary btn-lg" id="hero-login-btn">
                    Student Login <i class="fa-solid fa-arrow-right" style="margin-left:6px;"></i>
                </a>
                <a href="signup.php" class="btn-secondary-inv" id="hero-signup-btn">
                    New Student? Register
                </a>
            </div>
        </div>
    </section>

    <!-- Strip: Key stats/features -->
    <div class="lp-strip">
        <div class="lp-strip-item"><i class="fa-solid fa-check-circle"></i> CSRF-Protected Forms</div>
        <div class="lp-strip-item"><i class="fa-solid fa-check-circle"></i> XGBoost ML Engine</div>
        <div class="lp-strip-item"><i class="fa-solid fa-check-circle"></i> Fee-Gated Allocation</div>
        <div class="lp-strip-item"><i class="fa-solid fa-check-circle"></i> Role-Based Access</div>
        <div class="lp-strip-item"><i class="fa-solid fa-check-circle"></i> Account Lockout Security</div>
    </div>

    <!-- Features -->
    <section id="features" class="lp-features">
        <div class="lp-section-label">
            <span class="lp-section-tag">System Capabilities</span>
            <h2>Why FairMedAlloc?</h2>
            <p>Moving beyond "First Come, First Serve" to a transparent, medically-driven allocation model.</p>
        </div>

        <div class="lp-features-grid">
            <div class="lp-feature-card" id="feature-equity">
                <div class="lp-feature-icon" style="background:rgba(37,99,235,0.08);color:var(--c-info);">
                    <i class="fa-solid fa-scale-balanced"></i>
                </div>
                <h3>Equity-Focused Algorithm</h3>
                <p>Students with verified medical conditions and disabilities are automatically prioritised for accessible, clinic-proximal rooms.</p>
            </div>

            <div class="lp-feature-card" id="feature-ml">
                <div class="lp-feature-icon" style="background:rgba(22,163,74,0.08);color:var(--c-success);">
                    <i class="fa-solid fa-robot"></i>
                </div>
                <h3>ML-Powered Urgency Scoring</h3>
                <p>XGBoost model computes urgency scores (0–100) based on medical history, severity, mobility status, and academic level.</p>
            </div>

            <div class="glass-card lp-feature-card animate-fade-in" style="animation-delay:0.1s;">
                <div class="lp-feature-icon" style="background:rgba(201,168,76,0.1);color:var(--c-accent);">
                    <i class="fa-solid fa-eye"></i>
                </div>
                <h3>Transparent Process</h3>
                <p>Students can view their urgency score, allocation status, and hostel placement in real-time through their personal dashboard.</p>
            </div>

            <div class="lp-feature-card" id="feature-security">
                <div class="lp-feature-icon" style="background:rgba(0,33,71,0.06);color:var(--c-primary);">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <h3>Security-Hardened</h3>
                <p>CSRF token validation, bcrypt password hashing, brute-force account lockout, and prepared statements throughout.</p>
            </div>

            <div class="lp-feature-card" id="feature-csv">
                <div class="lp-feature-icon" style="background:rgba(245,158,11,0.08);color:var(--c-warning);">
                    <i class="fa-solid fa-file-csv"></i>
                </div>
                <h3>Bulk CSV Import</h3>
                <p>Administrators can import hundreds of student records at once via a structured CSV file with built-in validation and error reporting.</p>
            </div>

            <div class="lp-feature-card" id="feature-feegated">
                <div class="lp-feature-icon" style="background:rgba(220,38,38,0.08);color:var(--c-danger);">
                    <i class="fa-solid fa-credit-card"></i>
                </div>
                <h3>Fee-Gated Allocation</h3>
                <p>Students only receive a room assignment after school fee payment is verified — preventing ghost bookings and maximising fairness.</p>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="lp-cta">
        <h2>Ready to Check Your Allocation?</h2>
        <p>Log in to your student portal to view your hostel assignment, urgency score, and print your official allocation slip.</p>
        <div style="display:flex;gap:1rem;justify-content:center;flex-wrap:wrap;">
            <a href="login.php" class="btn btn-accent btn-lg" id="cta-login-btn" style="border-radius:6px;">
                <i class="fa-solid fa-sign-in-alt"></i> Student Login
            </a>
            <a href="signup.php" class="btn-secondary-inv" id="cta-signup-btn">
                New Student? Register
            </a>
        </div>
    </section>

    <!-- Footer -->
    <footer class="lp-footer" role="contentinfo">
        <p>© <?php echo date('Y'); ?> Redeemer's University — Computer Science Dept. Final Year Project.</p>
        <div style="display:flex;gap:1.5rem;">
            <a href="login.php">Student Portal</a>
            <a href="admin_login.php">Admin Portal</a>
        </div>
    </footer>

</body>
</html>
