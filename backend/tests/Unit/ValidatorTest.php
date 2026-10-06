<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\HttpException;
use App\Core\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testReturnsOnlyDeclaredKeys(): void
    {
        $out = Validator::validate(['name' => 'Asha', 'is_admin' => true], ['name' => 'required|string|max:50']);

        self::assertSame(['name' => 'Asha'], $out);
    }

    public function testCollectsFieldErrors(): void
    {
        try {
            Validator::validate(
                ['email' => 'not-an-email', 'currency' => 'EUR', 'phone' => '12345', 'consent' => false],
                ['email' => 'required|email', 'currency' => 'required|currency', 'phone' => 'required|phone', 'consent' => 'accepted', 'name' => 'required|string'],
            );
            self::fail('Expected validation failure');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
            self::assertSame(['email', 'currency', 'phone', 'consent', 'name'], array_keys($e->fields));
        }
    }

    public function testAcceptsValidValues(): void
    {
        $out = Validator::validate(
            ['email' => 'a@b.co', 'currency' => 'AED', 'phone' => '+971501234567', 'tz' => 'Europe/London', 'n' => '5', 'consent' => true, 'when' => '2026-10-05T05:30:00Z'],
            ['email' => 'required|email', 'currency' => 'required|currency', 'phone' => 'required|phone', 'tz' => 'required|timezone', 'n' => 'required|integer|between:1,10', 'consent' => 'accepted', 'when' => 'required|datetime'],
        );

        self::assertSame('AED', $out['currency']);
    }

    public function testAcceptsLegacyTimezoneAliasesReportedByBrowsers(): void
    {
        $out = Validator::validate(['tz' => 'Asia/Calcutta'], ['tz' => 'required|timezone']);

        self::assertSame('Asia/Calcutta', $out['tz']);
    }

    public function testRejectsUnsupportedCurrency(): void
    {
        $this->expectException(HttpException::class);
        Validator::validate(['c' => 'EUR'], ['c' => 'required|currency']);
    }

    public function testRejectsUnknownTimezoneAndOutOfRange(): void
    {
        $this->expectException(HttpException::class);
        Validator::validate(['tz' => 'Mars/Olympus', 'n' => 99], ['tz' => 'required|timezone', 'n' => 'required|integer|between:1,10']);
    }

    public function testParseDateTimeNormalisesToUtc(): void
    {
        $dt = Validator::parseDateTime('2026-10-05T10:00:00+05:30');

        self::assertSame('2026-10-05 04:30:00', $dt?->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $dt?->getTimezone()->getName());
    }
}
