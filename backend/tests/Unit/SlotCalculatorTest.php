<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Booking\SlotCalculator;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class SlotCalculatorTest extends TestCase
{
    private const TYPE = [
        'duration_minutes' => 60, 'slot_interval_minutes' => 30, 'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 15, 'lead_time_hours' => 0, 'max_advance_days' => 60,
    ];

    private static function utc(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }

    /** @return list<string> */
    private static function starts(array $slots): array
    {
        return array_map(static fn ($s) => $s['starts_at']->format('Y-m-d H:i'), $slots);
    }

    public function testGeneratesSlotsInRuleTimezone(): void
    {
        // Monday 5 Oct 2026, 10:00–12:00 IST = 04:30–06:30 UTC; 60-min sessions every 30 min.
        $rules = [['weekday' => 1, 'start_time' => '10:00:00', 'end_time' => '12:00:00', 'timezone' => 'Asia/Kolkata', 'valid_from' => null, 'valid_until' => null]];
        $slots = SlotCalculator::generate(self::TYPE, $rules, [], self::utc('2026-10-05 00:00'), self::utc('2026-10-06 00:00'), self::utc('2026-10-01 00:00'));

        self::assertSame(['2026-10-05 04:30', '2026-10-05 05:00', '2026-10-05 05:30'], self::starts($slots));
    }

    public function testBusyBlocksIncludingBuffersRemoveOverlappingSlots(): void
    {
        $rules = [['weekday' => 1, 'start_time' => '10:00:00', 'end_time' => '12:00:00', 'timezone' => 'Asia/Kolkata', 'valid_from' => null, 'valid_until' => null]];
        $busy = [[self::utc('2026-10-05 05:00'), self::utc('2026-10-05 06:15')]];
        $slots = SlotCalculator::generate(self::TYPE, $rules, $busy, self::utc('2026-10-05 00:00'), self::utc('2026-10-06 00:00'), self::utc('2026-10-01 00:00'));

        // 04:30 slot ends 05:30 (+15 buffer) → overlaps; every slot overlaps the busy block.
        self::assertSame([], self::starts($slots));
    }

    public function testLeadTimeAndMaxAdvanceAreRespected(): void
    {
        $rules = [['weekday' => 1, 'start_time' => '10:00:00', 'end_time' => '12:00:00', 'timezone' => 'Asia/Kolkata', 'valid_from' => null, 'valid_until' => null]];
        $type = ['lead_time_hours' => 24] + self::TYPE;
        $now = self::utc('2026-10-04 05:00');
        $slots = SlotCalculator::generate($type, $rules, [], self::utc('2026-10-05 00:00'), self::utc('2026-10-06 00:00'), $now);

        self::assertSame(['2026-10-05 05:00', '2026-10-05 05:30'], self::starts($slots));

        $tooFar = SlotCalculator::generate(['max_advance_days' => 1] + self::TYPE, $rules, [], self::utc('2026-10-12 00:00'), self::utc('2026-10-13 00:00'), self::utc('2026-10-01 00:00'));
        self::assertSame([], $tooFar);
    }

    public function testDaylightSavingTransitionKeepsLocalWallClock(): void
    {
        // London: BST ends Sunday 25 Oct 2026. A Monday 09:00 London slot is 08:00 UTC before, 09:00 UTC after.
        $rules = [['weekday' => 1, 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'timezone' => 'Europe/London', 'valid_from' => null, 'valid_until' => null]];
        $slots = SlotCalculator::generate(self::TYPE, $rules, [], self::utc('2026-10-19 00:00'), self::utc('2026-10-27 00:00'), self::utc('2026-10-01 00:00'));

        self::assertSame(['2026-10-19 08:00', '2026-10-26 09:00'], self::starts($slots));
    }

    public function testValidityWindowExcludesDates(): void
    {
        $rules = [['weekday' => 1, 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'timezone' => 'UTC', 'valid_from' => '2026-10-12', 'valid_until' => null]];
        $slots = SlotCalculator::generate(self::TYPE, $rules, [], self::utc('2026-10-05 00:00'), self::utc('2026-10-13 00:00'), self::utc('2026-10-01 00:00'));

        self::assertSame(['2026-10-12 10:00'], self::starts($slots));
    }

    public function testOverlapsAnyUsesHalfOpenIntervals(): void
    {
        $blocks = [[self::utc('2026-10-05 10:00'), self::utc('2026-10-05 11:00')]];

        self::assertFalse(SlotCalculator::overlapsAny(self::utc('2026-10-05 11:00'), self::utc('2026-10-05 12:00'), $blocks));
        self::assertTrue(SlotCalculator::overlapsAny(self::utc('2026-10-05 10:59'), self::utc('2026-10-05 12:00'), $blocks));
    }
}
