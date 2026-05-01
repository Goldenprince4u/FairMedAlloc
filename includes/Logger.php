<?php
/**
 * Logger Utility Class
 * ====================
 * Centralized logging framework for consistent error/info/warning messages
 * throughout the application. All messages are sent to PHP error_log with
 * timestamps and severity levels.
 *
 * Usage:
 *   Logger::error("Database connection failed", $exception);
 *   Logger::warning("Low memory detected");
 *   Logger::info("Allocation completed in 5.2s");
 *
 * @package Core
 * @subpackage Utilities
 * @author FairMedAlloc Team
 * @version 1.0.0
 */

class Logger {
    private const PREFIX = "[FairMedAlloc]";
    
    /**
     * Log an error message with optional exception details
     * 
     * @param string $message The main error message
     * @param Throwable|null $exception Optional exception for stack trace
     * @return void
     */
    public static function error(string $message, ?Throwable $exception = null): void {
        $log = self::PREFIX . " ERROR: " . $message;
        if ($exception) {
            $log .= " | Exception: " . $exception->getMessage();
            $log .= " | File: " . $exception->getFile() . ":" . $exception->getLine();
        }
        error_log($log);
    }

    /**
     * Log a warning message (non-critical issue)
     * 
     * @param string $message The warning message
     * @return void
     */
    public static function warning(string $message): void {
        error_log(self::PREFIX . " WARNING: " . $message);
    }

    /**
     * Log an informational message
     * 
     * @param string $message The info message
     * @return void
     */
    public static function info(string $message): void {
        error_log(self::PREFIX . " INFO: " . $message);
    }

    /**
     * Log a performance/timing metric
     * 
     * @param string $operation Name of the operation being timed
     * @param float $milliseconds Duration in milliseconds
     * @param string|null $details Optional additional details
     * @return void
     */
    public static function timing(string $operation, float $milliseconds, ?string $details = null): void {
        $log = self::PREFIX . " TIMING: {$operation} took {$milliseconds}ms";
        if ($details) {
            $log .= " ({$details})";
        }
        error_log($log);
    }

    /**
     * Execute a callable and log its execution time
     * 
     * @param string $operation Name of the operation for logging
     * @param callable $fn The function to execute
     * @param string|null $details Optional details to include in timing log
     * @return mixed The result of the callable
     */
    public static function timeOperation(string $operation, callable $fn, ?string $details = null): mixed {
        $start = microtime(true);
        try {
            $result = $fn();
            $duration = (microtime(true) - $start) * 1000;
            self::timing($operation, $duration, $details);
            return $result;
        } catch (Throwable $e) {
            $duration = (microtime(true) - $start) * 1000;
            self::error("$operation failed after {$duration}ms", $e);
            throw $e;
        }
    }

    /**
     * Log SQL query execution with duration
     * 
     * @param string $query The SQL query (sanitized for logging)
     * @param float $milliseconds Duration in milliseconds
     * @return void
     */
    public static function query(string $query, float $milliseconds): void {
        if ($milliseconds > 1000) {
            self::warning("Slow query: " . substr($query, 0, 100) . "... ({$milliseconds}ms)");
        } else {
            error_log(self::PREFIX . " QUERY: " . substr($query, 0, 100) . "... ({$milliseconds}ms)");
        }
    }
}
?>
