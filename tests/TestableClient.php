<?php

declare(strict_types=1);

namespace NoPayn\Tests;

use NoPayn\Exceptions\ApiException;
use NoPayn\Exceptions\NoPaynException;
use NoPayn\NoPaynClient;

/**
 * Subclass that replaces the HTTP layer with an in-memory mock queue,
 * allowing unit tests to run without network access.
 */
class TestableClient extends NoPaynClient
{
    /** @var list<array{type: string, statusCode?: int, body?: array<string, mixed>, message?: string}> */
    private array $mockResponses = [];

    /** @var list<array{method: string, endpoint: string, body: ?array<string, mixed>}> */
    private array $requests = [];

    /**
     * Enqueue a mock HTTP response.
     *
     * @param array<string, mixed> $body
     */
    public function mockResponse(int $statusCode, array $body): void
    {
        $this->mockResponses[] = [
            'type' => 'response',
            'statusCode' => $statusCode,
            'body' => $body,
        ];
    }

    /**
     * Enqueue a mock network error.
     */
    public function mockNetworkError(string $message): void
    {
        $this->mockResponses[] = [
            'type' => 'error',
            'message' => $message,
        ];
    }

    /**
     * @return array{method: string, endpoint: string, body: ?array<string, mixed>}|null
     */
    public function getLastRequest(): ?array
    {
        $last = end($this->requests);
        return $last !== false ? $last : null;
    }

    /**
     * @return list<array{method: string, endpoint: string, body: ?array<string, mixed>}>
     */
    public function getRequests(): array
    {
        return $this->requests;
    }

    protected function request(string $method, string $endpoint, ?array $body = null): array
    {
        $this->requests[] = [
            'method' => $method,
            'endpoint' => $endpoint,
            'body' => $body,
        ];

        if ($this->mockResponses === []) {
            throw new NoPaynException('No mock responses queued');
        }

        $mock = array_shift($this->mockResponses);

        if ($mock['type'] === 'error') {
            throw new NoPaynException("Network error: {$mock['message']}");
        }

        $statusCode = $mock['statusCode'];
        $responseBody = $mock['body'];

        if ($statusCode >= 400) {
            $errorMsg = $responseBody['error']['value']
                ?? $responseBody['error']['message']
                ?? $responseBody['detail']
                ?? 'Unknown error';
            throw new ApiException($statusCode, (string) $errorMsg, $responseBody);
        }

        return $responseBody;
    }
}
