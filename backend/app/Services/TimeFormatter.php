<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

final class TimeFormatter
{
    /** "Saturday, 10 October 2026 · 3:30 PM (Asia/Dubai, GMT+04:00)" */
    public static function forClient(string $utc, string $timezone): string
    {
        $local = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone));

        return $local->format('l, j F Y · g:i A') . " ({$timezone}, GMT" . $local->format('P') . ')';
    }

    public static function money(int $minor, ?string $currency): string
    {
        if ($currency === null) {
            return '';
        }
        $symbols = ['INR' => '₹', 'USD' => '$', 'AED' => 'AED ', 'GBP' => '£'];

        return ($symbols[$currency] ?? $currency . ' ') . number_format($minor / 100, 2);
    }

    public static function isoUtc(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
