<?php

declare(strict_types=1);

namespace App\Services;

use JsonException;
use PDO;
use RuntimeException;

final class UserManagementService
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_ROLES = ['admin', 'scanner', 'accountant'];

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getUsers(PDO $pdo, array $filters): array
    {
        $whereClauses = ['u.deleted_at IS NULL'];
        $parameters = [];

        $status = $this->nullableFlag($filters['status'] ?? null);
        if ($status !== null) {
            $whereClauses[] = 'u.status = :status';
            $parameters[':status'] = $status;
        }

        $role = $this->normalizeNullableRole($filters['role'] ?? null);
        if ($role !== null) {
            $whereClauses[] = 'u.role = :role';
            $parameters[':role'] = $role;
        }

        $query = trim((string) ($filters['query'] ?? ''));
        if ($query !== '') {
            $queryValue = '%' . strtolower($query) . '%';
            $whereClauses[] = '(
                LOWER(u.name) LIKE :query_name
                OR LOWER(u.email) LIKE :query_email
                OR LOWER(u.role) LIKE :query_role
            )';
            $parameters[':query_name'] = $queryValue;
            $parameters[':query_email'] = $queryValue;
            $parameters[':query_role'] = $queryValue;
        }

        $statement = $pdo->prepare(
            'SELECT
                u.id,
                u.name,
                u.email,
                u.role,
                u.permissions,
                u.status,
                u.created_at,
                u.updated_at
             FROM users u
             WHERE ' . implode(' AND ', $whereClauses) . '
             ORDER BY u.created_at DESC, u.id DESC'
        );

        foreach ($parameters as $key => $value) {
            if (is_int($value)) {
                $statement->bindValue($key, $value, PDO::PARAM_INT);
                continue;
            }

            $statement->bindValue($key, $value);
        }

        $statement->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $items = array_map([$this, 'mapUserRow'], $rows);

        return [
            'items' => $items,
            'filters' => [
                'status' => $status,
                'role' => $role,
                'query' => $query !== '' ? $query : null,
            ],
            'roleOptions' => self::ALLOWED_ROLES,
            'stats' => $this->buildStats($items),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUserDetails(PDO $pdo, int $userId): ?array
    {
        $user = $this->getUserRecord($pdo, $userId);
        if ($user === null) {
            return null;
        }

        return [
            'user' => $this->mapUserRow($user),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createUser(PDO $pdo, array $payload): array
    {
        $normalized = $this->validatePayload($pdo, $payload, true);

        $statement = $pdo->prepare(
            'INSERT INTO users (
                name,
                email,
                password,
                role,
                permissions,
                status
             ) VALUES (
                :name,
                :email,
                :password,
                :role,
                :permissions,
                :status
             )'
        );
        $statement->execute([
            ':name' => $normalized['name'],
            ':email' => $normalized['email'],
            ':password' => password_hash($normalized['password'], PASSWORD_DEFAULT),
            ':role' => $normalized['role'],
            ':permissions' => $this->encodeJson($normalized['permissions']),
            ':status' => $normalized['status'],
        ]);

        $user = $this->getUserDetails($pdo, (int) $pdo->lastInsertId());
        if ($user === null) {
            throw new RuntimeException('Created user could not be loaded.');
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function updateUser(PDO $pdo, int $userId, array $payload, int $actingUserId): ?array
    {
        $existing = $this->getUserRecord($pdo, $userId);
        if ($existing === null) {
            return null;
        }

        $normalized = $this->validatePayload($pdo, $payload, false, $existing);
        $this->guardProtectedAdminState(
            $pdo,
            $userId,
            $actingUserId,
            (string) ($existing['role'] ?? 'scanner'),
            (int) ($existing['status'] ?? 0),
            $normalized['role'],
            $normalized['status']
        );

        $fields = [
            'name = :name',
            'email = :email',
            'role = :role',
            'permissions = :permissions',
            'status = :status',
            'updated_at = NOW()',
        ];
        $parameters = [
            ':name' => $normalized['name'],
            ':email' => $normalized['email'],
            ':role' => $normalized['role'],
            ':permissions' => $this->encodeJson($normalized['permissions']),
            ':status' => $normalized['status'],
            ':user_id' => $userId,
        ];

        if ($normalized['password'] !== null) {
            $fields[] = 'password = :password';
            $parameters[':password'] = password_hash($normalized['password'], PASSWORD_DEFAULT);
        }

        $statement = $pdo->prepare(
            'UPDATE users
             SET ' . implode(', ', $fields) . '
             WHERE id = :user_id'
        );
        $statement->execute($parameters);

        return $this->getUserDetails($pdo, $userId);
    }

    public function deleteUser(PDO $pdo, int $userId, int $actingUserId): bool
    {
        $user = $this->getUserRecord($pdo, $userId, true);
        if ($user === null) {
            return false;
        }

        if ($userId === $actingUserId) {
            throw new RuntimeException('You cannot delete your own account.');
        }

        if ((string) ($user['role'] ?? '') === 'admin' && (int) ($user['status'] ?? 0) === 1 && $this->countActiveAdmins($pdo) <= 1) {
            throw new RuntimeException('At least one active admin account must remain.');
        }

        $statement = $pdo->prepare(
            'UPDATE users
             SET status = 0,
                 deleted_at = NOW(),
                 updated_at = NOW()
             WHERE id = :user_id'
        );
        $statement->execute([
            ':user_id' => $userId,
        ]);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUserRecord(PDO $pdo, int $userId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT *
                FROM users
                WHERE id = :user_id
                  AND deleted_at IS NULL
                LIMIT 1';

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $pdo->prepare($sql);
        $statement->execute([
            ':user_id' => $userId,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function validatePayload(PDO $pdo, array $payload, bool $isCreate, ?array $existing = null): array
    {
        $name = array_key_exists('name', $payload)
            ? $this->normalizeRequiredString($payload['name'], 'name')
            : trim((string) ($existing['name'] ?? ''));

        $email = array_key_exists('email', $payload)
            ? $this->normalizeEmail($payload['email'])
            : strtolower(trim((string) ($existing['email'] ?? '')));

        $password = array_key_exists('password', $payload)
            ? $this->normalizeNullablePassword($payload['password'])
            : null;

        $role = array_key_exists('role', $payload)
            ? $this->normalizeRole($payload['role'])
            : $this->normalizeRole($existing['role'] ?? null);

        $permissions = array_key_exists('permissions', $payload)
            ? $this->normalizePermissions($payload['permissions'])
            : $this->decodePermissions($existing['permissions'] ?? null);

        $status = array_key_exists('status', $payload)
            ? $this->normalizeFlag($payload['status'], 'status')
            : (int) ($existing['status'] ?? 1);

        if ($isCreate && $password === null) {
            throw new RuntimeException('password is required.');
        }

        $existingUserId = $existing !== null ? (int) ($existing['id'] ?? 0) : 0;
        $this->ensureUniqueEmail($pdo, $email, $existingUserId);

        return [
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'permissions' => $permissions,
            'status' => $status,
        ];
    }

    private function ensureUniqueEmail(PDO $pdo, string $email, int $ignoredUserId): void
    {
        $statement = $pdo->prepare(
            'SELECT id
             FROM users
             WHERE email = :email
               AND deleted_at IS NULL
               AND id <> :user_id
             LIMIT 1'
        );
        $statement->execute([
            ':email' => $email,
            ':user_id' => $ignoredUserId,
        ]);

        if ($statement->fetchColumn() !== false) {
            throw new RuntimeException('email is already in use.');
        }
    }

    private function guardProtectedAdminState(
        PDO $pdo,
        int $targetUserId,
        int $actingUserId,
        string $previousRole,
        int $previousStatus,
        string $nextRole,
        int $nextStatus
    ): void {
        if ($targetUserId === $actingUserId && $nextStatus !== 1) {
            throw new RuntimeException('You cannot deactivate your own account.');
        }

        if ($previousRole !== 'admin' || $previousStatus !== 1) {
            return;
        }

        $willStopBeingActiveAdmin = $nextRole !== 'admin' || $nextStatus !== 1;
        if ($willStopBeingActiveAdmin && $this->countActiveAdmins($pdo) <= 1) {
            throw new RuntimeException('At least one active admin account must remain.');
        }
    }

    private function countActiveAdmins(PDO $pdo): int
    {
        $statement = $pdo->query(
            "SELECT COUNT(*)
             FROM users
             WHERE role = 'admin'
               AND status = 1
               AND deleted_at IS NULL"
        );

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, int>
     */
    private function buildStats(array $items): array
    {
        $stats = [
            'totalUsers' => count($items),
            'activeUsers' => 0,
            'admins' => 0,
            'scanners' => 0,
            'accountants' => 0,
        ];

        foreach ($items as $item) {
            if (($item['status'] ?? false) === true) {
                $stats['activeUsers']++;
            }

            $role = (string) ($item['role'] ?? '');
            if ($role === 'admin') {
                $stats['admins']++;
            }

            if ($role === 'scanner') {
                $stats['scanners']++;
            }

            if ($role === 'accountant') {
                $stats['accountants']++;
            }
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapUserRow(array $row): array
    {
        $permissions = $this->decodePermissions($row['permissions'] ?? null);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'role' => (string) ($row['role'] ?? ''),
            'permissions' => $permissions,
            'permissionsCount' => count($permissions),
            'status' => (int) ($row['status'] ?? 0) === 1,
            'createdAt' => $row['created_at'] !== null ? (string) $row['created_at'] : null,
            'updatedAt' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function decodePermissions(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        $permissions = [];
        foreach ($decoded as $permission) {
            if (!is_string($permission) || trim($permission) === '') {
                continue;
            }

            $permissions[] = trim($permission);
        }

        return array_values(array_unique($permissions));
    }

    /**
     * @param array<int, string> $value
     */
    private function encodeJson(array $value): string
    {
        try {
            return json_encode(array_values($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Failed to encode JSON payload.');
        }
    }

    private function normalizeRequiredString(mixed $value, string $field): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            throw new RuntimeException(sprintf('%s is required.', $field));
        }

        return $normalized;
    }

    private function normalizeEmail(mixed $value): string
    {
        $email = strtolower(trim((string) $value));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('email is invalid.');
        }

        return $email;
    }

    private function normalizeNullablePassword(mixed $value): ?string
    {
        $password = trim((string) $value);
        if ($password === '') {
            return null;
        }

        if (strlen($password) < 8) {
            throw new RuntimeException('password must be at least 8 characters.');
        }

        return $password;
    }

    private function normalizeRole(mixed $value): string
    {
        $role = strtolower(trim((string) $value));
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new RuntimeException('role is invalid.');
        }

        return $role;
    }

    private function normalizeNullableRole(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->normalizeRole($value);
    }

    /**
     * @return array<int, string>
     */
    private function normalizePermissions(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $parts = array_map('trim', explode(',', $value));
            $permissions = array_filter($parts, static fn (string $item): bool => $item !== '');
            return array_values(array_unique($permissions));
        }

        if (!is_array($value)) {
            throw new RuntimeException('permissions must be an array or string.');
        }

        $permissions = [];
        foreach ($value as $permission) {
            if (!is_scalar($permission)) {
                continue;
            }

            $normalized = trim((string) $permission);
            if ($normalized === '') {
                continue;
            }

            $permissions[] = $normalized;
        }

        return array_values(array_unique($permissions));
    }

    private function normalizeFlag(mixed $value, string $field): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_numeric($value)) {
            $normalized = (int) $value;
            if (in_array($normalized, [0, 1], true)) {
                return $normalized;
            }
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return 1;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return 0;
        }

        throw new RuntimeException(sprintf('%s is invalid.', $field));
    }

    private function nullableFlag(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->normalizeFlag($value, 'flag');
    }
}
