<?php

declare(strict_types=1);

namespace NoPayn;

use NoPayn\Exceptions\ApiException;
use NoPayn\Exceptions\NoPaynException;
use NoPayn\Exceptions\WebhookException;

class NoPaynClient
{
    private const DEFAULT_BASE_URL = 'https://api.nopayn.co.uk';

    private const FINAL_STATUSES = ['completed', 'cancelled', 'expired', 'error'];

    private readonly string $apiKey;
    private readonly string $merchantId;
    private readonly string $baseUrl;

    /**
     * @param array{apiKey: string, merchantId: string, baseUrl?: string} $config
     */
    public function __construct(array $config)
    {
        if (empty($config['apiKey'])) {
            throw new NoPaynException('apiKey is required');
        }
        if (empty($config['merchantId'])) {
            throw new NoPaynException('merchantId is required');
        }

        $this->apiKey = $config['apiKey'];
        $this->merchantId = $config['merchantId'];
        $this->baseUrl = rtrim($config['baseUrl'] ?? self::DEFAULT_BASE_URL, '/');
    }

    // -------------------------------------------------------------------------
    // Order API
    // -------------------------------------------------------------------------

    /**
     * Create an order via POST /v1/orders/.
     * Returns the full order object including orderUrl (the HPP link).
     *
     * @param array{
     *     amount: int,
     *     currency: string,
     *     merchantOrderId?: string,
     *     description?: string,
     *     returnUrl?: string,
     *     failureUrl?: string,
     *     webhookUrl?: string,
     *     locale?: string,
     *     paymentMethods?: string[],
     *     expirationPeriod?: string,
     * } $params
     * @return array<string, mixed>
     */
    public function createOrder(array $params): array
    {
        $body = $this->buildOrderBody($params);
        $data = $this->request('POST', '/v1/orders/', $body);
        return self::mapOrder($data);
    }

    /**
     * Fetch an existing order via GET /v1/orders/{id}/.
     *
     * @return array<string, mixed>
     */
    public function getOrder(string $orderId): array
    {
        $data = $this->request('GET', '/v1/orders/' . rawurlencode($orderId) . '/');
        return self::mapOrder($data);
    }

    /**
     * Issue a full or partial refund via POST /v1/orders/{id}/refunds/.
     *
     * @return array{id: string, amount: int, status: string}
     */
    public function createRefund(string $orderId, int $amount, ?string $description = null): array
    {
        $body = ['amount' => $amount];
        if ($description !== null) {
            $body['description'] = $description;
        }

        $data = $this->request(
            'POST',
            '/v1/orders/' . rawurlencode($orderId) . '/refunds/',
            $body,
        );

        return [
            'id' => $data['id'],
            'amount' => $data['amount'],
            'status' => $data['status'],
        ];
    }

    // -------------------------------------------------------------------------
    // HPP Redirect — the "handshake"
    // -------------------------------------------------------------------------

    /**
     * Create an order and return the HPP redirect URL with an HMAC signature.
     *
     * The signature covers amount:currency:orderId so the merchant can later
     * verify that the return/callback parameters haven't been tampered with.
     *
     * @param array{
     *     amount: int,
     *     currency: string,
     *     merchantOrderId?: string,
     *     description?: string,
     *     returnUrl?: string,
     *     failureUrl?: string,
     *     webhookUrl?: string,
     *     locale?: string,
     *     paymentMethods?: string[],
     *     expirationPeriod?: string,
     * } $params
     * @return array{orderId: string, orderUrl: string, paymentUrl: ?string, signature: string, order: array<string, mixed>}
     */
    public function generatePaymentUrl(array $params): array
    {
        $order = $this->createOrder($params);

        $signature = Signature::generate(
            $this->apiKey,
            $params['amount'],
            $params['currency'],
            $order['id'],
        );

        return [
            'orderId' => $order['id'],
            'orderUrl' => $order['orderUrl'],
            'paymentUrl' => $order['transactions'][0]['paymentUrl'] ?? null,
            'signature' => $signature,
            'order' => $order,
        ];
    }

    // -------------------------------------------------------------------------
    // HMAC Signature Utilities
    // -------------------------------------------------------------------------

    /**
     * Generate an HMAC-SHA256 hex signature for the given payment parameters.
     * Canonical message: {amount}:{currency}:{orderId}
     */
    public function generateSignature(int $amount, string $currency, string $orderId): string
    {
        return Signature::generate($this->apiKey, $amount, $currency, $orderId);
    }

