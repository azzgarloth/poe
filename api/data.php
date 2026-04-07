<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once APP_SHARED_PATH . '/api_response.php';
require_once APP_SHARED_PATH . '/api_router.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$segments = api_get_route_segments();
$resource = strtolower($segments[0] ?? '');

try {
    if ($resource === '' || $resource === 'status') {
        json_success([
            'tool' => basename(dirname(__DIR__)),
            'status' => 'ok',
            'mode' => 'official_trade_api',
            'league' => poe_get_trade_league(),
            'searchStatus' => POE_DEFAULT_TRADE_STATUS,
            'currency' => POE_DEFAULT_PRICE_CURRENCY,
        ], 200);
    }

    if ($resource === 'gems') {
        $query = trim((string) ($_GET['query'] ?? ''));
        $limit = max(1, min(50, (int) ($_GET['limit'] ?? 12)));
        $catalog = poe_get_gem_catalog();

        json_success([
            'league' => poe_get_trade_league(),
            'searchStatus' => POE_DEFAULT_TRADE_STATUS,
            'count' => count($catalog),
            'gems' => $query === '' ? $catalog : poe_filter_gem_suggestions($query, $limit),
        ], 200);
    }

    if ($resource === 'sidebar-gems') {
        json_success(poe_fetch_sidebar_gems(), 200);
    }

    json_error('NOT_FOUND', 'Route not found', 404);
} catch (Throwable $e) {
    handle_exception($e);
}
