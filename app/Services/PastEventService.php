<?php

declare(strict_types=1);

namespace App\Services;

use JsonException;
use PDO;
use RuntimeException;

final class PastEventService
{
    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getPastEvents(PDO $pdo, array $filters): array
    {
        $whereClauses = ['pe.deleted_at IS NULL'];
        $parameters = [];

        $category = $this->normalizeNullableString($filters['category'] ?? null);
        if ($category !== null) {
            $whereClauses[] = 'LOWER(pe.categories) LIKE :category';
            $parameters[':category'] = '%' . strtolower($category) . '%';
        }

        $query = trim((string) ($filters['query'] ?? ''));
        if ($query !== '') {
            $queryValue = '%' . strtolower($query) . '%';
            $whereClauses[] = '(
                LOWER(JSON_UNQUOTE(JSON_EXTRACT(pe.title, "$.en"))) LIKE :query_en
                OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(pe.title, "$.ar"))) LIKE :query_ar
                OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(pe.title, "$.ku"))) LIKE :query_ku
                OR LOWER(pe.categories) LIKE :query_categories
                OR LOWER(COALESCE(pe.youtube_video_links, "")) LIKE :query_links
            )';
            $parameters[':query_en'] = $queryValue;
            $parameters[':query_ar'] = $queryValue;
            $parameters[':query_ku'] = $queryValue;
            $parameters[':query_categories'] = $queryValue;
            $parameters[':query_links'] = $queryValue;
        }

        $dateFrom = $this->normalizeNullableDate($filters['date_from'] ?? null);
        if ($dateFrom !== null) {
            $whereClauses[] = 'pe.date >= :date_from';
            $parameters[':date_from'] = $dateFrom;
        }

        $dateTo = $this->normalizeNullableDate($filters['date_to'] ?? null);
        if ($dateTo !== null) {
            $whereClauses[] = 'pe.date <= :date_to';
            $parameters[':date_to'] = $dateTo;
        }

        $statement = $pdo->prepare(
            'SELECT
                pe.id,
                pe.poster_image,
                pe.title,
                pe.date,
                pe.categories,
                pe.youtube_video_links,
                pe.created_at,
                pe.updated_at
             FROM past_events pe
             WHERE ' . implode(' AND ', $whereClauses) . '
             ORDER BY pe.date DESC, pe.id DESC'
        );

        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value);
        }

        $statement->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $items = array_map([$this, 'mapRow'], $rows);

        return [
            'items' => $items,
            'filters' => [
                'category' => $category,
                'query' => $query !== '' ? $query : null,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
            ],
            'summary' => $this->buildSummary($items),
            'categoryOptions' => $this->buildCategoryOptions($items),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPastEventDetails(PDO $pdo, int $pastEventId): ?array
    {
        $record = $this->getPastEventRecord($pdo, $pastEventId);
        if ($record === null) {
            return null;
        }

        return [
            'pastEvent' => $this->mapRow($record),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createPastEvent(PDO $pdo, array $payload): array
    {
        $normalized = $this->validatePayload($payload, true);

        $statement = $pdo->prepare(
            'INSERT INTO past_events (
                poster_image,
                title,
                date,
                categories,
                youtube_video_links
             ) VALUES (
                :poster_image,
                :title,
                :date,
                :categories,
                :youtube_video_links
             )'
        );
        $statement->execute([
            ':poster_image' => $normalized['poster_image'],
            ':title' => $this->encodeJson($normalized['title']),
            ':date' => $normalized['date'],
            ':categories' => $normalized['categories'],
            ':youtube_video_links' => $normalized['youtube_video_links'] !== null
                ? $this->encodeJson($normalized['youtube_video_links'])
                : null,
        ]);

        $created = $this->getPastEventDetails($pdo, (int) $pdo->lastInsertId());
        if ($created === null) {
            throw new RuntimeException('Created past event could not be loaded.');
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function updatePastEvent(PDO $pdo, int $pastEventId, array $payload): ?array
    {
        $existing = $this->getPastEventRecord($pdo, $pastEventId);
        if ($existing === null) {
            return null;
        }

        $normalized = $this->validatePayload($payload, false, $existing);

        $statement = $pdo->prepare(
            'UPDATE past_events
             SET poster_image = :poster_image,
                 title = :title,
                 date = :date,
                 categories = :categories,
                 youtube_video_links = :youtube_video_links,
                 updated_at = NOW()
             WHERE id = :past_event_id'
        );
        $statement->execute([
            ':poster_image' => $normalized['poster_image'],
            ':title' => $this->encodeJson($normalized['title']),
            ':date' => $normalized['date'],
            ':categories' => $normalized['categories'],
            ':youtube_video_links' => $normalized['youtube_video_links'] !== null
                ? $this->encodeJson($normalized['youtube_video_links'])
                : null,
            ':past_event_id' => $pastEventId,
        ]);

        return $this->getPastEventDetails($pdo, $pastEventId);
    }

    public function deletePastEvent(PDO $pdo, int $pastEventId): bool
    {
        $existing = $this->getPastEventRecord($pdo, $pastEventId, true);
        if ($existing === null) {
            return false;
        }

        $statement = $pdo->prepare(
            'UPDATE past_events
             SET deleted_at = NOW(),
                 updated_at = NOW()
             WHERE id = :past_event_id'
        );
        $statement->execute([
            ':past_event_id' => $pastEventId,
        ]);

        return true;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getPublicPastEvents(PDO $pdo, array $filters = []): array
    {
        $payload = $this->getPastEvents($pdo, $filters);

        return [
            'items' => array_map(
                static function (array $item): array {
                    unset($item['createdAt'], $item['updatedAt']);

                    return $item;
                },
                $payload['items'] ?? []
            ),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getPastEventRecord(PDO $pdo, int $pastEventId, bool $includeDeleted = false): ?array
    {
        $sql = 'SELECT
                    pe.id,
                    pe.poster_image,
                    pe.title,
                    pe.date,
                    pe.categories,
                    pe.youtube_video_links,
                    pe.created_at,
                    pe.updated_at,
                    pe.deleted_at
                FROM past_events pe
                WHERE pe.id = :past_event_id';

        if (!$includeDeleted) {
            $sql .= ' AND pe.deleted_at IS NULL';
        }

        $statement = $pdo->prepare($sql);
        $statement->bindValue(':past_event_id', $pastEventId, PDO::PARAM_INT);
        $statement->execute();

        $record = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($record) ? $record : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function validatePayload(array $payload, bool $isCreate, ?array $existing = null): array
    {
        $existingTitle = $existing !== null ? $this->decodeJsonColumnOrNull($existing['title'] ?? null) : null;
        $existingLinks = $existing !== null ? $this->decodeJsonListOrNull($existing['youtube_video_links'] ?? null) : null;

        $titleSource = array_key_exists('title', $payload)
            ? $payload['title']
            : ($isCreate ? null : $existingTitle);

        $posterImageSource = array_key_exists('poster_image', $payload)
            ? $payload['poster_image']
            : ($isCreate ? null : ($existing['poster_image'] ?? null));

        $dateSource = array_key_exists('date', $payload)
            ? $payload['date']
            : ($isCreate ? null : ($existing['date'] ?? null));

        $categoriesSource = array_key_exists('categories', $payload)
            ? $payload['categories']
            : ($isCreate ? null : ($existing['categories'] ?? null));

        $linksSource = array_key_exists('youtube_video_links', $payload)
            ? $payload['youtube_video_links']
            : ($isCreate ? null : $existingLinks);

        return [
            'poster_image' => $this->normalizeRequiredString($posterImageSource, 'poster_image'),
            'title' => $this->normalizeTranslations($titleSource, 'title'),
            'date' => $this->normalizeRequiredDate($dateSource, 'date'),
            'categories' => $this->normalizeCategories($categoriesSource),
            'youtube_video_links' => $this->normalizeNullableYoutubeLinks($linksSource),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $title = $this->decodeJsonColumnOrNull($row['title'] ?? null) ?? [];
        $categories = $this->splitCategories($row['categories'] ?? null);
        $videoLinks = $this->decodeJsonListOrNull($row['youtube_video_links'] ?? null) ?? [];
        $date = (string) ($row['date'] ?? '');

        return [
            'id' => (int) ($row['id'] ?? 0),
            'posterImage' => (string) ($row['poster_image'] ?? ''),
            'desktopImage' => (string) ($row['poster_image'] ?? ''),
            'title' => $title,
            'titleText' => $this->resolveDisplayText($title),
            'date' => $date,
            'year' => strlen($date) >= 4 ? substr($date, 0, 4) : null,
            'categories' => $categories,
            'categoriesText' => implode(', ', $categories),
            'youtubeVideoLinks' => $videoLinks,
            'videoCount' => count($videoLinks),
            'createdAt' => (string) ($row['created_at'] ?? ''),
            'updatedAt' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function buildSummary(array $items): array
    {
        $categories = [];
        $videoCount = 0;

        foreach ($items as $item) {
            foreach (($item['categories'] ?? []) as $category) {
                if (is_string($category) && $category !== '') {
                    $key = strtolower($category);
                    $categories[$key] = $category;
                }
            }

            $videoCount += (int) ($item['videoCount'] ?? 0);
        }

        return [
            'count' => count($items),
            'totalVideos' => $videoCount,
            'categoriesCount' => count($categories),
            'latestDate' => $items[0]['date'] ?? null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, string>
     */
    private function buildCategoryOptions(array $items): array
    {
        $options = [];

        foreach ($items as $item) {
            foreach (($item['categories'] ?? []) as $category) {
                if (!is_string($category) || $category === '') {
                    continue;
                }

                $key = strtolower($category);
                $options[$key] = $category;
            }
        }

        natcasesort($options);

        return array_values($options);
    }

    private function normalizeRequiredString(mixed $value, string $field): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            throw new RuntimeException(sprintf('%s is required.', $field));
        }

        return $normalized;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeRequiredDate(mixed $value, string $field): string
    {
        $normalized = $this->normalizeNullableDate($value);
        if ($normalized === null) {
            throw new RuntimeException(sprintf('%s is required.', $field));
        }

        return $normalized;
    }

    private function normalizeNullableDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $normalized);
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
        ) {
            throw new RuntimeException('date is invalid.');
        }

        return $date->format('Y-m-d');
    }

    private function normalizeTranslations(mixed $value, string $field): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                throw new RuntimeException(sprintf('%s is required.', $field));
            }

            return [
                'en' => $trimmed,
                'ar' => $trimmed,
            ];
        }

        if (!is_array($value)) {
            throw new RuntimeException(sprintf('%s must be a string or object.', $field));
        }

        $translations = [];

        foreach ($value as $locale => $text) {
            if (!is_scalar($text)) {
                continue;
            }

            $normalizedText = trim((string) $text);
            if ($normalizedText === '') {
                continue;
            }

            $translations[(string) $locale] = $normalizedText;
        }

        if ($translations === []) {
            throw new RuntimeException(sprintf('%s is required.', $field));
        }

        return $translations;
    }

    private function normalizeCategories(mixed $value): string
    {
        $categories = [];

        if (is_array($value)) {
            foreach ($value as $entry) {
                if (!is_scalar($entry)) {
                    continue;
                }

                $parts = preg_split('/[\r\n,]+/', (string) $entry) ?: [];
                foreach ($parts as $part) {
                    $normalized = trim($part);
                    if ($normalized === '') {
                        continue;
                    }

                    $key = strtolower($normalized);
                    $categories[$key] = $normalized;
                }
            }
        } else {
            $parts = preg_split('/[\r\n,]+/', trim((string) $value)) ?: [];
            foreach ($parts as $part) {
                $normalized = trim($part);
                if ($normalized === '') {
                    continue;
                }

                $key = strtolower($normalized);
                $categories[$key] = $normalized;
            }
        }

        if ($categories === []) {
            throw new RuntimeException('categories is required.');
        }

        $value = implode(', ', array_values($categories));
        if (strlen($value) > 255) {
            throw new RuntimeException('categories is too long.');
        }

        return $value;
    }

    /**
     * @return array<int, string>|null
     */
    private function normalizeNullableYoutubeLinks(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $links = [];
        $entries = is_array($value) ? $value : preg_split('/[\r\n,]+/', (string) $value);

        foreach ($entries ?: [] as $entry) {
            if (!is_scalar($entry)) {
                continue;
            }

            $normalized = trim((string) $entry);
            if ($normalized === '') {
                continue;
            }

            if (filter_var($normalized, FILTER_VALIDATE_URL) === false) {
                throw new RuntimeException('youtube_video_links contains an invalid URL.');
            }

            if (strlen($normalized) > 2048) {
                throw new RuntimeException('youtube_video_links contains a URL that is too long.');
            }

            if (!in_array($normalized, $links, true)) {
                $links[] = $normalized;
            }
        }

        return $links === [] ? null : $links;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function splitCategories(mixed $value): array
    {
        $categories = [];
        $parts = preg_split('/[\r\n,]+/', trim((string) $value)) ?: [];

        foreach ($parts as $part) {
            $normalized = trim($part);
            if ($normalized === '') {
                continue;
            }

            $key = strtolower($normalized);
            $categories[$key] = $normalized;
        }

        return array_values($categories);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonColumnOrNull(mixed $value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<int, string>|null
     */
    private function decodeJsonListOrNull(mixed $value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return null;
        }

        $values = [];
        foreach ($decoded as $item) {
            if (!is_scalar($item)) {
                continue;
            }

            $normalized = trim((string) $item);
            if ($normalized !== '' && !in_array($normalized, $values, true)) {
                $values[] = $normalized;
            }
        }

        return $values === [] ? null : $values;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function encodeJson(array $value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Failed to encode JSON payload.');
        }
    }

    /**
     * @param array<string, mixed> $translations
     */
    private function resolveDisplayText(array $translations): ?string
    {
        foreach (['en', 'ar', 'ku'] as $locale) {
            $value = $translations[$locale] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        foreach ($translations as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
