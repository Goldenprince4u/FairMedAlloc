<?php
// ── Security Headers ─────────────────────────────────────────────────────────
// Content-Security-Policy:
//   script-src 'unsafe-inline' — required for the inline theme-toggle script
//     in this file and ad-hoc page scripts; tighten with nonces in a future pass.
//   style-src + font-src cdnjs.cloudflare.com — Font Awesome CDN.
//   connect-src 'self' — restricts fetch() / XHR to same origin only.
//   frame-ancestors 'none' — prevents the app being embedded in iframes
//     (clickjacking protection, equivalent to X-Frame-Options: DENY).
if (!headers_sent()) {
    header("Content-Security-Policy: "
        . "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
        . "style-src 'self' https://cdnjs.cloudflare.com https://fonts.googleapis.com 'unsafe-inline'; "
        . "font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; "
        . "img-src 'self' data:; "
        . "connect-src 'self'; "
        . "frame-ancestors 'none';"
    );
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="FairMedAlloc – Fair and transparent hostel allocation system for Redeemer's University, powered by AI-driven medical prioritization.">
    <title><?php echo htmlspecialchars($page_title ?? "FairMedAlloc | Redeemer's University"); ?></title>

    <!--
        CRITICAL: Apply saved theme BEFORE stylesheets are parsed
        to prevent flash-of-light-theme (FOUT) on dark mode users.
    -->
    <script>
        (function() {
            var t = localStorage.getItem('fma-theme');
            if (!t) { t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; }
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Main CSS (desktop — the single source of truth) -->
    <link rel="stylesheet" href="css/main.css?v=<?php echo filemtime(__DIR__ . '/../css/main.css'); ?>">

    <!-- Mobile CSS (phones ≤768px — completely separate from desktop) -->
    <link rel="stylesheet" href="css/mobile.css?v=<?php echo filemtime(__DIR__ . '/../css/mobile.css'); ?>" media="(max-width: 768px)">

</head>
<body>
<button id="global-theme-toggle" class="theme-toggle-btn theme-toggle-floating" type="button" aria-label="Toggle color theme" aria-pressed="false">
    <i class="fa-solid fa-moon" id="theme-toggle-icon"></i>
    <span class="theme-toggle-label" id="theme-toggle-label">Dark mode</span>
</button>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.getElementById('global-theme-toggle');
    const toggleIcon = document.getElementById('theme-toggle-icon');
    const toggleLabel = document.getElementById('theme-toggle-label');
    const root = document.documentElement;
    const sidebarFooter = document.querySelector('.sidebar-footer');

    function updateIcon() {
        if (root.getAttribute('data-theme') === 'dark') {
            toggleIcon.className = 'fa-solid fa-sun';
            toggleLabel.textContent = 'Light mode';
            toggleBtn.setAttribute('aria-pressed', 'true');
        } else {
            toggleIcon.className = 'fa-solid fa-moon';
            toggleLabel.textContent = 'Dark mode';
            toggleBtn.setAttribute('aria-pressed', 'false');
        }
    }

    if (sidebarFooter) {
        toggleBtn.classList.remove('theme-toggle-floating');
        toggleBtn.classList.add('theme-toggle-sidebar');
        sidebarFooter.prepend(toggleBtn);
    }

    // Initial icon state
    updateIcon();

    toggleBtn.addEventListener('click', function() {
        const currentTheme = root.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        root.setAttribute('data-theme', newTheme);
        localStorage.setItem('fma-theme', newTheme);
        updateIcon();
    });
});
</script>
