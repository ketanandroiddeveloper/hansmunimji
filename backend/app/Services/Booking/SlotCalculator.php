<?php

declare(strict_types=1);

namespace App\Services\Booking;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Pure slot generation. Given weekly rules (in each rule's local time zone), blackout ranges and
 * busy blocks (UTC), produces bookable start times. No I/O — fully unit-testable, DST-safe.
 */
final class SlotCalculator
{
    /**
     * @param array{duration_minutes: int, slot_interval_minutes: int, buffer_before_minutes: int, buffer_after_minutes: int, lead_time_hours: int, max_advance_days: int} $type
     * @param list<array{weekday: int, start_time: string, end_time: string, timezone: string, valid_from: ?string, valid_until: ?string}> $rules
     * @param list<array{0: DateTimeImmutable, 1: DateTimeImmutable}> $blocked UTC intervals that slots (including buffers) must not overlap
     * @return list<array{starts_at: DateTimeImmutable, ends_at: DateTimeImmutable}>
     */
    public static function generate(
        array $type,
        array $rules,
        array $blocked,
        DateTimeImmutable $rangeStartUtc,
        DateTimeImmutable $rangeEndUtc,
        DateTimeImmutable $nowUtc,
    ): array {
        $utc = new DateTimeZone('UTC');
        $duration = new DateInterval('PT' . $type['duration_minutes'] . 'M');
        $interval = max(5, $type['slot_interval_minutes']);
        $earliest = $nowUtc->modify('+' . $type['lead_time_hours'] . ' hours');
        $latest = $nowUtc->modify('+' . $type['max_advance_days'] . ' days');
        $windowStart = max($rangeStartUtc, $earliest);
        $windowEnd = min($rangeEndUtc, $latest);
        if ($windowStart >= $windowEnd) {
            return [];
        }

        $slots = [];
        foreach ($rules as $rule) {
            $tz = new DateTimeZone($rule['timezone']);
            // Walk local calendar days covering the UTC window (±1 day for offset spill-over).
            $day = $windowStart->setTimezone($tz)->setTime(0, 0)->modify('-1 day');
            $lastDay = $windowEnd->setTimezone($tz)->setTime(0, 0)->modify('+1 day');

            for (; $day <= $lastDay; $day = $day->modify('+1 day')) {
                if ((int) $day->format('N') !== (int) $rule['weekday']) {
                    continue;
                }
                $date = $day->format('Y-m-d');
                if (($rule['valid_from'] && $date < $rule['valid_from']) || ($rule['valid_until'] && $date > $rule['valid_until'])) {
                    continue;
                }

                $open = new DateTimeImmutable($date . ' ' . $rule['start_time'], $tz);
                $close = new DateTimeImmutable($date . ' ' . $rule['end_time'], $tz);

                for ($start = $open; $start->add($duration) <= $close; $start = $start->modify("+{$interval} minutes")) {
                    $startUtc = $start->setTimezone($utc);
                    $endUtc = $startUtc->add($duration);
                    if ($startUtc < $windowStart || $startUtc > $windowEnd) {
                        continue;
                    }
                    $blockStart = $startUtc->modify('-' . $type['buffer_before_minutes'] . ' minutes');
                    $blockEnd = $endUtc->modify('+' . $type['buffer_after_minutes'] . ' minutes');
                    if (self::overlapsAny($blockStart, $blockEnd, $blocked)) {
                        continue;
                    }
                    $slots[$startUtc->getTimestamp()] = ['starts_at' => $startUtc, 'ends_at' => $endUtc];
                }
            }
        }

        ksort($slots);

        return array_values($slots);
    }

    /** @param list<array{0: DateTimeImmutable, 1: DateTimeImmutable}> $intervals */
    public static function overlapsAny(DateTimeImmutable $start, DateTimeImmutable $end, array $intervals): bool
    {
        foreach ($intervals as [$busyStart, $busyEnd]) {
            if ($start < $busyEnd && $end > $busyStart) {
                return true;
            }
        }

        return false;
    }
}
