<?php

declare(strict_types=1);

namespace App\Services;

use JsonException;
use PDO;
use RuntimeException;

final class MediaItemService
{
    /**
     * @var array<string, array{label:string,behavior:string}>
     */
    private const CATEGORY_DEFINITIONS = [
        'home_hero' => [
            'label' => 'Home Hero Slider',
            'behavior' => 'collection',
        ],
        'about_banner' => [
            'label' => 'About Page Banner',
            'behavior' => 'single',
        ],
        'contact_banner' => [
            'label' => 'Contact Page Banner',
            'behavior' => 'single',
        ],
        'services_banner' => [
            'label' => 'Services Page Banner',
            'behavior' => 'single',
        ],
        'homepage_slider' => [
            'label' => 'Homepage Promotional Slider',
            'behavior' => 'collection',
        ],
        'section_gallery' => [
            'label' => 'Section Gallery',
            'behavior' => 'collection',
        ],
        'upcoming_visuals' => [
            'label' => 'Upcoming Events Visuals',
            'behavior' => 'collection',
        ],
    ];

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getAdminItems(PDO $pdo, array $filters): array
    {
        $whereClauses = ['mi.deleted_at IS NULL'];
        $parameters = [];

        $status = $this->nullableFlag($filters['status'] ?? null);
        if ($status !== null) {
            $whereClauses[] = 'mi.status = :status';
            $parameters[':status'] = $status;
        }

        $category = $this->normalizeNullableCategory($filters['category'] ?? null);
        if ($category !== null) {
            $whereClauses[] = 'mi.category = :category';
            $parameters[':category'] = $category;
        }

        $query = trim((string) ($filters['query'] ?? ''));
        if ($query !== '') {
            $queryValue = '%' . $query . '%';
            $whereClauses[] = '(
                mi.category LIKE :query_category
                OR JSON_UNQUOTE(JSON_EXTRACT(mi.title, "$.en")) LIKE :query_title_en
                OR JSON_UNQUOTE(JSON_EXTRACT(mi.title, "$.ar")) LIKE :query_title_ar
                OR JSON_UNQUOTE(JSON_EXTRACT(mi.cta_label, "$.en")) LIKE :query_cta_en
                OR JSON_UNQUOTE(JSON_EXTRACT(mi.cta_label, "$.ar")) LIKE :query_cta_ar
                OR mi.cta_url LIKE :query_cta_url
            )';
            $parameters[':query_category'] = $queryValue;
            $parameters[':query_title_en'] = $queryValue;
            $parameters[':query_title_ar'] = $queryValue;
            $parameters[':query_cta_en'] = $queryValue;
            $parameters[':query_cta_ar'] = $queryValue;
            $parameters[':query_cta_url'] = $queryValue;
        }

        $statement = $pdo->prepare(
            'SELECT
                mi.id,
                mi.user_id,
                mi.category,
                mi.title,
                mi.desktop_image,
                mi.mobile_image,
                mi.cta_label,
                mi.cta_url,
                mi.sort_order,
                mi.status,
                mi.created_at,
                mi.updated_at
             FROM media_items mi
             WHERE ' . implode(' AND ', $whereClauses) . '
             ORDER BY mi.category ASC, mi.sort_order ASC, mi.id ASC'
        );

        foreach ($parameters as $key => $value) {
            if (is_int($value)) {
                $statement->bindValue($key, $value, PDO::PARAM_INT);
                continue;
            }

            $statement->bindValue($key, $value);
        }

        $statement->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $items = array_map([$this, 'mapAdminRow'], $rows);

