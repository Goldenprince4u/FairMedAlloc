<?php
/**
 * DbHelper
 * ========
 * Shared schema introspection and database utility methods.
 * Centralises checks that were previously duplicated across
 * AllocationEngine.php and api/admin_api.php.
 */
class DbHelper {
    /**
     * Returns true if the allocations table has an algorithm_version column.
     * Result is statically cached for the lifetime of the current PHP request.
     */
    public static function supportsAlgorithmVersion(mysqli $conn): bool {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $result = $conn->query("SHOW COLUMNS FROM allocations LIKE 'algorithm_version'");
        $cached = $result && $result->num_rows > 0;
        return $cached;
    }

    /**
     * Aligns the legacy medical_records schema with the application's current
     * string-based condition and mobility pipeline, then repairs obvious data
     * coercions introduced by the old enum/tinyint layout.
     */
    public static function alignMedicalSchema(mysqli $conn): void {
        static $completed = false;
        if ($completed) {
            return;
        }

        $versionKey = 'medical_schema_version';
        $targetVersion = 'v3';
        $versionStmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        if ($versionStmt) {
            $versionStmt->bind_param("s", $versionKey);
            $versionStmt->execute();
            $versionRow = $versionStmt->get_result()->fetch_assoc();
            $versionStmt->close();
            if (($versionRow['setting_value'] ?? '') === $targetVersion) {
                $completed = true;
                return;
            }
        }

        $conditionType = self::getColumnType($conn, 'medical_records', 'condition_category');
        if ($conditionType !== null && stripos($conditionType, 'enum(') === 0) {
            $conn->query("ALTER TABLE medical_records MODIFY condition_category VARCHAR(100) NULL DEFAULT 'None / Healthy'");
        }

        $mobilityType = self::getColumnType($conn, 'medical_records', 'mobility_status');
        if ($mobilityType !== null && stripos($mobilityType, 'varchar(') !== 0) {
            $conn->query("ALTER TABLE medical_records MODIFY mobility_status VARCHAR(50) NULL DEFAULT 'Normal Mobility'");
        }

        $repairQueries = [
            "UPDATE medical_records
             SET condition_category = CASE
                 WHEN condition_category = '' AND condition_details LIKE 'Sickle Cell (%' THEN 'Sickle Cell'
                 WHEN condition_category = '' AND condition_details LIKE 'Cardiac Issue (%' THEN 'Cardiac Issue'
                 WHEN condition_category = '' AND condition_details LIKE 'Physical Disability (%' THEN 'Physical Disability'
                 WHEN condition_category = '' AND condition_details LIKE 'Asthma (%' THEN 'Asthma'
                 WHEN condition_category = '' AND condition_details LIKE 'Epilepsy (%' THEN 'Epilepsy'
                 WHEN condition_category = '' AND condition_details LIKE 'Ulcer (%' THEN 'Ulcer'
                 WHEN condition_category = '' AND condition_details LIKE 'Visual Impairment (%' THEN 'Visual Impairment'
                 WHEN condition_category IS NULL OR TRIM(condition_category) = '' THEN 'None / Healthy'
                 ELSE condition_category
             END",
            "UPDATE medical_records
             SET mobility_status = CASE
                 WHEN mobility_status IS NULL OR TRIM(mobility_status) = '' OR mobility_status = '0' THEN 'Normal Mobility'
                 WHEN mobility_status = '1' THEN 'Artificial Limb'
                 WHEN mobility_status = '2' THEN 'Crutches/Walker'
                 WHEN mobility_status = '3' THEN 'Wheelchair User'
                 ELSE mobility_status
             END",
            "UPDATE medical_records
             SET mobility_status = 'Wheelchair User'
             WHERE condition_category IN ('Physical Disability', 'Orthopaedic')
               AND COALESCE(TRIM(mobility_status), 'Normal Mobility') = 'Normal Mobility'",
            "UPDATE medical_records
             SET is_requested_mobility = CASE
                 WHEN COALESCE(TRIM(mobility_status), 'Normal Mobility') IN ('Normal Mobility', '0') THEN 0
                 ELSE 1
             END",
        ];

        foreach ($repairQueries as $sql) {
            $conn->query($sql);
        }

        $seedStmt = $conn->prepare("
            INSERT INTO settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        if ($seedStmt) {
            $seedStmt->bind_param("ss", $versionKey, $targetVersion);
            $seedStmt->execute();
            $seedStmt->close();
        }

        $completed = true;
    }

    private static function getColumnType(mysqli $conn, string $table, string $column): ?string {
        $tableEsc = $conn->real_escape_string($table);
        $columnEsc = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'");
        if (!$result || $result->num_rows === 0) {
            return null;
        }

        $row = $result->fetch_assoc();
        return $row['Type'] ?? null;
    }
}
