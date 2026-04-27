<?php
/**
 * Backward-compatible proxy to the live admin API controller.
 *
 * The canonical endpoint is /api/admin_api.php. This file remains only so
 * older local references do not break while the codebase uses the namespaced
 * API path consistently.
 */
require __DIR__ . '/api/admin_api.php';
