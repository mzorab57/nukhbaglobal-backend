<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Helpers\Response;
use App\Services\FIBPaymentService;
use App\Services\TicketService;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class PaymentController
{
    private const MAX_REQUEST_BODY_BYTES = 1048576;
    

    public function checkout(): never
    {
        $pdo = Database::getInstance();

        try {
            $payload = $this->getRequestPayload();
            $checkoutData = $this->validateCheckoutPayload($payload);
            $fibService = new FIBPaymentService();
            $ticketService = new TicketService();

            $pdo->beginTransaction();

            if ($checkoutData['items'] !== []) {
                $preparedItems = $ticketService->prepareCheckoutItems($pdo, $checkoutData['items']);
                $checkoutData['items'] = $preparedItems['items'];
                $checkoutData['tickets_total_amount'] = $preparedItems['tickets_total_amount'];
            }

            $checkoutData['total_amount'] = $this->resolveCheckoutTotalAmount($checkoutData, $payload);

            $orderNumber = $this->generateUniqueNumber('ORD');
            $orderId = $this->insertOrder($pdo, $orderNumber, $checkoutData);

            if ($checkoutData['items'] !== []) {
                $this->insertOrderItems($pdo, $orderId, $checkoutData['items']);
                $ticketService->reserveTickets($pdo, $checkoutData['items']);
            }

            $fibPayment = $fibService->createFIBPayment(
                $checkoutData['total_amount'],
                'Order ' . $orderNumber
            );

            $invoiceNumber = $this->generateUniqueNumber('INV');
            $paymentId = $this->insertPaymentTracking(
                $pdo,
                $orderId,
                $invoiceNumber,
                $checkoutData['total_amount'],
                $fibPayment
            );

            $pdo->commit();

            Response::jsonResponse(
                true,
                'Checkout created and FIB payment initiated successfully.',
                [
                    'orderId' => $orderId,
                    'orderNumber' => $orderNumber,
                    'paymentRecordId' => $paymentId,
                    'paymentId' => $fibPayment['paymentId'] ?? null,
                    'qrCode' => $fibPayment['qrCode'] ?? null,
                    'readableCode' => $fibPayment['readableCode'] ?? null,
                    'redirectionLink' => $fibPayment['redirectionLink'] ?? null,
                ],
                201
            );
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $statusCode = $this->resolveHttpStatusCode($throwable->getMessage());
            Response::jsonResponse(false, $throwable->getMessage(), [], $statusCode);
        }
    }

    public function testCreate(): never
    {
        try {
            $payload = $this->getRequestPayload();
            $amount = $payload['amount'] ?? $_GET['amount'] ?? null;
            $description = $payload['description'] ?? $_GET['description'] ?? 'Test FIB payment';

            if ($amount === null) {
                Response::jsonResponse(false, 'The amount field is required.', [], 422);
            }

            $service = new FIBPaymentService();
            $payment = $service->createFIBPayment($amount, (string) $description);

            Response::jsonResponse(true, 'FIB payment created successfully.', $payment, 200);
        } catch (Throwable $throwable) {
            $statusCode = $this->resolveHttpStatusCode($throwable->getMessage());
            Response::jsonResponse(false, $throwable->getMessage(), [], $statusCode);
        }
    }

    public function checkStatus(string $paymentId): never
    {
        $pdo = Database::getInstance();

        try {
            $service = new FIBPaymentService();
            $fibResult = $service->checkPaymentStatus($paymentId);
            $tracking = $this->findPaymentTracking($pdo, $paymentId);

            if ($tracking === null) {
                Response::jsonResponse(false, 'Payment tracking record was not found.', [], 404);
            }

            $localPaymentStatus = (string) $tracking['payment_status'];
            $localOrderStatus = (string) $tracking['order_status'];
            $fibStatus = (string) $fibResult['status'];
            $issuedTickets = [];

            if (in_array($fibStatus, ['PAID', 'DECLINED'], true)) {
                [
                    'payment_status' => $localPaymentStatus,
                    'order_status' => $localOrderStatus,
                    'issued_tickets' => $issuedTickets,
                ] = $this->syncPaymentStatus(
                    $pdo,
                    $tracking,
                    $fibResult,
                    false
                );
            }

            Response::jsonResponse(
                true,
                'Payment status checked successfully.',
                [
                    'paymentId' => $paymentId,
                    'fibStatus' => $fibStatus,
                    'localPaymentStatus' => $localPaymentStatus,
                    'localOrderStatus' => $localOrderStatus,
                    'orderId' => (int) $tracking['order_id'],
                    'orderNumber' => (string) $tracking['order_number'],
                    'issuedTickets' => $issuedTickets,
                ],
                200
            );
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $statusCode = $this->resolveHttpStatusCode($throwable->getMessage());
            Response::jsonResponse(false, $throwable->getMessage(), [], $statusCode);
        }
    }

    public function fibCallback(): never
    {
        $pdo = Database::getInstance();

        try {
            $payload = $this->getRequestPayload();
            $paymentId = $this->extractFibPaymentId($payload);
            $fibStatus = $this->extractFibStatus($payload);

            if ($paymentId === null || $fibStatus === '') {
                Response::jsonResponse(
                    true,
                    'FIB callback received without actionable payment data.',
                    [
                        'paymentId' => $paymentId,
                        'status' => $fibStatus,
                    ],
                    202
                );
            }

            $tracking = $this->findPaymentTracking($pdo, $paymentId);

            if ($tracking === null) {
                Response::jsonResponse(
                    true,
                    'FIB callback received but no local payment record matched.',
                    [
                        'paymentId' => $paymentId,
                        'status' => $fibStatus,
                    ],
                    202
                );
            }

            [
                'payment_status' => $localPaymentStatus,
                'order_status' => $localOrderStatus,
                'issued_tickets' => $issuedTickets,
            ] = $this->syncPaymentStatus(
                $pdo,
                $tracking,
                [
                    'paymentId' => $paymentId,
                    'status' => $fibStatus,
                    'raw' => $payload,
                ],
                true
            );

            Response::jsonResponse(
                true,
                'FIB callback processed successfully.',
                [
                    'paymentId' => $paymentId,
                    'fibStatus' => $fibStatus,
                    'localPaymentStatus' => $localPaymentStatus,
                    'localOrderStatus' => $localOrderStatus,
                    'orderId' => (int) $tracking['order_id'],
                    'orderNumber' => (string) $tracking['order_number'],
                    'issuedTickets' => $issuedTickets,
                ],
                200
            );
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $statusCode = $this->resolveHttpStatusCode($throwable->getMessage());
            Response::jsonResponse(false, $throwable->getMessage(), [], $statusCode);
        }
    }

    private function getRequestPayload(): array
    {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        if ($contentLength > self::MAX_REQUEST_BODY_BYTES) {
            throw new RuntimeException('Request body is too large.');
        }

        $rawBody = file_get_contents('php://input');

        if (!is_string($rawBody) || trim($rawBody) === '') {
            return [];
        }

        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Malformed JSON request body.');
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function validateCheckoutPayload(array $payload): array
    {
        $customerName = trim((string) ($payload['customer_name'] ?? ''));
        $customerPhone = trim((string) ($payload['customer_phone'] ?? ''));
        $customerEmail = $this->nullableString($payload['customer_email'] ?? null);
        $customerAddress = $this->nullableString($payload['customer_address'] ?? null);
        $donationAmount = $this->normalizeMoney($payload['donation_amount'] ?? 0);
        $items = $this->normalizeOrderItems($payload['items'] ?? []);

        if ($customerName === '') {
            throw new \RuntimeException('The customer_name field is required.');
        }

        if ($customerPhone === '') {
            throw new RuntimeException('The customer_phone field is required.');
        }

        if ($customerEmail !== null && filter_var($customerEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('The customer_email field must contain a valid email address.');
        }

        if (mb_strlen($customerName) > 255) {
            throw new RuntimeException('The customer_name field is too long.');
        }

        if (mb_strlen($customerPhone) > 50) {
            throw new RuntimeException('The customer_phone field is too long.');
        }

        $ticketsTotalAmount = $items === []
            ? $this->normalizeMoney($payload['tickets_total_amount'] ?? 0)
            : 0.0;

        if ($ticketsTotalAmount <= 0) {
            if ($items === []) {
                throw new RuntimeException('The tickets total amount must be greater than zero.');
            }
        }

        $totalAmount = array_key_exists('total_amount', $payload)
            ? $this->normalizeMoney($payload['total_amount'])
            : round($ticketsTotalAmount + $donationAmount, 2);

        if (
            $items === [] &&
            abs($totalAmount - round($ticketsTotalAmount + $donationAmount, 2)) > 0.00001
        ) {
            throw new RuntimeException('The total amount does not match tickets plus donation totals.');
        }

        return [
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'customer_email' => $customerEmail,
            'customer_address' => $customerAddress,
            'donation_amount' => $donationAmount,
            'tickets_total_amount' => $ticketsTotalAmount,
            'total_amount' => $totalAmount,
            'items' => $items,
        ];
    }

    /**
     * @param mixed $value
     */
    private function normalizeMoney(mixed $value): float
    {
        if ($value === null || $value === '') {
            throw new RuntimeException('A numeric amount is required.');
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

    /**
     * @param mixed $items
     * @return array<int, array{ticket_id:int,quantity:int}>
     */
    private function normalizeOrderItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
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

    private function resolveCheckoutTotalAmount(array $checkoutData, array $payload): float
    {
        $expectedTotalAmount = round(
            (float) $checkoutData['tickets_total_amount'] + (float) $checkoutData['donation_amount'],
            2
        );
        $providedTotalAmount = array_key_exists('total_amount', $payload)
            ? $this->normalizeMoney($payload['total_amount'])
            : $expectedTotalAmount;

        if (abs($providedTotalAmount - $expectedTotalAmount) > 0.00001) {
            throw new RuntimeException('The total amount does not match tickets plus donation totals.');
        }

        return $expectedTotalAmount;
    }

    private function insertOrder(PDO $pdo, string $orderNumber, array $checkoutData): int
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
                DATE_ADD(NOW(), INTERVAL 15 MINUTE)
            )'
        );

        $statement->execute([
            ':order_number' => $orderNumber,
            ':customer_name' => $checkoutData['customer_name'],
            ':customer_email' => $checkoutData['customer_email'],
            ':customer_phone' => $checkoutData['customer_phone'],
            ':customer_address' => $checkoutData['customer_address'],
            ':tickets_total_amount' => number_format((float) $checkoutData['tickets_total_amount'], 2, '.', ''),
            ':donation_amount' => number_format((float) $checkoutData['donation_amount'], 2, '.', ''),
            ':total_amount' => number_format((float) $checkoutData['total_amount'], 2, '.', ''),
            ':status' => 'pending',
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

    private function insertPaymentTracking(
        PDO $pdo,
        int $orderId,
        string $invoiceNumber,
        float $amount,
        array $fibPayment
    ): int {
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

    private function findPaymentTracking(PDO $pdo, string $paymentId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT
                p.id AS payment_record_id,
                p.order_id,
                p.status AS payment_status,
                p.webhook_verified,
                o.order_number,
                o.status AS order_status
             FROM payments p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE p.gateway_name = :gateway_name
               AND p.gateway_transaction_id = :gateway_transaction_id
             LIMIT 1'
        );

        $statement->execute([
            ':gateway_name' => 'FIB',
            ':gateway_transaction_id' => $paymentId,
        ]);

        $result = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($result) ? $result : null;
    }

    private function updatePaymentSuccess(PDO $pdo, int $paymentRecordId, array $fibResult, bool $webhookVerified = false): void
    {
        $statement = $pdo->prepare(
            'UPDATE payments
             SET status = :status,
                 webhook_verified = GREATEST(webhook_verified, :webhook_verified),
                 paid_at = NOW(),
                 failed_reason = NULL,
                 gateway_response = :gateway_response,
                 updated_at = NOW()
             WHERE id = :id'
        );

        $statement->execute([
            ':status' => 'success',
            ':webhook_verified' => $webhookVerified ? 1 : 0,
            ':gateway_response' => json_encode($fibResult['raw'] ?? $fibResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id' => $paymentRecordId,
        ]);
    }

    private function updatePaymentFailed(
        PDO $pdo,
        int $paymentRecordId,
        array $fibResult,
        string $reason,
        bool $webhookVerified = false
    ): void
    {
        $statement = $pdo->prepare(
            'UPDATE payments
             SET status = :status,
                 webhook_verified = GREATEST(webhook_verified, :webhook_verified),
                 failed_reason = :failed_reason,
                 gateway_response = :gateway_response,
                 updated_at = NOW()
             WHERE id = :id'
        );

        $statement->execute([
            ':status' => 'failed',
            ':webhook_verified' => $webhookVerified ? 1 : 0,
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

    private function generateUniqueNumber(string $prefix): string
    {
        return sprintf(
            '%s-%s-%s',
            strtoupper($prefix),
            date('YmdHis'),
            bin2hex(random_bytes(4))
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $stringValue = trim((string) $value);

        return $stringValue === '' ? null : $stringValue;
    }

    private function extractFibPaymentId(array $payload): ?string
    {
        $candidates = [
            $payload['paymentId'] ?? null,
            $payload['payment_id'] ?? null,
            $payload['data']['paymentId'] ?? null,
            $payload['data']['payment_id'] ?? null,
            $payload['payment']['paymentId'] ?? null,
            $payload['payment']['payment_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function extractFibStatus(array $payload): string
    {
        $candidates = [
            $payload['status'] ?? null,
            $payload['paymentStatus'] ?? null,
            $payload['payment_status'] ?? null,
            $payload['data']['status'] ?? null,
            $payload['data']['paymentStatus'] ?? null,
            $payload['data']['payment_status'] ?? null,
            $payload['payment']['status'] ?? null,
            $payload['payment']['paymentStatus'] ?? null,
            $payload['payment']['payment_status'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return strtoupper(trim($candidate));
            }
        }

        return '';
    }

    /**
     * @param array{payment_record_id:mixed,order_id:mixed,payment_status:mixed,order_status:mixed,webhook_verified:mixed} $tracking
     * @param array{status:mixed,raw:mixed} $fibResult
     * @return array{payment_status:string,order_status:string,issued_tickets:array<int, array{ticketId:int,ticketCode:string,status:string,passengerName:?string}>}
     */
    private function syncPaymentStatus(PDO $pdo, array $tracking, array $fibResult, bool $webhookVerified): array
    {
        $fibStatus = strtoupper(trim((string) ($fibResult['status'] ?? '')));
        $paymentStatus = (string) $tracking['payment_status'];
        $orderStatus = (string) $tracking['order_status'];
        $paymentRecordId = (int) $tracking['payment_record_id'];
        $orderId = (int) $tracking['order_id'];
        $ticketService = new TicketService();

        if ($fibStatus === 'PAID') {
            $issuedTickets = $ticketService->getIssuedTicketsByPayment($pdo, $paymentRecordId);

            if ($paymentStatus === 'success' && $orderStatus === 'paid') {
                return [
                    'payment_status' => $paymentStatus,
                    'order_status' => $orderStatus,
                    'issued_tickets' => $issuedTickets,
                ];
            }

            $pdo->beginTransaction();

            $this->updatePaymentSuccess($pdo, $paymentRecordId, $fibResult, $webhookVerified);
            $this->updateOrderStatus($pdo, $orderId, 'paid');
            $issuedTickets = $ticketService->issueTicketsForPaidOrder($pdo, $orderId, $paymentRecordId);

            $pdo->commit();

            return [
                'payment_status' => 'success',
                'order_status' => 'paid',
                'issued_tickets' => $issuedTickets,
            ];
        }

        if ($fibStatus === 'DECLINED') {
            if ($paymentStatus === 'failed' && $orderStatus === 'cancelled') {
                return [
                    'payment_status' => $paymentStatus,
                    'order_status' => $orderStatus,
                    'issued_tickets' => [],
                ];
            }

            $pdo->beginTransaction();

            $ticketService->releaseReservedTicketsForOrder($pdo, $orderId);
            $this->updatePaymentFailed($pdo, $paymentRecordId, $fibResult, 'Declined by FIB', $webhookVerified);
            $this->updateOrderStatus($pdo, $orderId, 'cancelled');

            $pdo->commit();

            return [
                'payment_status' => 'failed',
                'order_status' => 'cancelled',
                'issued_tickets' => [],
            ];
        }

        return [
            'payment_status' => $paymentStatus,
            'order_status' => $orderStatus,
            'issued_tickets' => [],
        ];
    }

    private function resolveHttpStatusCode(string $message): int
    {
        $normalized = strtolower($message);

        if (
            str_contains($normalized, 'required') ||
            str_contains($normalized, 'invalid') ||
            str_contains($normalized, 'malformed')
        ) {
            return 422;
        }

        if (str_contains($normalized, 'not found')) {
            return 404;
        }

        if (str_contains($normalized, 'unauthorized') || str_contains($normalized, 'forbidden')) {
            return 401;
        }

        if (str_contains($normalized, 'too large')) {
            return 413;
        }

        return 500;
    }
}
