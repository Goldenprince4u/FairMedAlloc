<?php
/**
 * FairMedAlloc - Landing Page
 * Premium design with vibrant colors, glassmorphism, and dynamic animations.
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
    <script>
        (function() {
            var t = localStorage.getItem('fma-theme');
            if (!t) { t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; }
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/mobile.css" media="screen and (max-width: 768px)">
    <style>
        /* Modern Glassmorphic Premium Overrides */
        :root {
            --glass-bg: rgba(255, 255, 255, 0.08);
            --glass-border: rgba(255, 255, 255, 0.15);
            --glass-shadow: 0 8px 32px 0 rgba(0, 33, 71, 0.37);
        }
        [data-theme="dark"] {
            --glass-bg: rgba(0, 0, 0, 0.25);
            --glass-border: rgba(255, 255, 255, 0.05);
            --glass-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
        }

        body {
            background-color: var(--c-primary);
            color: #ffffff;
            margin: 0;
            overflow-x: hidden;
            position: relative;
            background-image: 
                radial-gradient(circle at 15% 50%, rgba(201, 168, 76, 0.15), transparent 25%),
                radial-gradient(circle at 85% 30%, rgba(74, 144, 217, 0.15), transparent 25%);
            background-attachment: fixed;
        }

        /* Abstract glowing blobs for background */
        .blob {
            position: absolute;
            filter: blur(80px);
            z-index: -1;
            opacity: 0.6;
            animation: floatBlob 10s infinite alternate ease-in-out;
        }
        .blob-1 { top: 10%; left: 5%; width: 300px; height: 300px; background: rgba(201,168,76, 0.3); }
        .blob-2 { bottom: 10%; right: 10%; width: 400px; height: 400px; background: rgba(74, 144, 217, 0.2); animation-delay: -5s; }

        @keyframes floatBlob {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(30px, -50px) scale(1.1); }
        }

        /* Navigation */
        .lp-nav {
            position: fixed;
            top: 0; left: 0; right: 0;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 4rem;
            z-index: 1000;
            background: rgba(0, 33, 71, 0.6);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--glass-border);
            transition: all 0.3s ease;
        }
        .lp-nav-brand {
            display: flex; align-items: center; gap: 12px;
            color: #fff; font-weight: 800; font-size: 1.25rem; letter-spacing: -0.02em; text-decoration: none;
        }
        .lp-nav-brand img {
            width: 40px; height: 40px; border-radius: 50%; object-fit: cover;
            border: 2px solid var(--c-accent); box-shadow: 0 0 10px rgba(201,168,76,0.5);
        }
        .lp-nav-links { display: flex; gap: 2rem; align-items: center; }
        .lp-nav-links a { color: rgba(255,255,255,0.85); font-weight: 600; text-decoration: none; transition: 0.2s; }
        .lp-nav-links a:hover { color: var(--c-accent); text-shadow: 0 0 8px rgba(201,168,76,0.4); }
        
        .btn-glass {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            color: #fff; font-weight: 700; padding: 0.6rem 1.4rem; border-radius: 8px;
            transition: all 0.3s ease; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem;
        }
        .btn-glass:hover {
            background: rgba(255, 255, 255, 0.15); transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            color: #fff;
        }
        .btn-glow {
            background: linear-gradient(135deg, var(--c-accent), var(--c-accent-hover));
            color: var(--c-primary); box-shadow: 0 4px 15px rgba(201,168,76,0.4);
            border: none; font-weight: 800; padding: 0.6rem 1.4rem; border-radius: 8px;
            transition: all 0.3s ease; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem;
        }
        .btn-glow:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(201,168,76,0.6); color: var(--c-primary); }

        /* Hero */
        .lp-hero {
            min-height: 100vh;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            text-align: center; padding: 6rem 2rem 4rem; position: relative; z-index: 10;
        }
        .hero-badge {
            background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2);
            padding: 0.5rem 1rem; border-radius: 30px; font-size: 0.85rem; font-weight: 700;
            color: var(--c-accent); letter-spacing: 0.05em; text-transform: uppercase; margin-bottom: 2rem;
            backdrop-filter: blur(5px);
        }
        .lp-hero h1 {
            font-size: clamp(2.5rem, 6vw, 4.5rem); font-weight: 900; line-height: 1.1; margin-bottom: 1.5rem;
            background: linear-gradient(to right, #fff, #e2e8f0); -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .lp-hero h1 span { background: linear-gradient(135deg, var(--c-accent), #e6c875); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .lp-hero p {
            font-size: 1.15rem; color: rgba(255,255,255,0.75); max-width: 650px; margin: 0 auto 3rem; line-height: 1.8;
        }
        
        /* Glass Strip */
        .lp-strip {
            background: rgba(255, 255, 255, 0.03); backdrop-filter: blur(10px);
            border-top: 1px solid rgba(255,255,255,0.05); border-bottom: 1px solid rgba(255,255,255,0.05);
            padding: 1.5rem 2rem; display: flex; gap: 3rem; justify-content: center; flex-wrap: wrap; position: relative; z-index: 10;
        }
        .lp-strip-item { display: flex; align-items: center; gap: 0.75rem; font-size: 0.9rem; font-weight: 700; color: #fff; }
        .lp-strip-item i { color: var(--c-accent); font-size: 1.2rem; filter: drop-shadow(0 0 5px rgba(201,168,76,0.5)); }

        /* Features Section */
        .lp-features { padding: 6rem 2rem; position: relative; z-index: 10; }
        .section-header { text-align: center; margin-bottom: 4rem; }
        .section-header h2 { font-size: 2.5rem; margin-bottom: 1rem; color: #fff; }
        .section-header p { color: rgba(255,255,255,0.7); max-width: 500px; margin: 0 auto; font-size: 1.1rem; }
        
        .lp-features-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 2rem; max-width: 1200px; margin: 0 auto;
        }
        .feature-card {
            background: var(--glass-bg); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border); border-radius: 20px; padding: 2.5rem 2rem;
            box-shadow: var(--glass-shadow); transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative; overflow: hidden;
        }
        .feature-card::before {
            content: ''; position: absolute; top: 0; left: -100%; width: 50%; height: 100%;
            background: linear-gradient(to right, transparent, rgba(255,255,255,0.1), transparent);
            transform: skewX(-20deg); transition: 0.5s;
        }
        .feature-card:hover::before { left: 150%; }
        .feature-card:hover { transform: translateY(-10px); border-color: rgba(255,255,255,0.3); }
        .feature-icon {
            width: 60px; height: 60px; border-radius: 15px; display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem; margin-bottom: 1.5rem; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.1);
        }
        .feature-card h3 { font-size: 1.25rem; color: #fff; margin-bottom: 1rem; }
        .feature-card p { font-size: 1rem; color: rgba(255,255,255,0.7); line-height: 1.6; margin: 0; }

        /* CTA */
        .lp-cta {
            padding: 6rem 2rem; text-align: center; background: rgba(0, 0, 0, 0.2); position: relative; z-index: 10;
        }
        .lp-cta-inner {
            max-width: 800px; margin: 0 auto; background: var(--glass-bg); backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border); border-radius: 30px; padding: 4rem 2rem; box-shadow: var(--glass-shadow);
        }
        .lp-cta h2 { font-size: 2.5rem; margin-bottom: 1rem; color: #fff; }
        .lp-cta p { font-size: 1.1rem; color: rgba(255,255,255,0.8); margin-bottom: 2.5rem; }

        /* Footer */
        .lp-footer {
            padding: 2rem 4rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;
            border-top: 1px solid rgba(255,255,255,0.05); background: rgba(0, 33, 71, 0.8); position: relative; z-index: 10;
        }
        .lp-footer p, .lp-footer a { color: rgba(255,255,255,0.5); font-size: 0.9rem; text-decoration: none; font-weight: 500; }
        .lp-footer a:hover { color: #fff; }

        @media (max-width: 768px) {
            .lp-nav { padding: 0 1.5rem; height: 60px; }
            .lp-nav-links { display: none; /* Hide standard links on mobile, keep CTA maybe or hamburger */ }
            .lp-hero { padding: 6rem 1rem 3rem; }
            .lp-strip { flex-direction: column; align-items: flex-start; gap: 1rem; }
            .lp-footer { flex-direction: column; text-align: center; padding: 2rem 1rem; }
        }
    </style>
</head>
<body>
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>

    <nav class="lp-nav">
        <a href="index.php" class="lp-nav-brand">
            <img src="assets/logo.jpeg" alt="Redeemer's University Logo">
            FairMedAlloc
        </a>
        <div class="lp-nav-links">
            <a href="#features">How It Works</a>
            <a href="admin_login.php">Admin Portal</a>
            <a href="login.php" class="btn-glow">Student Login</a>
        </div>
    </nav>

    <section class="lp-hero">
        <div class="hero-badge"><i class="fa-solid fa-graduation-cap"></i> FYP 2025/2026</div>
        <h1>Fair Allocation Based on<br><span>Medical Urgency</span></h1>
        <p>Experience an intelligent, machine learning-driven system designed to prioritize hostel placements for students with medical conditions and disabilities.</p>
        <div style="display:flex; gap:1rem; flex-wrap:wrap; justify-content:center;">
            <a href="login.php" class="btn-glow"><i class="fa-solid fa-right-to-bracket"></i> Login to Portal</a>
            <a href="signup.php" class="btn-glass"><i class="fa-solid fa-user-plus"></i> New Student Registration</a>
        </div>
    </section>

    <div class="lp-strip">
        <div class="lp-strip-item"><i class="fa-solid fa-shield-halved"></i> CSRF-Protected</div>
        <div class="lp-strip-item"><i class="fa-solid fa-robot"></i> XGBoost ML Engine</div>
        <div class="lp-strip-item"><i class="fa-solid fa-credit-card"></i> Fee-Gated Allocation</div>
        <div class="lp-strip-item"><i class="fa-solid fa-universal-access"></i> Accessibility First</div>
    </div>

    <section id="features" class="lp-features">
        <div class="section-header">
            <h2>Why FairMedAlloc?</h2>
            <p>Moving beyond "First Come, First Serve" to a transparent, medically-driven allocation model with premium aesthetics.</p>
        </div>
        <div class="lp-features-grid">
            <div class="feature-card">
                <div class="feature-icon" style="color: #4a90d9;"><i class="fa-solid fa-scale-balanced"></i></div>
                <h3>Equity-Focused Algorithm</h3>
                <p>Students with verified medical conditions are automatically prioritised for accessible, clinic-proximal rooms.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon" style="color: #3fb950;"><i class="fa-solid fa-brain"></i></div>
                <h3>ML-Powered Scoring</h3>
                <p>XGBoost model computes urgency scores (0–100) based on medical history, severity, and academic level.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon" style="color: var(--c-accent);"><i class="fa-solid fa-eye"></i></div>
                <h3>Transparent Process</h3>
                <p>View your urgency score, allocation status, and hostel placement in real-time through a beautiful personal dashboard.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon" style="color: #f85149;"><i class="fa-solid fa-lock"></i></div>
                <h3>Security-Hardened</h3>
                <p>Enterprise-grade security including bcrypt hashing, brute-force lockout, and comprehensive CSRF protection.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon" style="color: #d29922;"><i class="fa-solid fa-file-csv"></i></div>
                <h3>Bulk Management</h3>
                <p>Administrators can seamlessly import hundreds of student records via CSV with built-in robust validation.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon" style="color: #a371f7;"><i class="fa-solid fa-mobile-screen"></i></div>
                <h3>Mobile Native UX</h3>
                <p>Fully responsive design featuring a bottom navigation bar and accessible touch targets for any device size.</p>
            </div>
        </div>
    </section>

    <section class="lp-cta">
        <div class="lp-cta-inner">
            <h2>Ready to Check Your Allocation?</h2>
            <p>Log in to your student portal to view your hostel assignment, urgency score, and print your official slip.</p>
            <div style="display:flex;gap:1rem;justify-content:center;flex-wrap:wrap;margin-top:2rem;">
                <a href="login.php" class="btn-glow"><i class="fa-solid fa-sign-in-alt"></i> Access Dashboard</a>
                <a href="signup.php" class="btn-glass">Create an Account</a>
            </div>
        </div>
    </section>

    <footer class="lp-footer">
        <p>&copy; <?php echo date('Y'); ?> Redeemer's University — Computer Science Dept. Final Year Project.</p>
        <div style="display:flex; gap:1.5rem;">
            <a href="login.php">Student Portal</a>
            <a href="admin_login.php">Admin Portal</a>
        </div>
    </footer>
</body>
</html>
