<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Helpers\Auth;
use App\Helpers\Response;
use App\Services\AdminCatalogService;
use JsonException;
use RuntimeException;
use Throwable;

final class EventController
{
    public function index(): never
    {
        try {
            $pdo = Database::getInstance();
            $service = new AdminCatalogService();
            $events = $service->getEvents($pdo, $this->getFilters());

            Response::jsonResponse(true, 'Events loaded successfully.', $events, 200);
        } catch (Throwable $throwable) {
            $statusCode = $this->resolveHttpStatusCode($throwable->getMessage());
            Response::jsonResponse(false, $throwable->getMessage(), [], $statusCode);
        }
    }

    public function show(string $eventId): never
    {
        try {
            $id = $this->normalizeId($eventId, 'Event ID is invalid.');
            $pdo = Database::getInstance();
            $service = new AdminCatalogService();
            $event = $service->getEventDetails($pdo, $id);

            if ($event === null) {
                Response::jsonResponse(false, 'Event was not found.', [], 404);
            }

            Response::jsonResponse(true, 'Event loaded successfully.', $event, 200);
        } catch (Throwable $throwable) {
            $statusCode = $this->resolveHttpStatusCode($throwable->getMessage());
            Response::jsonResponse(false, $throwable->getMessage(), [], $statusCode);
        }
    }

    public function create(): never
    {
        $pdo = Database::getInstance();

        try {
            $userId = $this->getAuthenticatedUserId();
            $payload = $this->getRequestPayload();
            $service = new AdminCatalogService();

            $pdo->beginTransaction();
            $event = $service->createEvent($pdo, $payload, $userId);
            $this->insertActivityLog($pdo, $userId, 'create', 'events', (int) ($event['event']['id'] ?? 0), [], $event['event'] ?? []);
            $pdo->commit();

            Response::jsonResponse(true, 'Event created successfully.', $event, 201);
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $statusCode = $this->resolveHttpStatusCode($throwable->getMessage());
            Response::jsonResponse(false, $throwable->getMessage(), [], $statusCode);
        }
    }

    public function update(string $eventId): never
    {
        $pdo = Database::getInstance();

        try {
            $id = $this->normalizeId($eventId, 'Event ID is invalid.');
            $userId = $this->getAuthenticatedUserId();
            $payload = $this->getRequestPayload();
            $service = new AdminCatalogService();
            $before = $service->getEventDetails($pdo, $id);

            if ($before === null) {
                Response::jsonResponse(false, 'Event was not found.', [], 404);
            }

            $pdo->beginTransaction();
            $after = $service->updateEvent($pdo, $id, $payload);

            if ($after === null) {
                throw new RuntimeException('Event was not found.');
            }

            $this->insertActivityLog(
                $pdo,
                $userId,
                'update',
                'events',
                $id,
                $before['event'] ?? [],
                $after['event'] ?? []
            );
            $pdo->commit();

            Response::jsonResponse(true, 'Event updated successfully.', $after, 200);
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $statusCode = $this->resolveHttpStatusCode($throwable->getMessage());
            Response::jsonResponse(false, $throwable->getMessage(), [], $statusCode);
        }
    }

    public function delete(string $eventId): never
    {
        $pdo = Database::getInstance();

        try {
            $id = $this->normalizeId($eventId, 'Event ID is invalid.');
            $userId = $this->getAuthenticatedUserId();
            $service = new AdminCatalogService();
            $before = $service->getEventDetails($pdo, $id);

            if ($before === null) {
                Response::jsonResponse(false, 'Event was not found.', [], 404);
            }

            $pdo->beginTransaction();
            $deleted = $service->deleteEvent($pdo, $id);

            if (!$deleted) {
                throw new RuntimeException('Event was not found.');
            }

            $this->insertActivityLog(
                $pdo,
                $userId,
                'delete',
                'events',
                $id,
                $before['event'] ?? [],
                [
                    'deleted' => true,
                ]
            );
            $pdo->commit();

            Response::jsonResponse(true, 'Event deleted successfully.', ['id' => $id], 200);
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $statusCode = $this->resolveHttpStatusCode($throwable->getMessage());
            Response::jsonResponse(false, $throwable->getMessage(), [], $statusCode);
        }
    }

    public function tickets(string $eventId): never
    {
        try {
            $id = $this->normalizeId($eventId, 'Event ID is invalid.');
            $pdo = Database::getInstance();
            $service = new AdminCatalogService();

            if ($service->getEventDetails($pdo, $id) === null) {
                Response::jsonResponse(false, 'Event was not found.', [], 404);
            }

            Response::jsonResponse(
                true,
                'Event tickets loaded successfully.',
                [
                    'eventId' => $id,
                    'items' => $service->getTicketsByEvent($pdo, $id),
                ],
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

    /**
     * @return array<string, mixed>
     */
    private function getFilters(): array
    {
        return [
            'status' => $_GET['status'] ?? '',
            'upcoming' => $_GET['upcoming'] ?? '',
            'query' => trim((string) ($_GET['q'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $oldValues
     * @param array<string, mixed> $newValues
     */
    private function insertActivityLog(
        \PDO $pdo,
        int $userId,
        string $action,
        string $tableName,
        int $recordId,
        array $oldValues,
        array $newValues
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO activity_logs (
                user_id,
                action,
                table_name,
                record_id,
                old_values,
                new_values,
                ip_address,
                user_agent
            ) VALUES (
                :user_id,
                :action,
                :table_name,
                :record_id,
                :old_values,
                :new_values,
                :ip_address,
                :user_agent
            )'
        );
        $statement->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':table_name' => $tableName,
            ':record_id' => $recordId,
            ':old_values' => json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':new_values' => json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
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
