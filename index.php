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
        <div class="hero-blob blob-1"></div>
        <div class="hero-blob blob-2"></div>

        <div class="hero-content animate-fade-in">
            <div class="badge badge-warning mb-6 badge-final-year">
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
        <div class="max-w-6xl mx-auto container-xl">
            <div class="text-center mb-16">
                <h2 class="text-primary">Why FairMedAlloc?</h2>
                <p class="text-muted">Moving beyond "First Come, First Serve" to "Need First".</p>
            </div>

            <div class="grid grid-cols-3">
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
