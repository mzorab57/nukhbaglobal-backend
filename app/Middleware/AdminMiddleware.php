<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Response;
use RuntimeException;
use Throwable;

final class AdminMiddleware
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
                $role === 'admin' ||
                in_array('manage_catalog', $permissions, true) ||
                in_array('manage_events', $permissions, true) ||
                in_array('manage_tickets', $permissions, true)
            ) {
                return $user;
            }

            throw new RuntimeException('Forbidden: admin access is required.');
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, 'Forbidden: admin access is required.', [], 403);
        }
    }
}
