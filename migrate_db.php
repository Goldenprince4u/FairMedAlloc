<?php
require_once 'db_config.php';

// 1. Update existing enum values to match the new ones
$conn->query("UPDATE medical_records SET condition_category = 'None / Healthy' WHERE condition_category IN ('None')");
$conn->query("UPDATE medical_records SET condition_category = 'Sickle Cell Disease' WHERE condition_category IN ('Sickle Cell')");
$conn->query("UPDATE medical_records SET condition_category = 'Other' WHERE condition_category IN ('Mobility', 'Respiratory', 'Visual', 'Neurological')");

// Alter condition_category column
$conn->query("ALTER TABLE medical_records MODIFY condition_category ENUM('None / Healthy', 'Asthma', 'Epilepsy', 'Ulcer', 'Sickle Cell Disease', 'Cardiovascular', 'Visual Impairment', 'Physical Disability', 'Other') DEFAULT 'None / Healthy'");

// 2. Map existing mobility to integers (TINYINT)
// We will add a new column, migrate data, then swap columns
$conn->query("ALTER TABLE medical_records ADD COLUMN mobility_status_new TINYINT DEFAULT 0");

$conn->query("UPDATE medical_records SET mobility_status_new = 0 WHERE mobility_status = 'Normal Mobility'");
$conn->query("UPDATE medical_records SET mobility_status_new = 1 WHERE mobility_status = 'Artificial Limb'");
$conn->query("UPDATE medical_records SET mobility_status_new = 2 WHERE mobility_status = 'Crutches/Walker'");
$conn->query("UPDATE medical_records SET mobility_status_new = 3 WHERE mobility_status = 'Wheelchair User'");

// Drop old column, rename new column
$conn->query("ALTER TABLE medical_records DROP COLUMN mobility_status");
$conn->query("ALTER TABLE medical_records CHANGE COLUMN mobility_status_new mobility_status TINYINT DEFAULT 0");

echo "Migration completed.\n";