    /**
     * Constant-time verification of an HMAC-SHA256 signature.
     */
    public function verifySignature(
        int $amount,
        string $currency,
        string $orderId,
        string $signature,
    ): bool {
        return Signature::verify($this->apiKey, $amount, $currency, $orderId, $signature);
    }

    // -------------------------------------------------------------------------
    // Webhook Handling
    // -------------------------------------------------------------------------

    /**
     * Parse a raw webhook body into a typed payload.
     * Throws WebhookException if the body is invalid.
     *
     * @return array{event: string, order_id: string, project_id: ?string}
     */
    public function parseWebhookBody(string $rawBody): array
    {
        $body = json_decode($rawBody, true);

        if (!is_array($body)) {
            throw new WebhookException('Invalid JSON in webhook body');
        }

        if (empty($body['order_id']) || !is_string($body['order_id'])) {
            throw new WebhookException('Missing order_id in webhook payload');
        }

        return [
            'event' => $body['event'] ?? '',
            'order_id' => $body['order_id'],
            'project_id' => $body['project_id'] ?? null,
        ];
    }

    /**
     * Full webhook verification: parse the body, then call the API to confirm
     * the actual order status. Never trust the webhook payload alone.
     *
     * Returns the verified order and whether it has reached a final status.
     *
     * @return array{orderId: string, order: array<string, mixed>, isFinal: bool}
     */
    public function verifyWebhook(string $rawBody): array
    {
        $payload = $this->parseWebhookBody($rawBody);
        $order = $this->getOrder($payload['order_id']);

        return [
            'orderId' => $payload['order_id'],
            'order' => $order,
            'isFinal' => in_array($order['status'], self::FINAL_STATUSES, true),
        ];
    }

    // -------------------------------------------------------------------------
    // Internal HTTP
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    protected function request(string $method, string $endpoint, ?array $body = null): array
    {
        $url = $this->baseUrl . $endpoint;

        $headers = [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($this->apiKey . ':'),
        ];

        if ($body !== null && $method !== 'GET') {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        if ($body !== null && $method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new NoPaynException("Network error: {$error}");
        }

        /** @var string $response */
        $data = $response !== '' ? json_decode($response, true) : [];

        if (!is_array($data)) {
            throw new NoPaynException(
                'Invalid JSON response: ' . substr((string) $response, 0, 200),
            );
        }

        if ($httpCode >= 400) {
            $errorMsg = $data['error']['value']
                ?? $data['error']['message']
                ?? $data['detail']
                ?? 'Unknown error';
            throw new ApiException($httpCode, (string) $errorMsg, $data);
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // Response mapping (snake_case API → camelCase SDK)
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function mapOrder(array $data): array
    {
        $transactions = array_map(
            [self::class, 'mapTransaction'],
            $data['transactions'] ?? [],
        );

        return [
            'id' => $data['id'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'status' => $data['status'],
            'description' => $data['description'] ?? null,
            'merchantOrderId' => $data['merchant_order_id'] ?? null,
            'returnUrl' => $data['return_url'] ?? null,
            'failureUrl' => $data['failure_url'] ?? null,
            'orderUrl' => $data['order_url'] ?? null,
            'created' => $data['created'],
            'modified' => $data['modified'],
            'completed' => $data['completed'] ?? null,
            'transactions' => $transactions,
        ];
    }

    /**
     * @param array<string, mixed> $t
     * @return array<string, mixed>
     */
    private static function mapTransaction(array $t): array
    {
        return [
            'id' => $t['id'],
            'amount' => $t['amount'],
            'currency' => $t['currency'],
            'paymentMethod' => $t['payment_method'] ?? null,
            'paymentUrl' => $t['payment_url'] ?? null,
            'status' => $t['status'],
            'created' => $t['created'],
            'modified' => $t['modified'],
            'expirationPeriod' => $t['expiration_period'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function buildOrderBody(array $params): array
    {
        $body = [
            'amount' => $params['amount'],
            'currency' => $params['currency'],
        ];

        $map = [
            'merchantOrderId' => 'merchant_order_id',
            'description' => 'description',
            'returnUrl' => 'return_url',
            'failureUrl' => 'failure_url',
            'webhookUrl' => 'webhook_url',
            'locale' => 'locale',
            'paymentMethods' => 'payment_methods',
            'expirationPeriod' => 'expiration_period',
        ];

        foreach ($map as $camelKey => $snakeKey) {
            if (array_key_exists($camelKey, $params)) {
                $body[$snakeKey] = $params[$camelKey];
            }
        }

        return $body;
    }
}
