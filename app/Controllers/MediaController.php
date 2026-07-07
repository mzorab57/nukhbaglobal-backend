<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Helpers\Auth;
use App\Helpers\Response;
use App\Services\MediaItemService;
use JsonException;
use RuntimeException;
use Throwable;

final class MediaController
{
    public function index(): never
    {
        try {
            $pdo = Database::getInstance();
            $service = new MediaItemService();

            Response::jsonResponse(true, 'Media items loaded successfully.', $service->getAdminItems($pdo, $this->getFilters()), 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function show(string $itemId): never
    {
        try {
            $id = $this->normalizeId($itemId, 'Media item ID is invalid.');
            $pdo = Database::getInstance();
            $service = new MediaItemService();
            $item = $service->getAdminItemDetails($pdo, $id);

            if ($item === null) {
                Response::jsonResponse(false, 'Media item was not found.', [], 404);
            }

            Response::jsonResponse(true, 'Media item loaded successfully.', $item, 200);
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
            $service = new MediaItemService();

            $pdo->beginTransaction();
            $item = $service->createItem($pdo, $payload, $userId);
            $this->insertActivityLog($pdo, $userId, 'create', 'media_items', (int) ($item['item']['id'] ?? 0), [], $item['item'] ?? []);
            $pdo->commit();

            Response::jsonResponse(true, 'Media item created successfully.', $item, 201);
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function update(string $itemId): never
    {
        $pdo = Database::getInstance();

        try {
            $id = $this->normalizeId($itemId, 'Media item ID is invalid.');
            $userId = $this->getAuthenticatedUserId();
            $payload = $this->getRequestPayload();
            $service = new MediaItemService();
            $before = $service->getAdminItemDetails($pdo, $id);

            if ($before === null) {
                Response::jsonResponse(false, 'Media item was not found.', [], 404);
            }

            $pdo->beginTransaction();
            $after = $service->updateItem($pdo, $id, $payload);

            if ($after === null) {
                throw new RuntimeException('Media item was not found.');
            }

            $this->insertActivityLog(
                $pdo,
                $userId,
                'update',
                'media_items',
                $id,
                $before['item'] ?? [],
                $after['item'] ?? []
            );
            $pdo->commit();

            Response::jsonResponse(true, 'Media item updated successfully.', $after, 200);
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function delete(string $itemId): never
    {
        $pdo = Database::getInstance();

        try {
            $id = $this->normalizeId($itemId, 'Media item ID is invalid.');
            $userId = $this->getAuthenticatedUserId();
            $service = new MediaItemService();
            $before = $service->getAdminItemDetails($pdo, $id);

            if ($before === null) {
                Response::jsonResponse(false, 'Media item was not found.', [], 404);
            }

            $pdo->beginTransaction();
            $deleted = $service->deleteItem($pdo, $id);

            if (!$deleted) {
                throw new RuntimeException('Media item was not found.');
            }

            $this->insertActivityLog(
                $pdo,
                $userId,
                'delete',
                'media_items',
                $id,
                $before['item'] ?? [],
                [
                    'deleted' => true,
                ]
            );
            $pdo->commit();

            Response::jsonResponse(true, 'Media item deleted successfully.', ['id' => $id], 200);
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function reorder(): never
    {
        $pdo = Database::getInstance();

        try {
            $userId = $this->getAuthenticatedUserId();
            $payload = $this->getRequestPayload();
            $service = new MediaItemService();

            $pdo->beginTransaction();
            $reordered = $service->reorderCategory($pdo, $payload);
            $this->insertActivityLog($pdo, $userId, 'reorder', 'media_items', 0, [], $reordered);
            $pdo->commit();

            Response::jsonResponse(true, 'Media items reordered successfully.', $reordered, 200);
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
            'status' => $_GET['status'] ?? '',
            'category' => $_GET['category'] ?? '',
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
