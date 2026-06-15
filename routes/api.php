<?php

declare(strict_types=1);

use App\Controllers\PaymentController;
use App\Helpers\Response;

$router->get('/api/health', static function (): never {
    Response::jsonResponse(
        true,
        'NukhbaGlobal API is running.',
        [
            'service' => 'nukhbaglobal-backend',
            'timestamp' => date(DATE_ATOM),
        ],
        200
    );
});

$router->get('/api/test', static function (): never {
    Response::jsonResponse(
        true,
        'API test route is active.',
        [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/api/test', PHP_URL_PATH),
        ],
        200
    );
});

$router->post('/api/payments/fib/checkout', [PaymentController::class, 'checkout']);
$router->get('/api/payments/fib/check-status/{paymentId}', [PaymentController::class, 'checkStatus']);
$router->post('/api/payments/fib/test-create', [PaymentController::class, 'testCreate']);
$router->post('/api/payments/fib/callback', [PaymentController::class, 'fibCallback']);
