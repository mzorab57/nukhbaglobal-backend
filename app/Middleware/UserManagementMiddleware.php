<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Response;
use RuntimeException;
use Throwable;

final class UserManagementMiddleware
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        try {
            $user = (new AuthMiddleware())->handle();
            $role = (string) ($user['role'] ?? '');
            $permissions = is_array($user['permissions'] ?? null) ? $user['permissions'] : [];

            if ($role === 'admin' || in_array('manage_users', $permissions, true)) {
                return $user;
            }

            throw new RuntimeException('Forbidden: user management access is required.');
        } catch (Throwable) {
            Response::jsonResponse(false, 'Forbidden: user management access is required.', [], 403);
        }
    }
}
