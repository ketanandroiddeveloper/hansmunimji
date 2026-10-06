<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Core\Clock;
use App\Core\Database;
use App\Core\HttpException;
use DateTimeImmutable;
use DateTimeZone;

final class AvailabilityService
{
    public function __construct(private Database $db, private Clock $clock)
    {
    }

    /**
     * Slots for a type between two client-local dates (inclusive), grouped by client-local date.
     *
     * @param array<string, mixed> $type appointment_types row
     * @return list<array{date: string, slots: list<array{starts_at: string, ends_at: string, label: string}>}>
     */
    public function forRange(array $type, string $fromDate, string $toDate, string $clientTimezone, ?int $ignoreAppointmentId = null): array
    {
        $tz = new DateTimeZone($clientTimezone);
        $from = new DateTimeImmutable($fromDate . ' 00:00:00', $tz);
        $to = (new DateTimeImmutable($toDate . ' 00:00:00', $tz))->modify('+1 day');
        if ($to <= $from || $from->diff($to)->days > 62) {
            throw HttpException::validation(['to' => ['Choose a range of up to 62 days.']]);
        }

        $slots = $this->slots($type, $from->setTimezone(new DateTimeZone('UTC')), $to->setTimezone(new DateTimeZone('UTC')), $ignoreAppointmentId);

        $grouped = [];
        foreach ($slots as $slot) {
            $local = $slot['starts_at']->setTimezone($tz);
            $grouped[$local->format('Y-m-d')][] = [
                'starts_at' => $slot['starts_at']->format('Y-m-d\TH:i:s\Z'),
                'ends_at' => $slot['ends_at']->format('Y-m-d\TH:i:s\Z'),
                'label' => $local->format('g:i A'),
            ];
        }

        $out = [];
        foreach ($grouped as $date => $daySlots) {
            $out[] = ['date' => $date, 'slots' => $daySlots];
        }

        return $out;
    }

    /** True when the exact start time is currently a bookable slot for the type. */
    public function isAvailable(array $type, DateTimeImmutable $startsAtUtc, ?int $ignoreAppointmentId = null): bool
    {
        $slots = $this->slots($type, $startsAtUtc->modify('-1 minute'), $startsAtUtc->modify('+1 minute'), $ignoreAppointmentId);
        foreach ($slots as $slot) {
            if ($slot['starts_at'] == $startsAtUtc) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{starts_at: DateTimeImmutable, ends_at: DateTimeImmutable}> */
    private function slots(array $type, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc, ?int $ignoreAppointmentId): array
    {
        $rules = $this->db->all(
            'SELECT weekday, start_time, end_time, timezone, valid_from, valid_until FROM availability_schedules
             WHERE is_active = 1 AND (appointment_type_id = ? OR appointment_type_id IS NULL)',
            [$type['id']],
        );

        $padFrom = $fromUtc->modify('-1 day')->format('Y-m-d H:i:s');
        $padTo = $toUtc->modify('+1 day')->format('Y-m-d H:i:s');
        $blocked = [];

        foreach ($this->db->all(
            'SELECT starts_at, ends_at FROM unavailable_dates
             WHERE (appointment_type_id = ? OR appointment_type_id IS NULL) AND starts_at < ? AND ends_at > ?',
            [$type['id'], $padTo, $padFrom],
        ) as $row) {
            $blocked[] = [Clock::utc((string) $row['starts_at']), Clock::utc((string) $row['ends_at'])];
        }

        foreach ($this->db->all(
            "SELECT block_starts_at, block_ends_at FROM appointment_slots
             WHERE block_starts_at < ? AND block_ends_at > ?
               AND (status IN ('booked','blocked') OR (status = 'held' AND held_until > ?))
               AND (appointment_id IS NULL OR appointment_id <> ?)",
            [$padTo, $padFrom, $this->clock->nowString(), $ignoreAppointmentId ?? 0],
        ) as $row) {
            $blocked[] = [Clock::utc((string) $row['block_starts_at']), Clock::utc((string) $row['block_ends_at'])];
        }

        return SlotCalculator::generate(
            [
                'duration_minutes' => (int) $type['duration_minutes'],
                'slot_interval_minutes' => (int) $type['slot_interval_minutes'],
                'buffer_before_minutes' => (int) $type['buffer_before_minutes'],
                'buffer_after_minutes' => (int) $type['buffer_after_minutes'],
                'lead_time_hours' => (int) $type['lead_time_hours'],
                'max_advance_days' => (int) $type['max_advance_days'],
            ],
            array_map(static fn (array $r) => [
                'weekday' => (int) $r['weekday'],
                'start_time' => (string) $r['start_time'],
                'end_time' => (string) $r['end_time'],
                'timezone' => (string) $r['timezone'],
                'valid_from' => $r['valid_from'],
                'valid_until' => $r['valid_until'],
            ], $rules),
            $blocked,
            $fromUtc,
            $toUtc,
            $this->clock->now(),
        );
    }
}
