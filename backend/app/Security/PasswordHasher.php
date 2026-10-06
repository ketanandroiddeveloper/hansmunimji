<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\HttpException;

final class PasswordHasher
{
    /** A small deny-list of extremely common passwords; length policy does most of the work. */
    private const COMMON = [
        'password1234', 'password12345', '123456789012', 'qwertyuiop12', 'iloveyou1234',
        'administrator', 'welcome12345', 'letmein12345', 'changeme1234', 'passw0rd1234',
    ];

    public function __construct(private int $memoryKib = 65536, private int $timeCost = 4, private int $minLength = 12)
    {
    }

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => $this->memoryKib,
            'time_cost' => $this->timeCost,
            'threads' => 1,
        ]);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, [
            'memory_cost' => $this->memoryKib,
            'time_cost' => $this->timeCost,
            'threads' => 1,
        ]);
    }

    /** Throws a validation error when the password does not meet policy. */
    public function assertStrong(string $password, string $field = 'password', ?string $email = null): void
    {
        $problems = [];
        if (mb_strlen($password) < $this->minLength) {
            $problems[] = "Use at least {$this->minLength} characters.";
        }
        if (mb_strlen($password) > 256) {
            $problems[] = 'Use at most 256 characters.';
        }
        if (in_array(mb_strtolower($password), self::COMMON, true)) {
            $problems[] = 'This password is too common.';
        }
        if ($email !== null && str_contains(mb_strtolower($password), mb_strtolower(strtok($email, '@') ?: ''))) {
            $problems[] = 'The password must not contain your email name.';
        }
        if (count(array_unique(mb_str_split($password))) < 6) {
            $problems[] = 'Use a more varied password.';
        }
        if ($problems !== []) {
            throw HttpException::validation([$field => $problems]);
        }
    }

    /** Burns comparable CPU time when the user does not exist (mitigates user enumeration by timing). */
    public function dummyVerify(): void
    {
        static $dummy = null;
        $dummy ??= $this->hash(bin2hex(random_bytes(16)));
        password_verify('invalid-password', $dummy);
    }
}
