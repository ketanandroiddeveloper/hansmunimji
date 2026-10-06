<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Compact HMAC-signed, expiring tokens: base64url(payload).base64url(hmac).
 * Used for private audio streaming and OAuth `state`.
 */
final class SignedUrl
{
    public function __construct(private string $key)
    {
    }

    /** @param array<string, scalar> $claims */
    public function sign(array $claims, int $ttlSeconds): string
    {
        $claims['exp'] = time() + $ttlSeconds;
        $payload = self::b64(json_encode($claims, JSON_THROW_ON_ERROR));

        return $payload . '.' . self::b64(hash_hmac('sha256', $payload, $this->key, true));
    }

    /** @return array<string, scalar>|null claims when valid and unexpired */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $sig] = $parts;
        $expected = self::b64(hash_hmac('sha256', $payload, $this->key, true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
        $claims = $decoded === false ? null : json_decode($decoded, true);
        if (!is_array($claims) || !isset($claims['exp']) || (int) $claims['exp'] < time()) {
            return null;
        }

        return $claims;
    }

    private static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
