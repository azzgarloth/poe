<?php

declare(strict_types=1);

function json_success($data, int $status = 200): void
{
    http_response_code($status);
    if ($status === 204) {
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $code, string $message, int $status, ?Throwable $e = null): void
{
    $details = $e
        ? ($e->getPrevious() instanceof Throwable ? $e->getPrevious()->getMessage() : $e->getMessage())
        : $message;

    http_response_code($status);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
            'details' => $details,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function handle_exception(Throwable $e): void
{
    if (
        $e instanceof PDOException
        || $e->getPrevious() instanceof PDOException
        || str_starts_with($e->getMessage(), 'Database connection failed')
    ) {
        json_error('DB_CONNECTION_ERROR', 'Database connection failed', 500, $e);
    }

    json_error('INTERNAL_ERROR', 'Internal server error', 500, $e);
}
