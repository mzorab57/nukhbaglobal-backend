<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $instance = null;

    private function __construct()
    {
    }

    public static function getInstance(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $config = require dirname(__DIR__, 2) . '/config/config.php';
        $database = $config['database'] ?? null;

        if (!is_array($database)) {
            throw new RuntimeException('Database configuration is missing or invalid.');
        }

        $host = (string) ($database['host'] ?? '127.0.0.1');
        $port = (int) ($database['port'] ?? 3306);
        $dbname = (string) ($database['dbname'] ?? '');
        $username = (string) ($database['username'] ?? '');
        $password = (string) ($database['password'] ?? '');
        $charset = (string) ($database['charset'] ?? 'utf8mb4');

        if ($dbname === '') {
            throw new RuntimeException('Database name is required.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $dbname,
            $charset
        );

        try {
            self::$instance = new PDO(
                $dsn,
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to connect to the database.', 0, $exception);
        }

        return self::$instance;
    }
}
