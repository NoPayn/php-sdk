<?php

declare(strict_types=1);

namespace NoPayn\Tests;

use NoPayn\Exceptions\WebhookException;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private function createClient(): TestableClient
    {
        return new TestableClient([
            'apiKey' => 'test-api-key',
            'merchantId' => 'test-merchant',
        ]);
    }

    /** @return array<string, mixed> */
    private function orderResponse(string $status): array
    {
        return [
            'id' => 'order-123',
            'amount' => 1295,
            'currency' => 'EUR',
            'status' => $status,
            'created' => '2026-01-01T00:00:00+00:00',
            'modified' => '2026-01-01T00:00:00+00:00',
            'completed' => $status === 'completed' ? '2026-01-01T00:01:00+00:00' : null,
            'transactions' => [],
        ];
    }

    // ── parseWebhookBody ─────────────────────────────────────────────────────

    public function testParseWebhookBodyWithValidJson(): void
    {
        $client = $this->createClient();

        $payload = $client->parseWebhookBody(json_encode([
            'event' => 'status_changed',
            'order_id' => 'order-123',
            'project_id' => 'project-456',
        ]));

        $this->assertSame('status_changed', $payload['event']);
        $this->assertSame('order-123', $payload['order_id']);
        $this->assertSame('project-456', $payload['project_id']);
    }

    public function testParseWebhookBodyWithoutProjectId(): void
    {
        $client = $this->createClient();

        $payload = $client->parseWebhookBody(json_encode([
            'event' => 'status_changed',
            'order_id' => 'order-123',
        ]));

        $this->assertNull($payload['project_id']);
    }

    public function testParseWebhookBodyThrowsOnInvalidJson(): void
    {
        $client = $this->createClient();

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('Invalid JSON');
        $client->parseWebhookBody('not valid json{');
    }

    public function testParseWebhookBodyThrowsOnMissingOrderId(): void
    {
        $client = $this->createClient();

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('Missing order_id');
        $client->parseWebhookBody(json_encode(['event' => 'status_changed']));
    }

    public function testParseWebhookBodyThrowsOnEmptyOrderId(): void
    {
        $client = $this->createClient();

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('Missing order_id');
        $client->parseWebhookBody(json_encode([
            'event' => 'status_changed',
            'order_id' => '',
        ]));
    }

    // ── verifyWebhook ────────────────────────────────────────────────────────

    public function testVerifyWebhookFetchesOrderAndReturnsVerifiedData(): void
    {
        $client = $this->createClient();
        $client->mockResponse(200, $this->orderResponse('completed'));

        $result = $client->verifyWebhook(json_encode([
            'event' => 'status_changed',
            'order_id' => 'order-123',
        ]));

        $this->assertSame('order-123', $result['orderId']);
        $this->assertSame('completed', $result['order']['status']);
        $this->assertTrue($result['isFinal']);

        $req = $client->getLastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertStringContainsString('order-123', $req['endpoint']);
    }

    public function testVerifyWebhookReturnsFalseForNonFinalStatus(): void
    {
        $client = $this->createClient();
        $client->mockResponse(200, $this->orderResponse('processing'));

        $result = $client->verifyWebhook(json_encode([
            'event' => 'status_changed',
            'order_id' => 'order-123',
        ]));

        $this->assertFalse($result['isFinal']);
    }

    /**
     * @dataProvider finalStatusProvider
     */
    public function testVerifyWebhookIsFinalForAllTerminalStatuses(string $status): void
    {
        $client = $this->createClient();
        $client->mockResponse(200, $this->orderResponse($status));

        $result = $client->verifyWebhook(json_encode([
            'event' => 'status_changed',
            'order_id' => 'order-123',
        ]));

        $this->assertTrue($result['isFinal'], "Expected isFinal=true for status '{$status}'");
    }

    /** @return iterable<string, array{string}> */
    public static function finalStatusProvider(): iterable
    {
        yield 'completed' => ['completed'];
        yield 'cancelled' => ['cancelled'];
        yield 'expired' => ['expired'];
        yield 'error' => ['error'];
    }

    /**
     * @dataProvider nonFinalStatusProvider
     */
    public function testVerifyWebhookIsNotFinalForNonTerminalStatuses(string $status): void
    {
        $client = $this->createClient();
        $client->mockResponse(200, $this->orderResponse($status));

        $result = $client->verifyWebhook(json_encode([
            'event' => 'status_changed',
            'order_id' => 'order-123',
        ]));

        $this->assertFalse($result['isFinal'], "Expected isFinal=false for status '{$status}'");
    }

    /** @return iterable<string, array{string}> */
    public static function nonFinalStatusProvider(): iterable
    {
        yield 'new' => ['new'];
        yield 'processing' => ['processing'];
    }
}
