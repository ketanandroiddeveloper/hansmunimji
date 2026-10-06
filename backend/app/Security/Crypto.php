<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Authenticated field encryption (AES-256-GCM) with versioned keys.
 *
 * Envelope: "v{version}.{base64url(nonce[12] || tag[16] || ciphertext)}".
 * The logical field name is bound as additional authenticated data, so a ciphertext copied
 * from one column into another fails authentication.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;

    /** @param array<int, string> $keys version => 32-byte binary key */
    public function __construct(private array $keys, private int $activeVersion)
    {
        foreach ($keys as $version => $key) {
            if (strlen($key) !== 32) {
                throw new \InvalidArgumentException("Encryption key v{$version} must be exactly 32 bytes.");
            }
        }
    }

    public function isConfigured(): bool
    {
        return isset($this->keys[$this->activeVersion]);
    }

    public function activeVersion(): int
    {
        return $this->activeVersion;
    }

    public function encrypt(?string $plaintext, string $field): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return null;
        }
        $key = $this->keys[$this->activeVersion] ?? throw new \RuntimeException('No active encryption key configured.');
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $field, self::TAG_BYTES);
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return 'v' . $this->activeVersion . '.' . self::b64($nonce . $tag . $ciphertext);
    }

    public function decrypt(?string $envelope, string $field): ?string
    {
        if ($envelope === null || $envelope === '') {
            return null;
        }
        if (!preg_match('/^v(\d+)\.([A-Za-z0-9_-]+)$/', $envelope, $m)) {
            throw new \RuntimeException('Malformed encrypted value.');
        }
        $key = $this->keys[(int) $m[1]] ?? throw new \RuntimeException("Encryption key v{$m[1]} is not available.");
        $raw = self::unb64($m[2]);
        if (strlen($raw) < self::NONCE_BYTES + self::TAG_BYTES) {
            throw new \RuntimeException('Malformed encrypted value.');
        }
        $nonce = substr($raw, 0, self::NONCE_BYTES);
        $tag = substr($raw, self::NONCE_BYTES, self::TAG_BYTES);
        $ciphertext = substr($raw, self::NONCE_BYTES + self::TAG_BYTES);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $field);
        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed: value was tampered with or the key is wrong.');
        }

        return $plaintext;
    }

    public function needsRotation(?string $envelope): bool
    {
        return $envelope !== null && $envelope !== '' && !str_starts_with($envelope, 'v' . $this->activeVersion . '.');
    }

    /**
     * Encrypts the listed fields of a row, writing to `{field}_enc` keys.
     *
     * @param array<string, mixed> $data
     * @param list<string> $fields
     * @param string $context table name used to namespace the AAD
     * @return array<string, mixed>
     */
    public function encryptFields(array $data, array $fields, string $context): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                $out[$field . '_enc'] = $this->encrypt($value === null ? null : (string) $value, "{$context}.{$field}");
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $fields
     * @return array<string, string|null>
     */
    public function decryptFields(array $row, array $fields, string $context): array
    {
        $out = [];
        foreach ($fields as $field) {
            $out[$field] = $this->decrypt($row[$field . '_enc'] ?? null, "{$context}.{$field}");
        }

        return $out;
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    private static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function unb64(string $text): string
    {
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \RuntimeException('Malformed encrypted value.');
        }

        return $decoded;
    }
}
