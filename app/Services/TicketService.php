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
