<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Helpers\Response;
use App\Services\ReportingService;
use Throwable;

final class DashboardController
{
    public function overview(): never
    {
        try {
            $pdo = Database::getInstance();
            $service = new ReportingService();
            $overview = $service->getOverview($pdo);

            Response::jsonResponse(true, 'Dashboard overview loaded successfully.', $overview, 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, 'Failed to load dashboard overview.', [], 500);
        }
    }
}
