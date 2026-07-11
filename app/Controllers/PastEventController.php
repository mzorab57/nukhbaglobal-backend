<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Helpers\Auth;
use App\Helpers\Response;
use App\Services\PastEventService;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class PastEventController
{
    public function publicIndex(): never
    {
        try {
            $pdo = Database::getInstance();
            $service = new PastEventService();

            Response::jsonResponse(true, 'Past events loaded successfully.', $service->getPublicPastEvents($pdo, $this->getFilters()), 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function index(): never
    {
        try {
            $pdo = Database::getInstance();
            $service = new PastEventService();

            Response::jsonResponse(true, 'Past events loaded successfully.', $service->getPastEvents($pdo, $this->getFilters()), 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function show(string $pastEventId): never
    {
        try {
            $id = $this->normalizeId($pastEventId, 'Past event ID is invalid.');
            $pdo = Database::getInstance();
            $service = new PastEventService();
            $pastEvent = $service->getPastEventDetails($pdo, $id);

            if ($pastEvent === null) {
                Response::jsonResponse(false, 'Past event was not found.', [], 404);
            }

            Response::jsonResponse(true, 'Past event loaded successfully.', $pastEvent, 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function create(): never
    {
        $pdo = Database::getInstance();

        try {
            $userId = $this->getAuthenticatedUserId();
            $payload = $this->getRequestPayload();
            $service = new PastEventService();

            $pdo->beginTransaction();
            $pastEvent = $service->createPastEvent($pdo, $payload);
            $this->insertActivityLog($pdo, $userId, 'create', 'past_events', (int) ($pastEvent['pastEvent']['id'] ?? 0), [], $pastEvent['pastEvent'] ?? []);
            $pdo->commit();

            Response::jsonResponse(true, 'Past event created successfully.', $pastEvent, 201);
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function update(string $pastEventId): never
    {
        $pdo = Database::getInstance();

        try {
            $id = $this->normalizeId($pastEventId, 'Past event ID is invalid.');
            $userId = $this->getAuthenticatedUserId();
            $payload = $this->getRequestPayload();
            $service = new PastEventService();
            $before = $service->getPastEventDetails($pdo, $id);

            if ($before === null) {
                Response::jsonResponse(false, 'Past event was not found.', [], 404);
            }

            $pdo->beginTransaction();
            $after = $service->updatePastEvent($pdo, $id, $payload);

            if ($after === null) {
                throw new RuntimeException('Past event was not found.');
            }

            $this->insertActivityLog($pdo, $userId, 'update', 'past_events', $id, $before['pastEvent'] ?? [], $after['pastEvent'] ?? []);
            $pdo->commit();

            Response::jsonResponse(true, 'Past event updated successfully.', $after, 200);
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function delete(string $pastEventId): never
    {
        $pdo = Database::getInstance();

        try {
            $id = $this->normalizeId($pastEventId, 'Past event ID is invalid.');
            $userId = $this->getAuthenticatedUserId();
            $service = new PastEventService();
            $before = $service->getPastEventDetails($pdo, $id);

            if ($before === null) {
                Response::jsonResponse(false, 'Past event was not found.', [], 404);
            }

            $pdo->beginTransaction();
            $deleted = $service->deletePastEvent($pdo, $id);

            if (!$deleted) {
                throw new RuntimeException('Past event was not found.');
            }

            $this->insertActivityLog($pdo, $userId, 'delete', 'past_events', $id, $before['pastEvent'] ?? [], ['deleted' => true]);
            $pdo->commit();

            Response::jsonResponse(true, 'Past event deleted successfully.', ['id' => $id], 200);
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
            'category' => $_GET['category'] ?? '',
            'query' => trim((string) ($_GET['q'] ?? '')),
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
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

        if (
            str_contains($normalized, 'required')
            || str_contains($normalized, 'invalid')
            || str_contains($normalized, 'malformed')
            || str_contains($normalized, 'too long')
        ) {
            return 422;
        }

        if (str_contains($normalized, 'unauthorized')) {
            return 401;
        }

        return 500;
    }
}
