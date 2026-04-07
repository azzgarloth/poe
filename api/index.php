<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once APP_SHARED_PATH . '/api_response.php';
require_once APP_SHARED_PATH . '/api_router.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$segments = api_get_route_segments();
$resource = strtolower($segments[0] ?? '');

if ($resource === '' || $resource === 'status' || $resource === 'gems' || $resource === 'sidebar-gems') {
    if ($method !== 'GET') {
        json_error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
    }

    require __DIR__ . '/data.php';
}

if ($resource === 'evaluations') {
    if ($method !== 'POST') {
        json_error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
    }

    require __DIR__ . '/actions.php';
}

json_error('NOT_FOUND', 'Route not found', 404);
