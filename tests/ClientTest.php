<?php

declare(strict_types=1);

namespace NoPayn\Tests;

use NoPayn\Exceptions\ApiException;
use NoPayn\Exceptions\NoPaynException;
use NoPayn\NoPaynClient;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private function createClient(): TestableClient
    {
        return new TestableClient([
            'apiKey' => 'test-api-key',
            'merchantId' => 'test-merchant',
        ]);
    }

    /** @return array<string, mixed> */
    private function sampleOrderResponse(): array
    {
        return [
            'id' => '1c969951-f5f1-4290-ae41-6177961fb3cb',
            'amount' => 1295,
            'currency' => 'EUR',
            'status' => 'new',
            'description' => 'Test order',
            'merchant_order_id' => 'ORDER-001',
            'return_url' => 'https://example.com/success',
            'failure_url' => 'https://example.com/failure',
            'order_url' => 'https://api.nopayn.co.uk/pay/1c969951/',
            'created' => '2026-01-01T00:00:00+00:00',
            'modified' => '2026-01-01T00:00:00+00:00',
            'transactions' => [
                [
                    'id' => 'e3ed069e-c931-40ae-8035-e022e8a4e5e7',
                    'amount' => 1295,
                    'currency' => 'EUR',
                    'payment_method' => 'credit-card',
                    'payment_url' => 'https://api.nopayn.co.uk/redirect/e3ed069e/',
                    'status' => 'new',
                    'created' => '2026-01-01T00:00:00+00:00',
                    'modified' => '2026-01-01T00:00:00+00:00',
                    'expiration_period' => 'PT30M',
                ],
            ],
        ];
    }

    // ── Constructor ──────────────────────────────────────────────────────────

    public function testConstructorThrowsWithoutApiKey(): void
    {
        $this->expectException(NoPaynException::class);
        $this->expectExceptionMessage('apiKey is required');
        new NoPaynClient(['apiKey' => '', 'merchantId' => 'test']);
    }

    public function testConstructorThrowsWithoutMerchantId(): void
    {
        $this->expectException(NoPaynException::class);
        $this->expectExceptionMessage('merchantId is required');
        new NoPaynClient(['apiKey' => 'key', 'merchantId' => '']);
    }

    // ── createOrder ──────────────────────────────────────────────────────────

    public function testCreateOrderSendsCorrectPost(): void
    {
        $client = $this->createClient();
        $client->mockResponse(201, $this->sampleOrderResponse());

        $client->createOrder([
            'amount' => 1295,
            'currency' => 'EUR',
            'merchantOrderId' => 'ORDER-001',
            'description' => 'Test order',
            'returnUrl' => 'https://example.com/success',
            'failureUrl' => 'https://example.com/failure',
            'webhookUrl' => 'https://example.com/webhook',
            'locale' => 'en-GB',
            'expirationPeriod' => 'PT30M',
        ]);

        $req = $client->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/v1/orders/', $req['endpoint']);
        $this->assertSame(1295, $req['body']['amount']);
        $this->assertSame('EUR', $req['body']['currency']);
        $this->assertSame('ORDER-001', $req['body']['merchant_order_id']);
        $this->assertSame('Test order', $req['body']['description']);
        $this->assertSame('https://example.com/success', $req['body']['return_url']);
        $this->assertSame('https://example.com/failure', $req['body']['failure_url']);
        $this->assertSame('https://example.com/webhook', $req['body']['webhook_url']);
        $this->assertSame('en-GB', $req['body']['locale']);
        $this->assertSame('PT30M', $req['body']['expiration_period']);
    }

    public function testCreateOrderMapsResponseCorrectly(): void
    {
        $client = $this->createClient();
        $client->mockResponse(201, $this->sampleOrderResponse());

        $order = $client->createOrder(['amount' => 1295, 'currency' => 'EUR']);

        $this->assertSame('1c969951-f5f1-4290-ae41-6177961fb3cb', $order['id']);
        $this->assertSame(1295, $order['amount']);
        $this->assertSame('EUR', $order['currency']);
        $this->assertSame('new', $order['status']);
        $this->assertSame('ORDER-001', $order['merchantOrderId']);
        $this->assertSame('https://api.nopayn.co.uk/pay/1c969951/', $order['orderUrl']);
        $this->assertCount(1, $order['transactions']);
        $this->assertSame('credit-card', $order['transactions'][0]['paymentMethod']);
        $this->assertSame('https://api.nopayn.co.uk/redirect/e3ed069e/', $order['transactions'][0]['paymentUrl']);
        $this->assertSame('PT30M', $order['transactions'][0]['expirationPeriod']);
    }

    public function testCreateOrderThrowsApiExceptionOn4xx(): void
    {
        $client = $this->createClient();
        $client->mockResponse(400, ['error' => ['message' => 'Invalid amount']]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('HTTP 400');
        $client->createOrder(['amount' => -1, 'currency' => 'EUR']);
    }

    public function testApiExceptionExposesStatusCodeAndBody(): void
    {
        $client = $this->createClient();
        $errorBody = ['error' => ['message' => 'Unauthorized']];
        $client->mockResponse(401, $errorBody);

        try {
            $client->createOrder(['amount' => 1295, 'currency' => 'EUR']);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame($errorBody, $e->getErrorBody());
        }
    }

    public function testCreateOrderThrowsOnNetworkError(): void
    {
        $client = $this->createClient();
        $client->mockNetworkError('Connection refused');

        $this->expectException(NoPaynException::class);
        $this->expectExceptionMessage('Network error');
        $client->createOrder(['amount' => 1295, 'currency' => 'EUR']);
    }

    // ── getOrder ─────────────────────────────────────────────────────────────

    public function testGetOrderSendsCorrectGet(): void
    {
        $client = $this->createClient();
        $client->mockResponse(200, $this->sampleOrderResponse());

        $order = $client->getOrder('1c969951-f5f1-4290-ae41-6177961fb3cb');

        $req = $client->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/v1/orders/1c969951-f5f1-4290-ae41-6177961fb3cb/', $req['endpoint']);
        $this->assertNull($req['body']);
        $this->assertSame('1c969951-f5f1-4290-ae41-6177961fb3cb', $order['id']);
    }

    // ── createRefund ─────────────────────────────────────────────────────────

    public function testCreateRefundSendsCorrectPost(): void
    {
        $client = $this->createClient();
        $client->mockResponse(201, [
            'id' => 'refund-uuid',
            'amount' => 500,
            'status' => 'pending',
        ]);

        $refund = $client->createRefund('order-123', 500, 'Customer returned item');

        $req = $client->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertStringContainsString('/refunds/', $req['endpoint']);
        $this->assertSame(500, $req['body']['amount']);
        $this->assertSame('Customer returned item', $req['body']['description']);
        $this->assertSame('refund-uuid', $refund['id']);
        $this->assertSame(500, $refund['amount']);
        $this->assertSame('pending', $refund['status']);
    }

    public function testCreateRefundWithoutDescription(): void
    {
        $client = $this->createClient();
        $client->mockResponse(201, [
            'id' => 'refund-uuid',
            'amount' => 1295,
            'status' => 'pending',
        ]);

        $client->createRefund('order-123', 1295);

        $req = $client->getLastRequest();
        $this->assertArrayNotHasKey('description', $req['body']);
    }

    // ── captureTransaction ─────────────────────────────────────────────────

    public function testCaptureTransactionSendsCorrectPost(): void
    {
        $client = $this->createClient();
        $client->mockResponse(200, [
            'id' => 'txn-456',
            'amount' => 1295,
            'currency' => 'EUR',
            'status' => 'captured',
            'payment_method' => 'credit-card',
            'created' => '2026-01-01T00:00:00+00:00',
            'modified' => '2026-01-01T00:01:00+00:00',
        ]);

        $result = $client->captureTransaction('order-123', 'txn-456');

        $req = $client->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/v1/orders/order-123/transactions/txn-456/captures/', $req['endpoint']);
        $this->assertNull($req['body']);
        $this->assertSame('txn-456', $result['id']);
        $this->assertSame('captured', $result['status']);
    }

    // ── voidTransaction ──────────────────────────────────────────────────────

    public function testVoidTransactionSendsCorrectPost(): void
    {
        $client = $this->createClient();
        $client->mockResponse(200, [
            'id' => 'txn-456',
            'amount' => 500,
            'currency' => 'EUR',
            'status' => 'voided',
            'created' => '2026-01-01T00:00:00+00:00',
            'modified' => '2026-01-01T00:01:00+00:00',
        ]);

        $result = $client->voidTransaction('order-123', 'txn-456', 500, 'Customer cancelled');

        $req = $client->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/v1/orders/order-123/transactions/txn-456/voids/amount/', $req['endpoint']);
        $this->assertSame(500, $req['body']['amount']);
        $this->assertSame('Customer cancelled', $req['body']['description']);
        $this->assertSame('txn-456', $result['id']);
        $this->assertSame('voided', $result['status']);
    }

    public function testVoidTransactionWithoutDescription(): void
    {
        $client = $this->createClient();
        $client->mockResponse(200, [
            'id' => 'txn-456',
            'amount' => 1295,
            'currency' => 'EUR',
            'status' => 'voided',
            'created' => '2026-01-01T00:00:00+00:00',
            'modified' => '2026-01-01T00:01:00+00:00',
        ]);

        $client->voidTransaction('order-123', 'txn-456', 1295);

        $req = $client->getLastRequest();
        $this->assertArrayNotHasKey('description', $req['body']);
    }

    // ── createOrder with order_lines ─────────────────────────────────────────

    public function testCreateOrderWithOrderLines(): void
    {
        $client = $this->createClient();
        $client->mockResponse(201, $this->sampleOrderResponse());

        $orderLines = [
            [
                'name' => 'Widget',
                'quantity' => 2,
                'unit_price' => 500,
                'total_amount' => 1000,
            ],
            [
                'name' => 'Shipping',
                'quantity' => 1,
                'unit_price' => 295,
                'total_amount' => 295,
            ],
        ];

        $client->createOrder([
            'amount' => 1295,
            'currency' => 'EUR',
            'order_lines' => $orderLines,
        ]);

        $req = $client->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/v1/orders/', $req['endpoint']);
        $this->assertSame(1295, $req['body']['amount']);
        $this->assertSame('EUR', $req['body']['currency']);
        $this->assertCount(2, $req['body']['order_lines']);
        $this->assertSame('Widget', $req['body']['order_lines'][0]['name']);
        $this->assertSame(295, $req['body']['order_lines'][1]['total_amount']);
    }

    // ── createOrder with transactions ────────────────────────────────────────

    public function testCreateOrderWithTransactions(): void
    {
        $client = $this->createClient();
        $client->mockResponse(201, $this->sampleOrderResponse());

        $transactions = [
            [
                'payment_method' => 'credit-card',
                'capture_mode' => 'manual',
                'expiration_period' => 'PT30M',
            ],
        ];

        $client->createOrder([
            'amount' => 1295,
            'currency' => 'EUR',
            'transactions' => $transactions,
        ]);

        $req = $client->getLastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('/v1/orders/', $req['endpoint']);
        $this->assertCount(1, $req['body']['transactions']);
        $this->assertSame('credit-card', $req['body']['transactions'][0]['payment_method']);
        $this->assertSame('manual', $req['body']['transactions'][0]['capture_mode']);
        $this->assertSame('PT30M', $req['body']['transactions'][0]['expiration_period']);
    }

    public function testCreateOrderWithCustomer(): void
    {
        $client = $this->createClient();
        $client->mockResponse(201, $this->sampleOrderResponse());

        $customer = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
        ];

        $client->createOrder([
            'amount' => 1295,
            'currency' => 'EUR',
            'customer' => $customer,
        ]);

        $req = $client->getLastRequest();
        $this->assertSame('John', $req['body']['customer']['first_name']);
        $this->assertSame('Doe', $req['body']['customer']['last_name']);
        $this->assertSame('john@example.com', $req['body']['customer']['email']);
    }

    // ── generatePaymentUrl ───────────────────────────────────────────────────

    public function testGeneratePaymentUrlReturnsUrlAndSignature(): void
    {
        $client = $this->createClient();
        $client->mockResponse(201, $this->sampleOrderResponse());

        $result = $client->generatePaymentUrl(['amount' => 1295, 'currency' => 'EUR']);

        $this->assertSame('1c969951-f5f1-4290-ae41-6177961fb3cb', $result['orderId']);
        $this->assertSame('https://api.nopayn.co.uk/pay/1c969951/', $result['orderUrl']);
        $this->assertSame('https://api.nopayn.co.uk/redirect/e3ed069e/', $result['paymentUrl']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['signature']);
        $this->assertIsArray($result['order']);

        $this->assertTrue(
            $client->verifySignature(1295, 'EUR', $result['orderId'], $result['signature']),
        );
    }

    // ── Signature convenience methods ────────────────────────────────────────

    public function testGenerateAndVerifySignature(): void
    {
        $client = $this->createClient();
        $sig = $client->generateSignature(1295, 'EUR', 'order-123');
        $this->assertTrue($client->verifySignature(1295, 'EUR', 'order-123', $sig));
    }

    public function testVerifySignatureRejectsTamperedData(): void
    {
        $client = $this->createClient();
        $sig = $client->generateSignature(1295, 'EUR', 'order-123');
        $this->assertFalse($client->verifySignature(999, 'EUR', 'order-123', $sig));
    }
}
