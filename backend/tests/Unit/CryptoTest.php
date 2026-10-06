<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\BlindIndex;
use App\Security\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    private function crypto(int $active = 1): Crypto
    {
        return new Crypto([1 => random_bytes(32), 2 => random_bytes(32)], $active);
    }

    public function testRoundTripAndVersionedEnvelope(): void
    {
        $crypto = $this->crypto();
        $envelope = $crypto->encrypt('Confidential objective', 'applications.core_objective');

        self::assertStringStartsWith('v1.', $envelope);
        self::assertStringNotContainsString('Confidential', $envelope);
        self::assertSame('Confidential objective', $crypto->decrypt($envelope, 'applications.core_objective'));
    }

    public function testNullPassesThrough(): void
    {
        self::assertNull($this->crypto()->encrypt(null, 'x.y'));
        self::assertNull($this->crypto()->decrypt(null, 'x.y'));
    }

    public function testNoncesAreUnique(): void
    {
        $crypto = $this->crypto();
        self::assertNotSame($crypto->encrypt('same', 'a.b'), $crypto->encrypt('same', 'a.b'));
    }

    public function testCiphertextIsBoundToItsField(): void
    {
        $crypto = $this->crypto();
        $envelope = $crypto->encrypt('secret', 'appointments.client_email');

        $this->expectException(\RuntimeException::class);
        $crypto->decrypt($envelope, 'appointments.client_name');
    }

    public function testTamperingIsDetected(): void
    {
        $crypto = $this->crypto();
        $envelope = $crypto->encrypt('secret value', 'a.b');
        $tampered = substr($envelope, 0, -2) . (str_ends_with($envelope, 'A') ? 'BB' : 'AA');

        $this->expectException(\RuntimeException::class);
        $crypto->decrypt($tampered, 'a.b');
    }

    public function testRotationReadsOldKeysAndFlagsThem(): void
    {
        $keys = [1 => random_bytes(32), 2 => random_bytes(32)];
        $old = (new Crypto($keys, 1))->encrypt('rotate me', 'a.b');
        $current = new Crypto($keys, 2);

        self::assertTrue($current->needsRotation($old));
        self::assertSame('rotate me', $current->decrypt($old, 'a.b'));
        self::assertFalse($current->needsRotation($current->encrypt('rotate me', 'a.b')));
    }

    public function testBlindIndexNormalisesEmailAndIsKeyed(): void
    {
        $a = new BlindIndex(random_bytes(32));
        $b = new BlindIndex(random_bytes(32));

        self::assertSame($a->email('Client@Example.com '), $a->email('client@example.com'));
        self::assertNotSame($a->email('client@example.com'), $b->email('client@example.com'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $a->email('client@example.com'));
    }
}
