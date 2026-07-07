<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class TicketService
{
    /**
     * @param array<int, array{ticket_id:int,quantity:int}> $items
     * @return array{items:array<int, array{ticket_id:int,quantity:int,price_per_item:float}>,tickets_total_amount:float}
     */
    public function prepareCheckoutItems(PDO $pdo, array $items): array
    {
        if ($items === []) {
            return [
                'items' => [],
                'tickets_total_amount' => 0.0,
            ];
        }

        $groupedItems = $this->groupItemsByTicketId($items);
        $ticketIds = array_keys($groupedItems);
        $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
        $statement = $pdo->prepare(
            'SELECT
                id,
                price,
                capacity,
                reserved_count,
                sold_count,
                max_per_user,
                available_from,
                available_until,
                status,
                deleted_at
             FROM tickets
             WHERE id IN (' . $placeholders . ')
             FOR UPDATE'
        );
        $statement->execute($ticketIds);

        /** @var array<int, array<string, mixed>> $ticketRows */
        $ticketRows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $ticketMap = [];

        foreach ($ticketRows as $ticketRow) {
            $ticketMap[(int) $ticketRow['id']] = $ticketRow;
        }

        if (count($ticketMap) !== count($groupedItems)) {
            throw new RuntimeException('One or more selected tickets were not found.');
        }

        $preparedItems = [];
        $ticketsTotalAmount = 0.0;
        $currentTimestamp = time();

        foreach ($groupedItems as $ticketId => $quantity) {
            $ticket = $ticketMap[$ticketId];
            $status = (int) ($ticket['status'] ?? 0);
            $deletedAt = $ticket['deleted_at'] ?? null;

            if ($status !== 1 || $deletedAt !== null) {
                throw new RuntimeException(sprintf('Ticket %d is not available for sale.', $ticketId));
            }

            $availableFrom = $this->toTimestamp($ticket['available_from'] ?? null);
            $availableUntil = $this->toTimestamp($ticket['available_until'] ?? null);

            if ($availableFrom !== null && $currentTimestamp < $availableFrom) {
                throw new RuntimeException(sprintf('Ticket %d is not yet available.', $ticketId));
            }

            if ($availableUntil !== null && $currentTimestamp > $availableUntil) {
                throw new RuntimeException(sprintf('Ticket %d is no longer available.', $ticketId));
            }

            $maxPerUser = (int) ($ticket['max_per_user'] ?? 0);

            if ($maxPerUser > 0 && $quantity > $maxPerUser) {
                throw new RuntimeException(sprintf('Ticket %d exceeds the maximum quantity per order.', $ticketId));
            }

            $capacity = (int) ($ticket['capacity'] ?? 0);
            $reservedCount = (int) ($ticket['reserved_count'] ?? 0);
            $soldCount = (int) ($ticket['sold_count'] ?? 0);
            $remaining = $capacity - $reservedCount - $soldCount;

            if ($remaining < $quantity) {
                throw new RuntimeException(sprintf('Ticket %d does not have enough remaining capacity.', $ticketId));
            }

            $pricePerItem = round((float) ($ticket['price'] ?? 0), 2);
            $preparedItems[] = [
                'ticket_id' => $ticketId,
                'quantity' => $quantity,
                'price_per_item' => $pricePerItem,
            ];
            $ticketsTotalAmount += $quantity * $pricePerItem;
        }

        return [
            'items' => $preparedItems,
            'tickets_total_amount' => round($ticketsTotalAmount, 2),
        ];
    }

    /**
     * @param array<int, array{ticket_id:int,quantity:int,price_per_item:float}> $items
     */
    public function reserveTickets(PDO $pdo, array $items): void
    {
        if ($items === []) {
            return;
        }

        $statement = $pdo->prepare(
            'UPDATE tickets
             SET reserved_count = reserved_count + :quantity,
                 updated_at = NOW()
             WHERE id = :id'
        );

        foreach ($items as $item) {
            $statement->execute([
                ':quantity' => $item['quantity'],
                ':id' => $item['ticket_id'],
            ]);
        }
    }

    public function releaseReservedTicketsForOrder(PDO $pdo, int $orderId): void
    {
        $items = $this->fetchOrderItemsForUpdate($pdo, $orderId);

        if ($items === []) {
            return;
        }

        $statement = $pdo->prepare(
            'UPDATE tickets
             SET reserved_count = GREATEST(reserved_count - :quantity, 0),
                 updated_at = NOW()
             WHERE id = :id'
        );

        foreach ($items as $item) {
            $statement->execute([
                ':quantity' => $item['quantity'],
                ':id' => $item['ticket_id'],
            ]);
        }
    }

    /**
     * @return array<int, array{ticketId:int,ticketCode:string,status:string,passengerName:?string}>
     */
    public function issueTicketsForPaidOrder(PDO $pdo, int $orderId, int $paymentId): array
    {
        $existingTickets = $this->getIssuedTicketsByPayment($pdo, $paymentId);

        if ($existingTickets !== []) {
            return $existingTickets;
        }

        $items = $this->fetchOrderItemsForUpdate($pdo, $orderId);

        if ($items === []) {
            return [];
        }

        $insertStatement = $pdo->prepare(
            'INSERT INTO event_tickets (
                order_id,
                ticket_id,
                payment_id,
                passenger_name,
                ticket_code,
                status
            ) VALUES (
                :order_id,
                :ticket_id,
                :payment_id,
                :passenger_name,
                :ticket_code,
                :status
            )'
        );
        $updateInventoryStatement = $pdo->prepare(
            'UPDATE tickets
             SET reserved_count = GREATEST(reserved_count - :reserved_quantity, 0),
                 sold_count = sold_count + :sold_quantity,
                 updated_at = NOW()
             WHERE id = :id'
        );

        foreach ($items as $item) {
            for ($index = 0; $index < $item['quantity']; $index++) {
                $insertStatement->execute([
                    ':order_id' => $orderId,
                    ':ticket_id' => $item['ticket_id'],
                    ':payment_id' => $paymentId,
                    ':passenger_name' => null,
                    ':ticket_code' => $this->generateTicketCode(),
                    ':status' => 'valid',
                ]);
            }

            $updateInventoryStatement->execute([
                ':reserved_quantity' => $item['quantity'],
                ':sold_quantity' => $item['quantity'],
                ':id' => $item['ticket_id'],
            ]);
        }

        return $this->getIssuedTicketsByPayment($pdo, $paymentId);
    }

    /**
     * @return array<int, array{ticketId:int,ticketCode:string,status:string,passengerName:?string}>
     */
    public function getIssuedTicketsByPayment(PDO $pdo, int $paymentId): array
    {
        $statement = $pdo->prepare(
            'SELECT
                ticket_id,
                ticket_code,
                status,
                passenger_name
             FROM event_tickets
             WHERE payment_id = :payment_id
             ORDER BY id ASC'
        );
        $statement->execute([
            ':payment_id' => $paymentId,
        ]);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $issuedTickets = [];

        foreach ($rows as $row) {
            $issuedTickets[] = [
                'ticketId' => (int) $row['ticket_id'],
                'ticketCode' => (string) $row['ticket_code'],
                'status' => (string) $row['status'],
                'passengerName' => $row['passenger_name'] !== null ? (string) $row['passenger_name'] : null,
            ];
        }

        return $issuedTickets;
    }

    /**
     * @return array<int, array{ticketId:int,ticketCode:string,status:string,passengerName:?string}>
     */
    public function getIssuedTicketsByOrder(PDO $pdo, int $orderId): array
    {
        $statement = $pdo->prepare(
            'SELECT
                ticket_id,
                ticket_code,
                status,
                passenger_name
             FROM event_tickets
             WHERE order_id = :order_id
             ORDER BY id ASC'
        );
        $statement->execute([
            ':order_id' => $orderId,
        ]);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $issuedTickets = [];

        foreach ($rows as $row) {
            $issuedTickets[] = [
                'ticketId' => (int) $row['ticket_id'],
                'ticketCode' => (string) $row['ticket_code'],
                'status' => (string) $row['status'],
                'passengerName' => $row['passenger_name'] !== null ? (string) $row['passenger_name'] : null,
            ];
        }

        return $issuedTickets;
    }

    /**
     * @return array<int, array{ticketId:int,ticketCode:string,status:string,passengerName:?string}>
     */
    public function refundIssuedTicketsForOrder(PDO $pdo, int $orderId): array
    {
        $statement = $pdo->prepare(
            'SELECT
                id,
                ticket_id,
                ticket_code,
                status,
                passenger_name
             FROM event_tickets
             WHERE order_id = :order_id
             ORDER BY id ASC
             FOR UPDATE'
        );
        $statement->execute([
            ':order_id' => $orderId,
        ]);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return [];
        }

        $inventoryByTicketId = [];

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');

            if ($status === 'used') {
                throw new RuntimeException('Used tickets cannot be refunded.');
            }

            if ($status === 'refunded') {
                continue;
            }

            $ticketId = (int) $row['ticket_id'];
            $inventoryByTicketId[$ticketId] = ($inventoryByTicketId[$ticketId] ?? 0) + 1;
        }

        if ($inventoryByTicketId !== []) {
            $updateTicketsStatement = $pdo->prepare(
                'UPDATE tickets
                 SET sold_count = GREATEST(sold_count - :quantity, 0),
                     updated_at = NOW()
                 WHERE id = :id'
            );

            foreach ($inventoryByTicketId as $ticketId => $quantity) {
                $updateTicketsStatement->execute([
                    ':quantity' => $quantity,
                    ':id' => $ticketId,
                ]);
            }

            $updateIssuedTicketsStatement = $pdo->prepare(
                'UPDATE event_tickets
                 SET status = :status,
                     updated_at = NOW()
                 WHERE order_id = :order_id
                   AND status <> :refunded_status'
            );
            $updateIssuedTicketsStatement->execute([
                ':status' => 'refunded',
                ':order_id' => $orderId,
                ':refunded_status' => 'refunded',
            ]);
        }

        return $this->getIssuedTicketsByOrder($pdo, $orderId);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateIssuedTicketStatus(PDO $pdo, int $orderId, int $eventTicketId, string $action): array
    {
        if (!in_array($action, ['refund', 'cancel'], true)) {
            throw new RuntimeException('Ticket action is invalid.');
        }

        $statement = $pdo->prepare(
            'SELECT
                et.id,
                et.order_id,
                et.ticket_id,
                et.ticket_code,
                et.status,
                oi.price_per_item,
                o.status AS order_status,
                o.tickets_total_amount,
                o.donation_amount,
                o.total_amount,
                p.id AS payment_record_id,
                p.amount AS payment_amount,
                p.status AS payment_status,
                p.refund_amount
             FROM event_tickets et
             INNER JOIN orders o ON o.id = et.order_id
             LEFT JOIN order_items oi ON oi.order_id = et.order_id AND oi.ticket_id = et.ticket_id
             LEFT JOIN payments p ON p.order_id = et.order_id AND p.deleted_at IS NULL
             WHERE et.id = :event_ticket_id
               AND et.order_id = :order_id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute([
            ':event_ticket_id' => $eventTicketId,
            ':order_id' => $orderId,
        ]);

        /** @var array<string, mixed>|false $ticket */
        $ticket = $statement->fetch(PDO::FETCH_ASSOC);

        if ($ticket === false) {
            throw new RuntimeException('Issued ticket was not found for this order.');
        }

        $currentStatus = (string) ($ticket['status'] ?? '');
        $paymentStatus = $ticket['payment_status'] !== null ? (string) $ticket['payment_status'] : null;
        $pricePerItem = round((float) ($ticket['price_per_item'] ?? 0), 2);

        if ($currentStatus === 'used') {
            throw new RuntimeException('Used tickets cannot be changed.');
        }

        if ($currentStatus === 'refunded') {
            throw new RuntimeException('This ticket is already refunded.');
        }

        if ($currentStatus === 'cancelled') {
            throw new RuntimeException('This ticket is already cancelled.');
        }

        if ($action === 'refund') {
            if (!in_array((string) ($ticket['order_status'] ?? ''), ['paid', 'completed'], true) || $paymentStatus !== 'success') {
                throw new RuntimeException('Only paid tickets can be refunded.');
            }
        }

        $newStatus = $action === 'refund' ? 'refunded' : 'cancelled';

        $updateIssuedTicketStatement = $pdo->prepare(
            'UPDATE event_tickets
             SET status = :status,
                 updated_at = NOW()
             WHERE id = :event_ticket_id
               AND order_id = :order_id'
        );
        $updateIssuedTicketStatement->execute([
            ':status' => $newStatus,
            ':event_ticket_id' => $eventTicketId,
            ':order_id' => $orderId,
        ]);

        $updateInventoryStatement = $pdo->prepare(
            'UPDATE tickets
             SET sold_count = GREATEST(sold_count - 1, 0),
                 updated_at = NOW()
             WHERE id = :ticket_id'
        );
        $updateInventoryStatement->execute([
            ':ticket_id' => (int) $ticket['ticket_id'],
        ]);

        if ($action === 'refund') {
            $this->applyPartialRefundAdjustments(
                $pdo,
                $orderId,
                $ticket['payment_record_id'] !== null ? (int) $ticket['payment_record_id'] : null,
                $pricePerItem,
                round((float) ($ticket['payment_amount'] ?? 0), 2),
                round((float) ($ticket['refund_amount'] ?? 0), 2)
            );
        }

        $summary = $this->recalculateOrderStatusSummary($pdo, $orderId);

        return [
            'eventTicketId' => $eventTicketId,
            'ticketCode' => (string) ($ticket['ticket_code'] ?? ''),
            'oldStatus' => $currentStatus,
            'newStatus' => $newStatus,
            'pricePerItem' => $pricePerItem,
            'orderStatus' => $summary['order_status'],
            'paymentStatus' => $summary['payment_status'],
            'ticketsTotalAmount' => $summary['tickets_total_amount'],
            'totalAmount' => $summary['total_amount'],
            'refundAmount' => $summary['refund_amount'],
        ];
    }

    /**
     * @return array{order_status:string,payment_status:?string,tickets_total_amount:float,total_amount:float,refund_amount:float}
     */
    public function syncOrderStatusSummary(PDO $pdo, int $orderId): array
    {
        return $this->recalculateOrderStatusSummary($pdo, $orderId);
    }

    /**
     * @param array<int, array{ticket_id:int,quantity:int}> $items
     * @return array<int, int>
     */
    private function groupItemsByTicketId(array $items): array
    {
        $groupedItems = [];

        foreach ($items as $item) {
            $ticketId = (int) $item['ticket_id'];
            $quantity = (int) $item['quantity'];
            $groupedItems[$ticketId] = ($groupedItems[$ticketId] ?? 0) + $quantity;
        }

        return $groupedItems;
    }

    /**
     * @return array<int, array{ticket_id:int,quantity:int}>
     */
    private function fetchOrderItemsForUpdate(PDO $pdo, int $orderId): array
    {
        $statement = $pdo->prepare(
            'SELECT ticket_id, quantity
             FROM order_items
             WHERE order_id = :order_id
             ORDER BY id ASC
             FOR UPDATE'
        );
        $statement->execute([
            ':order_id' => $orderId,
        ]);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $items = [];

        foreach ($rows as $row) {
            $items[] = [
                'ticket_id' => (int) $row['ticket_id'],
                'quantity' => (int) $row['quantity'],
            ];
        }

        return $items;
    }

    private function applyPartialRefundAdjustments(
        PDO $pdo,
        int $orderId,
        ?int $paymentRecordId,
        float $refundAmount,
        float $paymentAmount,
        float $existingRefundAmount
    ): void {
        $orderUpdateStatement = $pdo->prepare(
            'UPDATE orders
             SET tickets_total_amount = GREATEST(tickets_total_amount - :tickets_refund_amount, 0),
                 total_amount = GREATEST(total_amount - :total_refund_amount, donation_amount),
                 updated_at = NOW()
             WHERE id = :order_id'
        );
        $orderUpdateStatement->execute([
            ':tickets_refund_amount' => number_format($refundAmount, 2, '.', ''),
            ':total_refund_amount' => number_format($refundAmount, 2, '.', ''),
            ':order_id' => $orderId,
        ]);

        if ($paymentRecordId === null || $paymentRecordId <= 0) {
            return;
        }

        $nextRefundAmount = round($existingRefundAmount + $refundAmount, 2);
        $nextStatus = $nextRefundAmount + 0.00001 >= $paymentAmount ? 'refunded' : 'success';

        $paymentUpdateStatement = $pdo->prepare(
            'UPDATE payments
             SET status = :status,
                 refund_amount = :refund_amount,
                 refunded_at = NOW(),
                 updated_at = NOW()
             WHERE id = :payment_record_id'
        );
        $paymentUpdateStatement->execute([
            ':status' => $nextStatus,
            ':refund_amount' => number_format($nextRefundAmount, 2, '.', ''),
            ':payment_record_id' => $paymentRecordId,
        ]);
    }

    /**
     * @return array{order_status:string,payment_status:?string,tickets_total_amount:float,total_amount:float,refund_amount:float}
     */
    private function recalculateOrderStatusSummary(PDO $pdo, int $orderId): array
    {
        $summaryStatement = $pdo->prepare(
            'SELECT
                o.status AS current_order_status,
                o.tickets_total_amount,
                o.total_amount,
                p.status AS payment_status,
                COALESCE(p.refund_amount, 0) AS refund_amount,
                SUM(CASE WHEN et.status = "valid" THEN 1 ELSE 0 END) AS valid_count,
                SUM(CASE WHEN et.status = "used" THEN 1 ELSE 0 END) AS used_count,
                SUM(CASE WHEN et.status = "cancelled" THEN 1 ELSE 0 END) AS cancelled_count,
                SUM(CASE WHEN et.status = "refunded" THEN 1 ELSE 0 END) AS refunded_count
             FROM orders o
             LEFT JOIN payments p ON p.order_id = o.id AND p.deleted_at IS NULL
             LEFT JOIN event_tickets et ON et.order_id = o.id
             WHERE o.id = :order_id
             GROUP BY o.id, o.status, o.tickets_total_amount, o.total_amount, p.status, p.refund_amount
             LIMIT 1'
        );
        $summaryStatement->execute([
            ':order_id' => $orderId,
        ]);

        /** @var array<string, mixed>|false $summary */
        $summary = $summaryStatement->fetch(PDO::FETCH_ASSOC);

        if ($summary === false) {
            throw new RuntimeException('Order summary could not be recalculated.');
        }

        $validCount = (int) ($summary['valid_count'] ?? 0);
        $usedCount = (int) ($summary['used_count'] ?? 0);
        $cancelledCount = (int) ($summary['cancelled_count'] ?? 0);
        $refundedCount = (int) ($summary['refunded_count'] ?? 0);
        $currentOrderStatus = (string) ($summary['current_order_status'] ?? 'completed');

        if ($validCount > 0) {
            $orderStatus = in_array($currentOrderStatus, ['paid', 'completed'], true)
                ? $currentOrderStatus
                : 'completed';
        } elseif ($usedCount > 0) {
            $orderStatus = 'completed';
        } else {
            $orderStatus = 'completed';
            if ($refundedCount > 0 && $cancelledCount === 0) {
                $orderStatus = 'refunded';
            } elseif ($cancelledCount > 0) {
                $orderStatus = 'cancelled';
            }
        }

        $updateOrderStatusStatement = $pdo->prepare(
            'UPDATE orders
             SET status = :status,
                 updated_at = NOW()
             WHERE id = :order_id'
        );
        $updateOrderStatusStatement->execute([
            ':status' => $orderStatus,
            ':order_id' => $orderId,
        ]);

        return [
            'order_status' => $orderStatus,
            'payment_status' => $summary['payment_status'] !== null ? (string) $summary['payment_status'] : null,
            'tickets_total_amount' => round((float) ($summary['tickets_total_amount'] ?? 0), 2),
            'total_amount' => round((float) ($summary['total_amount'] ?? 0), 2),
            'refund_amount' => round((float) ($summary['refund_amount'] ?? 0), 2),
        ];
    }

    private function toTimestamp(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    private function generateTicketCode(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($bytes), 4)
        );
    }
}
