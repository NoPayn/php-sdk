<?php

declare(strict_types=1);

namespace NoPayn\Tests;

use NoPayn\Signature;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
{
    private const SECRET = 'test-secret-key';

    public function testDeterministicOutput(): void
    {
        $sig1 = Signature::generate(self::SECRET, 1295, 'EUR', 'order-123');
        $sig2 = Signature::generate(self::SECRET, 1295, 'EUR', 'order-123');

        $this->assertSame($sig1, $sig2);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $sig1);
    }

    public function testRoundTripVerify(): void
    {
        $sig = Signature::generate(self::SECRET, 1295, 'EUR', 'order-123');
        $this->assertTrue(Signature::verify(self::SECRET, 1295, 'EUR', 'order-123', $sig));
    }

    public function testTamperedAmountFails(): void
    {
        $sig = Signature::generate(self::SECRET, 1295, 'EUR', 'order-123');
        $this->assertFalse(Signature::verify(self::SECRET, 999, 'EUR', 'order-123', $sig));
    }

    public function testTamperedCurrencyFails(): void
    {
        $sig = Signature::generate(self::SECRET, 1295, 'EUR', 'order-123');
        $this->assertFalse(Signature::verify(self::SECRET, 1295, 'GBP', 'order-123', $sig));
    }

    public function testTamperedOrderIdFails(): void
    {
        $sig = Signature::generate(self::SECRET, 1295, 'EUR', 'order-123');
        $this->assertFalse(Signature::verify(self::SECRET, 1295, 'EUR', 'order-456', $sig));
    }

    public function testWrongKeyFails(): void
    {
        $sig = Signature::generate(self::SECRET, 1295, 'EUR', 'order-123');
        $this->assertFalse(Signature::verify('wrong-key', 1295, 'EUR', 'order-123', $sig));
    }

    public function testMalformedSignatureFails(): void
    {
        $this->assertFalse(
            Signature::verify(self::SECRET, 1295, 'EUR', 'order-123', 'not-a-valid-hex'),
        );
    }
}
