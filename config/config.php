<?php

declare(strict_types=1);

/**
 * Loads environment variables from the project root .env file.
 *
 * This lightweight loader avoids external dependencies while keeping
 * configuration centralized and easy to override per environment.
 */
$envFile = dirname(__DIR__) . '/.env';

if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
        $name = trim($name);
        $value = trim($value);

        if ($name === '') {
            continue;
        }

        $value = trim($value, "\"'");

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;

        if (getenv($name) === false) {
            putenv($name . '=' . $value);
        }
    }
}

$env = static fn(string $key, mixed $default = null): mixed => $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;

return [
    'app' => [
        'env' => (string) $env('APP_ENV', 'production'),
        'debug' => filter_var($env('APP_DEBUG', false), FILTER_VALIDATE_BOOL),
        'url' => (string) $env('APP_URL', ''),
    ],
    'database' => [
        'driver' => 'mysql',
        'host' => (string) $env('DB_HOST', '127.0.0.1'),
        'port' => (int) $env('DB_PORT', 3306),
        'dbname' => (string) $env('DB_NAME', 'nukhbaglobal'),
        'username' => (string) $env('DB_USERNAME', 'root'),
        'password' => (string) $env('DB_PASSWORD', ''),
        'charset' => (string) $env('DB_CHARSET', 'utf8mb4'),
    ],
    'fib' => [
        'base_url' => rtrim((string) $env('FIB_BASE_URL', 'https://fib-stage.fib.iq'), '/'),
        'auth_realm' => (string) $env('FIB_AUTH_REALM', 'fib-online-shop'),
        'grant_type' => (string) $env('FIB_GRANT_TYPE', 'client_credentials'),
        'client_id' => (string) $env('FIB_CLIENT_ID', ''),
        'client_secret' => (string) $env('FIB_CLIENT_SECRET', ''),
        'currency' => (string) $env('FIB_CURRENCY', 'IQD'),
        'expires_in' => (int) $env('FIB_PAYMENT_EXPIRES_IN', 900),
        'redirect_url' => (string) $env(
            'FIB_REDIRECT_URL',
            rtrim((string) $env('APP_URL', ''), '/') . '/payment-return'
        ),
        'refundable_for' => (string) $env('FIB_REFUNDABLE_FOR', 'P1D'),
        'status_callback_url' => (string) $env(
            'FIB_STATUS_CALLBACK_URL',
            rtrim((string) $env('APP_URL', ''), '/') . '/api/payments/fib/callback'
        ),
    ],
];
