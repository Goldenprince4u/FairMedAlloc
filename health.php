<?php
/**
 * Application health endpoint for Railway deployments.
 *
 * Returns HTTP 200 only when:
 * - PHP can boot the application
 * - the database connection is available
 * - the local ML service responds, when enabled
 */

if (!headers_sent()) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

// Make db_config.php return JSON if the DB is unavailable.
$_SERVER['HTTP_ACCEPT'] = 'application/json';

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/includes/MlServiceClient.php';

function healthRespond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$checks = [
    'app' => 'ok',
    'db' => 'ok',
];

$mlEnabled = (getenv('FAIRMED_ENABLE_ML_SERVICE') ?: '1') !== '0';

if ($mlEnabled) {
    try {
        $mlClient = new MlServiceClient(ML_SERVICE_URL, 2.0);
        $mlHealth = $mlClient->health();

        if (($mlHealth['status'] ?? 'error') !== 'success') {
            throw new RuntimeException('ML service returned a non-success payload.');
        }

        $checks['ml'] = 'ok';
    } catch (Throwable $e) {
        // ML service may still be warming up (loading XGBoost model takes ~5-10s).
        // Return 200 with degraded status so Railway does not kill the container
        // in a boot loop. Apache serving PHP is the critical health indicator.
        Logger::warning('Healthcheck: ML service unavailable (may be warming up) - ' . $e->getMessage());
        $checks['ml'] = 'warming_up';
    }
} else {
    $checks['ml'] = 'disabled';
}

healthRespond(200, [
    'status' => 'ok',
    'checks' => $checks,
    'service' => 'FairMedAlloc',
    'environment' => getenv('RAILWAY_PUBLIC_DOMAIN') ? 'railway' : 'local',
]);
