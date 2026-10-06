<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Keyed hash for exact-match lookups on encrypted columns (e.g. find applications by email)
 * without storing the plaintext.
 */
final class BlindIndex
{
    public function __construct(private string $key)
    {
    }

    public function email(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        return $this->hash('email', mb_strtolower(trim($email)));
    }

    public function hash(string $purpose, string $value): string
    {
        if (strlen($this->key) < 32) {
            throw new \RuntimeException('BLIND_INDEX_KEY is not configured.');
        }

        return hash_hmac('sha256', $purpose . '|' . $value, $this->key);
    }
}
