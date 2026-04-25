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

    <!-- Preconnect for performance -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!-- Fonts: Manrope for stronger headings, Inter for readable body text -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">

    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Main CSS (the single source of truth – no Tailwind CDN) -->
    <link rel="stylesheet" href="css/main.css?v=<?php echo filemtime(__DIR__ . '/../css/main.css'); ?>">

</head>
<body>
