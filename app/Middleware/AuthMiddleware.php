<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Database;
use App\Helpers\Response;
use App\Services\AuthService;
use Throwable;

final class AuthMiddleware
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        try {
            $pdo = Database::getInstance();
            $service = new AuthService();

            return $service->authenticateCurrentUser($pdo);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, 'Unauthorized.', [], 401);
        }
    }
}
