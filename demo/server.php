<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use NoPayn\NoPaynClient;

$apiKey     = getenv('NOPAYN_API_KEY') ?: '';
$merchantId = getenv('NOPAYN_MERCHANT_ID') ?: '';
$baseUrl    = getenv('NOPAYN_BASE_URL') ?: 'https://api.nopayn.co.uk';
$publicUrl  = rtrim(getenv('PUBLIC_URL') ?: 'http://localhost:3000', '/');

if (!$apiKey || !$merchantId) {
    fwrite(STDERR, "Set NOPAYN_API_KEY and NOPAYN_MERCHANT_ID environment variables\n");
    exit(1);
}

$nopayn = new NoPaynClient([
    'apiKey'     => $apiKey,
    'merchantId' => $merchantId,
    'baseUrl'    => $baseUrl,
]);

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

// ── Product page ─────────────────────────────────────────────────────────────

if ($method === 'GET' && $path === '/') {
    require __DIR__ . '/views/index.php';
    exit;
}

// ── Initiate payment ─────────────────────────────────────────────────────────

if ($method === 'POST' && $path === '/pay') {
    try {
        $amount   = (int) round(((float) ($_POST['amount'] ?? 9.95)) * 100);
        $currency = $_POST['currency'] ?? 'EUR';
        $orderId  = 'DEMO-' . time();

        $result = $nopayn->generatePaymentUrl([
            'amount'           => $amount,
            'currency'         => $currency,
            'merchantOrderId'  => $orderId,
            'description'      => "Demo order {$orderId}",
            'returnUrl'        => "{$publicUrl}/success?order_id={$orderId}",
            'failureUrl'       => "{$publicUrl}/failure?order_id={$orderId}",
            'webhookUrl'       => "{$publicUrl}/webhook",
            'locale'           => $_POST['locale'] ?? 'en-GB',
            'expirationPeriod' => 'PT30M',
        ]);

        error_log("[PAY] Order {$result['orderId']} created — signature: {$result['signature']}");

        $redirectTo = $result['paymentUrl'] ?? $result['orderUrl'];
        header("Location: {$redirectTo}");
        exit;
    } catch (\Throwable $e) {
        error_log("[PAY] Error: {$e->getMessage()}");
        http_response_code(500);
        $title   = 'Payment Error';
        $message = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        require __DIR__ . '/views/failure.php';
        exit;
    }
}

// ── Return URLs ──────────────────────────────────────────────────────────────

if ($method === 'GET' && $path === '/success') {
    $orderId = htmlspecialchars($_GET['order_id'] ?? '(unknown)', ENT_QUOTES, 'UTF-8');
    require __DIR__ . '/views/success.php';
    exit;
}

if ($method === 'GET' && $path === '/failure') {
    $orderId = htmlspecialchars($_GET['order_id'] ?? '(unknown)', ENT_QUOTES, 'UTF-8');
    $title   = 'Payment Failed';
    $message = "Order {$orderId} was not completed.";
    require __DIR__ . '/views/failure.php';
    exit;
}

// ── Webhook endpoint ─────────────────────────────────────────────────────────

if ($method === 'POST' && $path === '/webhook') {
    try {
        $rawBody  = file_get_contents('php://input');
        $verified = $nopayn->verifyWebhook($rawBody);

        $final = $verified['isFinal'] ? 'true' : 'false';
        error_log("[WEBHOOK] Order {$verified['orderId']} → {$verified['order']['status']} (final: {$final})");

        http_response_code(200);
        echo 'OK';
    } catch (\Throwable $e) {
        error_log("[WEBHOOK] Verification failed: {$e->getMessage()}");
        http_response_code(200);
        echo 'OK';
    }
    exit;
}

// ── Order status check (for demo purposes) ──────────────────────────────────

if ($method === 'GET' && preg_match('#^/status/(.+)$#', $path, $matches)) {
    header('Content-Type: application/json');
    try {
        $order = $nopayn->getOrder($matches[1]);
        echo json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── 404 ──────────────────────────────────────────────────────────────────────

http_response_code(404);
echo 'Not Found';
