<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Helpers\Auth;
use App\Helpers\Response;
use App\Services\ScanService;
use JsonException;
use RuntimeException;
use Throwable;

final class ScanController
{
    private const MAX_REQUEST_BODY_BYTES = 262144;

    public function preview(): never
    {
        $pdo = Database::getInstance();

        try {
            $scanRequest = $this->validateScanRequest($this->getRequestPayload());
            $user = Auth::user();

            if ($user === null) {
                throw new RuntimeException('Unauthorized.');
            }

            $service = new ScanService();
            $ticket = $service->previewTicket(
                $pdo,
                $scanRequest['ticket_code'],
                (int) ($user['id'] ?? 0)
            );

            Response::jsonResponse(true, 'Ticket preview loaded successfully.', $ticket, 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(
                false,
                $throwable->getMessage(),
                [],
                $this->resolveHttpStatusCode($throwable->getMessage())
            );
        }
    }

    public function confirm(): never
    {
        $pdo = Database::getInstance();

        try {
            $scanRequest = $this->validateScanRequest($this->getRequestPayload());
            $user = Auth::user();

            if ($user === null) {
                throw new RuntimeException('Unauthorized.');
            }

            $service = new ScanService();
            $ticket = $service->confirmScan(
                $pdo,
                $scanRequest['ticket_code'],
                (int) ($user['id'] ?? 0)
            );

            Response::jsonResponse(true, 'Ticket scanned successfully.', $ticket, 200);
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Response::jsonResponse(
                false,
                $throwable->getMessage(),
                [],
                $this->resolveHttpStatusCode($throwable->getMessage())
            );
        }
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

    /**
     * @param array<string, mixed> $payload
     * @return array{ticket_code:string}
     */
    private function validateScanRequest(array $payload): array
    {
        $ticketCode = trim((string) ($payload['ticket_code'] ?? $payload['ticketCode'] ?? ''));

        if ($ticketCode === '') {
            throw new RuntimeException('Ticket code is required.');
        }

        return [
            'ticket_code' => $ticketCode,
        ];
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

        if (str_contains($normalized, 'not found')) {
            return 404;
        }

        if (
            str_contains($normalized, 'already used') ||
            str_contains($normalized, 'cannot be scanned')
        ) {
            return 409;
        }

        if (str_contains($normalized, 'unauthorized')) {
            return 401;
        }

        if (str_contains($normalized, 'permission') || str_contains($normalized, 'forbidden')) {
            return 403;
        }

        if (str_contains($normalized, 'too large')) {
            return 413;
        }

        return 500;
    }
}
