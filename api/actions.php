<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once APP_SHARED_PATH . '/api_response.php';
require_once APP_SHARED_PATH . '/api_router.php';
require_once APP_SHARED_PATH . '/api_request.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$segments = api_get_route_segments();
$resource = strtolower($segments[0] ?? '');

if ($resource !== 'evaluations') {
    json_error('NOT_FOUND', 'Route not found', 404);
}

try {
    $payload = parse_json_request_body();
    $gemNameInput = trim((string) ($payload['gemName'] ?? ''));
    if ($gemNameInput === '') {
        json_error('VALIDATION_ERROR', 'Gem name is required', 400);
    }

    $gemName = poe_find_gem_name($gemNameInput);
    if ($gemName === null) {
        json_error('VALIDATION_ERROR', 'Unknown gem name', 400);
    }

    json_success(poe_evaluate_gem($gemName), 200);
} catch (Throwable $e) {
    handle_exception($e);
}
