<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Helpers\Auth;
use App\Helpers\Response;
use App\Services\OfficeSaleService;
use JsonException;
use RuntimeException;
use Throwable;

final class OfficeSaleController
{
    public function create(): never
    {
        $pdo = Database::getInstance();

        try {
            $actingUserId = $this->getAuthenticatedUserId();
            $payload = $this->getRequestPayload();
            $service = new OfficeSaleService();

            $pdo->beginTransaction();
            $sale = $service->createCashSale($pdo, $payload, $actingUserId);
            $pdo->commit();

            Response::jsonResponse(true, 'Office cash sale created successfully.', $sale, 201);
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function printable(string $orderId): never
    {
        try {
            $id = $this->normalizeId($orderId, 'Order ID is invalid.');
            $pdo = Database::getInstance();
            $service = new OfficeSaleService();
            $payload = $service->getPrintablePassPayloadByOrderId($pdo, $id);

            if ($payload === null) {
                Response::jsonResponse(false, 'Order was not found.', [], 404);
            }

            Response::jsonResponse(true, 'Printable pass payload loaded successfully.', $payload, 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getRequestPayload(): array
    {
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

    private function getAuthenticatedUserId(): int
    {
        $user = Auth::user();
        $userId = (int) ($user['id'] ?? 0);

        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized.');
        }

        return $userId;
    }

    private function normalizeId(string $value, string $message): int
    {
        if (!ctype_digit($value) || (int) $value <= 0) {
            throw new RuntimeException($message);
        }

        return (int) $value;
    }

    private function resolveHttpStatusCode(string $message): int
    {
        $normalized = strtolower($message);

        if (str_contains($normalized, 'not found')) {
            return 404;
        }

        if (str_contains($normalized, 'invalid') || str_contains($normalized, 'required') || str_contains($normalized, 'malformed')) {
            return 422;
        }

        if (str_contains($normalized, 'unauthorized') || str_contains($normalized, 'forbidden')) {
            return 401;
        }

        return 500;
    }
}
