<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\EnvironmentGuard;
use App\Core\HttpException;
use App\Services\Booking\AppointmentStateMachine;
use PHPUnit\Framework\TestCase;

final class BookingStateTest extends TestCase
{
    public function testAllowedTransitions(): void
    {
        self::assertTrue(AppointmentStateMachine::canTransition('pending_payment', 'confirmed'));
        self::assertTrue(AppointmentStateMachine::canTransition('confirmed', 'rescheduled'));
        self::assertTrue(AppointmentStateMachine::canTransition('cancelled', 'refunded'));
    }

    public function testForbiddenTransitions(): void
    {
        self::assertFalse(AppointmentStateMachine::canTransition('refunded', 'confirmed'));
        self::assertFalse(AppointmentStateMachine::canTransition('completed', 'cancelled'));
        self::assertFalse(AppointmentStateMachine::canTransition('pending_payment', 'completed'));

        $this->expectException(HttpException::class);
        AppointmentStateMachine::assert('cancelled', 'confirmed');
    }

    public function testExpiryAndNoShowTransitions(): void
    {
        self::assertTrue(AppointmentStateMachine::canTransition('pending_payment', 'expired'));
        self::assertTrue(AppointmentStateMachine::canTransition('payment_failed', 'expired'));
        // A verified late payment may still confirm an expired reservation, or park it for reconciliation.
        self::assertTrue(AppointmentStateMachine::canTransition('expired', 'confirmed'));
        self::assertTrue(AppointmentStateMachine::canTransition('expired', 'payment_verification'));
        self::assertTrue(AppointmentStateMachine::canTransition('confirmed', 'no_show'));
        self::assertTrue(AppointmentStateMachine::canTransition('no_show', 'refunded'));

        self::assertFalse(AppointmentStateMachine::canTransition('confirmed', 'expired'));
        self::assertFalse(AppointmentStateMachine::canTransition('pending_payment', 'no_show'));
        self::assertFalse(AppointmentStateMachine::canTransition('no_show', 'confirmed'));
        self::assertFalse(AppointmentStateMachine::canTransition('expired', 'cancelled'));
    }

    public function testEveryTargetStatusIsAKnownStatus(): void
    {
        $known = AppointmentStateMachine::statuses();
        foreach ($known as $from) {
            foreach ($known as $to) {
                if (AppointmentStateMachine::canTransition($from, $to)) {
                    self::assertContains($to, $known);
                }
            }
        }
        self::assertContains('expired', $known);
        self::assertContains('no_show', $known);
    }

    public function testActiveStatuses(): void
    {
        self::assertTrue(AppointmentStateMachine::isActive('confirmed'));
        self::assertTrue(AppointmentStateMachine::isActive('rescheduled'));
        self::assertFalse(AppointmentStateMachine::isActive('pending_payment'));
    }

    /** @param array<string, mixed> $overrides */
    private static function config(string $env, array $overrides = []): Config
    {
        $items = [
            'app' => ['env' => $env, 'debug' => false, 'url' => 'https://api.example.com', 'frontend_url' => 'https://example.com'],
            'security' => ['app_key' => str_repeat('k', 32), 'blind_index_key' => str_repeat('b', 32), 'encryption_keys' => [1 => str_repeat('e', 32)], 'session' => ['secure' => true], 'require_two_factor' => true],
            'payments' => ['razorpay' => ['key_id' => ''], 'stripe' => ['secret_key' => '', 'publishable_key' => '']],
            'mail' => ['driver' => 'smtp'],
        ];

        return new Config(array_replace_recursive($items, $overrides));
    }

    public function testEnvironmentGuardBlocksLiveKeysOutsideProduction(): void
    {
        $problems = EnvironmentGuard::problems(self::config('staging', ['payments' => ['stripe' => ['secret_key' => 'sk_live_x']]]));

        self::assertNotEmpty($problems);
        self::assertStringContainsString('Live stripe secret', implode(' ', $problems));
    }

    public function testEnvironmentGuardBlocksTestKeysDebugAndLogMailInProduction(): void
    {
        $problems = implode(' ', EnvironmentGuard::problems(self::config('production', [
            'app' => ['debug' => true],
            'payments' => ['razorpay' => ['key_id' => 'rzp_test_x']],
            'mail' => ['driver' => 'log'],
        ])));

        self::assertStringContainsString('live razorpay', $problems);
        self::assertStringContainsString('APP_DEBUG', $problems);
        self::assertStringContainsString('MAIL_DRIVER', $problems);
    }

    public function testEnvironmentGuardRequiresAdminTwoFactorOutsideLocal(): void
    {
        $problems = implode(' ', EnvironmentGuard::problems(self::config('staging', ['security' => ['require_two_factor' => false]])));

        self::assertStringContainsString('ADMIN_REQUIRE_TWO_FACTOR', $problems);
        self::assertSame([], array_filter(EnvironmentGuard::problems(self::config('local', ['security' => ['require_two_factor' => false], 'mail' => ['driver' => 'log']])), static fn ($p) => str_contains($p, 'TWO_FACTOR')));
    }

    public function testEnvironmentGuardAcceptsAConsistentProductionConfig(): void
    {
        self::assertSame([], EnvironmentGuard::problems(self::config('production', ['payments' => ['stripe' => ['secret_key' => 'sk_live_x', 'publishable_key' => 'pk_live_x']]])));
    }
}
