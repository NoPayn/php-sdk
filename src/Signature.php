<?php

declare(strict_types=1);

namespace NoPayn;

final class Signature
{
    /**
     * Generate an HMAC-SHA256 signature for a payment payload.
     *
     * Canonical message: "{amount}:{currency}:{orderId}"
     *
     * @param string $secret  The API key used as the HMAC secret
     * @param int    $amount  Amount in smallest currency unit (cents)
     * @param string $currency ISO 4217 currency code
     * @param string $orderId The NoPayn order UUID or merchant order ID
     * @return string Hex-encoded HMAC-SHA256 signature
     */
    public static function generate(
        string $secret,
        int $amount,
        string $currency,
        string $orderId,
    ): string {
        $message = "{$amount}:{$currency}:{$orderId}";
        return hash_hmac('sha256', $message, $secret);
    }

    /**
     * Verify an HMAC-SHA256 signature using constant-time comparison.
     *
     * @return bool true if the signature is valid, false otherwise
     */
    public static function verify(
        string $secret,
        int $amount,
        string $currency,
        string $orderId,
        string $signature,
    ): bool {
        $expected = self::generate($secret, $amount, $currency, $orderId);
        return hash_equals($expected, $signature);
    }
}
