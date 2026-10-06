<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;

/**
 * Append-only record of lifecycle transitions for bookings, payments, registrations and applications.
 * Notes carry operational context only — never personal details, card data or secrets.
 */
final class StatusHistory
{
    public const SOURCES = ['client', 'admin', 'webhook', 'verify', 'scheduler', 'system'];

    public function __construct(private Database $db, private Clock $clock)
    {
    }

    public function record(string $subjectType, int $subjectId, ?string $from, string $to, string $source, ?int $userId = null, ?string $note = null): void
    {
        if ($from === $to && $note === null) {
            return;
        }
        $this->db->insert('status_history', [
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'from_status' => $from,
            'to_status' => $to,
            'source' => in_array($source, self::SOURCES, true) ? $source : 'system',
            'actor_user_id' => $userId,
            'note' => $note === null ? null : mb_substr($note, 0, 255),
            'created_at' => $this->clock->nowString(),
        ]);
    }

    /** @return list<array{from: ?string, to: string, source: string, actor: ?string, note: ?string, at: ?string}> */
    public function for(string $subjectType, int $subjectId): array
    {
        $rows = $this->db->all(
            'SELECT h.from_status, h.to_status, h.source, h.note, h.created_at, u.name AS actor
             FROM status_history h LEFT JOIN users u ON u.id = h.actor_user_id
             WHERE h.subject_type = ? AND h.subject_id = ? ORDER BY h.id',
            [$subjectType, $subjectId],
        );

        return array_map(static fn (array $r) => [
            'from' => $r['from_status'],
            'to' => (string) $r['to_status'],
            'source' => (string) $r['source'],
            'actor' => $r['actor'],
            'note' => $r['note'],
            'at' => Clock::iso((string) $r['created_at']),
        ], $rows);
    }
}
