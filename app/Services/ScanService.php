<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class ScanService
{
    /**
     * @return array<string, mixed>
     */
    public function previewTicket(PDO $pdo, string $ticketCode, int $scannerUserId): array
    {
        $scanner = $this->findScannerUser($pdo, $scannerUserId);
        $ticket = $this->findTicketByCode($pdo, $ticketCode);

        if ($ticket === null) {
            throw new RuntimeException('Ticket was not found.');
        }

        return $this->mapScanPayload($ticket, $scanner, $ticket['status'] === 'valid');
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmScan(PDO $pdo, string $ticketCode, int $scannerUserId): array
    {
        $scanner = $this->findScannerUser($pdo, $scannerUserId);
        $ticket = $this->findTicketByCode($pdo, $ticketCode, true);

        if ($ticket === null) {
            throw new RuntimeException('Ticket was not found.');
        }

        if ((string) $ticket['status'] !== 'valid') {
            throw new RuntimeException($this->resolveScanBlockReason($ticket));
        }

        $pdo->beginTransaction();

        try {
            $updateStatement = $pdo->prepare(
                'UPDATE event_tickets
                 SET status = :status,
                     scanned_at = NOW(),
                     scanned_by = :scanned_by,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $updateStatement->execute([
                ':status' => 'used',
                ':scanned_by' => $scannerUserId,
                ':id' => $ticket['id'],
            ]);

            $this->insertActivityLog($pdo, $scannerUserId, (int) $ticket['id'], $ticket);

            $updatedTicket = $this->findTicketByCode($pdo, $ticketCode, true);

            if ($updatedTicket === null) {
                throw new RuntimeException('Scanned ticket could not be reloaded.');
            }

            $pdo->commit();

            return $this->mapScanPayload($updatedTicket, $scanner, false);
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function findScannerUser(PDO $pdo, int $scannerUserId): array
    {
        if ($scannerUserId <= 0) {
            throw new RuntimeException('Scanner user ID is required.');
        }

        $statement = $pdo->prepare(
            'SELECT id, name, role, status, deleted_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute([
            ':id' => $scannerUserId,
        ]);

        /** @var array<string, mixed>|false $scanner */
        $scanner = $statement->fetch(PDO::FETCH_ASSOC);

        if ($scanner === false || $scanner['deleted_at'] !== null || (int) $scanner['status'] !== 1) {
            throw new RuntimeException('Scanner user is invalid or inactive.');
        }

        $role = (string) ($scanner['role'] ?? '');

        if (!in_array($role, ['admin', 'scanner'], true)) {
            throw new RuntimeException('Scanner user does not have scan permission.');
        }

        return $scanner;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findTicketByCode(PDO $pdo, string $ticketCode, bool $forUpdate = false): ?array
    {
        $normalizedCode = trim($ticketCode);

        if ($normalizedCode === '') {
            throw new RuntimeException('Ticket code is required.');
        }

        $statement = $pdo->prepare(
            'SELECT
                et.id,
                et.order_id,
                et.ticket_id,
                et.payment_id,
                et.passenger_name,
                et.ticket_code,
                et.scanned_at,
                et.scanned_by,
                et.status,
                o.order_number,
                o.customer_name,
                o.customer_email,
                o.customer_phone,
                o.status AS order_status,
                p.status AS payment_status,
                t.title AS ticket_title,
                e.id AS event_id,
                e.title AS event_title,
                e.date AS event_date,
                scanner.name AS scanned_by_name,
                scanner.role AS scanned_by_role
             FROM event_tickets et
             INNER JOIN orders o ON o.id = et.order_id
             INNER JOIN payments p ON p.id = et.payment_id
             INNER JOIN tickets t ON t.id = et.ticket_id
             INNER JOIN events e ON e.id = t.event_id
             LEFT JOIN users scanner ON scanner.id = et.scanned_by
             WHERE et.ticket_code = :ticket_code
             LIMIT 1'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([
            ':ticket_code' => $normalizedCode,
        ]);

        /** @var array<string, mixed>|false $ticket */
        $ticket = $statement->fetch(PDO::FETCH_ASSOC);

        return $ticket === false ? null : $ticket;
    }

    /**
     * @param array<string, mixed> $ticket
     * @param array<string, mixed> $scanner
     * @return array<string, mixed>
     */
    private function mapScanPayload(array $ticket, array $scanner, bool $canScan): array
    {
        $eventTitle = $this->decodeJsonColumn($ticket['event_title'] ?? null);
        $ticketTitle = $this->decodeJsonColumn($ticket['ticket_title'] ?? null);
        $ticketStatus = (string) ($ticket['status'] ?? '');

        return [
            'ticketId' => (int) $ticket['ticket_id'],
            'ticketRecordId' => (int) $ticket['id'],
            'ticketCode' => (string) $ticket['ticket_code'],
            'ticketStatus' => $ticketStatus,
            'canScan' => $canScan && $ticketStatus === 'valid',
            'statusLabel' => $this->resolveStatusLabel($ticketStatus),
            'orderId' => (int) $ticket['order_id'],
            'orderNumber' => (string) $ticket['order_number'],
            'orderStatus' => (string) $ticket['order_status'],
            'paymentId' => (int) $ticket['payment_id'],
            'paymentStatus' => (string) $ticket['payment_status'],
            'customer' => [
                'name' => (string) $ticket['customer_name'],
                'email' => $ticket['customer_email'] !== null ? (string) $ticket['customer_email'] : null,
                'phone' => (string) $ticket['customer_phone'],
            ],
            'event' => [
                'id' => (int) $ticket['event_id'],
                'title' => $eventTitle,
                'titleText' => $this->resolveDisplayText($eventTitle),
                'date' => (string) $ticket['event_date'],
            ],
            'ticket' => [
                'title' => $ticketTitle,
                'titleText' => $this->resolveDisplayText($ticketTitle),
                'passengerName' => $ticket['passenger_name'] !== null ? (string) $ticket['passenger_name'] : null,
            ],
            'lastScan' => [
                'scannedAt' => $ticket['scanned_at'] !== null ? (string) $ticket['scanned_at'] : null,
                'scannedBy' => $ticket['scanned_by'] !== null
                    ? [
                        'id' => (int) $ticket['scanned_by'],
                        'name' => $ticket['scanned_by_name'] !== null ? (string) $ticket['scanned_by_name'] : null,
                        'role' => $ticket['scanned_by_role'] !== null ? (string) $ticket['scanned_by_role'] : null,
                    ]
                    : null,
            ],
            'scanner' => [
                'id' => (int) $scanner['id'],
                'name' => (string) $scanner['name'],
                'role' => (string) $scanner['role'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $ticket
     */
    private function insertActivityLog(PDO $pdo, int $scannerUserId, int $recordId, array $ticket): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO activity_logs (
                user_id,
                action,
                table_name,
                record_id,
                old_values,
                new_values,
                ip_address,
                user_agent
             ) VALUES (
                :user_id,
                :action,
                :table_name,
                :record_id,
                :old_values,
                :new_values,
                :ip_address,
                :user_agent
             )'
        );
        $statement->execute([
            ':user_id' => $scannerUserId,
            ':action' => 'scan',
            ':table_name' => 'event_tickets',
            ':record_id' => $recordId,
            ':old_values' => json_encode(
                [
                    'status' => $ticket['status'] ?? null,
                    'scanned_at' => $ticket['scanned_at'] ?? null,
                    'scanned_by' => $ticket['scanned_by'] ?? null,
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            ':new_values' => json_encode(
                [
                    'status' => 'used',
                    'scanned_by' => $scannerUserId,
                    'scanned_at' => date('Y-m-d H:i:s'),
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            ':ip_address' => $this->resolveIpAddress(),
            ':user_agent' => $this->resolveUserAgent(),
        ]);
    }

    private function resolveStatusLabel(string $status): string
    {
        return match ($status) {
            'valid' => 'Ready to scan',
            'used' => 'Already used',
            'cancelled' => 'Cancelled',
            'refunded' => 'Refunded',
            default => 'Unknown',
        };
    }

    /**
     * @param array<string, mixed> $ticket
     */
    private function resolveScanBlockReason(array $ticket): string
    {
        return match ((string) ($ticket['status'] ?? '')) {
            'used' => 'Ticket has already been used.',
            'cancelled' => 'Ticket was cancelled and cannot be scanned.',
            'refunded' => 'Ticket was refunded and cannot be scanned.',
            default => 'Ticket is not valid for scanning.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonColumn(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $translations
     */
    private function resolveDisplayText(array $translations): ?string
    {
        foreach (['en', 'ar'] as $locale) {
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

    private function resolveIpAddress(): ?string
    {
        $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;

        if (!is_string($ipAddress) || trim($ipAddress) === '') {
            return null;
        }

        $forwarded = explode(',', $ipAddress);

        return trim($forwarded[0]);
    }

    private function resolveUserAgent(): ?string
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        return is_string($userAgent) && trim($userAgent) !== '' ? trim($userAgent) : null;
    }
}
