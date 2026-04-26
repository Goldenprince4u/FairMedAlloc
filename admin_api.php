<?php
/**
 * Deprecated API tombstone
 * ========================
 * The live admin API moved to /api/admin_api.php.
 * This root-level file remains only to prevent future reviewers from patching
 * the wrong endpoint by accident.
 */

http_response_code(410);
header('Content-Type: application/json');

echo json_encode([
    'status' => 'error',
    'message' => 'Deprecated endpoint. Use /api/admin_api.php instead.'
]);
