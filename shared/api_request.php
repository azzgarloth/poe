<?php

declare(strict_types=1);

require_once __DIR__ . '/api_response.php';

function parse_json_request_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '')));
    if (!str_starts_with($contentType, 'application/json')) {
        json_error('UNSUPPORTED_MEDIA_TYPE', 'Content-Type must be application/json', 415);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        json_error('BAD_REQUEST', 'Request body must be a valid JSON object', 400);
    }

    return $decoded;
}
