<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Response;
use RuntimeException;
use Throwable;

final class AccountantMiddleware
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

            if (
                in_array($role, ['admin', 'accountant'], true) ||
                in_array('reports', $permissions, true) ||
                in_array('view_reports', $permissions, true)
            ) {
                return $user;
            }

            throw new RuntimeException('Forbidden: accountant access is required.');
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, 'Forbidden: accountant access is required.', [], 403);
        }
    }
}
