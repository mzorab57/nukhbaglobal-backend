<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class ReportingService
{
    /**
     * @return array<string, mixed>
     */
    public function getOverview(PDO $pdo): array
    {
        $ordersSummary = $pdo->query(
            'SELECT
                COUNT(*) AS total_orders,
                SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) AS pending_orders,
                SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END) AS paid_orders,
                SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) AS completed_orders,
                SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) AS cancelled_orders,
                COALESCE(SUM(total_amount), 0) AS gross_order_amount
             FROM orders
             WHERE deleted_at IS NULL'
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $paymentsSummary = $pdo->query(
            'SELECT
                COUNT(*) AS total_payments,
                SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) AS pending_payments,
                SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END) AS successful_payments,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) AS failed_payments,
                SUM(CASE WHEN status = "refunded" THEN 1 ELSE 0 END) AS refunded_payments,
                COALESCE(SUM(CASE WHEN status = "success" THEN amount ELSE 0 END), 0) AS successful_payment_amount,
                COALESCE(SUM(CASE WHEN status = "pending" THEN amount ELSE 0 END), 0) AS pending_payment_amount
             FROM payments
             WHERE deleted_at IS NULL'
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $ticketsSummary = $pdo->query(
            'SELECT
                COUNT(*) AS total_issued_tickets,
                SUM(CASE WHEN status = "valid" THEN 1 ELSE 0 END) AS valid_tickets,
                SUM(CASE WHEN status = "used" THEN 1 ELSE 0 END) AS used_tickets,
                SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) AS cancelled_tickets,
                SUM(CASE WHEN status = "refunded" THEN 1 ELSE 0 END) AS refunded_tickets
             FROM event_tickets'
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $recentOrdersStatement = $pdo->query(
            'SELECT
                o.id,
                o.order_number,
                o.customer_name,
                o.total_amount,
                o.status,
                o.created_at,
                p.status AS payment_status,
                p.gateway_name
             FROM orders o
             LEFT JOIN payments p ON p.order_id = o.id AND p.deleted_at IS NULL
             WHERE o.deleted_at IS NULL
             ORDER BY o.id DESC
             LIMIT 5'
        );
        $recentOrders = $recentOrdersStatement->fetchAll(PDO::FETCH_ASSOC);

        return [
            'orders' => [
                'total' => (int) ($ordersSummary['total_orders'] ?? 0),
                'pending' => (int) ($ordersSummary['pending_orders'] ?? 0),
                'paid' => (int) ($ordersSummary['paid_orders'] ?? 0),
                'completed' => (int) ($ordersSummary['completed_orders'] ?? 0),
                'cancelled' => (int) ($ordersSummary['cancelled_orders'] ?? 0),
                'grossAmount' => round((float) ($ordersSummary['gross_order_amount'] ?? 0), 2),
            ],
            'payments' => [
                'total' => (int) ($paymentsSummary['total_payments'] ?? 0),
                'pending' => (int) ($paymentsSummary['pending_payments'] ?? 0),
                'success' => (int) ($paymentsSummary['successful_payments'] ?? 0),
                'failed' => (int) ($paymentsSummary['failed_payments'] ?? 0),
                'refunded' => (int) ($paymentsSummary['refunded_payments'] ?? 0),
                'successfulAmount' => round((float) ($paymentsSummary['successful_payment_amount'] ?? 0), 2),
                'pendingAmount' => round((float) ($paymentsSummary['pending_payment_amount'] ?? 0), 2),
            ],
            'tickets' => [
                'issued' => (int) ($ticketsSummary['total_issued_tickets'] ?? 0),
                'valid' => (int) ($ticketsSummary['valid_tickets'] ?? 0),
                'used' => (int) ($ticketsSummary['used_tickets'] ?? 0),
                'cancelled' => (int) ($ticketsSummary['cancelled_tickets'] ?? 0),
                'refunded' => (int) ($ticketsSummary['refunded_tickets'] ?? 0),
            ],
            'recentOrders' => array_map(
                static fn(array $row): array => [
                    'id' => (int) $row['id'],
                    'orderNumber' => (string) $row['order_number'],
                    'customerName' => (string) $row['customer_name'],
                    'totalAmount' => round((float) $row['total_amount'], 2),
                    'orderStatus' => (string) $row['status'],
                    'paymentStatus' => $row['payment_status'] !== null ? (string) $row['payment_status'] : null,
                    'gatewayName' => $row['gateway_name'] !== null ? (string) $row['gateway_name'] : null,
                    'createdAt' => (string) $row['created_at'],
                ],
                $recentOrders
            ),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getOrders(PDO $pdo, array $filters): array
    {
        $whereClauses = ['o.deleted_at IS NULL'];
        $parameters = [];

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $whereClauses[] = 'o.status = :status';
            $parameters[':status'] = $status;
        }

        $paymentStatus = trim((string) ($filters['payment_status'] ?? ''));
        if ($paymentStatus !== '') {
            $whereClauses[] = 'p.status = :payment_status';
            $parameters[':payment_status'] = $paymentStatus;
        }

        $query = trim((string) ($filters['query'] ?? ''));
        if ($query !== '') {
            $queryValue = '%' . $query . '%';
            $whereClauses[] = '(
                o.order_number LIKE :query_order_number
                OR o.customer_name LIKE :query_customer_name
                OR o.customer_phone LIKE :query_customer_phone
                OR o.customer_email LIKE :query_customer_email
                OR p.gateway_transaction_id LIKE :query_gateway_transaction_id
            )';
            $parameters[':query_order_number'] = $queryValue;
            $parameters[':query_customer_name'] = $queryValue;
            $parameters[':query_customer_phone'] = $queryValue;
            $parameters[':query_customer_email'] = $queryValue;
            $parameters[':query_gateway_transaction_id'] = $queryValue;
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $whereClauses[] = 'DATE(o.created_at) >= :date_from';
            $parameters[':date_from'] = $dateFrom;
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $whereClauses[] = 'DATE(o.created_at) <= :date_to';
            $parameters[':date_to'] = $dateTo;
        }

        $whereSql = implode(' AND ', $whereClauses);
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $countStatement = $pdo->prepare(
            'SELECT COUNT(DISTINCT o.id)
             FROM orders o
             LEFT JOIN payments p ON p.order_id = o.id AND p.deleted_at IS NULL
             WHERE ' . $whereSql
        );
        foreach ($parameters as $key => $value) {
            $countStatement->bindValue($key, $value);
        }
        $countStatement->execute();
        $total = (int) $countStatement->fetchColumn();

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
                o.status,
                o.created_at,
                p.id AS payment_record_id,
                p.invoice_number,
                p.gateway_name,
                p.gateway_transaction_id,
                p.status AS payment_status,
                p.paid_at,
                COALESCE(oi_aggregate.items_count, 0) AS items_count,
                COALESCE(oi_aggregate.quantity_count, 0) AS quantity_count,
                COALESCE(et_aggregate.issued_tickets_count, 0) AS issued_tickets_count
             FROM orders o
             LEFT JOIN payments p ON p.order_id = o.id AND p.deleted_at IS NULL
             LEFT JOIN (
                SELECT
                    order_id,
                    COUNT(*) AS items_count,
                    COALESCE(SUM(quantity), 0) AS quantity_count
                FROM order_items
                GROUP BY order_id
             ) oi_aggregate ON oi_aggregate.order_id = o.id
             LEFT JOIN (
                SELECT
                    order_id,
                    COUNT(*) AS issued_tickets_count
                FROM event_tickets
                GROUP BY order_id
             ) et_aggregate ON et_aggregate.order_id = o.id
             WHERE ' . $whereSql . '
             ORDER BY o.id DESC
             LIMIT :limit OFFSET :offset'
        );

        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => array_map([$this, 'mapOrderListRow'], $rows),
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'totalPages' => max(1, (int) ceil($total / $perPage)),
            ],
            'filters' => [
                'status' => $status !== '' ? $status : null,
                'paymentStatus' => $paymentStatus !== '' ? $paymentStatus : null,
                'query' => $query !== '' ? $query : null,
                'dateFrom' => $dateFrom !== '' ? $dateFrom : null,
                'dateTo' => $dateTo !== '' ? $dateTo : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOrderDetails(PDO $pdo, int $orderId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT
                o.id,
                o.order_number,
                o.customer_name,
                o.customer_email,
                o.customer_phone,
                o.customer_address,
                o.tickets_total_amount,
                o.donation_amount,
                o.total_amount,
                o.status,
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
                p.webhook_verified,
                p.paid_at,
                p.failed_reason,
                p.refunded_at,
                p.refund_amount,
                p.refund_reference
             FROM orders o
             LEFT JOIN payments p ON p.order_id = o.id AND p.deleted_at IS NULL
             WHERE o.id = :order_id
               AND o.deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            ':order_id' => $orderId,
        ]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $itemsStatement = $pdo->prepare(
            'SELECT
                oi.id,
                oi.ticket_id,
                oi.quantity,
                oi.price_per_item,
                t.title AS ticket_title,
                se.id AS sub_event_id,
                se.title AS sub_event_title,
                ci.id AS city_id,
                ci.name AS city_name,
                e.id AS event_id,
                e.title AS event_title,
                e.date AS event_date
             FROM order_items oi
             INNER JOIN tickets t ON t.id = oi.ticket_id
             INNER JOIN events e ON e.id = t.event_id
             LEFT JOIN sub_events se ON se.id = t.sub_event_id AND se.deleted_at IS NULL
             LEFT JOIN cities ci ON ci.id = se.city_id
             WHERE oi.order_id = :order_id
             ORDER BY oi.id ASC'
        );
        $itemsStatement->execute([
            ':order_id' => $orderId,
        ]);
        $items = $itemsStatement->fetchAll(PDO::FETCH_ASSOC);

        $issuedTicketsStatement = $pdo->prepare(
            'SELECT
                et.id,
                et.ticket_id,
                et.ticket_code,
                et.passenger_name,
                et.status,
                et.scanned_at,
                et.created_at,
                oi.price_per_item,
                se.id AS sub_event_id,
                se.title AS sub_event_title,
                ci.id AS city_id,
                ci.name AS city_name,
                scanner.id AS scanned_by_id,
                scanner.name AS scanned_by_name
             FROM event_tickets et
             LEFT JOIN order_items oi ON oi.order_id = et.order_id AND oi.ticket_id = et.ticket_id
             LEFT JOIN tickets t ON t.id = et.ticket_id
             LEFT JOIN sub_events se ON se.id = t.sub_event_id AND se.deleted_at IS NULL
             LEFT JOIN cities ci ON ci.id = se.city_id
             LEFT JOIN users scanner ON scanner.id = et.scanned_by
             WHERE et.order_id = :order_id
             ORDER BY et.id ASC'
        );
        $issuedTicketsStatement->execute([
            ':order_id' => $orderId,
        ]);
        $issuedTickets = $issuedTicketsStatement->fetchAll(PDO::FETCH_ASSOC);

        return [
            'order' => [
                'id' => (int) $row['id'],
                'orderNumber' => (string) $row['order_number'],
                'status' => (string) $row['status'],
                'customer' => [
                    'name' => (string) $row['customer_name'],
                    'email' => $row['customer_email'] !== null ? (string) $row['customer_email'] : null,
                    'phone' => (string) $row['customer_phone'],
                    'address' => $row['customer_address'] !== null ? (string) $row['customer_address'] : null,
                ],
                'amounts' => [
                    'tickets' => round((float) $row['tickets_total_amount'], 2),
                    'donation' => round((float) $row['donation_amount'], 2),
                    'total' => round((float) $row['total_amount'], 2),
                ],
                'expiresAt' => $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
                'createdAt' => (string) $row['created_at'],
                'updatedAt' => (string) $row['updated_at'],
            ],
            'payment' => [
                'id' => $row['payment_record_id'] !== null ? (int) $row['payment_record_id'] : null,
                'type' => $row['payment_type'] !== null ? (string) $row['payment_type'] : null,
                'invoiceNumber' => $row['invoice_number'] !== null ? (string) $row['invoice_number'] : null,
                'gatewayName' => $row['gateway_name'] !== null ? (string) $row['gateway_name'] : null,
                'gatewayTransactionId' => $row['gateway_transaction_id'] !== null ? (string) $row['gateway_transaction_id'] : null,
                'amount' => $row['payment_amount'] !== null ? round((float) $row['payment_amount'], 2) : null,
                'status' => $row['payment_status'] !== null ? (string) $row['payment_status'] : null,
                'webhookVerified' => (int) ($row['webhook_verified'] ?? 0) === 1,
                'paidAt' => $row['paid_at'] !== null ? (string) $row['paid_at'] : null,
                'failedReason' => $row['failed_reason'] !== null ? (string) $row['failed_reason'] : null,
                'refundedAt' => $row['refunded_at'] !== null ? (string) $row['refunded_at'] : null,
                'refundAmount' => $row['refund_amount'] !== null ? round((float) $row['refund_amount'], 2) : null,
                'refundReference' => $row['refund_reference'] !== null ? (string) $row['refund_reference'] : null,
            ],
            'items' => array_map([$this, 'mapOrderItemRow'], $items),
            'issuedTickets' => array_map([$this, 'mapIssuedTicketRow'], $issuedTickets),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getScanOverview(PDO $pdo): array
    {
        $summaryStatement = $pdo->query(
            'SELECT
                COUNT(*) AS total_scans,
                COUNT(DISTINCT user_id) AS unique_scanners,
                COUNT(DISTINCT record_id) AS unique_tickets_scanned,
                SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS scans_today,
                SUM(CASE WHEN YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1) THEN 1 ELSE 0 END) AS scans_this_week
             FROM activity_logs
             WHERE action = "scan"
               AND table_name = "event_tickets"'
        );
        $summary = $summaryStatement->fetch(PDO::FETCH_ASSOC) ?: [];

        $recentScansStatement = $pdo->query(
            'SELECT
                al.id,
                al.created_at,
                al.ip_address,
                al.user_agent,
                al.old_values,
                al.new_values,
                u.id AS scanner_id,
                u.name AS scanner_name,
                u.email AS scanner_email,
                u.role AS scanner_role,
                et.id AS event_ticket_id,
                et.ticket_id,
                et.ticket_code,
                et.status AS ticket_status,
                et.passenger_name,
                o.id AS order_id,
                o.order_number,
                o.customer_name,
                t.title AS ticket_title,
                e.id AS event_id,
                e.title AS event_title,
                e.date AS event_date
             FROM activity_logs al
             INNER JOIN users u ON u.id = al.user_id
             LEFT JOIN event_tickets et ON et.id = al.record_id AND al.table_name = "event_tickets"
             LEFT JOIN orders o ON o.id = et.order_id
             LEFT JOIN tickets t ON t.id = et.ticket_id
             LEFT JOIN events e ON e.id = t.event_id
             WHERE al.action = "scan"
               AND al.table_name = "event_tickets"
             ORDER BY al.id DESC
             LIMIT 10'
        );
        $recentScans = $recentScansStatement->fetchAll(PDO::FETCH_ASSOC);

        return [
            'summary' => [
                'totalScans' => (int) ($summary['total_scans'] ?? 0),
                'uniqueScanners' => (int) ($summary['unique_scanners'] ?? 0),
                'uniqueTicketsScanned' => (int) ($summary['unique_tickets_scanned'] ?? 0),
                'scansToday' => (int) ($summary['scans_today'] ?? 0),
                'scansThisWeek' => (int) ($summary['scans_this_week'] ?? 0),
            ],
            'recentScans' => array_map([$this, 'mapScanLogRow'], $recentScans),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getScanLogs(PDO $pdo, array $filters): array
    {
        $whereClauses = [
            'al.action = :action',
            'al.table_name = :table_name',
        ];
        $parameters = [
            ':action' => 'scan',
            ':table_name' => 'event_tickets',
        ];

        $scannerUserId = (int) ($filters['scanner_user_id'] ?? 0);
        if ($scannerUserId > 0) {
            $whereClauses[] = 'al.user_id = :scanner_user_id';
            $parameters[':scanner_user_id'] = $scannerUserId;
        }

        $query = trim((string) ($filters['query'] ?? ''));
        if ($query !== '') {
            $queryValue = '%' . $query . '%';
            $whereClauses[] = '(
                et.ticket_code LIKE :scan_query_ticket_code
                OR o.order_number LIKE :scan_query_order_number
                OR o.customer_name LIKE :scan_query_customer_name
                OR u.name LIKE :scan_query_scanner_name
            )';
            $parameters[':scan_query_ticket_code'] = $queryValue;
            $parameters[':scan_query_order_number'] = $queryValue;
            $parameters[':scan_query_customer_name'] = $queryValue;
            $parameters[':scan_query_scanner_name'] = $queryValue;
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $whereClauses[] = 'DATE(al.created_at) >= :scan_date_from';
            $parameters[':scan_date_from'] = $dateFrom;
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $whereClauses[] = 'DATE(al.created_at) <= :scan_date_to';
            $parameters[':scan_date_to'] = $dateTo;
        }

        $whereSql = implode(' AND ', $whereClauses);
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $countStatement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM activity_logs al
             INNER JOIN users u ON u.id = al.user_id
             LEFT JOIN event_tickets et ON et.id = al.record_id AND al.table_name = "event_tickets"
             LEFT JOIN orders o ON o.id = et.order_id
             WHERE ' . $whereSql
        );
        foreach ($parameters as $key => $value) {
            $countStatement->bindValue($key, $value);
        }
        $countStatement->execute();
        $total = (int) $countStatement->fetchColumn();

        $statement = $pdo->prepare(
            'SELECT
                al.id,
                al.created_at,
                al.ip_address,
                al.user_agent,
                al.old_values,
                al.new_values,
                u.id AS scanner_id,
                u.name AS scanner_name,
                u.email AS scanner_email,
                u.role AS scanner_role,
                et.id AS event_ticket_id,
                et.ticket_code,
                et.status AS ticket_status,
                et.passenger_name,
                o.id AS order_id,
                o.order_number,
                o.customer_name,
                t.id AS ticket_id,
                t.title AS ticket_title,
                e.id AS event_id,
                e.title AS event_title,
                e.date AS event_date
             FROM activity_logs al
             INNER JOIN users u ON u.id = al.user_id
             LEFT JOIN event_tickets et ON et.id = al.record_id AND al.table_name = "event_tickets"
             LEFT JOIN orders o ON o.id = et.order_id
             LEFT JOIN tickets t ON t.id = et.ticket_id
             LEFT JOIN events e ON e.id = t.event_id
             WHERE ' . $whereSql . '
             ORDER BY al.id DESC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => array_map([$this, 'mapScanLogRow'], $rows),
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'totalPages' => max(1, (int) ceil($total / $perPage)),
            ],
            'filters' => [
                'scannerUserId' => $scannerUserId > 0 ? $scannerUserId : null,
                'query' => $query !== '' ? $query : null,
                'dateFrom' => $dateFrom !== '' ? $dateFrom : null,
                'dateTo' => $dateTo !== '' ? $dateTo : null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapOrderListRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'orderNumber' => (string) $row['order_number'],
            'customerName' => (string) $row['customer_name'],
            'customerEmail' => $row['customer_email'] !== null ? (string) $row['customer_email'] : null,
            'customerPhone' => (string) $row['customer_phone'],
            'ticketsTotalAmount' => round((float) $row['tickets_total_amount'], 2),
            'donationAmount' => round((float) $row['donation_amount'], 2),
            'totalAmount' => round((float) $row['total_amount'], 2),
            'orderStatus' => (string) $row['status'],
            'payment' => [
                'id' => $row['payment_record_id'] !== null ? (int) $row['payment_record_id'] : null,
                'invoiceNumber' => $row['invoice_number'] !== null ? (string) $row['invoice_number'] : null,
                'gatewayName' => $row['gateway_name'] !== null ? (string) $row['gateway_name'] : null,
                'gatewayTransactionId' => $row['gateway_transaction_id'] !== null ? (string) $row['gateway_transaction_id'] : null,
                'status' => $row['payment_status'] !== null ? (string) $row['payment_status'] : null,
                'paidAt' => $row['paid_at'] !== null ? (string) $row['paid_at'] : null,
            ],
            'itemsCount' => (int) $row['items_count'],
            'quantityCount' => (int) $row['quantity_count'],
            'issuedTicketsCount' => (int) $row['issued_tickets_count'],
            'createdAt' => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapOrderItemRow(array $row): array
    {
        $ticketTitle = $this->decodeJsonColumn($row['ticket_title'] ?? null);
        $eventTitle = $this->decodeJsonColumn($row['event_title'] ?? null);
        $subEventTitle = $this->decodeJsonColumn($row['sub_event_title'] ?? null);

        return [
            'id' => (int) $row['id'],
            'ticketId' => (int) $row['ticket_id'],
            'quantity' => (int) $row['quantity'],
            'pricePerItem' => round((float) $row['price_per_item'], 2),
            'lineAmount' => round((float) $row['price_per_item'] * (int) $row['quantity'], 2),
            'ticketTitle' => $ticketTitle,
            'ticketTitleText' => $this->resolveDisplayText($ticketTitle),
            'subEvent' => [
                'id' => $row['sub_event_id'] !== null ? (int) $row['sub_event_id'] : null,
                'title' => $subEventTitle,
                'titleText' => $this->resolveDisplayText($subEventTitle),
            ],
            'city' => [
                'id' => $row['city_id'] !== null ? (int) $row['city_id'] : null,
                'name' => $row['city_name'] !== null ? (string) $row['city_name'] : null,
            ],
            'event' => [
                'id' => (int) $row['event_id'],
                'title' => $eventTitle,
                'titleText' => $this->resolveDisplayText($eventTitle),
                'date' => (string) $row['event_date'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapIssuedTicketRow(array $row): array
    {
        $subEventTitle = $this->decodeJsonColumn($row['sub_event_title'] ?? null);

        return [
            'id' => (int) $row['id'],
            'ticketId' => (int) $row['ticket_id'],
            'ticketCode' => (string) $row['ticket_code'],
            'passengerName' => $row['passenger_name'] !== null ? (string) $row['passenger_name'] : null,
            'status' => (string) $row['status'],
            'pricePerItem' => round((float) ($row['price_per_item'] ?? 0), 2),
            'canRefund' => (string) $row['status'] === 'valid',
            'canCancel' => (string) $row['status'] === 'valid',
            'subEvent' => [
                'id' => $row['sub_event_id'] !== null ? (int) $row['sub_event_id'] : null,
                'title' => $subEventTitle,
                'titleText' => $this->resolveDisplayText($subEventTitle),
            ],
            'city' => [
                'id' => $row['city_id'] !== null ? (int) $row['city_id'] : null,
                'name' => $row['city_name'] !== null ? (string) $row['city_name'] : null,
            ],
            'scannedAt' => $row['scanned_at'] !== null ? (string) $row['scanned_at'] : null,
            'createdAt' => (string) $row['created_at'],
            'scannedBy' => $row['scanned_by_id'] !== null
                ? [
                    'id' => (int) $row['scanned_by_id'],
                    'name' => $row['scanned_by_name'] !== null ? (string) $row['scanned_by_name'] : null,
                ]
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapScanLogRow(array $row): array
    {
        $eventTitle = $this->decodeJsonColumn($row['event_title'] ?? null);
        $ticketTitle = $this->decodeJsonColumn($row['ticket_title'] ?? null);
        $oldValues = $this->decodeJsonColumn($row['old_values'] ?? null);
        $newValues = $this->decodeJsonColumn($row['new_values'] ?? null);

        return [
            'id' => (int) $row['id'],
            'createdAt' => (string) $row['created_at'],
            'ipAddress' => $row['ip_address'] !== null ? (string) $row['ip_address'] : null,
            'userAgent' => $row['user_agent'] !== null ? (string) $row['user_agent'] : null,
            'scanner' => [
                'id' => $row['scanner_id'] !== null ? (int) $row['scanner_id'] : null,
                'name' => $row['scanner_name'] !== null ? (string) $row['scanner_name'] : null,
                'email' => $row['scanner_email'] !== null ? (string) $row['scanner_email'] : null,
                'role' => $row['scanner_role'] !== null ? (string) $row['scanner_role'] : null,
            ],
            'ticket' => [
                'eventTicketId' => $row['event_ticket_id'] !== null ? (int) $row['event_ticket_id'] : null,
                'ticketId' => $row['ticket_id'] !== null ? (int) $row['ticket_id'] : null,
                'ticketCode' => $row['ticket_code'] !== null ? (string) $row['ticket_code'] : null,
                'ticketStatus' => $row['ticket_status'] !== null ? (string) $row['ticket_status'] : null,
                'passengerName' => $row['passenger_name'] !== null ? (string) $row['passenger_name'] : null,
                'title' => $ticketTitle,
                'titleText' => $this->resolveDisplayText($ticketTitle),
            ],
            'order' => [
                'id' => $row['order_id'] !== null ? (int) $row['order_id'] : null,
                'orderNumber' => $row['order_number'] !== null ? (string) $row['order_number'] : null,
                'customerName' => $row['customer_name'] !== null ? (string) $row['customer_name'] : null,
            ],
            'event' => [
                'id' => $row['event_id'] !== null ? (int) $row['event_id'] : null,
                'title' => $eventTitle,
                'titleText' => $this->resolveDisplayText($eventTitle),
                'date' => $row['event_date'] !== null ? (string) $row['event_date'] : null,
            ],
            'changes' => [
                'oldValues' => $oldValues,
                'newValues' => $newValues,
            ],
        ];
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
}
