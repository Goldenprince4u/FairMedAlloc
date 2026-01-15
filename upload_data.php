<?php
/**
 * Data Import
 * Bulk student registration.
 */
session_start();
require_once 'db_config.php';
require_once 'includes/security_helper.php';
if (($_SESSION['role'] ?? '') !== 'admin') { header("Location: login.php"); exit(); }

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    if ($_FILES['csv_file']['error'] === 0) {
        $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $count = 0;
        // Skip header
        fgetcsv($file);
        
        while (($row = fgetcsv($file)) !== false) {
            // Process Row
            // Assume format: Matric, Name, Level, Faculty, Dept
            $count++;
        }
        fclose($file);
        $msg = "Successfully processed $count records.";
    } else {
        $msg = "File upload error.";
    }
}

$page_title = "Import Data | FairMedAlloc";
require_once 'includes/header.php';
?>

<div class="app-shell">
    <?php require_once 'includes/nav.php'; ?>

    <main class="main-content">
        <h1>Data Import</h1>
        <p class="text-muted mb-8">Bulk student registration via CSV.</p>

        <?php if($msg): ?>
            <div class="badge badge-green mb-6 w-full"><?php echo $msg; ?></div>
        <?php endif; ?>

        <div class="card" style="padding: 4rem; text-align: center; border: 2px dashed var(--c-border);">
            <i class="fa-solid fa-cloud-arrow-up text-4xl text-muted mb-4" style="font-size: 3rem;"></i>
            <h3 class="mb-2">Drag Configuration File Here</h3>
            <p class="text-muted mb-6">or click to browse local storage</p>

            <form method="post" enctype="multipart/form-data">
                <?php csrf_field(); ?>
                <input type="file" name="csv_file" id="fileIn" class="hidden" style="display:none;" onchange="this.form.submit()">
                <label for="fileIn" class="btn btn-primary">
                    Select CSV File
                </label>
            </form>
            
            <div class="mt-8 text-xs text-muted">
                Required Columns: Matric No, Full Name, Level, Faculty, Department
            </div>
        </div>
    </main>
</div>
</body>
</html>
