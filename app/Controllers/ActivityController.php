<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Helpers\Response;
use App\Services\ReportingService;
use RuntimeException;
use Throwable;

final class ActivityController
{
    public function scanOverview(): never
    {
        try {
            $pdo = Database::getInstance();
            $service = new ReportingService();
            $overview = $service->getScanOverview($pdo);

            Response::jsonResponse(true, 'Scan overview loaded successfully.', $overview, 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, 'Failed to load scan overview.', [], 500);
        }
    }

    public function scanLogs(): never
    {
        try {
            $pdo = Database::getInstance();
            $service = new ReportingService();
            $logs = $service->getScanLogs($pdo, $this->getFilters());

            Response::jsonResponse(true, 'Scan logs loaded successfully.', $logs, 200);
        } catch (Throwable $throwable) {
            $statusCode = $this->resolveHttpStatusCode($throwable->getMessage());
            Response::jsonResponse(false, $throwable->getMessage(), [], $statusCode);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getFilters(): array
    {
        $page = (int) ($_GET['page'] ?? 1);
        $perPage = (int) ($_GET['per_page'] ?? 20);
        $query = trim((string) ($_GET['q'] ?? ''));
        $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
        $dateTo = trim((string) ($_GET['date_to'] ?? ''));
        $scannerUserId = (int) ($_GET['scanner_user_id'] ?? 0);

        if ($dateFrom !== '' && strtotime($dateFrom) === false) {
            throw new RuntimeException('date_from is invalid.');
        }

        if ($dateTo !== '' && strtotime($dateTo) === false) {
            throw new RuntimeException('date_to is invalid.');
        }

        if ($scannerUserId < 0) {
            throw new RuntimeException('scanner_user_id is invalid.');
        }

        return [
            'page' => max(1, $page),
            'per_page' => max(1, min(100, $perPage)),
            'query' => $query,
            'scanner_user_id' => $scannerUserId,
            'date_from' => $dateFrom !== '' ? date('Y-m-d', strtotime($dateFrom)) : '',
            'date_to' => $dateTo !== '' ? date('Y-m-d', strtotime($dateTo)) : '',
        ];
    }

    private function resolveHttpStatusCode(string $message): int
    {
        $normalized = strtolower($message);

        if (str_contains($normalized, 'not found')) {
            return 404;
        }

        if (str_contains($normalized, 'invalid')) {
            return 422;
        }

        return 500;
    }
}
