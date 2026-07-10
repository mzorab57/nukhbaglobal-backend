<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class VolunteerApplicationService
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
        $whereClauses = ['va.deleted_at IS NULL'];
        $parameters = [];

        $status = $this->normalizeNullableStatus($filters['status'] ?? null);
        if ($status !== null) {
            $whereClauses[] = 'va.status = :status';
            $parameters[':status'] = $status;
        }

        $source = $this->normalizeNullableSource($filters['source'] ?? null);
        if ($source !== null) {
            $whereClauses[] = 'va.source = :source';
            $parameters[':source'] = $source;
        }

        $query = trim((string) ($filters['query'] ?? ''));
        if ($query !== '') {
            $queryValue = '%' . strtolower($query) . '%';
            $whereClauses[] = '(
                LOWER(va.first_name) LIKE :query_first_name
                OR LOWER(va.last_name) LIKE :query_last_name
                OR LOWER(va.phone_number) LIKE :query_phone
                OR LOWER(va.whatsapp_number) LIKE :query_whatsapp
                OR LOWER(COALESCE(va.address, "")) LIKE :query_address
                OR LOWER(COALESCE(va.reason, "")) LIKE :query_reason
            )';
            $parameters[':query_first_name'] = $queryValue;
            $parameters[':query_last_name'] = $queryValue;
            $parameters[':query_phone'] = $queryValue;
            $parameters[':query_whatsapp'] = $queryValue;
            $parameters[':query_address'] = $queryValue;
            $parameters[':query_reason'] = $queryValue;
        }

        $statement = $pdo->prepare(
            'SELECT
                va.id,
                va.first_name,
                va.last_name,
                va.whatsapp_number,
                va.phone_number,
                va.age,
                va.address,
                va.reason,
                va.has_volunteered_before,
                va.experience,
                va.confirm_correct,
                va.agree_rules,
                va.status,
                va.source,
                va.admin_notes,
                va.submitted_by_user_id,
                va.created_at,
                va.updated_at,
                u.name AS submitted_by_name
             FROM volunteer_applications va
             LEFT JOIN users u ON u.id = va.submitted_by_user_id
             WHERE ' . implode(' AND ', $whereClauses) . '
             ORDER BY va.created_at DESC, va.id DESC'
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
            'INSERT INTO volunteer_applications (
                first_name,
                last_name,
                whatsapp_number,
                phone_number,
                age,
                address,
                reason,
                has_volunteered_before,
                experience,
                confirm_correct,
                agree_rules,
                status,
                source,
                admin_notes,
                submitted_by_user_id
             ) VALUES (
                :first_name,
                :last_name,
                :whatsapp_number,
                :phone_number,
                :age,
                :address,
                :reason,
                :has_volunteered_before,
                :experience,
                :confirm_correct,
                :agree_rules,
                :status,
                :source,
                :admin_notes,
                :submitted_by_user_id
             )'
        );
        $statement->execute([
            ':first_name' => $normalized['first_name'],
            ':last_name' => $normalized['last_name'],
            ':whatsapp_number' => $normalized['whatsapp_number'],
            ':phone_number' => $normalized['phone_number'],
            ':age' => $normalized['age'],
            ':address' => $normalized['address'],
            ':reason' => $normalized['reason'],
            ':has_volunteered_before' => $normalized['has_volunteered_before'],
            ':experience' => $normalized['experience'],
            ':confirm_correct' => $normalized['confirm_correct'],
            ':agree_rules' => $normalized['agree_rules'],
            ':status' => $normalized['status'],
            ':source' => $normalized['source'],
            ':admin_notes' => $normalized['admin_notes'],
            ':submitted_by_user_id' => $submittedByUserId,
        ]);

        $application = $this->getApplicationDetails($pdo, (int) $pdo->lastInsertId());
        if ($application === null) {
            throw new RuntimeException('Created volunteer application could not be loaded.');
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
            'UPDATE volunteer_applications
             SET first_name = :first_name,
                 last_name = :last_name,
                 whatsapp_number = :whatsapp_number,
                 phone_number = :phone_number,
                 age = :age,
                 address = :address,
                 reason = :reason,
                 has_volunteered_before = :has_volunteered_before,
                 experience = :experience,
                 confirm_correct = :confirm_correct,
                 agree_rules = :agree_rules,
                 status = :status,
                 source = :source,
                 admin_notes = :admin_notes,
                 updated_at = NOW()
             WHERE id = :application_id'
        );
        $statement->execute([
            ':first_name' => $normalized['first_name'],
            ':last_name' => $normalized['last_name'],
            ':whatsapp_number' => $normalized['whatsapp_number'],
            ':phone_number' => $normalized['phone_number'],
            ':age' => $normalized['age'],
            ':address' => $normalized['address'],
            ':reason' => $normalized['reason'],
            ':has_volunteered_before' => $normalized['has_volunteered_before'],
            ':experience' => $normalized['experience'],
            ':confirm_correct' => $normalized['confirm_correct'],
            ':agree_rules' => $normalized['agree_rules'],
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
            'UPDATE volunteer_applications
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
                va.id,
                va.first_name,
                va.last_name,
                va.whatsapp_number,
                va.phone_number,
                va.age,
                va.address,
                va.reason,
                va.has_volunteered_before,
                va.experience,
                va.confirm_correct,
                va.agree_rules,
                va.status,
                va.source,
                va.admin_notes,
                va.submitted_by_user_id,
                va.deleted_at,
                va.created_at,
                va.updated_at,
                u.name AS submitted_by_name
             FROM volunteer_applications va
             LEFT JOIN users u ON u.id = va.submitted_by_user_id
             WHERE va.id = :application_id
             ' . ($includeDeleted ? '' : 'AND va.deleted_at IS NULL') . '
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
        $firstName = array_key_exists('first_name', $payload)
            ? trim((string) $payload['first_name'])
            : trim((string) ($existing['first_name'] ?? ''));
        $lastName = array_key_exists('last_name', $payload)
            ? trim((string) $payload['last_name'])
            : trim((string) ($existing['last_name'] ?? ''));
        $whatsappNumber = array_key_exists('whatsapp_number', $payload)
            ? trim((string) $payload['whatsapp_number'])
            : trim((string) ($existing['whatsapp_number'] ?? ''));
        $phoneNumber = array_key_exists('phone_number', $payload)
            ? trim((string) $payload['phone_number'])
            : trim((string) ($existing['phone_number'] ?? ''));
        $age = array_key_exists('age', $payload)
            ? (int) $payload['age']
            : (int) ($existing['age'] ?? 0);
        $address = array_key_exists('address', $payload)
            ? trim((string) $payload['address'])
            : trim((string) ($existing['address'] ?? ''));
        $reason = array_key_exists('reason', $payload)
            ? trim((string) $payload['reason'])
            : trim((string) ($existing['reason'] ?? ''));
        $hasVolunteeredBefore = array_key_exists('has_volunteered_before', $payload)
            ? $this->normalizeYesNo((string) $payload['has_volunteered_before'], 'has_volunteered_before')
            : (string) ($existing['has_volunteered_before'] ?? 'no');
        $experience = $this->nullableString($payload['experience'] ?? ($existing['experience'] ?? null));
        $confirmCorrect = array_key_exists('confirm_correct', $payload)
            ? $this->normalizeBoolean($payload['confirm_correct'])
            : (int) ($existing['confirm_correct'] ?? 0);
        $agreeRules = array_key_exists('agree_rules', $payload)
            ? $this->normalizeBoolean($payload['agree_rules'])
            : (int) ($existing['agree_rules'] ?? 0);
        $adminNotes = $this->nullableString($payload['admin_notes'] ?? ($existing['admin_notes'] ?? null));
        $source = array_key_exists('source', $payload)
            ? $this->normalizeSource((string) $payload['source'])
            : (string) ($existing['source'] ?? $defaultSource);
        $status = array_key_exists('status', $payload)
            ? $this->normalizeStatus((string) $payload['status'])
            : ($isCreate ? 'new' : (string) ($existing['status'] ?? 'new'));

        if ($firstName === '') {
            throw new RuntimeException('The first_name field is required.');
        }

        if ($lastName === '') {
            throw new RuntimeException('The last_name field is required.');
        }

        if ($whatsappNumber === '') {
            throw new RuntimeException('The whatsapp_number field is required.');
        }

        if ($phoneNumber === '') {
            throw new RuntimeException('The phone_number field is required.');
        }

        if ($age <= 0) {
            throw new RuntimeException('The age field is invalid.');
        }

        if ($address === '') {
            throw new RuntimeException('The address field is required.');
        }

        if ($reason === '') {
            throw new RuntimeException('The reason field is required.');
        }

        if ($confirmCorrect !== 1) {
            throw new RuntimeException('The confirm_correct field must be accepted.');
        }

        if ($agreeRules !== 1) {
            throw new RuntimeException('The agree_rules field must be accepted.');
        }

        $this->assertMaxLength($firstName, 150, 'first_name');
        $this->assertMaxLength($lastName, 150, 'last_name');
        $this->assertMaxLength($whatsappNumber, 50, 'whatsapp_number');
        $this->assertMaxLength($phoneNumber, 50, 'phone_number');
        $this->assertMaxLength($address, 255, 'address');
        $this->assertMaxLength($reason, 5000, 'reason');
        $this->assertMaxLength($experience, 5000, 'experience');
        $this->assertMaxLength($adminNotes, 5000, 'admin_notes');

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'whatsapp_number' => $whatsappNumber,
            'phone_number' => $phoneNumber,
            'age' => $age,
            'address' => $address,
            'reason' => $reason,
            'has_volunteered_before' => $hasVolunteeredBefore,
            'experience' => $experience,
            'confirm_correct' => $confirmCorrect,
            'agree_rules' => $agreeRules,
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
            'firstName' => (string) $row['first_name'],
            'lastName' => (string) $row['last_name'],
            'fullName' => trim((string) $row['first_name'] . ' ' . (string) $row['last_name']),
            'whatsappNumber' => (string) $row['whatsapp_number'],
            'phoneNumber' => (string) $row['phone_number'],
            'age' => (int) $row['age'],
            'address' => (string) $row['address'],
            'reason' => (string) $row['reason'],
            'hasVolunteeredBefore' => (string) $row['has_volunteered_before'],
            'experience' => $row['experience'] !== null ? (string) $row['experience'] : null,
            'confirmCorrect' => (int) $row['confirm_correct'] === 1,
            'agreeRules' => (int) $row['agree_rules'] === 1,
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

    private function normalizeYesNo(string $value, string $field): string
    {
        $normalized = strtolower(trim($value));
        if (!in_array($normalized, ['yes', 'no'], true)) {
            throw new RuntimeException(sprintf('The %s field is invalid.', $field));
        }

        return $normalized;
    }

    private function normalizeBoolean(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return 1;
        }

        return 0;
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
