<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Helpers\Auth;
use App\Helpers\Response;
use App\Services\StallApplicationService;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class StallApplicationController
{
    public function index(): never
    {
        try {
            $pdo = Database::getInstance();
            $service = new StallApplicationService();

            Response::jsonResponse(true, 'Stall applications loaded successfully.', $service->getApplications($pdo, $this->getFilters()), 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function show(string $applicationId): never
    {
        try {
            $id = $this->normalizeId($applicationId, 'Application ID is invalid.');
            $pdo = Database::getInstance();
            $service = new StallApplicationService();
            $application = $service->getApplicationDetails($pdo, $id);

            if ($application === null) {
                Response::jsonResponse(false, 'Application was not found.', [], 404);
            }

            Response::jsonResponse(true, 'Stall application loaded successfully.', $application, 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function submit(): never
    {
        $pdo = Database::getInstance();

        try {
            $payload = $this->getRequestPayload();
            $service = new StallApplicationService();

            $pdo->beginTransaction();
            $application = $service->createApplication($pdo, $payload, 'website');
            $pdo->commit();

            Response::jsonResponse(true, 'Your application has been sent successfully.', $application, 201);
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function create(): never
    {
        $pdo = Database::getInstance();

        try {
            $actingUserId = $this->getAuthenticatedUserId();
            $payload = $this->getRequestPayload();
            $service = new StallApplicationService();

            $pdo->beginTransaction();
            $application = $service->createApplication($pdo, $payload, 'admin', $actingUserId);
            $this->insertActivityLog($pdo, $actingUserId, 'create', 'stall_applications', (int) ($application['application']['id'] ?? 0), [], $application['application'] ?? []);
            $pdo->commit();

            Response::jsonResponse(true, 'Stall application created successfully.', $application, 201);
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function update(string $applicationId): never
    {
        $pdo = Database::getInstance();

        try {
            $id = $this->normalizeId($applicationId, 'Application ID is invalid.');
            $actingUserId = $this->getAuthenticatedUserId();
            $payload = $this->getRequestPayload();
            $service = new StallApplicationService();
            $before = $service->getApplicationDetails($pdo, $id);

            if ($before === null) {
                Response::jsonResponse(false, 'Application was not found.', [], 404);
            }

            $pdo->beginTransaction();
            $after = $service->updateApplication($pdo, $id, $payload);

            if ($after === null) {
                throw new RuntimeException('Application was not found.');
            }

            $this->insertActivityLog($pdo, $actingUserId, 'update', 'stall_applications', $id, $before['application'] ?? [], $after['application'] ?? []);
            $pdo->commit();

            Response::jsonResponse(true, 'Stall application updated successfully.', $after, 200);
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function delete(string $applicationId): never
    {
        $pdo = Database::getInstance();

        try {
            $id = $this->normalizeId($applicationId, 'Application ID is invalid.');
            $actingUserId = $this->getAuthenticatedUserId();
            $service = new StallApplicationService();
            $before = $service->getApplicationDetails($pdo, $id);

            if ($before === null) {
                Response::jsonResponse(false, 'Application was not found.', [], 404);
            }

            $pdo->beginTransaction();
            $deleted = $service->deleteApplication($pdo, $id);

            if (!$deleted) {
                throw new RuntimeException('Application was not found.');
            }

            $this->insertActivityLog($pdo, $actingUserId, 'delete', 'stall_applications', $id, $before['application'] ?? [], ['deleted' => true]);
            $pdo->commit();

            Response::jsonResponse(true, 'Stall application deleted successfully.', ['id' => $id], 200);
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
            'source' => $_GET['source'] ?? '',
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

        if (
            str_contains($normalized, 'required') ||
            str_contains($normalized, 'invalid') ||
            str_contains($normalized, 'malformed') ||
            str_contains($normalized, 'must contain')
        ) {
            return 422;
        }

        if (str_contains($normalized, 'unauthorized')) {
            return 401;
        }

        return 500;
    }
}
