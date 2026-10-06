<?php

declare(strict_types=1);

namespace App\Security;

final class Tokens
{
    /** URL-safe random token (default 256 bits). */
    public static function random(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** Human-friendly unguessable reference, e.g. "PA-7KQ2-M9XD-4TJH" (≈ 60 bits). */
    public static function reference(string $prefix): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $chars = '';
        for ($i = 0; $i < 12; $i++) {
            $chars .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $prefix . '-' . implode('-', str_split($chars, 4));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function equals(string $knownHash, string $token): bool
    {
        return hash_equals($knownHash, self::hash($token));
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
