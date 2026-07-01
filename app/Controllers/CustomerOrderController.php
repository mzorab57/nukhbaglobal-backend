<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Helpers\Response;
use App\Services\CustomerOrderTrackingService;
use JsonException;
use RuntimeException;
use Throwable;

final class CustomerOrderController
{
    private const MAX_REQUEST_BODY_BYTES = 262144;

    public function track(): never
    {
        try {
            $pdo = Database::getInstance();
            $service = new CustomerOrderTrackingService();
            $payload = $this->getLookupPayload();

            Response::jsonResponse(
                true,
                'Order tracking details loaded successfully.',
                $service->track($pdo, $payload),
                200
            );
        } catch (Throwable $throwable) {
            $statusCode = $this->resolveHttpStatusCode($throwable->getMessage());
            Response::jsonResponse(false, $throwable->getMessage(), [], $statusCode);
        }
    }

    public function passes(): never
    {
        try {
            $pdo = Database::getInstance();
            $service = new CustomerOrderTrackingService();
            $payload = $this->getLookupPayload();

            Response::jsonResponse(
                true,
                'Customer passes loaded successfully.',
                $service->getPasses($pdo, $payload),
                200
            );
        } catch (Throwable $throwable) {
            $statusCode = $this->resolveHttpStatusCode($throwable->getMessage());
            Response::jsonResponse(false, $throwable->getMessage(), [], $statusCode);
        }
    }

    public function updatePassengerNames(): never
    {
        try {
            $pdo = Database::getInstance();
            $service = new CustomerOrderTrackingService();
            $payload = $this->getJsonPayload();
            $lookup = [
                'order_number' => $payload['order_number'] ?? $_GET['order_number'] ?? null,
                'customer_phone' => $payload['customer_phone'] ?? $_GET['customer_phone'] ?? null,
                'customer_email' => $payload['customer_email'] ?? $_GET['customer_email'] ?? null,
            ];
            $passengers = is_array($payload['passengers'] ?? null) ? $payload['passengers'] : [];

            Response::jsonResponse(
                true,
                'Passenger names updated successfully.',
                $service->updatePassengerNames($pdo, $lookup, $passengers),
                200
            );
        } catch (Throwable $throwable) {
            $statusCode = $this->resolveHttpStatusCode($throwable->getMessage());
            Response::jsonResponse(false, $throwable->getMessage(), [], $statusCode);
        }
    }

    public function printablePasses(): never
    {
        try {
            $pdo = Database::getInstance();
            $service = new CustomerOrderTrackingService();
            $payload = $this->getLookupPayload();

            Response::jsonResponse(
                true,
                'Printable pass payload loaded successfully.',
                $service->getPrintablePassPayload($pdo, $payload),
                200
            );
        } catch (Throwable $throwable) {
            $statusCode = $this->resolveHttpStatusCode($throwable->getMessage());
            Response::jsonResponse(false, $throwable->getMessage(), [], $statusCode);
        }
    }

    public function retryPayment(): never
    {
        try {
            $pdo = Database::getInstance();
            $service = new CustomerOrderTrackingService();
            $payload = $this->getLookupPayload();

            Response::jsonResponse(
                true,
                'Customer payment retry handled successfully.',
                $service->retryPayment($pdo, $payload),
                200
            );
        } catch (Throwable $throwable) {
            $statusCode = $this->resolveHttpStatusCode($throwable->getMessage());
            Response::jsonResponse(false, $throwable->getMessage(), [], $statusCode);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getLookupPayload(): array
    {
        $queryPayload = $this->getQueryLookupPayload();
        $decoded = $this->getJsonPayload();

        if ($decoded === []) {
            return $queryPayload;
        }

        return [
            'order_number' => $decoded['order_number'] ?? $queryPayload['order_number'],
            'customer_phone' => $decoded['customer_phone'] ?? $queryPayload['customer_phone'],
            'customer_email' => $decoded['customer_email'] ?? $queryPayload['customer_email'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getQueryLookupPayload(): array
    {
        return [
            'order_number' => $_GET['order_number'] ?? null,
            'customer_phone' => $_GET['customer_phone'] ?? null,
            'customer_email' => $_GET['customer_email'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getJsonPayload(): array
    {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH'], true)) {
            return [];
        }

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

        if (str_contains($normalized, 'not found')) {
            return 404;
        }

        if (
            str_contains($normalized, 'required') ||
            str_contains($normalized, 'invalid') ||
            str_contains($normalized, 'malformed') ||
            str_contains($normalized, 'too long') ||
            str_contains($normalized, 'cannot be managed') ||
            str_contains($normalized, 'only be managed') ||
            str_contains($normalized, 'cannot be retried') ||
            str_contains($normalized, 'retryable')
        ) {
            return 422;
        }

        if (str_contains($normalized, 'too large')) {
            return 413;
        }

        return 500;
    }
}
