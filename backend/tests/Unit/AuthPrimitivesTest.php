<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\HttpException;
use App\Security\PasswordHasher;
use App\Security\SignedUrl;
use App\Security\Tokens;
use App\Security\Totp;
use PHPUnit\Framework\TestCase;

final class AuthPrimitivesTest extends TestCase
{
    public function testTotpMatchesRfc6238Vector(): void
    {
        // RFC 6238 Appendix B, SHA-1 secret "12345678901234567890" (base32 below), T = 59 → 94287082 (8 digits) → 287082 (6 digits).
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        self::assertSame('287082', Totp::code($secret, 59));
        self::assertTrue(Totp::verify($secret, '287082', 59));
        self::assertTrue(Totp::verify($secret, '287082', 59 + 30), 'one step of clock drift is tolerated');
        self::assertFalse(Totp::verify($secret, '287082', 59 + 120));
        self::assertFalse(Totp::verify($secret, 'abcdef', 59));
    }

    public function testSignedUrlRoundTripExpiryAndTampering(): void
    {
        $signer = new SignedUrl(random_bytes(32));
        $token = $signer->sign(['purpose' => 'audio', 'id' => 7], 60);

        self::assertSame(7, $signer->verify($token)['id'] ?? null);
        self::assertNull($signer->verify($token . 'x'));
        self::assertNull((new SignedUrl(random_bytes(32)))->verify($token));
        self::assertNull($signer->verify($signer->sign(['id' => 1], -1)));
    }

    public function testTokensAreOpaqueAndComparedByHash(): void
    {
        $token = Tokens::random(32);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        self::assertTrue(Tokens::equals(Tokens::hash($token), $token));
        self::assertFalse(Tokens::equals(Tokens::hash($token), $token . 'a'));
        self::assertMatchesRegularExpression('/^PA-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', Tokens::reference('PA'));
    }

    public function testPasswordPolicyAndArgon2id(): void
    {
        $hasher = new PasswordHasher(memoryKib: 8192, timeCost: 1);
        $hash = $hasher->hash('a long passphrase 42!');

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($hasher->verify('a long passphrase 42!', $hash));
        self::assertFalse($hasher->verify('wrong', $hash));

        $this->expectException(HttpException::class);
        $hasher->assertStrong('short1!', 'password');
    }

    public function testPasswordMayNotContainEmailLocalPart(): void
    {
        $this->expectException(HttpException::class);
        (new PasswordHasher(8192, 1))->assertStrong('ananya-rao-2026-secure', 'password', 'ananya-rao@example.com');
    }
}
