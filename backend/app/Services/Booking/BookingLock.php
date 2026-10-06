<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Core\Database;
use App\Core\HttpException;

/**
 * Serialises writes that affect the practitioner's calendar (one bookable resource).
 * Uses a MySQL named lock so overlap checks and inserts cannot interleave across PHP workers.
 */
final class BookingLock
{
    private const NAME = 'private_advisory_booking';

    public function __construct(private Database $db)
    {
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        $acquired = (int) $this->db->value('SELECT GET_LOCK(?, 5)', [self::NAME]);
        if ($acquired !== 1) {
            throw new HttpException(503, 'busy', 'The calendar is momentarily busy. Please try again.', [], ['Retry-After' => '2']);
        }
        try {
            return $callback();
        } finally {
            $this->db->value('SELECT RELEASE_LOCK(?)', [self::NAME]);
        }
    }
}
