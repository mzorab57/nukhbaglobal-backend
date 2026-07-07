<?php

declare(strict_types=1);

use App\Controllers\ActivityController;
use App\Controllers\AuthController;
use App\Controllers\CatalogController;
use App\Controllers\CityController;
use App\Controllers\CountryController;
use App\Controllers\CustomerOrderController;
use App\Controllers\DashboardController;
use App\Controllers\EventController;
use App\Controllers\MediaController;
use App\Controllers\OrderController;
use App\Controllers\OfficeSaleController;
use App\Controllers\PaymentController;
use App\Controllers\ScanController;
use App\Controllers\SubEventController;
use App\Controllers\TicketController;
use App\Controllers\UploadController;
use App\Controllers\UserController;
use App\Helpers\Response;
use App\Middleware\AccountantMiddleware;
use App\Middleware\AdminMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\ScannerMiddleware;
use App\Middleware\UserManagementMiddleware;

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

$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->get('/api/auth/me', static function (): never {
    (new AuthMiddleware())->handle();
    (new AuthController())->me();
});

$router->get('/api/catalog/home', static function (): never {
    (new CatalogController())->home();
});

$router->get('/api/catalog/featured', static function (): never {
    (new CatalogController())->featured();
});

$router->get('/api/catalog/trending', static function (): never {
    (new CatalogController())->trending();
});

$router->get('/api/catalog/search', static function (): never {
    (new CatalogController())->search();
});

$router->get('/api/catalog/events', static function (): never {
    (new CatalogController())->events();
});

$router->get('/api/catalog/events/{eventId}', static function (string $eventId): never {
    (new CatalogController())->showEvent($eventId);
});

$router->get('/api/catalog/events/{eventId}/checkout', static function (string $eventId): never {
    (new CatalogController())->checkoutFeed($eventId);
});

$router->get('/api/catalog/countries', static function (): never {
    (new CatalogController())->countries();
});

$router->get('/api/catalog/cities', static function (): never {
    (new CatalogController())->cities();
});

$router->get('/api/catalog/media', static function (): never {
    (new CatalogController())->media();
});

$router->get('/api/customer/orders/track', static function (): never {
    (new CustomerOrderController())->track();
});

$router->post('/api/customer/orders/track', static function (): never {
    (new CustomerOrderController())->track();
});

$router->get('/api/customer/orders/passes', static function (): never {
    (new CustomerOrderController())->passes();
});

$router->post('/api/customer/orders/passes', static function (): never {
    (new CustomerOrderController())->passes();
});

$router->get('/api/customer/orders/passes/printable', static function (): never {
    (new CustomerOrderController())->printablePasses();
});

$router->post('/api/customer/orders/passes/printable', static function (): never {
    (new CustomerOrderController())->printablePasses();
});

$router->post('/api/customer/orders/passengers/update', static function (): never {
    (new CustomerOrderController())->updatePassengerNames();
});

$router->post('/api/customer/orders/payments/retry', static function (): never {
    (new CustomerOrderController())->retryPayment();
});

$router->get('/api/admin/reports/overview', static function (): never {
    (new AccountantMiddleware())->handle();
    (new DashboardController())->overview();
});

$router->get('/api/admin/reports/scans/overview', static function (): never {
    (new AccountantMiddleware())->handle();
    (new ActivityController())->scanOverview();
});

$router->get('/api/admin/reports/scans/logs', static function (): never {
    (new AccountantMiddleware())->handle();
    (new ActivityController())->scanLogs();
});

$router->get('/api/admin/countries', static function (): never {
    (new AdminMiddleware())->handle();
    (new CountryController())->index();
});

$router->get('/api/admin/countries/{countryId}', static function (string $countryId): never {
    (new AdminMiddleware())->handle();
    (new CountryController())->show($countryId);
});

$router->post('/api/admin/countries/create', static function (): never {
    (new AdminMiddleware())->handle();
    (new CountryController())->create();
});

