<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Clock;
use App\Core\Database;

/**
 * Fixed-window rate limiter backed by MySQL (atomic upsert). Keys are HMAC'd so raw IPs and
 * emails are never stored.
 */
final class RateLimiter
{
    public function __construct(private Database $db, private Clock $clock, private string $key)
    {
    }

    /**
     * Records a hit and returns the number of seconds to wait if the limit is exceeded, else 0.
     */
    public function hit(string $bucket, string $identity, int $maxHits, int $windowSeconds): int
    {
        $id = hash_hmac('sha256', $bucket . '|' . $identity, $this->key);
        $now = $this->clock->now();
        $resetAt = $now->modify("+{$windowSeconds} seconds")->format('Y-m-d H:i:s');
        $nowString = $now->format('Y-m-d H:i:s');

        $this->db->run(
            'INSERT INTO rate_limits (id, bucket, hits, reset_at) VALUES (:id, :bucket, 1, :reset_at)
             ON DUPLICATE KEY UPDATE
                hits = IF(reset_at <= :now1, 1, hits + 1),
                reset_at = IF(reset_at <= :now2, VALUES(reset_at), reset_at)',
            ['id' => $id, 'bucket' => $bucket, 'reset_at' => $resetAt, 'now1' => $nowString, 'now2' => $nowString],
        );

        $row = $this->db->first('SELECT hits, reset_at FROM rate_limits WHERE id = ?', [$id]);
        if ($row !== null && (int) $row['hits'] > $maxHits) {
            return max(1, Clock::utc((string) $row['reset_at'])->getTimestamp() - $now->getTimestamp());
        }

        return 0;
    }

    public function clear(string $bucket, string $identity): void
    {
        $this->db->delete('rate_limits', ['id' => hash_hmac('sha256', $bucket . '|' . $identity, $this->key)]);
    }
}
