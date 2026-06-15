<?php

declare(strict_types=1);

namespace App\Helpers;

use JsonException;

final class Response
{
    private function __construct()
    {
    }

    public static function jsonResponse(
        bool $status,
        string $message,
        array $data = [],
        int $statusCode = 200,
        array $meta = []
    ): never {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        try {
            echo json_encode(
                [
                    'status' => $status,
                    'message' => $message,
                    'data' => $data,
                    'meta' => $meta,
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            echo '{"status":false,"message":"Failed to encode JSON response.","data":[],"meta":[]}';
        }

        exit;
    }
}
