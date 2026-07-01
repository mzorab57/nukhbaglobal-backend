<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Auth;
use PDO;
use RuntimeException;

final class AuthService
{
    /**
     * @return array<string, mixed>
     */
    public function attemptLogin(PDO $pdo, string $email, string $password): array
    {
        $normalizedEmail = strtolower(trim($email));

        if ($normalizedEmail === '' || trim($password) === '') {
            throw new RuntimeException('Email and password are required.');
        }

        $user = $this->findActiveUserByEmail($pdo, $normalizedEmail);

        if ($user === null || !password_verify($password, (string) ($user['password'] ?? ''))) {
            throw new RuntimeException('Invalid email or password.');
        }

        $sanitizedUser = $this->sanitizeUser($user);

        return [
            'access_token' => Auth::issueToken($sanitizedUser),
            'token_type' => 'Bearer',
            'expires_in' => Auth::getTokenTtl(),
            'user' => $sanitizedUser,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function authenticateCurrentUser(PDO $pdo): array
    {
        $payload = Auth::authenticateRequest();
        $userId = (int) ($payload['sub'] ?? 0);

        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized.');
        }

        $user = $this->findActiveUserById($pdo, $userId);

        if ($user === null) {
            throw new RuntimeException('Unauthorized.');
        }

        $sanitizedUser = $this->sanitizeUser($user);
        Auth::setAuthenticatedUser($sanitizedUser);

        return $sanitizedUser;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findActiveUserByEmail(PDO $pdo, string $email): ?array
    {
        $statement = $pdo->prepare(
            'SELECT id, name, email, password, role, permissions, status, deleted_at, created_at, updated_at
             FROM users
             WHERE email = :email
             LIMIT 1'
        );
        $statement->execute([
            ':email' => $email,
        ]);

        /** @var array<string, mixed>|false $user */
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if ($user === false || $user['deleted_at'] !== null || (int) ($user['status'] ?? 0) !== 1) {
            return null;
        }

        return $user;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findActiveUserById(PDO $pdo, int $userId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT id, name, email, password, role, permissions, status, deleted_at, created_at, updated_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute([
            ':id' => $userId,
        ]);

        /** @var array<string, mixed>|false $user */
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if ($user === false || $user['deleted_at'] !== null || (int) ($user['status'] ?? 0) !== 1) {
            return null;
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function sanitizeUser(array $user): array
    {
        return [
            'id' => (int) ($user['id'] ?? 0),
            'name' => (string) ($user['name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'role' => (string) ($user['role'] ?? ''),
            'permissions' => $this->decodePermissions($user['permissions'] ?? null),
            'status' => (int) ($user['status'] ?? 0),
            'created_at' => $user['created_at'] !== null ? (string) $user['created_at'] : null,
            'updated_at' => $user['updated_at'] !== null ? (string) $user['updated_at'] : null,
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
}