$router->post('/api/admin/countries/{countryId}/update', static function (string $countryId): never {
    (new AdminMiddleware())->handle();
    (new CountryController())->update($countryId);
});

$router->post('/api/admin/countries/{countryId}/delete', static function (string $countryId): never {
    (new AdminMiddleware())->handle();
    (new CountryController())->delete($countryId);
});

$router->get('/api/admin/cities', static function (): never {
    (new AdminMiddleware())->handle();
    (new CityController())->index();
});

$router->get('/api/admin/cities/{cityId}', static function (string $cityId): never {
    (new AdminMiddleware())->handle();
    (new CityController())->show($cityId);
});

$router->post('/api/admin/cities/create', static function (): never {
    (new AdminMiddleware())->handle();
    (new CityController())->create();
});

$router->post('/api/admin/cities/{cityId}/update', static function (string $cityId): never {
    (new AdminMiddleware())->handle();
    (new CityController())->update($cityId);
});

$router->post('/api/admin/cities/{cityId}/delete', static function (string $cityId): never {
    (new AdminMiddleware())->handle();
    (new CityController())->delete($cityId);
});

$router->get('/api/admin/events', static function (): never {
    (new AdminMiddleware())->handle();
    (new EventController())->index();
});

$router->get('/api/admin/events/{eventId}', static function (string $eventId): never {
    (new AdminMiddleware())->handle();
    (new EventController())->show($eventId);
});

$router->post('/api/admin/events/create', static function (): never {
    (new AdminMiddleware())->handle();
    (new EventController())->create();
});

$router->post('/api/admin/events/{eventId}/update', static function (string $eventId): never {
    (new AdminMiddleware())->handle();
    (new EventController())->update($eventId);
});

$router->post('/api/admin/events/{eventId}/delete', static function (string $eventId): never {
    (new AdminMiddleware())->handle();
    (new EventController())->delete($eventId);
});

$router->get('/api/admin/events/{eventId}/tickets', static function (string $eventId): never {
    (new AdminMiddleware())->handle();
    (new EventController())->tickets($eventId);
});

$router->get('/api/admin/sub-events', static function (): never {
    (new AdminMiddleware())->handle();
    (new SubEventController())->index();
});

$router->get('/api/admin/sub-events/{subEventId}', static function (string $subEventId): never {
    (new AdminMiddleware())->handle();
    (new SubEventController())->show($subEventId);
});

$router->post('/api/admin/events/{eventId}/sub-events/create', static function (string $eventId): never {
    (new AdminMiddleware())->handle();
    (new SubEventController())->create($eventId);
});

$router->post('/api/admin/sub-events/{subEventId}/update', static function (string $subEventId): never {
    (new AdminMiddleware())->handle();
    (new SubEventController())->update($subEventId);
});

$router->post('/api/admin/sub-events/{subEventId}/delete', static function (string $subEventId): never {
    (new AdminMiddleware())->handle();
    (new SubEventController())->delete($subEventId);
});

$router->post('/api/admin/events/{eventId}/tickets/create', static function (string $eventId): never {
    (new AdminMiddleware())->handle();
    (new TicketController())->create($eventId);
});

$router->get('/api/admin/media', static function (): never {
    (new AdminMiddleware())->handle();
    (new MediaController())->index();
});

$router->get('/api/admin/media/{itemId}', static function (string $itemId): never {
    (new AdminMiddleware())->handle();
    (new MediaController())->show($itemId);
});

$router->post('/api/admin/media/create', static function (): never {
    (new AdminMiddleware())->handle();
    (new MediaController())->create();
});

$router->post('/api/admin/media/{itemId}/update', static function (string $itemId): never {
    (new AdminMiddleware())->handle();
    (new MediaController())->update($itemId);
});

$router->post('/api/admin/media/{itemId}/delete', static function (string $itemId): never {
    (new AdminMiddleware())->handle();
    (new MediaController())->delete($itemId);
});

