# nopayn-php-sdk

Official PHP SDK for the [NoPayn Payment Gateway](https://costplus.io). Simplifies the HPP (Hosted Payment Page) redirect flow, HMAC payload signing, and webhook verification.

[![CI](https://github.com/NoPayn/php-sdk/actions/workflows/ci.yml/badge.svg)](https://github.com/NoPayn/php-sdk/actions/workflows/ci.yml)

## Features

- **Zero external dependencies** — uses only built-in PHP extensions (`curl`, `json`, `hash`)
- PHP 8.1+ with readonly properties, named arguments, and strict types
- HMAC-SHA256 signature generation and constant-time verification via `hash_equals`
- Automatic snake_case/camelCase mapping between the API and the SDK
- Webhook parsing + API-based order verification (as recommended by NoPayn)
- Tested across PHP 8.1, 8.2, and 8.3

## Requirements

- PHP >= 8.1
- Extensions: `curl`, `json` (both included in standard PHP)
- A NoPayn / Cost+ merchant account — [manage.nopayn.io](https://manage.nopayn.io/)

## Installation

```bash
composer require nopayn/sdk
```

## Quick Start

### 1. Initialise the client

```php
use NoPayn\NoPaynClient;

$nopayn = new NoPaynClient([
    'apiKey'     => 'your-api-key',      // From the NoPayn merchant portal
    'merchantId' => 'your-project',      // Your project/merchant ID
]);
```

### 2. Create a payment and redirect to the HPP

```php
$result = $nopayn->generatePaymentUrl([
    'amount'           => 1295,            // €12.95 in cents
    'currency'         => 'EUR',
    'merchantOrderId'  => 'ORDER-001',
    'description'      => 'Premium Widget',
    'returnUrl'        => 'https://shop.example.com/success',
    'failureUrl'       => 'https://shop.example.com/failure',
    'webhookUrl'       => 'https://shop.example.com/webhook',
    'locale'           => 'en-GB',
    'expirationPeriod' => 'PT30M',
]);

// Redirect the customer
// $result['orderUrl']   → HPP (customer picks payment method)
// $result['paymentUrl'] → direct link to the first transaction's payment method
// $result['signature']  → HMAC-SHA256 for verification
// $result['orderId']    → NoPayn order UUID
header('Location: ' . ($result['paymentUrl'] ?? $result['orderUrl']));
```

### 3. Handle the webhook

```php
$rawBody  = file_get_contents('php://input');
$verified = $nopayn->verifyWebhook($rawBody);

echo $verified['order']['status']; // 'completed', 'cancelled', etc.
echo $verified['isFinal'];        // true when the order won't change

if ($verified['order']['status'] === 'completed') {
    // Fulfil the order
}

http_response_code(200);
```

## API Reference

### `new NoPaynClient(array $config)`

| Parameter | Type | Required | Default |
|-----------|------|----------|---------|
| `apiKey` | `string` | Yes | — |
| `merchantId` | `string` | Yes | — |
| `baseUrl` | `string` | No | `https://api.nopayn.co.uk` |

### `$client->createOrder(array $params): array`

Creates an order via `POST /v1/orders/`.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `amount` | `int` | Yes | Amount in smallest currency unit (cents) |
| `currency` | `string` | Yes | ISO 4217 code (`EUR`, `GBP`, `USD`, `NOK`, `SEK`) |
| `merchantOrderId` | `string` | No | Your internal order reference |
| `description` | `string` | No | Order description |
| `returnUrl` | `string` | No | Redirect after successful payment |
| `failureUrl` | `string` | No | Redirect on cancel/expiry/error |
| `webhookUrl` | `string` | No | Async status-change notifications |
| `locale` | `string` | No | HPP language (`en-GB`, `de-DE`, `nl-NL`, etc.) |
| `paymentMethods` | `string[]` | No | Filter HPP methods |
| `expirationPeriod` | `string` | No | ISO 8601 duration (`PT30M`) |

**Available payment methods:** `credit-card`, `apple-pay`, `google-pay`, `vipps-mobilepay`

### `$client->getOrder(string $orderId): array`

Fetches order details via `GET /v1/orders/{id}/`.

### `$client->createRefund(string $orderId, int $amount, ?string $description = null): array`

Issues a full or partial refund via `POST /v1/orders/{id}/refunds/`.

### `$client->generatePaymentUrl(array $params): array`

Convenience method that creates an order and returns:

```php
[
    'orderId'    => string,   // NoPayn order UUID
    'orderUrl'   => string,   // HPP URL
    'paymentUrl' => ?string,  // Direct payment URL (first transaction)
    'signature'  => string,   // HMAC-SHA256 of amount:currency:orderId
    'order'      => array,    // Full order object
]
```

### `$client->generateSignature(int $amount, string $currency, string $orderId): string`

Generates an HMAC-SHA256 hex signature. The canonical message is `{amount}:{currency}:{orderId}`, signed with the API key.

### `$client->verifySignature(int $amount, string $currency, string $orderId, string $signature): bool`

Constant-time verification of an HMAC-SHA256 signature. Returns `true` if valid.

### `$client->verifyWebhook(string $rawBody): array`

Parses the webhook body, then calls `GET /v1/orders/{id}/` to verify the actual status. Returns:

```php
[
    'orderId' => string,
    'order'   => array,  // Verified via API
    'isFinal' => bool,   // true for completed/cancelled/expired/error
]
```

### `$client->parseWebhookBody(string $rawBody): array`

Parses and validates a webhook body without calling the API.

### Standalone HMAC Utilities

```php
use NoPayn\Signature;

$sig = Signature::generate('your-api-key', 1295, 'EUR', 'order-uuid');
$ok  = Signature::verify('your-api-key', 1295, 'EUR', 'order-uuid', $sig);
```

## Error Handling

```php
use NoPayn\Exceptions\ApiException;
use NoPayn\Exceptions\NoPaynException;
use NoPayn\Exceptions\WebhookException;

try {
    $nopayn->createOrder(['amount' => 100, 'currency' => 'EUR']);
} catch (ApiException $e) {
    echo $e->getStatusCode(); // 401, 400, etc.
    print_r($e->getErrorBody()); // Raw API error response
} catch (NoPaynException $e) {
    echo $e->getMessage(); // Network or parsing error
}
```

## Order Statuses

| Status | Final? | Description |
|--------|--------|-------------|
| `new` | No | Order created |
| `processing` | No | Payment in progress |
| `completed` | Yes | Payment successful — deliver the goods |
| `cancelled` | Yes | Payment cancelled by customer |
| `expired` | Yes | Payment link timed out |
| `error` | Yes | Technical failure |

## Webhook Best Practices

1. **Always verify via the API** — the webhook payload only contains the order ID, never the status. The SDK's `verifyWebhook()` does this automatically.
2. **Return HTTP 200** to acknowledge receipt. Any other code triggers up to 10 retries (2 minutes apart).
3. **Implement a backup poller** — for orders older than 10 minutes that haven't reached a final status, poll `getOrder()` as a safety net.
4. **Be idempotent** — you may receive the same webhook multiple times.

## Demo Merchant Site

A Docker-based demo app is included for testing the full payment flow.

### Run with Docker Compose

```bash
cd demo

# Create a .env file
cat > .env << EOF
NOPAYN_API_KEY=your-api-key
NOPAYN_MERCHANT_ID=your-merchant-id
PUBLIC_URL=http://localhost:3000
EOF

docker compose up --build
```

Open [http://localhost:3000](http://localhost:3000) to see the demo checkout page.

### Run without Docker

```bash
# Install dependencies
composer install

# Start the server
NOPAYN_API_KEY=your-key NOPAYN_MERCHANT_ID=your-id php -S localhost:3000 demo/router.php
```

## Testing

```bash
composer install
vendor/bin/phpunit              # Run all tests
vendor/bin/phpunit --coverage-text  # With coverage report
```

## Test Cards

Use these cards in NoPayn test mode (project status `active-testing`):

| Card | Number | Notes |
|------|--------|-------|
| Visa (frictionless) | `4018 8100 0010 0036` | No 3DS challenge |
| Mastercard (frictionless) | `5420 7110 0021 0016` | No 3DS challenge |
| Visa (3DS) | `4018 8100 0015 0015` | OTP: `0101` (success), `3333` (fail) |
| Mastercard (3DS) | `5299 9100 1000 0015` | OTP: `4445` (success), `9999` (fail) |

Use any future expiry date and any 3-digit CVC.

## License

MIT — see [LICENSE](LICENSE).

## Support

- NoPayn API docs: [dev.nopayn.io](https://dev.nopayn.io/)
- Merchant portal: [manage.nopayn.io](https://manage.nopayn.io/)
- Developer: [Cost+](https://costplus.io)
