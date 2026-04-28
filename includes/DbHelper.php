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
}