$router->post('/api/admin/media/reorder', static function (): never {
    (new AdminMiddleware())->handle();
    (new MediaController())->reorder();
});

$router->post('/api/admin/uploads/image', static function (): never {
    (new AdminMiddleware())->handle();
    (new UploadController())->image();
});

$router->get('/api/admin/users', static function (): never {
    (new UserManagementMiddleware())->handle();
    (new UserController())->index();
});

$router->get('/api/admin/users/{userId}', static function (string $userId): never {
    (new UserManagementMiddleware())->handle();
    (new UserController())->show($userId);
});

$router->post('/api/admin/users/create', static function (): never {
    (new UserManagementMiddleware())->handle();
    (new UserController())->create();
});

$router->post('/api/admin/users/{userId}/update', static function (string $userId): never {
    (new UserManagementMiddleware())->handle();
    (new UserController())->update($userId);
});

$router->post('/api/admin/users/{userId}/delete', static function (string $userId): never {
    (new UserManagementMiddleware())->handle();
    (new UserController())->delete($userId);
});

$router->get('/api/admin/tickets/{ticketId}', static function (string $ticketId): never {
    (new AdminMiddleware())->handle();
    (new TicketController())->show($ticketId);
});

$router->post('/api/admin/tickets/{ticketId}/update', static function (string $ticketId): never {
    (new AdminMiddleware())->handle();
    (new TicketController())->update($ticketId);
});

$router->post('/api/admin/tickets/{ticketId}/delete', static function (string $ticketId): never {
    (new AdminMiddleware())->handle();
    (new TicketController())->delete($ticketId);
});

$router->get('/api/admin/orders', static function (): never {
    (new AccountantMiddleware())->handle();
    (new OrderController())->index();
});

$router->get('/api/admin/orders/{orderId}', static function (string $orderId): never {
    (new AccountantMiddleware())->handle();
    (new OrderController())->show($orderId);
});

$router->get('/api/admin/orders/{orderId}/printable-passes', static function (string $orderId): never {
    (new AccountantMiddleware())->handle();
    (new OfficeSaleController())->printable($orderId);
});

$router->post('/api/admin/office-sales/create', static function (): never {
    (new AccountantMiddleware())->handle();
    (new OfficeSaleController())->create();
});

$router->post('/api/admin/orders/{orderId}/cancel', static function (string $orderId): never {
    (new AccountantMiddleware())->handle();
    (new PaymentController())->cancelOrder($orderId);
});

$router->post('/api/admin/orders/{orderId}/tickets/{eventTicketId}/cancel', static function (string $orderId, string $eventTicketId): never {
    (new AccountantMiddleware())->handle();
    (new PaymentController())->cancelIssuedTicket($orderId, $eventTicketId);
});

$router->post('/api/admin/orders/{orderId}/refund', static function (string $orderId): never {
    (new AccountantMiddleware())->handle();
    (new PaymentController())->refundOrder($orderId);
});

$router->post('/api/admin/orders/{orderId}/tickets/{eventTicketId}/refund', static function (string $orderId, string $eventTicketId): never {
    (new AccountantMiddleware())->handle();
    (new PaymentController())->refundIssuedTicket($orderId, $eventTicketId);
});

$router->post('/api/payments/fib/checkout', [PaymentController::class, 'checkout']);
$router->get('/api/payments/fib/check-status/{paymentId}', [PaymentController::class, 'checkStatus']);
$router->post('/api/payments/fib/test-create', [PaymentController::class, 'testCreate']);
$router->post('/api/payments/fib/callback', [PaymentController::class, 'fibCallback']);
$router->post('/api/scans/preview', static function (): never {
    (new ScannerMiddleware())->handle();
    (new ScanController())->preview();
});
$router->post('/api/scans/confirm', static function (): never {
    (new ScannerMiddleware())->handle();
    (new ScanController())->confirm();
});
