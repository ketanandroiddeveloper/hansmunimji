<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;

/**
 * Non-secret, admin-editable configuration. Secrets never live here — they come from the environment.
 */
final class SettingsService
{
    /** Keys that may never be stored as settings (defence in depth against secret leakage). */
    private const FORBIDDEN = '/(secret|password|token|api_key|private_key)/i';

    /** @var array<string, array{value: mixed, is_public: bool}>|null */
    private ?array $cache = null;

    public function __construct(private Database $db, private Clock $clock)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->load()[$key]['value'] ?? $default;
    }

    /** @return array<string, mixed> */
    public function public(): array
    {
        $out = [];
        foreach ($this->load() as $key => $row) {
            if ($row['is_public']) {
                $out[$key] = $row['value'];
            }
        }

        return $out;
    }

    /** @return array<string, array{value: mixed, is_public: bool}> */
    public function all(): array
    {
        return $this->load();
    }

    public function set(string $key, mixed $value, ?bool $isPublic = null, ?int $userId = null): void
    {
        if (preg_match(self::FORBIDDEN, $key)) {
            throw new \InvalidArgumentException('Secrets must be configured through environment variables, not settings.');
        }
        $existing = $this->load()[$key] ?? null;
        $public = $isPublic ?? ($existing['is_public'] ?? false);
        $this->db->run(
            'INSERT INTO settings (`key`, value, is_public, updated_by, updated_at) VALUES (:k, :v, :p, :u, :t)
             ON DUPLICATE KEY UPDATE value = VALUES(value), is_public = VALUES(is_public), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            ['k' => $key, 'v' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'p' => $public ? 1 : 0, 'u' => $userId, 't' => $this->clock->nowString()],
        );
        $this->cache = null;
    }

    /** @return array<string, array{value: mixed, is_public: bool}> */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $this->cache = [];
        foreach ($this->db->all('SELECT `key`, value, is_public FROM settings') as $row) {
            $this->cache[(string) $row['key']] = [
                'value' => json_decode((string) $row['value'], true),
                'is_public' => (bool) $row['is_public'],
            ];
        }

        return $this->cache;
    }
}
