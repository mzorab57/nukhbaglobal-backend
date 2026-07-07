<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class OfficeSaleService
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createCashSale(PDO $pdo, array $payload, int $actingUserId): array
    {
        $validated = $this->validatePayload($payload);
        $ticketService = new TicketService();

        $preparedItems = $ticketService->prepareCheckoutItems($pdo, $validated['items']);
        if ($preparedItems['items'] === []) {
            throw new RuntimeException('At least one ticket selection is required.');
        }

        $totals = $this->resolveTotals($validated, $preparedItems['tickets_total_amount']);
        $orderNumber = $this->generateUniqueNumber('OFF');
        $invoiceNumber = $this->generateUniqueNumber('CSH');

        $orderId = $this->insertOrder($pdo, $orderNumber, $validated, $totals);
        $this->insertOrderItems($pdo, $orderId, $preparedItems['items']);
        $paymentId = $this->insertCashPayment($pdo, $orderId, $invoiceNumber, $totals['totalAmount']);
        $issuedTickets = $ticketService->issueTicketsForPaidOrder($pdo, $orderId, $paymentId);

        $this->insertActivityLog(
            $pdo,
            $actingUserId,
            'create',
            'orders',
            $orderId,
            [],
            [
                'order_number' => $orderNumber,
                'payment_type' => 'cash',
                'payment_status' => 'success',
                'order_status' => 'paid',
                'issued_tickets_count' => count($issuedTickets),
            ]
        );

        return [
            'order' => [
                'id' => $orderId,
                'orderNumber' => $orderNumber,
                'status' => 'paid',
                'customer' => [
                    'name' => $validated['customer_name'],
                    'email' => $validated['customer_email'],
                    'phone' => $validated['customer_phone'],
                    'address' => $validated['customer_address'],
                ],
                'amounts' => [
                    'tickets' => $totals['ticketsTotalAmount'],
                    'donation' => $totals['donationAmount'],
                    'total' => $totals['totalAmount'],
                    'currency' => 'IQD',
                ],
            ],
            'payment' => [
                'id' => $paymentId,
                'invoiceNumber' => $invoiceNumber,
                'type' => 'cash',
                'status' => 'success',
                'gatewayName' => 'OFFICE_CASH',
                'paidAt' => date('Y-m-d H:i:s'),
            ],
            'summary' => [
                'issuedTicketsCount' => count($issuedTickets),
            ],
            'printable' => $this->getPrintablePassPayloadByOrderId($pdo, $orderId),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPrintablePassPayloadByOrderId(PDO $pdo, int $orderId): ?array
    {
        $order = $this->getOrderRecord($pdo, $orderId);

        if ($order === null) {
            return null;
        }

        $printablePasses = $this->getPrintablePasses($pdo, $orderId, (string) $order['order_number'], (string) $order['customer_name']);

        return [
            'order' => [
                'id' => (int) $order['id'],
                'orderNumber' => (string) $order['order_number'],
                'status' => (string) $order['order_status'],
                'trackingState' => $this->resolveTrackingState(
                    (string) $order['order_status'],
                    $order['payment_status'] !== null ? (string) $order['payment_status'] : null
                ),
                'createdAt' => (string) $order['created_at'],
            ],
            'customer' => [
                'name' => (string) $order['customer_name'],
                'email' => $order['customer_email'] !== null ? (string) $order['customer_email'] : null,
                'phone' => (string) $order['customer_phone'],
            ],
            'payment' => [
                'type' => $order['payment_type'] !== null ? (string) $order['payment_type'] : null,
                'status' => $order['payment_status'] !== null ? (string) $order['payment_status'] : null,
                'invoiceNumber' => $order['invoice_number'] !== null ? (string) $order['invoice_number'] : null,
                'paidAt' => $order['paid_at'] !== null ? (string) $order['paid_at'] : null,
            ],
            'printablePasses' => $printablePasses,
            'summary' => [
                'printableCount' => count($printablePasses),
                'currency' => 'IQD',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function validatePayload(array $payload): array
    {
        $customerName = trim((string) ($payload['customer_name'] ?? ''));
        $customerPhone = trim((string) ($payload['customer_phone'] ?? ''));
        $customerEmail = $this->nullableString($payload['customer_email'] ?? null);
        $customerAddress = $this->nullableString($payload['customer_address'] ?? null);
        $donationAmount = $this->normalizeMoney($payload['donation_amount'] ?? 0);
        $items = $this->normalizeOrderItems($payload['items'] ?? []);

        if ($customerName === '') {
            throw new RuntimeException('The customer_name field is required.');
        }

        if ($customerPhone === '') {
            throw new RuntimeException('The customer_phone field is required.');
        }

        if ($customerEmail !== null && filter_var($customerEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('The customer_email field must contain a valid email address.');
        }

        return [
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'customer_email' => $customerEmail,
            'customer_address' => $customerAddress,
            'donation_amount' => $donationAmount,
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $validated
     * @return array{ticketsTotalAmount:float,donationAmount:float,totalAmount:float}
     */
    private function resolveTotals(array $validated, float $ticketsTotalAmount): array
    {
        $donationAmount = round((float) ($validated['donation_amount'] ?? 0), 2);
        $totalAmount = round($ticketsTotalAmount + $donationAmount, 2);

        return [
            'ticketsTotalAmount' => round($ticketsTotalAmount, 2),
            'donationAmount' => $donationAmount,
            'totalAmount' => $totalAmount,
        ];
    }

    private function insertOrder(PDO $pdo, string $orderNumber, array $validated, array $totals): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO orders (
                order_number,
                customer_name,
                customer_email,
                customer_phone,
                customer_address,
                tickets_total_amount,
                donation_amount,
                total_amount,
                status,
                expires_at
            ) VALUES (
                :order_number,
                :customer_name,
                :customer_email,
                :customer_phone,
                :customer_address,
                :tickets_total_amount,
                :donation_amount,
                :total_amount,
                :status,
                NULL
            )'
        );
        $statement->execute([
            ':order_number' => $orderNumber,
            ':customer_name' => $validated['customer_name'],
            ':customer_email' => $validated['customer_email'],
            ':customer_phone' => $validated['customer_phone'],
            ':customer_address' => $validated['customer_address'],
            ':tickets_total_amount' => number_format($totals['ticketsTotalAmount'], 2, '.', ''),
            ':donation_amount' => number_format($totals['donationAmount'], 2, '.', ''),
            ':total_amount' => number_format($totals['totalAmount'], 2, '.', ''),
            ':status' => 'paid',
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<int, array{ticket_id:int,quantity:int,price_per_item:float}> $items
     */
    private function insertOrderItems(PDO $pdo, int $orderId, array $items): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO order_items (order_id, ticket_id, quantity, price_per_item)
             VALUES (:order_id, :ticket_id, :quantity, :price_per_item)'
        );

        foreach ($items as $item) {
            $statement->execute([
                ':order_id' => $orderId,
                ':ticket_id' => $item['ticket_id'],
                ':quantity' => $item['quantity'],
                ':price_per_item' => number_format($item['price_per_item'], 2, '.', ''),
            ]);
        }
    }

    private function insertCashPayment(PDO $pdo, int $orderId, string $invoiceNumber, float $amount): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO payments (
                order_id,
                type,
                invoice_number,
                gateway_name,
                gateway_transaction_id,
                amount,
                status,
                gateway_response,
                webhook_verified,
                paid_at
            ) VALUES (
                :order_id,
                :type,
                :invoice_number,
                :gateway_name,
                :gateway_transaction_id,
                :amount,
                :status,
                :gateway_response,
                :webhook_verified,
                NOW()
            )'
        );
        $statement->execute([
            ':order_id' => $orderId,
            ':type' => 'cash',
            ':invoice_number' => $invoiceNumber,
            ':gateway_name' => 'OFFICE_CASH',
            ':gateway_transaction_id' => $invoiceNumber,
            ':amount' => number_format($amount, 2, '.', ''),
            ':status' => 'success',
            ':gateway_response' => json_encode(
                ['source' => 'office_cash_sale'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            ':webhook_verified' => 1,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getOrderRecord(PDO $pdo, int $orderId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT
                o.id,
                o.order_number,
                o.customer_name,
                o.customer_email,
                o.customer_phone,
                o.created_at,
                o.status AS order_status,
                p.type AS payment_type,
                p.status AS payment_status,
                p.invoice_number,
                p.paid_at
             FROM orders o
             LEFT JOIN payments p ON p.id = (
                SELECT p2.id
                FROM payments p2
                WHERE p2.order_id = o.id
                  AND p2.deleted_at IS NULL
                ORDER BY p2.id DESC
                LIMIT 1
             )
             WHERE o.id = :order_id
               AND o.deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            ':order_id' => $orderId,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getPrintablePasses(PDO $pdo, int $orderId, string $orderNumber, string $customerName): array
    {
        $statement = $pdo->prepare(
            'SELECT
                et.ticket_code,
                et.passenger_name,
                et.status,
                et.scanned_at,
                t.title AS ticket_title,
                e.title AS event_title,
                e.date AS event_date,
                se.title AS sub_event_title
             FROM event_tickets et
             INNER JOIN tickets t ON t.id = et.ticket_id
             INNER JOIN events e ON e.id = t.event_id
             LEFT JOIN sub_events se ON se.id = t.sub_event_id AND se.deleted_at IS NULL
             WHERE et.order_id = :order_id
             ORDER BY et.id ASC'
        );
        $statement->execute([
            ':order_id' => $orderId,
        ]);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $row) use ($orderNumber, $customerName): array {
            $ticketCode = (string) ($row['ticket_code'] ?? '');
            $eventTitle = $this->resolveDisplayText($this->decodeJsonColumn($row['event_title'] ?? null));
            $ticketTitle = $this->resolveDisplayText($this->decodeJsonColumn($row['ticket_title'] ?? null));
            $subEventTitle = $this->resolveDisplayTextOrNull($this->decodeJsonColumnOrNull($row['sub_event_title'] ?? null));

            return [
                'ticketCode' => $ticketCode,
                'qrPayload' => $this->buildQrPayload($ticketCode),
                'display' => [
                    'title' => $eventTitle ?? 'Event Pass',
                    'subtitle' => $ticketTitle ?? 'Ticket',
                    'passengerName' => $row['passenger_name'] !== null ? (string) $row['passenger_name'] : $customerName,
                    'eventDate' => $row['event_date'] !== null ? (string) $row['event_date'] : null,
                    'subEventTitle' => $subEventTitle,
                    'status' => $row['status'] !== null ? (string) $row['status'] : null,
                    'scannedAt' => $row['scanned_at'] !== null ? (string) $row['scanned_at'] : null,
                    'orderNumber' => $orderNumber,
                ],
            ];
        }, $rows);
    }

    /**
     * @param array<string, mixed> $oldValues
     * @param array<string, mixed> $newValues
     */
    private function insertActivityLog(PDO $pdo, int $userId, string $action, string $tableName, int $recordId, array $oldValues, array $newValues): void
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
            ':user_id' => $userId,
            ':action' => $action,
            ':table_name' => $tableName,
            ':record_id' => $recordId,
            ':old_values' => json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':new_values' => json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    /**
     * @param mixed $items
     * @return array<int, array{ticket_id:int,quantity:int}>
     */
    private function normalizeOrderItems(mixed $items): array
    {
        if (!is_array($items)) {
            throw new RuntimeException('The items field must be an array.');
        }

        $normalizedItems = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('Each order item must be an object.');
            }

            $ticketId = (int) ($item['ticket_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($ticketId <= 0 || $quantity <= 0) {
                throw new RuntimeException('Each order item requires valid ticket_id and quantity values.');
            }

            $normalizedItems[] = [
                'ticket_id' => $ticketId,
                'quantity' => $quantity,
            ];
        }

        return $normalizedItems;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeMoney(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $stringValue = str_replace(',', '', trim((string) $value));

        if ($stringValue === '' || !is_numeric($stringValue)) {
            throw new RuntimeException('Invalid monetary amount provided.');
        }

        $amount = round((float) $stringValue, 2);

        if ($amount < 0) {
            throw new RuntimeException('Amounts cannot be negative.');
        }

        return $amount;
    }

    private function generateUniqueNumber(string $prefix): string
    {
        return sprintf(
            '%s-%s-%s',
            strtoupper($prefix),
            date('YmdHis'),
            bin2hex(random_bytes(4))
        );
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

    /**
     * @param array<string, mixed>|null $translations
     */
    private function resolveDisplayTextOrNull(?array $translations): ?string
    {
        if ($translations === null) {
            return null;
        }

        return $this->resolveDisplayText($translations);
    }

    private function resolveTrackingState(string $orderStatus, ?string $paymentStatus): string
    {
        if ($orderStatus === 'refunded' || $paymentStatus === 'refunded') {
            return 'refunded';
        }

        if ($orderStatus === 'completed') {
            return 'completed';
        }

        if ($orderStatus === 'paid' || $paymentStatus === 'success') {
            return 'paid';
        }

        if ($orderStatus === 'cancelled' || $paymentStatus === 'failed') {
            return 'cancelled';
        }

        return 'awaiting_payment';
    }

    private function buildQrPayload(string $ticketCode): string
    {
        return 'NUKHBAGLOBAL:TICKET:' . $ticketCode;
    }
}
