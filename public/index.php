<?php

declare(strict_types=1);

use App\Core\Router;
use App\Helpers\Response;

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

$config = require BASE_PATH . '/config/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $router = new Router();

    require BASE_PATH . '/routes/api.php';

    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $scriptPath = str_replace('\\', '/', $scriptName);
    $baseDirectory = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    $normalizedPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';

    if ($scriptPath !== '' && str_starts_with($normalizedPath, $scriptPath)) {
        $normalizedPath = substr($normalizedPath, strlen($scriptPath)) ?: '/';
    }

    if ($baseDirectory !== '' && $baseDirectory !== '/' && str_starts_with($normalizedPath, $baseDirectory)) {
        $normalizedPath = substr($normalizedPath, strlen($baseDirectory)) ?: '/';
    }

    $queryString = $_SERVER['QUERY_STRING'] ?? '';
    $dispatchUri = $queryString !== '' ? $normalizedPath . '?' . $queryString : $normalizedPath;

    $router->dispatch($dispatchUri, $_SERVER['REQUEST_METHOD'] ?? 'GET');
} catch (Throwable $throwable) {
    $isDebug = (bool) ($config['app']['debug'] ?? false);

    Response::jsonResponse(
        false,
        'Unhandled server error.',
        $isDebug
            ? [
                'error' => $throwable->getMessage(),
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
            ]
            : [],
        500
    );
}
