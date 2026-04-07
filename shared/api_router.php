<?php

declare(strict_types=1);

require_once __DIR__ . '/api_response.php';

function api_get_route_segments(): array
{
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $uriPath = is_string($uriPath) ? $uriPath : '/';

    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/index.php'));
    $scriptDir = rtrim($scriptDir, '/');
    if ($scriptDir === '') {
        $scriptDir = '/';
    }

    $routePath = $uriPath;
    if ($scriptDir !== '/' && str_starts_with($routePath, $scriptDir)) {
        $routePath = substr($routePath, strlen($scriptDir));
    }

    $routePath = trim($routePath, '/');
    if ($routePath === '' || $routePath === 'index.php') {
        return [];
    }

    return array_values(array_filter(explode('/', $routePath), static fn ($segment): bool => $segment !== ''));
}

function api_parse_positive_id(?string $segment): ?int
{
    if ($segment === null || $segment === '') {
        return null;
    }

    if (!ctype_digit($segment)) {
        json_error('BAD_REQUEST', 'Invalid ID format', 400);
    }

    $id = (int) $segment;
    if ($id <= 0) {
        json_error('BAD_REQUEST', 'ID must be greater than zero', 400);
    }

    return $id;
}

function api_require_no_id(?int $id): void
{
    if ($id !== null) {
        json_error('BAD_REQUEST', 'This route does not accept ID in path', 400);
    }
}
