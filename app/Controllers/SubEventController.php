<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Helpers\Auth;
use App\Helpers\Response;
use App\Services\AdminCatalogService;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class SubEventController
{
    public function index(): never
    {
        try {
            $pdo = Database::getInstance();
            $service = new AdminCatalogService();
            $subEvents = $service->getSubEvents($pdo, $this->getFilters());

            Response::jsonResponse(true, 'Sub events loaded successfully.', $subEvents, 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function show(string $subEventId): never
    {
        try {
            $id = $this->normalizeId($subEventId, 'Sub event ID is invalid.');
            $pdo = Database::getInstance();
            $service = new AdminCatalogService();
            $subEvent = $service->getSubEventDetails($pdo, $id);

            if ($subEvent === null) {
                Response::jsonResponse(false, 'Sub event was not found.', [], 404);
            }

            Response::jsonResponse(true, 'Sub event loaded successfully.', $subEvent, 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function create(string $eventId): never
    {
        $pdo = Database::getInstance();

        try {
            $normalizedEventId = $this->normalizeId($eventId, 'Event ID is invalid.');
            $userId = $this->getAuthenticatedUserId();
            $payload = $this->getRequestPayload();
            $service = new AdminCatalogService();

            $pdo->beginTransaction();
            $subEvent = $service->createSubEvent($pdo, $normalizedEventId, $payload);
            $this->insertActivityLog($pdo, $userId, 'create', 'sub_events', (int) ($subEvent['subEvent']['id'] ?? 0), [], $subEvent['subEvent'] ?? []);
            $pdo->commit();

            Response::jsonResponse(true, 'Sub event created successfully.', $subEvent, 201);
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function update(string $subEventId): never
    {
        $pdo = Database::getInstance();

        try {
            $id = $this->normalizeId($subEventId, 'Sub event ID is invalid.');
            $userId = $this->getAuthenticatedUserId();
            $payload = $this->getRequestPayload();
            $service = new AdminCatalogService();
            $before = $service->getSubEventDetails($pdo, $id);

            if ($before === null) {
                Response::jsonResponse(false, 'Sub event was not found.', [], 404);
            }

            $pdo->beginTransaction();
            $after = $service->updateSubEvent($pdo, $id, $payload);

            if ($after === null) {
                throw new RuntimeException('Sub event was not found.');
            }

            $this->insertActivityLog($pdo, $userId, 'update', 'sub_events', $id, $before['subEvent'] ?? [], $after['subEvent'] ?? []);
            $pdo->commit();

            Response::jsonResponse(true, 'Sub event updated successfully.', $after, 200);
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function delete(string $subEventId): never
    {
        $pdo = Database::getInstance();

        try {
            $id = $this->normalizeId($subEventId, 'Sub event ID is invalid.');
            $userId = $this->getAuthenticatedUserId();
            $service = new AdminCatalogService();
            $before = $service->getSubEventDetails($pdo, $id);

            if ($before === null) {
                Response::jsonResponse(false, 'Sub event was not found.', [], 404);
            }

            $pdo->beginTransaction();
            $deleted = $service->deleteSubEvent($pdo, $id);

            if (!$deleted) {
                throw new RuntimeException('Sub event was not found.');
            }

            $this->insertActivityLog($pdo, $userId, 'delete', 'sub_events', $id, $before['subEvent'] ?? [], ['deleted' => true]);
            $pdo->commit();

            Response::jsonResponse(true, 'Sub event deleted successfully.', ['id' => $id], 200);
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getFilters(): array
    {
        return [
            'event_id' => $_GET['event_id'] ?? '',
            'city_id' => $_GET['city_id'] ?? '',
            'query' => trim((string) ($_GET['q'] ?? '')),
        ];
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

    private function normalizeId(string $value, string $message): int
    {
        if (!ctype_digit($value) || (int) $value <= 0) {
            throw new RuntimeException($message);
        }

        return (int) $value;
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

    /**
     * @param array<string, mixed> $oldValues
     * @param array<string, mixed> $newValues
     */
    private function insertActivityLog(PDO $pdo, int $userId, string $action, string $tableName, int $recordId, array $oldValues, array $newValues): void
    {
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
