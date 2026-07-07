<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Helpers\Auth;
use App\Helpers\Response;
use App\Services\UserManagementService;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class UserController
{
    public function index(): never
    {
        try {
            $pdo = Database::getInstance();
            $service = new UserManagementService();

            Response::jsonResponse(true, 'Users loaded successfully.', $service->getUsers($pdo, $this->getFilters()), 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function show(string $userId): never
    {
        try {
            $id = $this->normalizeId($userId, 'User ID is invalid.');
            $pdo = Database::getInstance();
            $service = new UserManagementService();
            $user = $service->getUserDetails($pdo, $id);

            if ($user === null) {
                Response::jsonResponse(false, 'User was not found.', [], 404);
            }

            Response::jsonResponse(true, 'User loaded successfully.', $user, 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function create(): never
    {
        $pdo = Database::getInstance();

        try {
            $actingUserId = $this->getAuthenticatedUserId();
            $payload = $this->getRequestPayload();
            $service = new UserManagementService();

            $pdo->beginTransaction();
            $user = $service->createUser($pdo, $payload);
            $this->insertActivityLog($pdo, $actingUserId, 'create', 'users', (int) ($user['user']['id'] ?? 0), [], $user['user'] ?? []);
            $pdo->commit();

            Response::jsonResponse(true, 'User created successfully.', $user, 201);
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function update(string $userId): never
    {
        $pdo = Database::getInstance();

        try {
            $id = $this->normalizeId($userId, 'User ID is invalid.');
            $actingUserId = $this->getAuthenticatedUserId();
            $payload = $this->getRequestPayload();
            $service = new UserManagementService();
            $before = $service->getUserDetails($pdo, $id);

            if ($before === null) {
                Response::jsonResponse(false, 'User was not found.', [], 404);
            }

            $pdo->beginTransaction();
            $after = $service->updateUser($pdo, $id, $payload, $actingUserId);

            if ($after === null) {
                throw new RuntimeException('User was not found.');
            }

            $this->insertActivityLog($pdo, $actingUserId, 'update', 'users', $id, $before['user'] ?? [], $after['user'] ?? []);
            $pdo->commit();

            Response::jsonResponse(true, 'User updated successfully.', $after, 200);
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function delete(string $userId): never
    {
        $pdo = Database::getInstance();

        try {
            $id = $this->normalizeId($userId, 'User ID is invalid.');
            $actingUserId = $this->getAuthenticatedUserId();
            $service = new UserManagementService();
            $before = $service->getUserDetails($pdo, $id);

            if ($before === null) {
                Response::jsonResponse(false, 'User was not found.', [], 404);
            }

            $pdo->beginTransaction();
            $deleted = $service->deleteUser($pdo, $id, $actingUserId);

            if (!$deleted) {
                throw new RuntimeException('User was not found.');
            }

            $this->insertActivityLog($pdo, $actingUserId, 'delete', 'users', $id, $before['user'] ?? [], ['deleted' => true]);
            $pdo->commit();

            Response::jsonResponse(true, 'User deleted successfully.', ['id' => $id], 200);
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
            'role' => $_GET['role'] ?? '',
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

        if (str_contains($normalized, 'invalid') || str_contains($normalized, 'required') || str_contains($normalized, 'malformed') || str_contains($normalized, 'already')) {
            return 422;
        }

        if (str_contains($normalized, 'unauthorized')) {
            return 401;
        }

        if (str_contains($normalized, 'forbidden')) {
            return 403;
        }

        return 500;
    }
}