        return [
            'items' => $items,
            'filters' => [
                'status' => $status,
                'category' => $category,
                'query' => $query !== '' ? $query : null,
            ],
            'categoryOptions' => $this->buildCategoryOptions($pdo, $items),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAdminItemDetails(PDO $pdo, int $itemId): ?array
    {
        $record = $this->getItemRecord($pdo, $itemId);
        if ($record === null) {
            return null;
        }

        return [
            'item' => $this->mapAdminRow($record),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createItem(PDO $pdo, array $payload, int $userId): array
    {
        $normalized = $this->validatePayload($payload, true);

        $statement = $pdo->prepare(
            'INSERT INTO media_items (
                user_id,
                category,
                title,
                desktop_image,
                mobile_image,
                cta_label,
                cta_url,
                sort_order,
                status
             ) VALUES (
                :user_id,
                :category,
                :title,
                :desktop_image,
                :mobile_image,
                :cta_label,
                :cta_url,
                0,
                :status
             )'
        );
        $statement->execute([
            ':user_id' => $userId,
            ':category' => $normalized['category'],
            ':title' => $normalized['title'] !== null ? $this->encodeJson($normalized['title']) : null,
            ':desktop_image' => $normalized['desktop_image'],
            ':mobile_image' => $normalized['mobile_image'],
            ':cta_label' => $normalized['cta_label'] !== null ? $this->encodeJson($normalized['cta_label']) : null,
            ':cta_url' => $normalized['cta_url'],
            ':status' => $normalized['status'],
        ]);

        $itemId = (int) $pdo->lastInsertId();
        $this->placeItemInCategoryOrder($pdo, $normalized['category'], $itemId, $normalized['sort_order']);

        $item = $this->getAdminItemDetails($pdo, $itemId);
        if ($item === null) {
            throw new RuntimeException('Created media item could not be loaded.');
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function updateItem(PDO $pdo, int $itemId, array $payload): ?array
    {
        $existing = $this->getItemRecord($pdo, $itemId);
        if ($existing === null) {
            return null;
        }

        $normalized = $this->validatePayload($payload, false, $existing);
        $previousCategory = (string) $existing['category'];

        $statement = $pdo->prepare(
            'UPDATE media_items
             SET category = :category,
                 title = :title,
                 desktop_image = :desktop_image,
                 mobile_image = :mobile_image,
                 cta_label = :cta_label,
                 cta_url = :cta_url,
                 status = :status,
                 updated_at = NOW()
             WHERE id = :item_id'
        );
        $statement->execute([
            ':category' => $normalized['category'],
            ':title' => $normalized['title'] !== null ? $this->encodeJson($normalized['title']) : null,
            ':desktop_image' => $normalized['desktop_image'],
            ':mobile_image' => $normalized['mobile_image'],
            ':cta_label' => $normalized['cta_label'] !== null ? $this->encodeJson($normalized['cta_label']) : null,
            ':cta_url' => $normalized['cta_url'],
            ':status' => $normalized['status'],
            ':item_id' => $itemId,
        ]);

        if ($previousCategory !== $normalized['category']) {
            $this->compactCategoryOrder($pdo, $previousCategory);
        }

        $this->placeItemInCategoryOrder($pdo, $normalized['category'], $itemId, $normalized['sort_order']);

        return $this->getAdminItemDetails($pdo, $itemId);
    }

    public function deleteItem(PDO $pdo, int $itemId): bool
    {
        $existing = $this->getItemRecord($pdo, $itemId, true);
        if ($existing === null) {
            return false;
        }

        $statement = $pdo->prepare(
            'UPDATE media_items
             SET status = 0,
                 deleted_at = NOW(),
                 updated_at = NOW()
             WHERE id = :item_id'
        );
        $statement->execute([
            ':item_id' => $itemId,
        ]);

        $this->compactCategoryOrder($pdo, (string) $existing['category']);

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function reorderCategory(PDO $pdo, array $payload): array
    {
        $category = $this->normalizeCategory($payload['category'] ?? null);
        $itemIds = $this->normalizeIdList($payload['item_ids'] ?? null);

        $existingIds = $this->getOrderedCategoryIds($pdo, $category);
        $sortedExistingIds = $existingIds;
        sort($sortedExistingIds);

        $sortedRequestedIds = $itemIds;
        sort($sortedRequestedIds);

        if ($sortedExistingIds !== $sortedRequestedIds) {
            throw new RuntimeException('item_ids must include every active record in the selected category.');
        }

        $this->applyCategoryOrder($pdo, $itemIds);

        return [
            'category' => $category,
            'items' => $this->getPublicCategoryBucket($pdo, $category, true)['items'],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getPublicFeed(PDO $pdo, array $filters): array
    {
        $requestedCategories = $this->normalizeRequestedCategories($filters);
        if ($requestedCategories === []) {
            $requestedCategories = array_keys(self::CATEGORY_DEFINITIONS);
        }

        $categories = [];

        foreach ($requestedCategories as $category) {
            $bucket = $this->getPublicCategoryBucket($pdo, $category, false);
            $categories[$category] = $bucket;
        }

        return [
            'categories' => $categories,
            'categoryOptions' => $this->buildCategoryOptions($pdo),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getItemRecord(PDO $pdo, int $itemId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT *
                FROM media_items
                WHERE id = :item_id
                  AND deleted_at IS NULL
                LIMIT 1';

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $pdo->prepare($sql);
        $statement->execute([
            ':item_id' => $itemId,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed>|null $filters
     * @return array<int, string>
     */
    private function normalizeRequestedCategories(?array $filters): array
    {
        $rawCategories = [];

        if (isset($filters['category'])) {
            $rawCategories[] = $filters['category'];
        }

        $categoriesValue = $filters['categories'] ?? null;
        if (is_string($categoriesValue) && trim($categoriesValue) !== '') {
            $rawCategories = array_merge($rawCategories, explode(',', $categoriesValue));
        } elseif (is_array($categoriesValue)) {
            $rawCategories = array_merge($rawCategories, $categoriesValue);
        }

        $categories = [];

        foreach ($rawCategories as $value) {
            try {
                $normalized = $this->normalizeCategory($value);
            } catch (RuntimeException) {
                continue;
            }

            if (!in_array($normalized, $categories, true)) {
                $categories[] = $normalized;
            }
        }

        return $categories;
    }

    /**
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function validatePayload(array $payload, bool $isCreate, ?array $existing = null): array
    {
        $category = array_key_exists('category', $payload)
            ? $this->normalizeCategory($payload['category'])
            : $this->normalizeCategory($existing['category'] ?? null);

        $title = array_key_exists('title', $payload)
            ? $this->normalizeNullableTranslations($payload['title'], 'title')
            : $this->decodeJsonColumnOrNull($existing['title'] ?? null);

        $desktopImage = array_key_exists('desktop_image', $payload)
            ? $this->normalizeRequiredString($payload['desktop_image'], 'desktop_image')
            : trim((string) ($existing['desktop_image'] ?? ''));

        $mobileImage = array_key_exists('mobile_image', $payload)
            ? $this->normalizeRequiredString($payload['mobile_image'], 'mobile_image')
            : trim((string) ($existing['mobile_image'] ?? ''));

        $ctaLabel = array_key_exists('cta_label', $payload)
            ? $this->normalizeNullableTranslations($payload['cta_label'], 'cta_label')
            : $this->decodeJsonColumnOrNull($existing['cta_label'] ?? null);

        $ctaUrl = array_key_exists('cta_url', $payload)
            ? $this->normalizeNullableUrl($payload['cta_url'])
            : $this->normalizeNullableUrl($existing['cta_url'] ?? null);

        $sortOrder = array_key_exists('sort_order', $payload)
            ? $this->normalizeNullablePositiveInt($payload['sort_order'], 'sort_order')
            : ($isCreate ? null : (int) ($existing['sort_order'] ?? 1));

        $status = array_key_exists('status', $payload)
            ? $this->normalizeFlag($payload['status'], 'status')
            : (int) ($existing['status'] ?? 1);

        if ($isCreate && ($desktopImage === '' || $mobileImage === '')) {
            throw new RuntimeException('desktop_image and mobile_image are required.');
        }

        return [
            'category' => $category,
            'title' => $title,
            'desktop_image' => $desktopImage,
            'mobile_image' => $mobileImage,
            'cta_label' => $ctaLabel,
            'cta_url' => $ctaUrl,
            'sort_order' => $sortOrder,
            'status' => $status,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, string>>
     */
    private function buildCategoryOptions(PDO $pdo, array $items = []): array
    {
        $categories = array_keys(self::CATEGORY_DEFINITIONS);

        if ($items !== []) {
            foreach ($items as $item) {
                $key = (string) ($item['category'] ?? '');
                if ($key !== '' && !in_array($key, $categories, true)) {
                    $categories[] = $key;
                }
            }
        } else {
            $statement = $pdo->query(
                'SELECT DISTINCT category
                 FROM media_items
                 WHERE deleted_at IS NULL
                 ORDER BY category ASC'
            );

            /** @var array<int, string> $dbCategories */
            $dbCategories = array_map(
                static fn (mixed $value): string => (string) $value,
                $statement->fetchAll(PDO::FETCH_COLUMN)
            );

            foreach ($dbCategories as $category) {
                if ($category !== '' && !in_array($category, $categories, true)) {
                    $categories[] = $category;
                }
            }
        }

        sort($categories);

        return array_map(
            fn (string $category): array => [
                'key' => $category,
                'label' => $this->resolveCategoryLabel($category),
                'behavior' => $this->resolveCategoryBehavior($category),
            ],
            $categories
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getPublicCategoryBucket(PDO $pdo, string $category, bool $forAdminResponse): array
    {
        $statement = $pdo->prepare(
            'SELECT
                id,
                user_id,
                category,
                title,
                desktop_image,
                mobile_image,
                cta_label,
                cta_url,
                sort_order,
                status,
                created_at,
                updated_at
             FROM media_items
             WHERE category = :category
               AND deleted_at IS NULL
               AND status = 1
             ORDER BY sort_order ASC, id ASC'
        );
        $statement->execute([
            ':category' => $category,
        ]);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $items = array_map(
            $forAdminResponse ? [$this, 'mapAdminRow'] : [$this, 'mapPublicRow'],
            $rows
        );

        return [
            'key' => $category,
            'label' => $this->resolveCategoryLabel($category),
            'behavior' => $this->resolveCategoryBehavior($category),
            'items' => $items,
            'primaryItem' => $items[0] ?? null,
            'count' => count($items),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapAdminRow(array $row): array
    {
        $title = $this->decodeJsonColumnOrNull($row['title'] ?? null);
        $ctaLabel = $this->decodeJsonColumnOrNull($row['cta_label'] ?? null);
        $category = (string) ($row['category'] ?? '');

        return [
            'id' => (int) $row['id'],
            'userId' => (int) ($row['user_id'] ?? 0),
            'category' => $category,
            'categoryLabel' => $this->resolveCategoryLabel($category),
            'behavior' => $this->resolveCategoryBehavior($category),
            'title' => $title,
            'titleText' => $title !== null ? $this->resolveDisplayText($title) : null,
            'desktopImage' => (string) ($row['desktop_image'] ?? ''),
            'mobileImage' => (string) ($row['mobile_image'] ?? ''),
            'ctaLabel' => $ctaLabel,
            'ctaLabelText' => $ctaLabel !== null ? $this->resolveDisplayText($ctaLabel) : null,
            'ctaUrl' => $row['cta_url'] !== null ? (string) $row['cta_url'] : null,
            'sortOrder' => (int) ($row['sort_order'] ?? 0),
            'status' => (int) ($row['status'] ?? 0) === 1,
            'createdAt' => (string) ($row['created_at'] ?? ''),
            'updatedAt' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapPublicRow(array $row): array
    {
        $mapped = $this->mapAdminRow($row);
        unset($mapped['userId'], $mapped['status']);

        return $mapped;
    }

    private function placeItemInCategoryOrder(PDO $pdo, string $category, int $itemId, ?int $requestedPosition): void
    {
        $ids = $this->getOrderedCategoryIds($pdo, $category, $itemId);
        $targetIndex = $requestedPosition === null
            ? count($ids)
            : max(0, min(count($ids), $requestedPosition - 1));

        array_splice($ids, $targetIndex, 0, [$itemId]);

        $this->applyCategoryOrder($pdo, $ids);
    }

    private function compactCategoryOrder(PDO $pdo, string $category): void
    {
        $ids = $this->getOrderedCategoryIds($pdo, $category);
        $this->applyCategoryOrder($pdo, $ids);
    }

    /**
     * @return array<int, int>
     */
    private function getOrderedCategoryIds(PDO $pdo, string $category, ?int $excludeItemId = null): array
    {
        $sql = 'SELECT id
                FROM media_items
                WHERE category = :category
                  AND deleted_at IS NULL';

        if ($excludeItemId !== null) {
            $sql .= ' AND id <> :exclude_item_id';
        }

        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $statement = $pdo->prepare($sql);
        $statement->bindValue(':category', $category);

        if ($excludeItemId !== null) {
            $statement->bindValue(':exclude_item_id', $excludeItemId, PDO::PARAM_INT);
        }

        $statement->execute();

        return array_map(
            static fn (mixed $value): int => (int) $value,
            $statement->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    /**
     * @param array<int, int> $itemIds
     */
    private function applyCategoryOrder(PDO $pdo, array $itemIds): void
    {
        $statement = $pdo->prepare(
            'UPDATE media_items
             SET sort_order = :sort_order,
                 updated_at = NOW()
             WHERE id = :item_id'
        );

        foreach ($itemIds as $index => $itemId) {
            $statement->execute([
                ':sort_order' => $index + 1,
                ':item_id' => $itemId,
            ]);
        }
    }

    private function resolveCategoryLabel(string $category): string
    {
        $definition = self::CATEGORY_DEFINITIONS[$category] ?? null;
        if (is_array($definition) && isset($definition['label'])) {
            return $definition['label'];
        }

        $label = str_replace(['_', '-'], ' ', $category);

        return ucwords(trim($label));
    }

    private function resolveCategoryBehavior(string $category): string
    {
        $definition = self::CATEGORY_DEFINITIONS[$category] ?? null;

        return is_array($definition) ? (string) ($definition['behavior'] ?? 'collection') : 'collection';
    }

    private function normalizeCategory(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));
        $normalized = preg_replace('/[^a-z0-9_-]+/', '_', $normalized) ?? '';
        $normalized = trim($normalized, '_-');

        if ($normalized === '') {
            throw new RuntimeException('category is required.');
        }

        return $normalized;
    }

    private function normalizeNullableCategory(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->normalizeCategory($value);
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

    private function normalizeNullableTranslations(mixed $value, string $field): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        if (is_array($value) && $value === []) {
            return null;
        }

        return $this->normalizeTranslations($value, $field);
    }

    private function normalizeRequiredString(mixed $value, string $field): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            throw new RuntimeException(sprintf('%s is required.', $field));
        }

        return $normalized;
    }

    private function normalizeNullableUrl(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeFlag(mixed $value, string $field): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_numeric($value)) {
            $normalized = (int) $value;
            if (in_array($normalized, [0, 1], true)) {
                return $normalized;
            }
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return 1;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return 0;
        }

        throw new RuntimeException(sprintf('%s is invalid.', $field));
    }

    private function nullableFlag(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->normalizeFlag($value, 'flag');
    }

    private function normalizeNullablePositiveInt(mixed $value, string $field): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (!is_numeric($value)) {
            throw new RuntimeException(sprintf('%s is invalid.', $field));
        }

        $normalized = (int) $value;
        if ($normalized <= 0) {
            throw new RuntimeException(sprintf('%s must be greater than zero.', $field));
        }

        return $normalized;
    }

    /**
     * @param array<int, mixed>|null $value
     * @return array<int, int>
     */
    private function normalizeIdList(?array $value): array
    {
        if (!is_array($value) || $value === []) {
            throw new RuntimeException('item_ids is required.');
        }

        $ids = [];

        foreach ($value as $item) {
            if (!is_numeric($item)) {
                throw new RuntimeException('item_ids is invalid.');
            }

            $normalized = (int) $item;
            if ($normalized <= 0 || in_array($normalized, $ids, true)) {
                throw new RuntimeException('item_ids is invalid.');
            }

            $ids[] = $normalized;
        }

        return $ids;
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
