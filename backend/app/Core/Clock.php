<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/** Injectable UTC clock so time-dependent logic is testable. */
class Clock
{
    private ?DateTimeImmutable $frozen = null;

    public function now(): DateTimeImmutable
    {
        return $this->frozen ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function freeze(DateTimeImmutable $at): void
    {
        $this->frozen = $at->setTimezone(new DateTimeZone('UTC'));
    }

    public function nowString(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }

    public static function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    /** Formats a DB UTC datetime as ISO-8601 with Z suffix. */
    public static function iso(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::utc($value)->format('Y-m-d\TH:i:s\Z');
    }
}
