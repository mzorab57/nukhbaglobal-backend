<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class StallApplicationService
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_STATUSES = ['new', 'contacted', 'approved', 'rejected'];

    /**
     * @var array<int, string>
     */
    private const ALLOWED_SOURCES = ['website', 'admin'];

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getApplications(PDO $pdo, array $filters): array
    {
        $whereClauses = ['sa.deleted_at IS NULL'];
        $parameters = [];

        $status = $this->normalizeNullableStatus($filters['status'] ?? null);
        if ($status !== null) {
            $whereClauses[] = 'sa.status = :status';
            $parameters[':status'] = $status;
        }

        $source = $this->normalizeNullableSource($filters['source'] ?? null);
        if ($source !== null) {
            $whereClauses[] = 'sa.source = :source';
            $parameters[':source'] = $source;
        }

        $query = trim((string) ($filters['query'] ?? ''));
        if ($query !== '') {
            $queryValue = '%' . strtolower($query) . '%';
            $whereClauses[] = '(
                LOWER(sa.full_name) LIKE :query_full_name
                OR LOWER(COALESCE(sa.business_name, "")) LIKE :query_business_name
                OR LOWER(COALESCE(sa.email, "")) LIKE :query_email
                OR LOWER(sa.phone) LIKE :query_phone
                OR LOWER(COALESCE(sa.whatsapp, "")) LIKE :query_whatsapp
                OR LOWER(COALESCE(sa.city, "")) LIKE :query_city
                OR LOWER(COALESCE(sa.booth_type, "")) LIKE :query_booth_type
            )';
            $parameters[':query_full_name'] = $queryValue;
            $parameters[':query_business_name'] = $queryValue;
            $parameters[':query_email'] = $queryValue;
            $parameters[':query_phone'] = $queryValue;
            $parameters[':query_whatsapp'] = $queryValue;
            $parameters[':query_city'] = $queryValue;
            $parameters[':query_booth_type'] = $queryValue;
        }

        $statement = $pdo->prepare(
            'SELECT
                sa.id,
                sa.full_name,
                sa.business_name,
                sa.email,
                sa.phone,
                sa.whatsapp,
                sa.city,
                sa.booth_type,
                sa.message,
                sa.status,
                sa.source,
                sa.admin_notes,
                sa.submitted_by_user_id,
                sa.created_at,
                sa.updated_at,
                u.name AS submitted_by_name
             FROM stall_applications sa
             LEFT JOIN users u ON u.id = sa.submitted_by_user_id
             WHERE ' . implode(' AND ', $whereClauses) . '
             ORDER BY sa.created_at DESC, sa.id DESC'
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
                'status' => $status,
                'source' => $source,
                'query' => $query !== '' ? $query : null,
            ],
            'statusOptions' => self::ALLOWED_STATUSES,
            'sourceOptions' => self::ALLOWED_SOURCES,
            'stats' => $this->buildStats($items),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getApplicationDetails(PDO $pdo, int $applicationId): ?array
    {
        $record = $this->getApplicationRecord($pdo, $applicationId);
        if ($record === null) {
            return null;
        }

        return [
            'application' => $this->mapRow($record),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createApplication(PDO $pdo, array $payload, string $source = 'website', ?int $submittedByUserId = null): array
    {
        $normalized = $this->validatePayload($payload, true, null, $source);

        $statement = $pdo->prepare(
            'INSERT INTO stall_applications (
                full_name,
                business_name,
                email,
                phone,
                whatsapp,
                city,
                booth_type,
                message,
                status,
                source,
                admin_notes,
                submitted_by_user_id
             ) VALUES (
                :full_name,
                :business_name,
                :email,
                :phone,
                :whatsapp,
                :city,
                :booth_type,
                :message,
                :status,
                :source,
                :admin_notes,
                :submitted_by_user_id
             )'
        );
        $statement->execute([
            ':full_name' => $normalized['full_name'],
            ':business_name' => $normalized['business_name'],
            ':email' => $normalized['email'],
            ':phone' => $normalized['phone'],
            ':whatsapp' => $normalized['whatsapp'],
            ':city' => $normalized['city'],
            ':booth_type' => $normalized['booth_type'],
            ':message' => $normalized['message'],
            ':status' => $normalized['status'],
            ':source' => $normalized['source'],
            ':admin_notes' => $normalized['admin_notes'],
            ':submitted_by_user_id' => $submittedByUserId,
        ]);

        $application = $this->getApplicationDetails($pdo, (int) $pdo->lastInsertId());
        if ($application === null) {
            throw new RuntimeException('Created application could not be loaded.');
        }

        return $application;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function updateApplication(PDO $pdo, int $applicationId, array $payload): ?array
    {
        $existing = $this->getApplicationRecord($pdo, $applicationId);
        if ($existing === null) {
            return null;
        }

        $normalized = $this->validatePayload($payload, false, $existing);

        $statement = $pdo->prepare(
            'UPDATE stall_applications
             SET full_name = :full_name,
                 business_name = :business_name,
                 email = :email,
                 phone = :phone,
                 whatsapp = :whatsapp,
                 city = :city,
                 booth_type = :booth_type,
                 message = :message,
                 status = :status,
                 source = :source,
                 admin_notes = :admin_notes,
                 updated_at = NOW()
             WHERE id = :application_id'
        );
        $statement->execute([
            ':full_name' => $normalized['full_name'],
            ':business_name' => $normalized['business_name'],
            ':email' => $normalized['email'],
            ':phone' => $normalized['phone'],
            ':whatsapp' => $normalized['whatsapp'],
            ':city' => $normalized['city'],
            ':booth_type' => $normalized['booth_type'],
            ':message' => $normalized['message'],
            ':status' => $normalized['status'],
            ':source' => $normalized['source'],
            ':admin_notes' => $normalized['admin_notes'],
            ':application_id' => $applicationId,
        ]);

        return $this->getApplicationDetails($pdo, $applicationId);
    }

    public function deleteApplication(PDO $pdo, int $applicationId): bool
    {
        $existing = $this->getApplicationRecord($pdo, $applicationId, true);
        if ($existing === null) {
            return false;
        }

        $statement = $pdo->prepare(
            'UPDATE stall_applications
             SET deleted_at = NOW(),
                 updated_at = NOW()
             WHERE id = :application_id'
        );
        $statement->execute([
            ':application_id' => $applicationId,
        ]);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getApplicationRecord(PDO $pdo, int $applicationId, bool $includeDeleted = false): ?array
    {
        $statement = $pdo->prepare(
            'SELECT
                sa.id,
                sa.full_name,
                sa.business_name,
                sa.email,
                sa.phone,
                sa.whatsapp,
                sa.city,
                sa.booth_type,
                sa.message,
                sa.status,
                sa.source,
                sa.admin_notes,
                sa.submitted_by_user_id,
                sa.deleted_at,
                sa.created_at,
                sa.updated_at,
                u.name AS submitted_by_name
             FROM stall_applications sa
             LEFT JOIN users u ON u.id = sa.submitted_by_user_id
             WHERE sa.id = :application_id
             ' . ($includeDeleted ? '' : 'AND sa.deleted_at IS NULL') . '
             LIMIT 1'
        );
        $statement->execute([
            ':application_id' => $applicationId,
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
    private function validatePayload(array $payload, bool $isCreate, ?array $existing = null, string $defaultSource = 'admin'): array
    {
        $fullName = array_key_exists('full_name', $payload)
            ? trim((string) $payload['full_name'])
            : trim((string) ($existing['full_name'] ?? ''));
        $businessName = $this->nullableString($payload['business_name'] ?? ($existing['business_name'] ?? null));
        $email = $this->nullableString($payload['email'] ?? ($existing['email'] ?? null));
        $phone = array_key_exists('phone', $payload)
            ? trim((string) $payload['phone'])
            : trim((string) ($existing['phone'] ?? ''));
        $whatsapp = $this->nullableString($payload['whatsapp'] ?? ($existing['whatsapp'] ?? null));
        $city = $this->nullableString($payload['city'] ?? ($existing['city'] ?? null));
        $boothType = $this->nullableString($payload['booth_type'] ?? ($existing['booth_type'] ?? null));
        $message = $this->nullableString($payload['message'] ?? ($existing['message'] ?? null));
        $adminNotes = $this->nullableString($payload['admin_notes'] ?? ($existing['admin_notes'] ?? null));
        $source = array_key_exists('source', $payload)
            ? $this->normalizeSource((string) $payload['source'])
            : (string) ($existing['source'] ?? $defaultSource);
        $status = array_key_exists('status', $payload)
            ? $this->normalizeStatus((string) $payload['status'])
            : ($isCreate ? 'new' : (string) ($existing['status'] ?? 'new'));

        if ($fullName === '') {
            throw new RuntimeException('The full_name field is required.');
        }

        if ($phone === '') {
            throw new RuntimeException('The phone field is required.');
        }

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('The email field must contain a valid email address.');
        }

        $this->assertMaxLength($fullName, 255, 'full_name');
        $this->assertMaxLength($businessName, 255, 'business_name');
        $this->assertMaxLength($email, 255, 'email');
        $this->assertMaxLength($phone, 50, 'phone');
        $this->assertMaxLength($whatsapp, 50, 'whatsapp');
        $this->assertMaxLength($city, 150, 'city');
        $this->assertMaxLength($boothType, 150, 'booth_type');
        $this->assertMaxLength($message, 5000, 'message');
        $this->assertMaxLength($adminNotes, 5000, 'admin_notes');

        return [
            'full_name' => $fullName,
            'business_name' => $businessName,
            'email' => $email,
            'phone' => $phone,
            'whatsapp' => $whatsapp,
            'city' => $city,
            'booth_type' => $boothType,
            'message' => $message,
            'admin_notes' => $adminNotes,
            'status' => $status,
            'source' => $source,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'fullName' => (string) $row['full_name'],
            'businessName' => $row['business_name'] !== null ? (string) $row['business_name'] : null,
            'email' => $row['email'] !== null ? (string) $row['email'] : null,
            'phone' => (string) $row['phone'],
            'whatsapp' => $row['whatsapp'] !== null ? (string) $row['whatsapp'] : null,
            'city' => $row['city'] !== null ? (string) $row['city'] : null,
            'boothType' => $row['booth_type'] !== null ? (string) $row['booth_type'] : null,
            'message' => $row['message'] !== null ? (string) $row['message'] : null,
            'status' => (string) $row['status'],
            'source' => (string) $row['source'],
            'adminNotes' => $row['admin_notes'] !== null ? (string) $row['admin_notes'] : null,
            'submittedByUserId' => $row['submitted_by_user_id'] !== null ? (int) $row['submitted_by_user_id'] : null,
            'submittedByName' => $row['submitted_by_name'] !== null ? (string) $row['submitted_by_name'] : null,
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, int>
     */
    private function buildStats(array $items): array
    {
        $stats = [
            'total' => count($items),
            'new' => 0,
            'contacted' => 0,
            'approved' => 0,
            'rejected' => 0,
            'website' => 0,
            'admin' => 0,
        ];

        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? '');
            $source = (string) ($item['source'] ?? '');

            if (array_key_exists($status, $stats)) {
                $stats[$status]++;
            }

            if (array_key_exists($source, $stats)) {
                $stats[$source]++;
            }
        }

        return $stats;
    }

    private function normalizeStatus(string $value): string
    {
        $normalized = strtolower(trim($value));
        if (!in_array($normalized, self::ALLOWED_STATUSES, true)) {
            throw new RuntimeException('The status field is invalid.');
        }

        return $normalized;
    }

    private function normalizeNullableStatus(mixed $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        return $this->normalizeStatus($normalized);
    }

    private function normalizeSource(string $value): string
    {
        $normalized = strtolower(trim($value));
        if (!in_array($normalized, self::ALLOWED_SOURCES, true)) {
            throw new RuntimeException('The source field is invalid.');
        }

        return $normalized;
    }

    private function normalizeNullableSource(mixed $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        return $this->normalizeSource($normalized);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        return $normalized !== '' ? $normalized : null;
    }

    private function assertMaxLength(?string $value, int $maxLength, string $field): void
    {
        if ($value !== null && mb_strlen($value) > $maxLength) {
            throw new RuntimeException(sprintf('The %s field is too long.', $field));
        }
    }
}
