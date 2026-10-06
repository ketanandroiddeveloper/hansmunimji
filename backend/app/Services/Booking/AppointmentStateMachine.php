<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Core\HttpException;

final class AppointmentStateMachine
{
    public const ACTIVE = ['confirmed', 'rescheduled'];

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'pending_application' => ['awaiting_approval', 'cancelled'],
        'awaiting_approval' => ['pending_payment', 'confirmed', 'cancelled'],
        'pending_payment' => ['payment_verification', 'confirmed', 'payment_failed', 'cancelled', 'expired'],
        'payment_verification' => ['confirmed', 'payment_failed', 'cancelled', 'refunded'],
        'payment_failed' => ['payment_verification', 'confirmed', 'cancelled', 'expired'],
        // A late verified payment may still confirm (time free) or park for reconciliation.
        'expired' => ['confirmed', 'payment_verification', 'refunded'],
        'confirmed' => ['rescheduled', 'cancelled', 'completed', 'no_show'],
        'rescheduled' => ['rescheduled', 'cancelled', 'completed', 'no_show'],
        'cancelled' => ['refunded'],
        'completed' => ['refunded'],
        'no_show' => ['refunded'],
        'refunded' => [],
    ];

    /** @return list<string> */
    public static function statuses(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function assert(string $from, string $to): void
    {
        if (!self::canTransition($from, $to)) {
            throw HttpException::conflict('invalid_state', "This booking cannot move from '{$from}' to '{$to}'.");
        }
    }

    public static function isActive(string $status): bool
    {
        return in_array($status, self::ACTIVE, true);
    }
}
