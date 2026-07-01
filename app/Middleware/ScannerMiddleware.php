<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Response;
use RuntimeException;
use Throwable;

final class ScannerMiddleware
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
                in_array($role, ['admin', 'scanner'], true) ||
                in_array('scan', $permissions, true) ||
                in_array('scan_tickets', $permissions, true)
            ) {
                return $user;
            }

            throw new RuntimeException('Forbidden: scanner access is required.');
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, 'Forbidden: scanner access is required.', [], 403);
        }
    }
}
