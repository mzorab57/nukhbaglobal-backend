<?php

declare(strict_types=1);

namespace App\Helpers;

use JsonException;
use RuntimeException;

final class Auth
{
    /**
     * @var array<string, mixed>|null
     */
    private static ?array $authenticatedUser = null;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function issueToken(array $user): string
    {
        $config = self::getAuthConfig();
        $issuedAt = time();
        $expiresAt = $issuedAt + (int) $config['token_ttl'];

        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];
        $payload = [
            'iss' => (string) $config['issuer'],
            'sub' => (int) ($user['id'] ?? 0),
            'email' => (string) ($user['email'] ?? ''),
            'role' => (string) ($user['role'] ?? ''),
            'permissions' => is_array($user['permissions'] ?? null) ? array_values($user['permissions']) : [],
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ];

        $headerSegment = self::base64UrlEncode(self::jsonEncode($header));
        $payloadSegment = self::base64UrlEncode(self::jsonEncode($payload));
        $signature = hash_hmac(
            'sha256',
            $headerSegment . '.' . $payloadSegment,
            (string) $config['secret'],
            true
        );

        return $headerSegment . '.' . $payloadSegment . '.' . self::base64UrlEncode($signature);
    }

    /**
     * @return array<string, mixed>
     */
    public static function authenticateRequest(): array
    {
        $token = self::extractBearerToken();
        $payload = self::validateToken($token);

        return $payload;
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function setAuthenticatedUser(array $user): void
    {
        self::$authenticatedUser = $user;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        return self::$authenticatedUser;
    }

    public static function clearAuthenticatedUser(): void
    {
        self::$authenticatedUser = null;
    }

    public static function getTokenTtl(): int
    {
        $config = self::getAuthConfig();

        return (int) $config['token_ttl'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function validateToken(string $token): array
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            throw new RuntimeException('Unauthorized.');
        }

        [$headerSegment, $payloadSegment, $signatureSegment] = $segments;
        $config = self::getAuthConfig();
        $expectedSignature = self::base64UrlEncode(
            hash_hmac(
                'sha256',
                $headerSegment . '.' . $payloadSegment,
                (string) $config['secret'],
                true
            )
        );

        if (!hash_equals($expectedSignature, $signatureSegment)) {
            throw new RuntimeException('Unauthorized.');
        }

        $payloadJson = self::base64UrlDecode($payloadSegment);
        $payload = self::jsonDecode($payloadJson);

        if (($payload['iss'] ?? null) !== $config['issuer']) {
            throw new RuntimeException('Unauthorized.');
        }

        $expiresAt = (int) ($payload['exp'] ?? 0);

        if ($expiresAt <= time()) {
            throw new RuntimeException('Unauthorized.');
        }

        return $payload;
    }

    private static function extractBearerToken(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? null;

        if (!is_string($header) || trim($header) === '') {
            throw new RuntimeException('Unauthorized.');
        }

        if (preg_match('/^\s*Bearer\s+(.+)\s*$/i', $header, $matches) !== 1) {
            throw new RuntimeException('Unauthorized.');
        }

        return trim($matches[1]);
    }

    /**
     * @return array{issuer:string,secret:string,token_ttl:int}
     */
    private static function getAuthConfig(): array
    {
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        $auth = $config['auth'] ?? null;

        if (!is_array($auth)) {
            throw new RuntimeException('Authentication configuration is missing.');
        }

        $issuer = trim((string) ($auth['issuer'] ?? ''));
        $secret = trim((string) ($auth['secret'] ?? ''));
        $tokenTtl = (int) ($auth['token_ttl'] ?? 0);

        if ($issuer === '' || $secret === '' || $tokenTtl <= 0) {
            throw new RuntimeException('Authentication configuration is invalid.');
        }

        return [
            'issuer' => $issuer,
            'secret' => $secret,
            'token_ttl' => $tokenTtl,
        ];
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $paddingLength = 4 - (strlen($value) % 4);

        if ($paddingLength < 4) {
            $value .= str_repeat('=', $paddingLength);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw new RuntimeException('Unauthorized.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function jsonEncode(array $value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Failed to encode authentication payload.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function jsonDecode(string $value): array
    {
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Unauthorized.');
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Unauthorized.');
        }

        return $decoded;
    }
}
