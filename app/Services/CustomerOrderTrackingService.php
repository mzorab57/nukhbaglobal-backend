<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

final class CustomerOrderTrackingService
{
    /**
     * @param array<string, mixed> $lookup
     * @return array<string, mixed>
     */
    public function track(PDO $pdo, array $lookup): array
    {
        $record = $this->getVerifiedTrackedOrder($pdo, $lookup);
        $orderId = (int) $record['id'];
        $items = $this->getOrderItems($pdo, $orderId);
        $issuedTickets = $this->getIssuedTickets($pdo, $orderId);
        $gatewayResponse = $this->decodeJsonColumn($record['gateway_response'] ?? null);
        $trackingState = $this->resolveTrackingState(
            (string) $record['order_status'],
            $record['payment_status'] !== null ? (string) $record['payment_status'] : null
        );

        return [
            'order' => [
                'orderNumber' => (string) $record['order_number'],
                'status' => (string) $record['order_status'],
                'trackingState' => $trackingState,
                'expiresAt' => $record['expires_at'] !== null ? (string) $record['expires_at'] : null,
                'createdAt' => (string) $record['created_at'],
                'updatedAt' => (string) $record['updated_at'],
            ],
            'customer' => [
                'name' => (string) $record['customer_name'],
                'email' => $record['customer_email'] !== null ? (string) $record['customer_email'] : null,
                'phone' => (string) $record['customer_phone'],
                'maskedEmail' => $this->maskEmail($record['customer_email'] !== null ? (string) $record['customer_email'] : null),
                'maskedPhone' => $this->maskPhone((string) $record['customer_phone']),
            ],
            'amounts' => [
                'tickets' => round((float) $record['tickets_total_amount'], 2),
                'donation' => round((float) $record['donation_amount'], 2),
                'total' => round((float) $record['total_amount'], 2),
                'currency' => 'IQD',
            ],
            'payment' => [
                'type' => $record['payment_type'] !== null ? (string) $record['payment_type'] : null,
                'gatewayName' => $record['gateway_name'] !== null ? (string) $record['gateway_name'] : null,
                'status' => $record['payment_status'] !== null ? (string) $record['payment_status'] : null,
                'invoiceNumber' => $record['invoice_number'] !== null ? (string) $record['invoice_number'] : null,
                'paymentId' => $record['gateway_transaction_id'] !== null ? (string) $record['gateway_transaction_id'] : null,
                'amount' => $record['payment_amount'] !== null ? round((float) $record['payment_amount'], 2) : null,
                'paidAt' => $record['paid_at'] !== null ? (string) $record['paid_at'] : null,
                'failedReason' => $record['failed_reason'] !== null ? (string) $record['failed_reason'] : null,
                'refundedAt' => $record['refunded_at'] !== null ? (string) $record['refunded_at'] : null,
                'refundAmount' => $record['refund_amount'] !== null ? round((float) $record['refund_amount'], 2) : null,
                'refundReference' => $record['refund_reference'] !== null ? (string) $record['refund_reference'] : null,
                'links' => [
                    'redirectionLink' => $this->normalizeNullableString($gatewayResponse['redirectionLink'] ?? null),
                    'qrCode' => $this->normalizeNullableString($gatewayResponse['qrCode'] ?? null),
                    'readableCode' => $this->normalizeNullableString($gatewayResponse['readableCode'] ?? null),
                ],
            ],
            'items' => $items,
            'issuedTickets' => $issuedTickets,
            'summary' => [
                'itemsCount' => count($items),
                'ticketsQuantity' => array_sum(array_map(
                    static fn (array $item): int => (int) ($item['quantity'] ?? 0),
                    $items
                )),
                'issuedTicketsCount' => count($issuedTickets),
                'canCheckPaymentStatus' => $record['gateway_name'] !== null && strtoupper((string) $record['gateway_name']) === 'FIB',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $lookup
     * @return array<string, mixed>
     */
    public function getPasses(PDO $pdo, array $lookup): array
    {
        $record = $this->getVerifiedTrackedOrder($pdo, $lookup);
        $orderId = (int) $record['id'];
        $issuedTickets = $this->getIssuedTickets($pdo, $orderId);

        return [
            'order' => [
                'orderNumber' => (string) $record['order_number'],
                'status' => (string) $record['order_status'],
                'trackingState' => $this->resolveTrackingState(
                    (string) $record['order_status'],
                    $record['payment_status'] !== null ? (string) $record['payment_status'] : null
                ),
                'createdAt' => (string) $record['created_at'],
            ],
            'customer' => [
                'name' => (string) $record['customer_name'],
                'maskedEmail' => $this->maskEmail($record['customer_email'] !== null ? (string) $record['customer_email'] : null),
                'maskedPhone' => $this->maskPhone((string) $record['customer_phone']),
            ],
            'passes' => $issuedTickets,
            'summary' => [
                'passesCount' => count($issuedTickets),
                'downloadReady' => $issuedTickets !== [],
                'passengerNamesComplete' => array_reduce(
                    $issuedTickets,
                    static fn (bool $carry, array $ticket): bool => $carry && trim((string) ($ticket['passengerName'] ?? '')) !== '',
                    true
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $lookup
     * @return array<string, mixed>
     */
    public function getPrintablePassPayload(PDO $pdo, array $lookup): array
    {
        $record = $this->getVerifiedTrackedOrder($pdo, $lookup);
        $orderId = (int) $record['id'];
        $issuedTickets = $this->getIssuedTickets($pdo, $orderId);

        return [
            'order' => [
                'orderNumber' => (string) $record['order_number'],
                'trackingState' => $this->resolveTrackingState(
                    (string) $record['order_status'],
                    $record['payment_status'] !== null ? (string) $record['payment_status'] : null
                ),
            ],
            'customer' => [
                'name' => (string) $record['customer_name'],
                'maskedPhone' => $this->maskPhone((string) $record['customer_phone']),
            ],
            'printablePasses' => array_map(
                fn (array $ticket): array => $this->mapPrintablePass($ticket, (string) $record['order_number'], (string) $record['customer_name']),
                $issuedTickets
            ),
            'summary' => [
                'printableCount' => count($issuedTickets),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $lookup
     * @return array<string, mixed>
     */
    public function retryPayment(PDO $pdo, array $lookup): array
    {
        $record = $this->getVerifiedTrackedOrder($pdo, $lookup);
        $orderId = (int) $record['id'];
        $orderStatus = strtolower((string) $record['order_status']);
        $paymentStatus = strtolower((string) ($record['payment_status'] ?? ''));
        $gatewayName = strtoupper(trim((string) ($record['gateway_name'] ?? '')));
        $gatewayResponse = $this->decodeJsonColumn($record['gateway_response'] ?? null);

        if ($gatewayName !== 'FIB') {
            throw new RuntimeException('Payment retry is only supported for FIB orders.');
        }

        if (in_array($orderStatus, ['paid', 'completed'], true) || $paymentStatus === 'success') {
            return [
                'orderNumber' => (string) $record['order_number'],
                'orderStatus' => (string) $record['order_status'],
                'paymentStatus' => $record['payment_status'] !== null ? (string) $record['payment_status'] : null,
                'action' => 'already_paid',
                'payment' => [
                    'paymentId' => $record['gateway_transaction_id'] !== null ? (string) $record['gateway_transaction_id'] : null,
                    'redirectionLink' => $this->normalizeNullableString($gatewayResponse['redirectionLink'] ?? null),
                    'qrCode' => $this->normalizeNullableString($gatewayResponse['qrCode'] ?? null),
                    'readableCode' => $this->normalizeNullableString($gatewayResponse['readableCode'] ?? null),
                ],
                'issuedTickets' => $this->getIssuedTickets($pdo, $orderId),
            ];
        }

        if ($orderStatus === 'refunded' || $paymentStatus === 'refunded') {
            throw new RuntimeException('Refunded orders cannot create a new payment.');
        }

        if ($paymentStatus === 'pending') {
            return [
                'orderNumber' => (string) $record['order_number'],
                'orderStatus' => (string) $record['order_status'],
                'paymentStatus' => $record['payment_status'] !== null ? (string) $record['payment_status'] : null,
                'action' => 'reused_pending_payment',
                'payment' => [
                    'paymentId' => $record['gateway_transaction_id'] !== null ? (string) $record['gateway_transaction_id'] : null,
                    'redirectionLink' => $this->normalizeNullableString($gatewayResponse['redirectionLink'] ?? null),
                    'qrCode' => $this->normalizeNullableString($gatewayResponse['qrCode'] ?? null),
                    'readableCode' => $this->normalizeNullableString($gatewayResponse['readableCode'] ?? null),
                ],
            ];
        }

        if (!in_array($orderStatus, ['pending', 'expired', 'cancelled'], true)) {
            throw new RuntimeException('Order payment cannot be retried in its current state.');
        }

        $orderItems = $this->getRetryableOrderItems($pdo, $orderId);
        if ($orderItems === []) {
            throw new RuntimeException('This order has no retryable items.');
        }

        $ticketService = new TicketService();
        $fibService = new FIBPaymentService();

        $pdo->beginTransaction();

        try {
            $preparedItems = $ticketService->prepareCheckoutItems($pdo, $orderItems);
            $ticketService->reserveTickets($pdo, $preparedItems['items']);

            $fibPayment = $fibService->createFIBPayment(
                round((float) $record['total_amount'], 2),
                'Order ' . (string) $record['order_number'] . ' retry'
            );

            $invoiceNumber = $this->generateUniqueNumber('INV');
            $paymentRecordId = $this->insertPaymentTracking(
                $pdo,
                $orderId,
                $invoiceNumber,
                round((float) $record['total_amount'], 2),
                $fibPayment
            );

            $this->updateOrderForRetry($pdo, $orderId);
            $pdo->commit();
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        }

        return [
            'orderNumber' => (string) $record['order_number'],
            'orderStatus' => 'pending',
            'paymentStatus' => 'pending',
            'action' => 'created_new_payment',
            'payment' => [
                'paymentRecordId' => $paymentRecordId,
                'paymentId' => $fibPayment['paymentId'] ?? null,
                'redirectionLink' => $fibPayment['redirectionLink'] ?? null,
                'qrCode' => $fibPayment['qrCode'] ?? null,
                'readableCode' => $fibPayment['readableCode'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $lookup
     * @param array<int, array<string, mixed>> $passengers
     * @return array<string, mixed>
     */
    public function updatePassengerNames(PDO $pdo, array $lookup, array $passengers): array
    {
        $record = $this->getVerifiedTrackedOrder($pdo, $lookup);
        $orderId = (int) $record['id'];
        $orderStatus = strtolower((string) $record['order_status']);
        $paymentStatus = strtolower((string) ($record['payment_status'] ?? ''));

        if (!in_array($orderStatus, ['paid', 'completed'], true) || !in_array($paymentStatus, ['success', ''], true)) {
            throw new RuntimeException('Passenger names can only be managed for paid orders.');
        }

        $normalizedPassengers = $this->normalizePassengerPayload($passengers);

        if ($normalizedPassengers === []) {
            throw new RuntimeException('At least one passenger update is required.');
        }

        $ticketCodes = array_keys($normalizedPassengers);
        $placeholders = implode(',', array_fill(0, count($ticketCodes), '?'));

        $pdo->beginTransaction();

        try {
            $statement = $pdo->prepare(
                'SELECT id, ticket_code, status
                 FROM event_tickets
                 WHERE order_id = ?
                   AND ticket_code IN (' . $placeholders . ')
                 FOR UPDATE'
            );
            $statement->execute(array_merge([$orderId], $ticketCodes));

            /** @var array<int, array<string, mixed>> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $existingCodes = [];

            foreach ($rows as $row) {
                $ticketCode = (string) ($row['ticket_code'] ?? '');
                $existingCodes[] = $ticketCode;
                $status = (string) ($row['status'] ?? '');

                if (in_array($status, ['refunded', 'cancelled'], true)) {
                    throw new RuntimeException(sprintf('Passenger name cannot be updated for ticket %s.', $ticketCode));
                }
            }

            sort($existingCodes);
            $expectedCodes = $ticketCodes;
            sort($expectedCodes);

            if ($existingCodes !== $expectedCodes) {
                throw new RuntimeException('One or more ticket codes are invalid for this order.');
            }

            $updateStatement = $pdo->prepare(
                'UPDATE event_tickets
                 SET passenger_name = :passenger_name,
                     updated_at = NOW()
                 WHERE order_id = :order_id
                   AND ticket_code = :ticket_code'
            );

            foreach ($normalizedPassengers as $ticketCode => $passengerName) {
                $updateStatement->execute([
                    ':passenger_name' => $passengerName,
                    ':order_id' => $orderId,
                    ':ticket_code' => $ticketCode,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        }

        return [
            'orderNumber' => (string) $record['order_number'],
            'passes' => $this->getIssuedTickets($pdo, $orderId),
        ];
    }

    /**
     * @param array<string, mixed> $lookup
     * @return array<string, mixed>
     */
    private function getVerifiedTrackedOrder(PDO $pdo, array $lookup): array
    {
        $orderNumber = $this->normalizeOrderNumber($lookup['order_number'] ?? null);
        $customerPhone = $this->normalizeNullableString($lookup['customer_phone'] ?? null);
        $customerEmail = $this->normalizeNullableString($lookup['customer_email'] ?? null);

        if ($customerPhone === null && $customerEmail === null) {
            throw new RuntimeException('Either customer_phone or customer_email is required.');
        }

        $record = $this->findTrackedOrder($pdo, $orderNumber, $customerPhone, $customerEmail);

        if ($record === null) {
            throw new RuntimeException('Order tracking details were not found.');
        }

        $this->syncPendingFibPaymentIfNeeded($pdo, $record);
        $record = $this->findTrackedOrder($pdo, $orderNumber, $customerPhone, $customerEmail);

        if ($record === null) {
            throw new RuntimeException('Order tracking details were not found.');
        }

        return $record;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findTrackedOrder(
        PDO $pdo,
        string $orderNumber,
        ?string $customerPhone,
        ?string $customerEmail
    ): ?array {
        $whereClauses = [
            'o.deleted_at IS NULL',
            'o.order_number = :order_number',
        ];
        $parameters = [
            ':order_number' => $orderNumber,
        ];

        if ($customerPhone !== null) {
            $whereClauses[] = 'o.customer_phone = :customer_phone';
            $parameters[':customer_phone'] = $customerPhone;
        }

        if ($customerEmail !== null) {
            $whereClauses[] = 'LOWER(COALESCE(o.customer_email, "")) = :customer_email';
            $parameters[':customer_email'] = strtolower($customerEmail);
        }

        $statement = $pdo->prepare(
            'SELECT
                o.id,
                o.order_number,
                o.customer_name,
                o.customer_email,
                o.customer_phone,
                o.tickets_total_amount,
                o.donation_amount,
                o.total_amount,
                o.status AS order_status,
                o.expires_at,
                o.created_at,
                o.updated_at,
                p.id AS payment_record_id,
                p.type AS payment_type,
                p.invoice_number,
                p.gateway_name,
                p.gateway_transaction_id,
                p.amount AS payment_amount,
                p.status AS payment_status,
                p.gateway_response,
                p.paid_at,
                p.failed_reason,
                p.refunded_at,
                p.refund_amount,
                p.refund_reference
             FROM orders o
             LEFT JOIN payments p ON p.id = (
                SELECT p2.id
                FROM payments p2
                WHERE p2.order_id = o.id
                  AND p2.deleted_at IS NULL
                ORDER BY p2.id DESC
                LIMIT 1
             )
             WHERE ' . implode(' AND ', $whereClauses) . '
             LIMIT 1'
        );
        $statement->execute($parameters);

        $result = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($result) ? $result : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getOrderItems(PDO $pdo, int $orderId): array
    {
        $statement = $pdo->prepare(
            'SELECT
                oi.id,
                oi.quantity,
                oi.price_per_item,
                t.title AS ticket_title,
                se.title AS sub_event_title,
                se.date AS sub_event_date,
                e.title AS event_title,
                e.date AS event_date
             FROM order_items oi
             INNER JOIN tickets t ON t.id = oi.ticket_id
             INNER JOIN events e ON e.id = t.event_id
             LEFT JOIN sub_events se ON se.id = t.sub_event_id AND se.deleted_at IS NULL
             WHERE oi.order_id = :order_id
             ORDER BY oi.id ASC'
        );
        $statement->execute([
            ':order_id' => $orderId,
        ]);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $row): array {
            $ticketTitle = $this->decodeJsonColumn($row['ticket_title'] ?? null);
            $eventTitle = $this->decodeJsonColumn($row['event_title'] ?? null);
            $subEventTitle = $this->decodeJsonColumnOrNull($row['sub_event_title'] ?? null);
            $quantity = (int) ($row['quantity'] ?? 0);
            $pricePerItem = round((float) ($row['price_per_item'] ?? 0), 2);

            return [
                'quantity' => $quantity,
                'pricePerItem' => $pricePerItem,
                'lineTotal' => round($quantity * $pricePerItem, 2),
                'ticketTitle' => $ticketTitle,
                'ticketTitleText' => $this->resolveDisplayText($ticketTitle),
                'eventTitle' => $eventTitle,
                'eventTitleText' => $this->resolveDisplayText($eventTitle),
                'eventDate' => (string) $row['event_date'],
                'subEventTitle' => $subEventTitle,
                'subEventTitleText' => $subEventTitle !== null ? $this->resolveDisplayText($subEventTitle) : null,
                'subEventDate' => $row['sub_event_date'] !== null ? (string) $row['sub_event_date'] : null,
            ];
        }, $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getIssuedTickets(PDO $pdo, int $orderId): array
    {
        $statement = $pdo->prepare(
            'SELECT
                et.ticket_code,
                et.passenger_name,
                et.status,
                et.scanned_at,
                t.title AS ticket_title,
                se.title AS sub_event_title,
                e.title AS event_title,
                e.date AS event_date
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

        return array_map(function (array $row): array {
            $ticketTitle = $this->decodeJsonColumn($row['ticket_title'] ?? null);
            $eventTitle = $this->decodeJsonColumn($row['event_title'] ?? null);
            $subEventTitle = $this->decodeJsonColumnOrNull($row['sub_event_title'] ?? null);
            $status = (string) $row['status'];
            $ticketCode = (string) $row['ticket_code'];

            return [
                'ticketCode' => $ticketCode,
                'status' => $status,
                'passengerName' => $row['passenger_name'] !== null ? (string) $row['passenger_name'] : null,
                'scannedAt' => $row['scanned_at'] !== null ? (string) $row['scanned_at'] : null,
                'ticketTitle' => $ticketTitle,
                'ticketTitleText' => $this->resolveDisplayText($ticketTitle),
                'eventTitle' => $eventTitle,
                'eventTitleText' => $this->resolveDisplayText($eventTitle),
                'eventDate' => (string) $row['event_date'],
                'subEventTitle' => $subEventTitle,
                'subEventTitleText' => $subEventTitle !== null ? $this->resolveDisplayText($subEventTitle) : null,
                'canUpdatePassengerName' => !in_array($status, ['refunded', 'cancelled'], true),
                'qrPayload' => $this->buildQrPayload($ticketCode),
            ];
        }, $rows);
    }

    /**
     * @return array<int, array{ticket_id:int,quantity:int}>
     */
    private function getRetryableOrderItems(PDO $pdo, int $orderId): array
    {
        $statement = $pdo->prepare(
            'SELECT ticket_id, quantity
             FROM order_items
             WHERE order_id = :order_id
             ORDER BY id ASC'
        );
        $statement->execute([
            ':order_id' => $orderId,
        ]);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): array => [
                'ticket_id' => (int) ($row['ticket_id'] ?? 0),
                'quantity' => (int) ($row['quantity'] ?? 0),
            ],
            $rows
        );
    }

    /**
     * Best-effort sync so tracking can reflect the latest gateway state without failing hard.
     *
     * @param array<string, mixed> $record
     */
    private function syncPendingFibPaymentIfNeeded(PDO $pdo, array $record): void
    {
        $paymentRecordId = (int) ($record['payment_record_id'] ?? 0);
        $gatewayName = strtoupper(trim((string) ($record['gateway_name'] ?? '')));
        $paymentStatus = strtolower(trim((string) ($record['payment_status'] ?? '')));
        $orderStatus = strtolower(trim((string) ($record['order_status'] ?? '')));
        $gatewayTransactionId = trim((string) ($record['gateway_transaction_id'] ?? ''));

        if (
            $paymentRecordId <= 0 ||
            $gatewayName !== 'FIB' ||
            $paymentStatus !== 'pending' ||
            $gatewayTransactionId === '' ||
            in_array($orderStatus, ['cancelled', 'refunded', 'completed'], true)
        ) {
            return;
        }

        try {
            $fibService = new FIBPaymentService();
            $fibResult = $fibService->checkPaymentStatus($gatewayTransactionId);
            $fibStatus = strtoupper(trim((string) ($fibResult['status'] ?? '')));
            $ticketService = new TicketService();
            $orderId = (int) $record['id'];

            if ($fibStatus === 'PAID') {
                $pdo->beginTransaction();
                $this->updatePaymentSuccess($pdo, $paymentRecordId, $fibResult);
                $this->updateOrderStatus($pdo, $orderId, 'paid');
                $ticketService->issueTicketsForPaidOrder($pdo, $orderId, $paymentRecordId);
                $pdo->commit();

                return;
            }

            if ($fibStatus === 'DECLINED') {
                $pdo->beginTransaction();
                $ticketService->releaseReservedTicketsForOrder($pdo, $orderId);
                $this->updatePaymentFailed($pdo, $paymentRecordId, $fibResult, 'Declined by FIB');
                $this->updateOrderStatus($pdo, $orderId, 'cancelled');
                $pdo->commit();
            }
        } catch (Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private function updatePaymentSuccess(PDO $pdo, int $paymentRecordId, array $fibResult): void
    {
        $statement = $pdo->prepare(
            'UPDATE payments
             SET status = :status,
                 paid_at = NOW(),
                 failed_reason = NULL,
                 gateway_response = :gateway_response,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            ':status' => 'success',
            ':gateway_response' => json_encode($fibResult['raw'] ?? $fibResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id' => $paymentRecordId,
        ]);
    }

    private function updatePaymentFailed(PDO $pdo, int $paymentRecordId, array $fibResult, string $reason): void
    {
        $statement = $pdo->prepare(
            'UPDATE payments
             SET status = :status,
                 failed_reason = :failed_reason,
                 gateway_response = :gateway_response,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            ':status' => 'failed',
            ':failed_reason' => $reason,
            ':gateway_response' => json_encode($fibResult['raw'] ?? $fibResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id' => $paymentRecordId,
        ]);
    }

    private function updateOrderStatus(PDO $pdo, int $orderId, string $status): void
    {
        $statement = $pdo->prepare(
            'UPDATE orders
             SET status = :status,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            ':status' => $status,
            ':id' => $orderId,
        ]);
    }

    private function updateOrderForRetry(PDO $pdo, int $orderId): void
    {
        $statement = $pdo->prepare(
            'UPDATE orders
             SET status = :status,
                 expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE),
                 updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            ':status' => 'pending',
            ':id' => $orderId,
        ]);
    }

    private function insertPaymentTracking(PDO $pdo, int $orderId, string $invoiceNumber, float $amount, array $fibPayment): int
    {
        $gatewayTransactionId = trim((string) ($fibPayment['paymentId'] ?? ''));

        if ($gatewayTransactionId === '') {
            throw new RuntimeException('FIB payment ID was not returned.');
        }

        $statement = $pdo->prepare(
            'INSERT INTO payments (
                order_id,
                type,
                invoice_number,
                gateway_name,
                gateway_transaction_id,
                amount,
                status,
                gateway_response
            ) VALUES (
                :order_id,
                :type,
                :invoice_number,
                :gateway_name,
                :gateway_transaction_id,
                :amount,
                :status,
                :gateway_response
            )'
        );

        $statement->execute([
            ':order_id' => $orderId,
            ':type' => 'online',
            ':invoice_number' => $invoiceNumber,
            ':gateway_name' => 'FIB',
            ':gateway_transaction_id' => $gatewayTransactionId,
            ':amount' => number_format($amount, 2, '.', ''),
            ':status' => 'pending',
            ':gateway_response' => json_encode($fibPayment['raw'] ?? $fibPayment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return (int) $pdo->lastInsertId();
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

    private function normalizeOrderNumber(mixed $value): string
    {
        $orderNumber = strtoupper(trim((string) $value));

        if ($orderNumber === '') {
            throw new RuntimeException('The order_number field is required.');
        }

        if (mb_strlen($orderNumber) > 100) {
            throw new RuntimeException('The order_number field is too long.');
        }

        return $orderNumber;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $passengers
     * @return array<string, string>
     */
    private function normalizePassengerPayload(array $passengers): array
    {
        $normalizedPassengers = [];

        foreach ($passengers as $passenger) {
            if (!is_array($passenger)) {
                throw new RuntimeException('Each passenger update must be an object.');
            }

            $ticketCode = trim((string) ($passenger['ticket_code'] ?? ''));
            $passengerName = trim((string) ($passenger['passenger_name'] ?? ''));

            if ($ticketCode === '' || $passengerName === '') {
                throw new RuntimeException('Each passenger update requires ticket_code and passenger_name.');
            }

            if (mb_strlen($ticketCode) > 36) {
                throw new RuntimeException('A ticket_code value is too long.');
            }

            if (mb_strlen($passengerName) > 255) {
                throw new RuntimeException('A passenger_name value is too long.');
            }

            $normalizedPassengers[$ticketCode] = $passengerName;
        }

        return $normalizedPassengers;
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

        if ($orderStatus === 'expired') {
            return 'expired';
        }

        return 'awaiting_payment';
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

    private function maskEmail(?string $email): ?string
    {
        if ($email === null || !str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        if ($local === '') {
            return '***@' . $domain;
        }

        $visible = substr($local, 0, 1);

        return $visible . str_repeat('*', max(2, strlen($local) - 1)) . '@' . $domain;
    }

    private function maskPhone(string $phone): string
    {
        $length = strlen($phone);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4) . substr($phone, -4);
    }

    /**
     * @param array<string, mixed> $ticket
     * @return array<string, mixed>
     */
    private function mapPrintablePass(array $ticket, string $orderNumber, string $customerName): array
    {
        $ticketCode = (string) ($ticket['ticketCode'] ?? '');

        return [
            'ticketCode' => $ticketCode,
            'qrPayload' => $this->buildQrPayload($ticketCode),
            'display' => [
                'title' => (string) ($ticket['eventTitleText'] ?? 'Event Pass'),
                'subtitle' => (string) ($ticket['ticketTitleText'] ?? 'Ticket'),
                'passengerName' => $ticket['passengerName'] !== null ? (string) $ticket['passengerName'] : $customerName,
                'eventDate' => $ticket['eventDate'] ?? null,
                'subEventTitle' => $ticket['subEventTitleText'] ?? null,
                'status' => $ticket['status'] ?? null,
                'orderNumber' => $orderNumber,
            ],
        ];
    }

    private function buildQrPayload(string $ticketCode): string
    {
        return 'NUKHBAGLOBAL:TICKET:' . $ticketCode;
    }
}
