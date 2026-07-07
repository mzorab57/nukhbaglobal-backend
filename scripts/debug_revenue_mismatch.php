<?php

declare(strict_types=1);

use App\Core\Database;
use PDO;
use Throwable;

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/config/config.php';

try {
    $pdo = Database::getInstance();

    $totals = [
        'orders_total_amount' => fetchSingleValue(
            $pdo,
            'SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE deleted_at IS NULL'
        ),
        'payments_success_amount' => fetchSingleValue(
            $pdo,
            'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE deleted_at IS NULL AND status = "success"'
        ),
        'payments_refunded_amount' => fetchSingleValue(
            $pdo,
            'SELECT COALESCE(SUM(refund_amount), 0) FROM payments WHERE deleted_at IS NULL'
        ),
        'latest_payment_success_amount' => fetchSingleValue(
            $pdo,
            'SELECT COALESCE(SUM(latest_payment.amount), 0)
             FROM (
                 SELECT p1.*
                 FROM payments p1
                 INNER JOIN (
                     SELECT order_id, MAX(id) AS max_id
                     FROM payments
                     WHERE deleted_at IS NULL
                     GROUP BY order_id
                 ) latest ON latest.max_id = p1.id
                 WHERE p1.deleted_at IS NULL
                   AND p1.status = "success"
             ) latest_payment'
        ),
    ];

    $duplicatePaymentsStatement = $pdo->query(
        'SELECT
            o.id AS order_id,
            o.order_number,
            o.total_amount,
            COUNT(p.id) AS payments_count,
            SUM(CASE WHEN p.status = "success" THEN p.amount ELSE 0 END) AS successful_payments_amount,
            GROUP_CONCAT(CONCAT("#", p.id, ":", p.status, ":", p.amount) ORDER BY p.id SEPARATOR " | ") AS payments_debug
         FROM orders o
         INNER JOIN payments p ON p.order_id = o.id AND p.deleted_at IS NULL
         WHERE o.deleted_at IS NULL
         GROUP BY o.id, o.order_number, o.total_amount
         HAVING COUNT(p.id) > 1
         ORDER BY o.id DESC'
    );

    $successfulPaymentsStatement = $pdo->query(
        'SELECT
            p.id,
            p.order_id,
            o.order_number,
            o.total_amount AS order_total_amount,
            p.amount AS payment_amount,
            p.status,
            p.gateway_name,
            p.invoice_number,
            p.created_at
         FROM payments p
         INNER JOIN orders o ON o.id = p.order_id
         WHERE p.deleted_at IS NULL
           AND o.deleted_at IS NULL
           AND p.status = "success"
         ORDER BY p.id DESC'
    );

    echo json_encode(
        [
            'totals' => $totals,
            'orders_with_multiple_payments' => $duplicatePaymentsStatement->fetchAll(PDO::FETCH_ASSOC),
            'successful_payments' => $successfulPaymentsStatement->fetchAll(PDO::FETCH_ASSOC),
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . PHP_EOL);
    exit(1);
}

function fetchSingleValue(PDO $pdo, string $sql): float
{
    $statement = $pdo->query($sql);

    return round((float) $statement->fetchColumn(), 2);
}
