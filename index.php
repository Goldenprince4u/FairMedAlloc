<?php
/**
 * FairMedAlloc - Premium Landing Page
 */
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FairMedAlloc | Redeemer's University</title>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:wght@300;700;900&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/main.css">
    <style>
        /* Landing Specific Overrides */
        body { overflow-x: hidden; }
        
        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            background: radial-gradient(circle at 50% 50%, #0d3b6e 0%, #001229 100%);
            color: white;
            padding: 8rem 2rem 4rem;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-image: url('https://www.transparenttextures.com/patterns/cubes.png');
            opacity: 0.05;
            pointer-events: none;
        }

        .hero-blob {
            position: absolute;
            width: 600px;
            height: 600px;
            background: linear-gradient(135deg, var(--c-accent), var(--c-primary));
            filter: blur(80px);
            border-radius: 50%;
            opacity: 0.15;
            z-index: 1;
            animation: float 10s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(30px, -50px) scale(1.1); }
        }

        .hero-content {
            position: relative;
            z-index: 10;
            max-width: 900px;
            text-align: center;
        }

        .hero h1 {
            font-size: clamp(3rem, 6vw, 5rem);
            line-height: 1.1;
            margin-bottom: 1.5rem;
            background: linear-gradient(135deg, #fff 0%, #cbd5e1 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }

        .hero p {
            font-size: 1.25rem;
            color: #94a3b8;
            margin-bottom: 3rem;
            font-weight: 300;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
        }

        .features {
            padding: 6rem 2rem;
            background: var(--c-bg-body);
        }

        .nav-transparent {
            position: absolute;
            top: 0; left: 0; right: 0;
            padding: 2rem 3rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 50;
        }

        .nav-transparent .brand { margin: 0; color: white; font-size: 1.5rem; font-family: var(--font-heading); font-weight: 900; }
        .nav-transparent a { color: rgba(255,255,255,0.8); }
        .nav-transparent a:hover { color: white; }
    </style>
</head>
<body>

    <!-- Navigation -->
    <nav class="nav-transparent animate-fade-in">
        <div class="brand">FairMedAlloc</div>
        <div class="flex gap-6 items-center">
            <a href="login.php" class="font-bold border-b-2 border-transparent hover:border-white transition-all pb-1">Portal Login</a>
            <a href="#features" class="hidden sm:block">How it Works</a>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-blob" style="top: -10%; left: -10%;"></div>
        <div class="hero-blob" style="bottom: -10%; right: -10%; animation-delay: -5s; background: var(--c-primary);"></div>

        <div class="hero-content animate-fade-in">
            <div class="badge badge-warning mb-6" style="background: rgba(255, 215, 0, 0.1); color: #ffd700; border: 1px solid rgba(255,215,0,0.3);">
                <i class="fa-solid fa-star mr-2"></i> Final Year Project 2026
            </div>
            
            <h1>Fair Allocation Based on <br> Medical Urgency.</h1>
            
            <p>
                An intelligent system leveraging <strong>Machine Learning</strong> to prioritize student health and safety in hostel allocation at Redeemer's University.
            </p>
            
            <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
                <a href="login.php" class="btn btn-primary btn-lg shadow-glow">
                    Get Started <i class="fa-solid fa-arrow-right ml-2 opacity-70"></i>
                </a>
                <a href="signup.php" class="btn btn-secondary" style="background: rgba(255,255,255,0.1); color: white; border-color: rgba(255,255,255,0.2);">
                    New Student Registration
                </a>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="features">
        <div class="max-w-6xl mx-auto" style="max-width: 1200px; margin: 0 auto;">
            <div class="text-center mb-16">
                <h2 class="text-primary">Why FairMedAlloc?</h2>
                <p class="text-muted">Moving beyond "First Come, First Serve" to "Need First".</p>
            </div>

            <div class="grid grid-cols-3" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
                <!-- Feature 1 -->
                <div class="glass-card">
                    <div class="w-12 h-12 rounded-full bg-blue-50 flex items-center justify-center text-primary text-xl mb-4">
                        <i class="fa-solid fa-scale-balanced"></i>
                    </div>
                    <h3 class="h4">Equity Focused</h3>
                    <p class="text-muted text-sm">Our algorithm ensures that students with verified medical conditions are prioritized for accessible rooms.</p>
                </div>

                <!-- Feature 2 -->
                <div class="glass-card">
                    <div class="w-12 h-12 rounded-full bg-green-50 flex items-center justify-center text-success text-xl mb-4">
                        <i class="fa-solid fa-robot"></i>
                    </div>
                    <h3 class="h4">ML Powered</h3>
                    <p class="text-muted text-sm">Uses XGBoost to calculate urgency scores based on medical history, disability status, and academic level.</p>
                </div>

                <!-- Feature 3 -->
                <div class="glass-card">
                    <div class="w-12 h-12 rounded-full bg-amber-50 flex items-center justify-center text-warning text-xl mb-4">
                        <i class="fa-solid fa-shield-heart"></i>
                    </div>
                    <h3 class="h4">Privacy First</h3>
                    <p class="text-muted text-sm">All medical data is encrypted and handled with strict confidentiality protocols.</p>
                </div>
            </div>
        </div>
    </section>

    <footer class="text-center py-8 text-muted text-sm border-t border-gray-200">
        <p>&copy; <?php echo date('Y'); ?> Redeemer's University. Computer Science Dept. Final Year Project.</p>
    </footer>

</body>
</html>
