<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;

/**
 * Safe operational log for third-party calls: operation, outcome, HTTP status, error category and
 * an internal reference. Never pass tokens, addresses, message content or provider error text.
 */
final class IntegrationLog
{
    public function __construct(private Database $db, private Clock $clock, private Config $config, private Logger $logger)
    {
    }

    public function record(string $provider, string $operation, bool $success, ?int $httpStatus = null, ?string $category = null, ?string $reference = null, ?float $startedAt = null): void
    {
        try {
            $this->db->insert('integration_logs', [
                'provider' => $provider,
                'environment' => $this->config->environment(),
                'operation' => mb_substr($operation, 0, 60),
                'outcome' => $success ? 'success' : 'failure',
                'http_status' => $httpStatus ?: null,
                'error_category' => $success ? null : mb_substr((string) ($category ?? 'unknown'), 0, 40),
                'reference' => $reference !== null ? mb_substr($reference, 0, 64) : null,
                'duration_ms' => $startedAt !== null ? (int) round((microtime(true) - $startedAt) * 1000) : null,
                'created_at' => $this->clock->nowString(),
            ]);
        } catch (\Throwable $e) {
            // Logging must never break the operation being logged.
            $this->logger->warning('integration_log_failed', ['message' => $e->getMessage()]);
        }
    }

    /** @return list<array<string, mixed>> */
    public function recent(string $provider, int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));

        return $this->db->all(
            "SELECT operation, outcome, http_status, error_category, reference, duration_ms, created_at
             FROM integration_logs WHERE provider = ? AND environment = ? ORDER BY id DESC LIMIT {$limit}",
            [$provider, $this->config->environment()],
        );
    }

    /** Latest entry for any of the given operations, or null. @param list<string> $operations */
    public function latest(string $provider, array $operations): ?array
    {
        $placeholders = implode(',', array_fill(0, count($operations), '?'));

        return $this->db->first(
            "SELECT operation, outcome, error_category, created_at FROM integration_logs
             WHERE provider = ? AND environment = ? AND operation IN ({$placeholders}) ORDER BY id DESC LIMIT 1",
            [$provider, $this->config->environment(), ...$operations],
        );
    }
}
