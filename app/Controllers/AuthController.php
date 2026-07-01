<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Helpers\Auth;
use App\Helpers\Response;
use App\Services\AuthService;
use JsonException;
use RuntimeException;
use Throwable;

final class AuthController
{
    private const MAX_REQUEST_BODY_BYTES = 262144;

    public function login(): never
    {
        $pdo = Database::getInstance();

        try {
            $payload = $this->getRequestPayload();
            $email = trim((string) ($payload['email'] ?? ''));
            $password = (string) ($payload['password'] ?? '');
            $service = new AuthService();
            $result = $service->attemptLogin($pdo, $email, $password);

            Response::jsonResponse(true, 'Login successful.', $result, 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(
                false,
                $throwable->getMessage(),
                [],
                $this->resolveHttpStatusCode($throwable->getMessage())
            );
        }
    }

    public function me(): never
    {
        $user = Auth::user();

        if ($user === null) {
            Response::jsonResponse(false, 'Unauthorized.', [], 401);
        }

        Response::jsonResponse(true, 'Authenticated user loaded successfully.', $user, 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function getRequestPayload(): array
    {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        if ($contentLength > self::MAX_REQUEST_BODY_BYTES) {
            throw new RuntimeException('Request body is too large.');
        }

        $rawBody = file_get_contents('php://input');

        if (!is_string($rawBody) || trim($rawBody) === '') {
            return [];
        }

        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Malformed JSON request body.');
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function resolveHttpStatusCode(string $message): int
    {
        $normalized = strtolower($message);

        if (
            str_contains($normalized, 'required') ||
            str_contains($normalized, 'invalid') ||
            str_contains($normalized, 'malformed')
        ) {
            return 422;
        }

        if (str_contains($normalized, 'unauthorized')) {
            return 401;
        }

        if (str_contains($normalized, 'too large')) {
            return 413;
        }

        return 401;
    }
}
