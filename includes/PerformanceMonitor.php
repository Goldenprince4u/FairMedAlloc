<?php
/**
 * Performance Monitor Utility Class
 * =================================
 * Wraps database queries and operations to track execution time and identify
 * performance bottlenecks. Logs queries that exceed threshold durations.
 *
 * Usage:
 *   $monitor = new PerformanceMonitor();
 *   $result = $monitor->queryTime('fetch_students', function() use ($conn) {
 *       return $conn->query("SELECT * FROM student_profiles WHERE ...");
 *   }, 100); // warn if > 100ms
 *
 * @package Core
 * @subpackage Utilities
 * @author FairMedAlloc Team
 * @version 1.0.0
 */

class PerformanceMonitor {
    // Default threshold for warning about slow operations (milliseconds)
    private const DEFAULT_SLOW_THRESHOLD_MS = 500;
    
    private $measurements = [];
    
    /**
     * Execute a callable and measure its execution time
     * 
     * @param string $operationName Name of the operation for logging
     * @param callable $fn The function to execute
     * @param int|null $warnThresholdMs Threshold in ms for warning logs (null = no warning)
     * @return mixed The result of the callable
     * @throws Throwable Any exception thrown by the callable
     */
    public function measure(string $operationName, callable $fn, ?int $warnThresholdMs = null): mixed {
        $start = microtime(true);
        try {
            $result = $fn();
            $duration = (microtime(true) - $start) * 1000;
            
            // Store measurement for later analysis
            $this->measurements[$operationName][] = $duration;
            
            // Log if exceeds threshold
            if ($warnThresholdMs !== null && $duration > $warnThresholdMs) {
                Logger::warning(
                    "$operationName exceeded threshold: {$duration}ms (threshold: {$warnThresholdMs}ms)"
                );
            } else {
                Logger::timing($operationName, $duration);
            }
            
            return $result;
        } catch (Throwable $e) {
            $duration = (microtime(true) - $start) * 1000;
            Logger::error("$operationName failed after {$duration}ms", $e);
            throw $e;
        }
    }
    
    /**
     * Execute a database query and measure its execution time
     * 
     * @param string $name Name/description of the query
     * @param callable $queryFn Function that executes the query
     * @param int|null $warnThresholdMs Threshold for warning (default: 500ms)
     * @return mixed The query result
     */
    public function query(string $name, callable $queryFn, ?int $warnThresholdMs = null): mixed {
        $warnThreshold = $warnThresholdMs ?? self::DEFAULT_SLOW_THRESHOLD_MS;
        return $this->measure($name, $queryFn, $warnThreshold);
    }
    
    /**
     * Get statistics for all measured operations
     * 
     * @return array Associative array with operation statistics
     */
    public function getStatistics(): array {
        $stats = [];
        foreach ($this->measurements as $operation => $times) {
            $stats[$operation] = [
                'count' => count($times),
                'total_ms' => array_sum($times),
                'avg_ms' => array_sum($times) / count($times),
                'min_ms' => min($times),
                'max_ms' => max($times),
            ];
        }
        return $stats;
    }
    
    /**
     * Log all statistics to error log
     */
    public function logStatistics(): void {
        $stats = $this->getStatistics();
        if (empty($stats)) {
            return;
        }
        
        Logger::info("=== Performance Statistics ===");
        foreach ($stats as $operation => $data) {
            $msg = sprintf(
                "%s: %d calls, avg %.2fms (min: %.2fms, max: %.2fms, total: %.2fms)",
                $operation,
                $data['count'],
                $data['avg_ms'],
                $data['min_ms'],
                $data['max_ms'],
                $data['total_ms']
            );
            Logger::info($msg);
        }
    }
}
?>
