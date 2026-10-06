<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Logger;

/**
 * MySQL-backed job queue. `unique_key` dedupes *pending* jobs (it is cleared once a job
 * finishes) so e.g. only one calendar sync per appointment is queued at a time.
 */
final class JobQueue
{
    private const BACKOFF_MINUTES = [1, 5, 15, 60, 240];

    public function __construct(private Database $db, private Clock $clock, private Logger $logger)
    {
    }

    /** @param array<string, scalar|null> $payload */
    public function push(string $type, array $payload, ?string $uniqueKey = null, int $delaySeconds = 0, int $maxAttempts = 5): void
    {
        $this->db->run(
            'INSERT IGNORE INTO jobs (type, payload, unique_key, status, attempts, max_attempts, available_at, created_at)
             VALUES (:type, :payload, :unique_key, \'pending\', 0, :max, :available, :created)',
            [
                'type' => $type,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'unique_key' => $uniqueKey,
                'max' => $maxAttempts,
                'available' => $this->clock->now()->modify("+{$delaySeconds} seconds")->format('Y-m-d H:i:s'),
                'created' => $this->clock->nowString(),
            ],
        );
    }

    /**
     * @param array<string, callable(array<string, mixed>): void> $handlers
     * @param (callable(string, array<string, mixed>, \Throwable): void)|null $onFinalFailure
     */
    public function processDue(array $handlers, int $limit = 10, ?callable $onFinalFailure = null): int
    {
        $jobs = $this->db->transaction(function () use ($limit) {
            $rows = $this->db->all(
                "SELECT * FROM jobs WHERE status = 'pending' AND available_at <= ? ORDER BY id LIMIT {$limit} FOR UPDATE SKIP LOCKED",
                [$this->clock->nowString()],
            );
            foreach ($rows as $row) {
                $this->db->update('jobs', ['status' => 'reserved', 'reserved_at' => $this->clock->nowString()], ['id' => $row['id']]);
            }

            return $rows;
        });

        foreach ($jobs as $job) {
            $payload = json_decode((string) $job['payload'], true) ?: [];
            $attempts = (int) $job['attempts'] + 1;
            try {
                $handler = $handlers[$job['type']] ?? throw new \RuntimeException("No handler for job type '{$job['type']}'.");
                $handler($payload);
                $this->db->update('jobs', ['status' => 'done', 'attempts' => $attempts, 'finished_at' => $this->clock->nowString(), 'unique_key' => null, 'last_error' => null], ['id' => $job['id']]);
            } catch (\Throwable $e) {
                $final = $attempts >= (int) $job['max_attempts'];
                $delay = self::BACKOFF_MINUTES[min($attempts - 1, count(self::BACKOFF_MINUTES) - 1)];
                $this->db->update('jobs', [
                    'status' => $final ? 'failed' : 'pending',
                    'attempts' => $attempts,
                    'available_at' => $this->clock->now()->modify("+{$delay} minutes")->format('Y-m-d H:i:s'),
                    'finished_at' => $final ? $this->clock->nowString() : null,
                    'unique_key' => $final ? null : $job['unique_key'],
                    'last_error' => mb_substr($e->getMessage(), 0, 250),
                ], ['id' => $job['id']]);
                $this->logger->error('job_failed', ['job' => $job['type'], 'attempts' => $attempts, 'message' => $e->getMessage()]);
                if ($final && $onFinalFailure) {
                    $onFinalFailure((string) $job['type'], $payload, $e);
                }
            }
        }

        return count($jobs);
    }

    /** Re-queues stale reservations (worker crashed mid-job). */
    public function releaseStale(int $minutes = 15): int
    {
        return $this->db->run(
            "UPDATE jobs SET status = 'pending' WHERE status = 'reserved' AND reserved_at < ?",
            [$this->clock->now()->modify("-{$minutes} minutes")->format('Y-m-d H:i:s')],
        )->rowCount();
    }
}
