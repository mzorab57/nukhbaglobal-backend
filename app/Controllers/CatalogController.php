<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Helpers\Response;
use App\Services\MediaItemService;
use App\Services\PublicCatalogService;
use RuntimeException;
use Throwable;

final class CatalogController
{
    public function home(): never
    {
        try {
            $pdo = Database::getInstance();
            $service = new PublicCatalogService();

            Response::jsonResponse(true, 'Home feed loaded successfully.', $service->getHomeFeed($pdo), 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function events(): never
    {
        try {
            $pdo = Database::getInstance();
            $service = new PublicCatalogService();

            Response::jsonResponse(true, 'Public events loaded successfully.', $service->getEvents($pdo, $this->getEventFilters()), 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function showEvent(string $eventId): never
    {
        try {
            $id = $this->normalizeId($eventId, 'Event ID is invalid.');
            $pdo = Database::getInstance();
            $service = new PublicCatalogService();
            $event = $service->getEventDetails($pdo, $id);

            if ($event === null) {
                Response::jsonResponse(false, 'Event was not found.', [], 404);
            }

            Response::jsonResponse(true, 'Public event loaded successfully.', $event, 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function checkoutFeed(string $eventId): never
    {
        try {
            $id = $this->normalizeId($eventId, 'Event ID is invalid.');
            $pdo = Database::getInstance();
            $service = new PublicCatalogService();
            $feed = $service->getCheckoutFeed($pdo, $id);

            if ($feed === null) {
                Response::jsonResponse(false, 'Event was not found.', [], 404);
            }

            Response::jsonResponse(true, 'Checkout feed loaded successfully.', $feed, 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function countries(): never
    {
        try {
            $pdo = Database::getInstance();
            $service = new PublicCatalogService();

            Response::jsonResponse(true, 'Public countries loaded successfully.', $service->getCountries($pdo), 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function featured(): never
    {
        try {
            $pdo = Database::getInstance();
            $service = new PublicCatalogService();

            Response::jsonResponse(true, 'Featured feed loaded successfully.', $service->getFeaturedFeed($pdo), 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function trending(): never
    {
        try {
            $pdo = Database::getInstance();
            $service = new PublicCatalogService();
            $limit = max(1, min(20, (int) ($_GET['limit'] ?? 8)));

            Response::jsonResponse(true, 'Trending feed loaded successfully.', $service->getTrendingFeed($pdo, $limit), 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function search(): never
    {
        try {
            $query = trim((string) ($_GET['q'] ?? ''));
            $limit = max(1, min(20, (int) ($_GET['limit'] ?? 10)));
            $pdo = Database::getInstance();
            $service = new PublicCatalogService();

            Response::jsonResponse(true, 'Search results loaded successfully.', $service->search($pdo, $query, $limit), 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function cities(): never
    {
        try {
            $pdo = Database::getInstance();
            $service = new PublicCatalogService();

            Response::jsonResponse(true, 'Public cities loaded successfully.', $service->getCities($pdo, $this->getCityFilters()), 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    public function media(): never
    {
        try {
            $pdo = Database::getInstance();
            $service = new MediaItemService();

            Response::jsonResponse(true, 'Public media feed loaded successfully.', $service->getPublicFeed($pdo, $this->getMediaFilters()), 200);
        } catch (Throwable $throwable) {
            Response::jsonResponse(false, $throwable->getMessage(), [], $this->resolveHttpStatusCode($throwable->getMessage()));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getEventFilters(): array
    {
        return [
            'upcoming' => $_GET['upcoming'] ?? '',
            'country_id' => $_GET['country_id'] ?? '',
            'city_id' => $_GET['city_id'] ?? '',
            'q' => trim((string) ($_GET['q'] ?? '')),
            'query' => trim((string) ($_GET['q'] ?? '')),
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
            'limit' => $_GET['limit'] ?? 12,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getCityFilters(): array
    {
        return [
            'country_id' => $_GET['country_id'] ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getMediaFilters(): array
    {
        return [
            'category' => $_GET['category'] ?? '',
            'categories' => $_GET['categories'] ?? '',
        ];
    }

    private function normalizeId(string $value, string $message): int
    {
        if (!ctype_digit($value) || (int) $value <= 0) {
            throw new RuntimeException($message);
        }

        return (int) $value;
    }

    private function resolveHttpStatusCode(string $message): int
    {
        $normalized = strtolower($message);

        if (str_contains($normalized, 'not found')) {
            return 404;
        }

        if (str_contains($normalized, 'invalid') || str_contains($normalized, 'required')) {
            return 422;
        }

        return 500;
    }
}
