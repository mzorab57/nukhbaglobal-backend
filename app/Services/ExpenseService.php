<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class ExpenseService
{
    /**
     * @var array<int, string>
     */
    private const DEFAULT_CATEGORY_OPTIONS = [
        'venue',
        'marketing',
        'transport',
        'staff',
        'equipment',
        'operations',
        'catering',
        'other',
    ];

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getExpenses(PDO $pdo, array $filters): array
    {
        $whereClauses = ['e.deleted_at IS NULL'];
        $parameters = [];

        $eventId = $this->normalizeNullableId($filters['event_id'] ?? null);
        if ($eventId !== null) {
            $whereClauses[] = 'e.event_id = :event_id';
            $parameters[':event_id'] = $eventId;
        }

        $category = $this->normalizeNullableString($filters['category'] ?? null);
        if ($category !== null) {
            $whereClauses[] = 'e.category = :category';
            $parameters[':category'] = $category;
        }

        $query = trim((string) ($filters['query'] ?? ''));
        if ($query !== '') {
            $queryValue = '%' . strtolower($query) . '%';
            $whereClauses[] = '(
                LOWER(e.title) LIKE :query_title
                OR LOWER(e.category) LIKE :query_category
                OR LOWER(COALESCE(e.notes, "")) LIKE :query_notes
                OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(ev.title, "$.en"))) LIKE :query_event_en
                OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(ev.title, "$.ar"))) LIKE :query_event_ar
            )';
            $parameters[':query_title'] = $queryValue;
            $parameters[':query_category'] = $queryValue;
            $parameters[':query_notes'] = $queryValue;
            $parameters[':query_event_en'] = $queryValue;
            $parameters[':query_event_ar'] = $queryValue;
        }

        $dateFrom = $this->normalizeNullableDate($filters['date_from'] ?? null);
        if ($dateFrom !== null) {
            $whereClauses[] = 'e.expense_date >= :date_from';
            $parameters[':date_from'] = $dateFrom;
        }

        $dateTo = $this->normalizeNullableDate($filters['date_to'] ?? null);
        if ($dateTo !== null) {
            $whereClauses[] = 'e.expense_date <= :date_to';
            $parameters[':date_to'] = $dateTo;
        }

        $whereSql = implode(' AND ', $whereClauses);

        $statement = $pdo->prepare(
            'SELECT
                e.id,
                e.event_id,
                e.user_id,
                e.title,
                e.category,
                e.amount,
                e.receipt_file,
                e.notes,
                e.expense_date,
                e.created_at,
                e.updated_at,
                u.name AS created_by_name,
                ev.title AS event_title
             FROM expenses e
             INNER JOIN events ev ON ev.id = e.event_id
             INNER JOIN users u ON u.id = e.user_id
             WHERE ' . $whereSql . '
             ORDER BY e.expense_date DESC, e.id DESC'
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
        $items = array_map([$this, 'mapRow'], $rows);

        return [
            'items' => $items,
            'filters' => [
                'eventId' => $eventId,
                'category' => $category,
                'query' => $query !== '' ? $query : null,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
            ],
            'summary' => $this->buildSummary($items),
            'categoryOptions' => $this->buildCategoryOptions($pdo, $items),
            'eventOptions' => $this->buildEventOptions($pdo),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getExpenseDetails(PDO $pdo, int $expenseId): ?array
    {
        $record = $this->getExpenseRecord($pdo, $expenseId);
        if ($record === null) {
            return null;
        }

        return [
            'expense' => $this->mapRow($record),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createExpense(PDO $pdo, array $payload, int $userId): array
    {
        $normalized = $this->validatePayload($pdo, $payload, true);

        $statement = $pdo->prepare(
            'INSERT INTO expenses (
                event_id,
                user_id,
                title,
                category,
                amount,
                receipt_file,
                notes,
                expense_date
             ) VALUES (
                :event_id,
                :user_id,
                :title,
                :category,
                :amount,
                :receipt_file,
                :notes,
                :expense_date
             )'
        );
        $statement->execute([
            ':event_id' => $normalized['event_id'],
            ':user_id' => $userId,
            ':title' => $normalized['title'],
            ':category' => $normalized['category'],
            ':amount' => $normalized['amount'],
            ':receipt_file' => $normalized['receipt_file'],
            ':notes' => $normalized['notes'],
            ':expense_date' => $normalized['expense_date'],
        ]);

        $expense = $this->getExpenseDetails($pdo, (int) $pdo->lastInsertId());
        if ($expense === null) {
            throw new RuntimeException('Created expense could not be loaded.');
        }

        return $expense;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function updateExpense(PDO $pdo, int $expenseId, array $payload): ?array
    {
        $existing = $this->getExpenseRecord($pdo, $expenseId);
        if ($existing === null) {
            return null;
        }

        $normalized = $this->validatePayload($pdo, $payload, false, $existing);

        $statement = $pdo->prepare(
            'UPDATE expenses
             SET event_id = :event_id,
                 title = :title,
                 category = :category,
                 amount = :amount,
                 receipt_file = :receipt_file,
                 notes = :notes,
                 expense_date = :expense_date,
                 updated_at = NOW()
             WHERE id = :expense_id'
        );
        $statement->execute([
            ':event_id' => $normalized['event_id'],
            ':title' => $normalized['title'],
            ':category' => $normalized['category'],
            ':amount' => $normalized['amount'],
            ':receipt_file' => $normalized['receipt_file'],
            ':notes' => $normalized['notes'],
            ':expense_date' => $normalized['expense_date'],
            ':expense_id' => $expenseId,
        ]);

        return $this->getExpenseDetails($pdo, $expenseId);
    }

    public function deleteExpense(PDO $pdo, int $expenseId): bool
    {
        $existing = $this->getExpenseRecord($pdo, $expenseId, true);
        if ($existing === null) {
            return false;
        }

        $statement = $pdo->prepare(
            'UPDATE expenses
             SET deleted_at = NOW(),
                 updated_at = NOW()
             WHERE id = :expense_id'
        );
        $statement->execute([
            ':expense_id' => $expenseId,
        ]);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getExpenseRecord(PDO $pdo, int $expenseId, bool $includeDeleted = false): ?array
    {
        $statement = $pdo->prepare(
            'SELECT
                e.id,
                e.event_id,
                e.user_id,
                e.title,
                e.category,
                e.amount,
                e.receipt_file,
                e.notes,
                e.expense_date,
                e.deleted_at,
                e.created_at,
                e.updated_at,
                u.name AS created_by_name,
                ev.title AS event_title
             FROM expenses e
             INNER JOIN users u ON u.id = e.user_id
             INNER JOIN events ev ON ev.id = e.event_id
             WHERE e.id = :expense_id
             ' . ($includeDeleted ? '' : 'AND e.deleted_at IS NULL') . '
             LIMIT 1'
        );
        $statement->execute([
            ':expense_id' => $expenseId,
        ]);

        $record = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($record)) {
            return null;
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function validatePayload(PDO $pdo, array $payload, bool $isCreate, ?array $existing = null): array
    {
        $eventId = array_key_exists('event_id', $payload)
            ? $this->normalizePositiveId($payload['event_id'], 'event_id')
            : (int) ($existing['event_id'] ?? 0);
        $title = array_key_exists('title', $payload)
            ? trim((string) $payload['title'])
            : trim((string) ($existing['title'] ?? ''));
        $category = array_key_exists('category', $payload)
            ? trim((string) $payload['category'])
            : trim((string) ($existing['category'] ?? ''));
        $amount = array_key_exists('amount', $payload)
            ? (float) $payload['amount']
            : (float) ($existing['amount'] ?? 0);
        $receiptFile = array_key_exists('receipt_file', $payload)
            ? $this->normalizeNullableString($payload['receipt_file'])
            : $this->normalizeNullableString($existing['receipt_file'] ?? null);
        $notes = array_key_exists('notes', $payload)
            ? $this->normalizeNullableString($payload['notes'])
            : $this->normalizeNullableString($existing['notes'] ?? null);
        $expenseDate = array_key_exists('expense_date', $payload)
            ? $this->normalizeDate((string) $payload['expense_date'], 'expense_date')
            : (string) ($existing['expense_date'] ?? '');

        if ($eventId <= 0) {
            throw new RuntimeException('The event_id field is required.');
        }

        if ($title === '') {
            throw new RuntimeException('The title field is required.');
        }

        if ($category === '') {
            throw new RuntimeException('The category field is required.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('The amount field is invalid.');
        }

        if ($expenseDate === '') {
            throw new RuntimeException('The expense_date field is required.');
        }

        $this->assertEventExists($pdo, $eventId);
        $this->assertMaxLength($title, 255, 'title');
        $this->assertMaxLength($category, 100, 'category');
        $this->assertMaxLength($receiptFile, 255, 'receipt_file');
        $this->assertMaxLength($notes, 5000, 'notes');

        return [
            'event_id' => $eventId,
            'title' => $title,
            'category' => $category,
            'amount' => round($amount, 2),
            'receipt_file' => $receiptFile,
            'notes' => $notes,
            'expense_date' => $expenseDate,
        ];
    }

    private function assertEventExists(PDO $pdo, int $eventId): void
    {
        $statement = $pdo->prepare(
            'SELECT id
             FROM events
             WHERE id = :event_id
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            ':event_id' => $eventId,
        ]);

        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('The selected event was not found.');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'eventId' => (int) $row['event_id'],
            'userId' => (int) $row['user_id'],
            'title' => (string) $row['title'],
            'category' => (string) $row['category'],
            'amount' => round((float) $row['amount'], 2),
            'receiptFile' => $row['receipt_file'] !== null ? (string) $row['receipt_file'] : null,
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
            'expenseDate' => (string) $row['expense_date'],
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
            'createdByName' => (string) $row['created_by_name'],
            'event' => [
                'id' => (int) $row['event_id'],
                'title' => $this->decodeJson($row['event_title']),
                'titleText' => $this->resolveTitleText($row['event_title']),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function buildSummary(array $items): array
    {
        $totalAmount = 0.0;
        $byCategory = [];

        foreach ($items as $item) {
            $amount = (float) ($item['amount'] ?? 0);
            $category = (string) ($item['category'] ?? 'other');
            $totalAmount += $amount;
            $byCategory[$category] = round(($byCategory[$category] ?? 0) + $amount, 2);
        }

        arsort($byCategory);

        return [
            'count' => count($items),
            'totalAmount' => round($totalAmount, 2),
            'byCategory' => array_map(
                static fn(string $key, float $value): array => [
                    'key' => $key,
                    'label' => ucwords(str_replace(['_', '-'], ' ', $key)),
                    'amount' => round($value, 2),
                ],
                array_keys($byCategory),
                array_values($byCategory)
            ),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, string>>
     */
    private function buildCategoryOptions(PDO $pdo, array $items): array
    {
        $categories = self::DEFAULT_CATEGORY_OPTIONS;

        foreach ($items as $item) {
            $key = trim((string) ($item['category'] ?? ''));
            if ($key !== '' && !in_array($key, $categories, true)) {
                $categories[] = $key;
            }
        }

        $statement = $pdo->query(
            'SELECT DISTINCT category
             FROM expenses
             WHERE deleted_at IS NULL
             ORDER BY category ASC'
        );

        /** @var array<int, string> $dbCategories */
        $dbCategories = $statement->fetchAll(PDO::FETCH_COLUMN) ?: [];

        foreach ($dbCategories as $category) {
            if ($category !== '' && !in_array($category, $categories, true)) {
                $categories[] = $category;
            }
        }

        sort($categories);

        return array_map(
            static fn(string $category): array => [
                'key' => $category,
                'label' => ucwords(str_replace(['_', '-'], ' ', $category)),
            ],
            $categories
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildEventOptions(PDO $pdo): array
    {
        $statement = $pdo->query(
            'SELECT id, title, date
             FROM events
             WHERE deleted_at IS NULL
             ORDER BY date DESC, id DESC'
        );

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            fn(array $row): array => [
                'id' => (int) $row['id'],
                'title' => $this->decodeJson($row['title']),
                'titleText' => $this->resolveTitleText($row['title']),
                'date' => (string) $row['date'],
            ],
            $rows
        );
    }

    /**
     * @return array<string, string>|null
     */
    private function decodeJson(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return [
                'en' => isset($value['en']) ? (string) $value['en'] : '',
                'ar' => isset($value['ar']) ? (string) $value['ar'] : '',
            ];
        }

        $decoded = json_decode((string) $value, true);
        if (!is_array($decoded)) {
            return null;
        }

        return [
            'en' => isset($decoded['en']) ? (string) $decoded['en'] : '',
            'ar' => isset($decoded['ar']) ? (string) $decoded['ar'] : '',
        ];
    }

    private function resolveTitleText(mixed $value): ?string
    {
        $decoded = $this->decodeJson($value);
        if ($decoded === null) {
            return null;
        }

        foreach (['en', 'ar'] as $key) {
            $text = trim((string) ($decoded[$key] ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    private function normalizePositiveId(mixed $value, string $field): int
    {
        $id = (int) $value;
        if ($id <= 0) {
            throw new RuntimeException(sprintf('The %s field is invalid.', $field));
        }

        return $id;
    }

    private function normalizeNullableId(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeDate(string $value, string $field): string
    {
        $normalized = trim($value);
        if ($normalized === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized) !== 1) {
            throw new RuntimeException(sprintf('The %s field is invalid.', $field));
        }

        return $normalized;
    }

    private function normalizeNullableDate(mixed $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        return $this->normalizeDate($normalized, 'date');
    }

    private function assertMaxLength(?string $value, int $maxLength, string $field): void
    {
        if ($value !== null && mb_strlen($value) > $maxLength) {
            throw new RuntimeException(sprintf('The %s field is too long.', $field));
        }
    }
}
