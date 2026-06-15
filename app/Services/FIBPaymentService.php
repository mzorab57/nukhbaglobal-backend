<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class FIBPaymentService
{
    private static ?string $cachedAccessToken = null;
    private static int $cachedAccessTokenExpiresAt = 0;

    private readonly string $baseUrl;
    private readonly string $authRealm;
    private readonly string $grantType;
    private readonly string $clientId;
    private readonly string $clientSecret;
    private readonly string $currency;
    private readonly string $redirectUrl;
    private readonly string $refundableFor;
    private readonly string $statusCallbackUrl;

    public function __construct()
    {
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        $fib = $config['fib'] ?? null;

        if (!is_array($fib)) {
            throw new RuntimeException('FIB configuration is missing or invalid.');
        }

        $this->baseUrl = (string) ($fib['base_url'] ?? '');
        $this->authRealm = (string) ($fib['auth_realm'] ?? 'fib-online-shop');
        $this->grantType = (string) ($fib['grant_type'] ?? 'client_credentials');
        $this->clientId = (string) ($fib['client_id'] ?? '');
        $this->clientSecret = (string) ($fib['client_secret'] ?? '');
        $this->currency = (string) ($fib['currency'] ?? 'IQD');
        $this->redirectUrl = (string) ($fib['redirect_url'] ?? '');
        $this->refundableFor = (string) ($fib['refundable_for'] ?? 'P1D');
        $this->statusCallbackUrl = (string) ($fib['status_callback_url'] ?? '');

        if (
            $this->baseUrl === '' ||
            $this->authRealm === '' ||
            $this->clientId === '' ||
            $this->clientSecret === '' ||
            $this->redirectUrl === '' ||
            $this->statusCallbackUrl === ''
        ) {
            throw new RuntimeException('FIB credentials or callback URL are incomplete.');
        }
    }

    public function createFIBPayment(int|float|string $amount, string $description): array
    {
        $token = $this->getAccessToken();
        $normalizedAmount = $this->normalizeAmount($amount);

        $payload = [
            'monetaryValue' => [
                'amount' => $normalizedAmount,
                'currency' => $this->currency,
            ],
            'statusCallbackUrl' => $this->statusCallbackUrl,
            'description' => trim($description),
            'redirectUri' => $this->redirectUrl,
            'refundableFor' => $this->refundableFor,
        ];

        if ($payload['description'] === '') {
            throw new RuntimeException('Payment description is required.');
        }

        $response = $this->sendJsonRequest(
            'POST',
            '/protected/v1/payments',
            $payload,
            [
                'Authorization: Bearer ' . $token,
            ]
        );

        return [
            'paymentId' => $response['paymentId'] ?? null,
            'qrCode' => $response['qrCode'] ?? null,
            'readableCode' => $response['readableCode'] ?? null,
            'redirectionLink' => $response['redirectionLink'] ?? null,
            'raw' => $response,
        ];
    }

    public function checkPaymentStatus(string $paymentId): array
    {
        $paymentId = trim($paymentId);

        if ($paymentId === '') {
            throw new RuntimeException('Payment ID is required.');
        }

        $token = $this->getAccessToken();
        $response = $this->executeCurlRequest(
            'GET',
            '/protected/v1/payments/' . rawurlencode($paymentId) . '/status',
            null,
            [
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ]
        );

        $status = $this->extractStatus($response);

        if ($status === '') {
            throw new RuntimeException('FIB payment status was not returned.');
        }

        return [
            'paymentId' => $paymentId,
            'status' => $status,
            'raw' => $response,
        ];
    }

    private function getAccessToken(): string
    {
        if (
            self::$cachedAccessToken !== null &&
            self::$cachedAccessTokenExpiresAt > time()
        ) {
            return self::$cachedAccessToken;
        }

        $response = $this->sendFormRequest(
            'POST',
            '/auth/realms/' . rawurlencode($this->authRealm) . '/protocol/openid-connect/token',
            [
                'grant_type' => $this->grantType,
            ],
            [
                'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            ]
        );

        $accessToken = $response['access_token'] ?? null;

        if (!is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('FIB access token was not returned.');
        }

        $expiresIn = max(0, (int) ($response['expires_in'] ?? 0));
        self::$cachedAccessToken = $accessToken;
        self::$cachedAccessTokenExpiresAt = time() + max(0, $expiresIn - 5);

        return $accessToken;
    }

    private function normalizeAmount(int|float|string $amount): int
    {
        if (is_int($amount)) {
            if ($amount <= 0) {
                throw new RuntimeException('Amount must be greater than zero.');
            }

            return $amount;
        }

        $stringAmount = is_string($amount) ? trim($amount) : (string) $amount;
        $stringAmount = str_replace(',', '', $stringAmount);

        if ($stringAmount === '' || !is_numeric($stringAmount)) {
            throw new RuntimeException('Amount must be numeric.');
        }

        $floatAmount = (float) $stringAmount;
        $integerAmount = (int) $floatAmount;

        if ($floatAmount <= 0 || abs($floatAmount - $integerAmount) > 0.00001) {
            throw new RuntimeException('FIB amount must be a positive whole number in IQD.');
        }

        return $integerAmount;
    }

    private function sendJsonRequest(
        string $method,
        string $path,
        array $payload,
        array $headers = []
    ): array {
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';

        return $this->executeCurlRequest(
            $method,
            $path,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $headers
        );
    }

    private function sendFormRequest(string $method, string $path, array $payload, array $headers = []): array
    {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Accept: application/json';

        return $this->executeCurlRequest(
            $method,
            $path,
            http_build_query($payload),
            $headers
        );
    }

    private function extractStatus(array $response): string
    {
        $candidates = [
            $response['status'] ?? null,
            $response['paymentStatus'] ?? null,
            $response['data']['status'] ?? null,
            $response['data']['paymentStatus'] ?? null,
            $response['payment']['status'] ?? null,
            $response['payment']['paymentStatus'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return strtoupper(trim($candidate));
            }
        }

        return '';
    }

    private function executeCurlRequest(
        string $method,
        string $path,
        ?string $body,
        array $headers
    ): array {
        $url = $this->baseUrl . $path;
        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 30,
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($curl, $options);

        $responseBody = curl_exec($curl);
        $curlError = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($responseBody === false) {
            throw new RuntimeException('FIB request failed: ' . $curlError);
        }

        $decoded = json_decode($responseBody, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('FIB returned an invalid JSON response.');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = $decoded['error_description']
                ?? $decoded['message']
                ?? $decoded['error']
                ?? 'FIB request failed.';

            throw new RuntimeException(sprintf('FIB API error (%d): %s', $statusCode, (string) $message));
        }

        return $decoded;
    }
}
