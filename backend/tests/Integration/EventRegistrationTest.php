<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\HttpException;
use App\Services\Events\EventRegistrationService;
use App\Services\Payments\PaymentService;

final class EventRegistrationTest extends IntegrationTestCase
{
    public function testSeatsAreNeverOversoldAndOverflowJoinsTheWaitlist(): void
    {
        $this->event(['seat_quota' => 3]);
        self::assertSame('pending_payment', $this->register('a@example.test', 2)['status']);
        self::assertSame('waitlisted', $this->register('b@example.test', 2)['status']);
        self::assertSame('pending_payment', $this->register('c@example.test', 1)['status']);
        self::assertSame('waitlisted', $this->register('d@example.test', 1)['status']);

        $this->db->run("UPDATE events SET waitlist_enabled = 0 WHERE slug = 'test-gathering'");
        $this->assertConflict('sold_out', fn () => $this->register('e@example.test', 1));
        self::assertSame(3, $this->svc(EventRegistrationService::class)->seatsTaken($this->eventId()));
    }

    public function testConcurrentRequestsForTheLastSeatCannotOversell(): void
    {
        // Separate processes use the real clock, so the gathering must lie in the real future.
        $this->event(['seat_quota' => 1, 'waitlist_enabled' => 0, 'starts_at' => '2099-01-01 10:00:00', 'ends_at' => '2099-01-01 12:00:00']);
        $script = dirname(__DIR__) . '/Support/register_seat.php';
        $env = array_filter([
            'APP_ENV' => 'testing',
            'PATH' => getenv('PATH'),
            'APP_KEY' => getenv('APP_KEY'),
            'BLIND_INDEX_KEY' => getenv('BLIND_INDEX_KEY'),
            'ENCRYPTION_KEYS' => getenv('ENCRYPTION_KEYS'),
            'STORAGE_PATH' => getenv('STORAGE_PATH'),
        ], 'is_string');
        $startAt = sprintf('%.4F', microtime(true) + 2.0);
        $procs = [];
        for ($i = 0; $i < 6; $i++) {
            $procs[] = proc_open([PHP_BINARY, $script, 'test-gathering', "racer{$i}@example.test", $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            $outputs[$i] = $pipes;
        }
        $results = [];
        foreach ($procs as $i => $proc) {
            $results[] = trim((string) stream_get_contents($outputs[$i][1])) ?: trim((string) stream_get_contents($outputs[$i][2]));
            proc_close($proc);
        }

        sort($results);
        self::assertSame(['error:sold_out', 'error:sold_out', 'error:sold_out', 'error:sold_out', 'error:sold_out', 'pending_payment'], $results);
        self::assertSame(1, (int) $this->db->value("SELECT COALESCE(SUM(seats), 0) FROM event_registrations WHERE status = 'pending_payment'"));
    }

    public function testPricesAreChosenPerCurrencyWithTaxAndNeverConverted(): void
    {
        $this->event(['tax_rate_bp' => 1800, 'tax_inclusive' => 0, 'tax_label' => 'GST']);
        $usd = $this->register('usd@example.test', 2, 'USD');
        self::assertSame(['currency' => 'USD', 'subtotal_minor' => 3000, 'tax_minor' => 540, 'total_minor' => 3540], $usd['amount']);

        $order = $this->svc(PaymentService::class)->createOrder(['registration_reference' => $usd['reference'], 'access_token' => $usd['access_token'], 'gateway' => 'stripe'], null);
        self::assertSame(3540, $this->stripe->orders[$order['order_id']]->amountMinor);
        self::assertSame('USD', $this->stripe->orders[$order['order_id']]->currency);

        try {
            $this->register('gbp@example.test', 1, 'GBP');
            self::fail('A currency without a configured price must be refused.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
        }
    }

    public function testRegistrationWindowIsEnforced(): void
    {
        $this->event(['registration_opens_at' => '2030-03-05 00:00:00', 'registration_closes_at' => '2030-03-08 00:00:00']);
        $this->assertConflict('registration_not_open', fn () => $this->register('early@example.test'));

        $this->travel('+5 days');
        self::assertSame('pending_payment', $this->register('ontime@example.test')['status']);

        $this->travel('+3 days');
        $this->assertConflict('registration_closed', fn () => $this->register('late@example.test'));
    }

    public function testExpiredHoldIsReheldAtCheckoutWhenTheSeatIsStillFree(): void
    {
        $this->event(['seat_quota' => 1]);
        $a = $this->register('a@example.test');
        $this->travel('+2 hours');
        $this->svc(EventRegistrationService::class)->expireHolds();
        self::assertSame('expired', $this->registration($a)['status']);

        $order = $this->pay($a, 'razorpay');
        self::assertSame('pending_payment', $this->registration($a)['status']);
        $this->captureWebhook($this->razorpay, $order['order_id'], 'evt_rehold');
        self::assertSame('confirmed', $this->registration($a)['status']);
    }

    public function testLatePaymentAfterTheSeatWasTakenIsRefundedNotOversold(): void
    {
        $this->event(['seat_quota' => 1, 'waitlist_enabled' => 0]);
        $a = $this->register('a@example.test');
        $order = $this->pay($a, 'razorpay');

        $this->travel('+2 hours');
        $this->svc(EventRegistrationService::class)->expireHolds();
        $b = $this->register('b@example.test');
        self::assertSame('pending_payment', $b['status']);

        $this->captureWebhook($this->razorpay, $order['order_id'], 'evt_late');
        self::assertSame('expired', $this->registration($a)['status']);
        self::assertSame(1, $this->svc(EventRegistrationService::class)->seatsTaken($this->eventId()));

        $this->runJobs();
        self::assertCount(1, $this->razorpay->refunds);
        self::assertSame(111100, $this->razorpay->refunds[0]['amount']);
        $this->webhook('razorpay', ['id' => 'evt_rf', 'kind' => 'refund_processed', 'refund_id' => 'razorpay_rfnd_1']);
        self::assertSame('refunded', $this->registration($a)['status']);
    }

    public function testClientCancellationFollowsTheRefundPolicyAndOffersTheSeatToTheWaitlist(): void
    {
        $this->event(['seat_quota' => 1, 'refund_on_cancel_percent' => 50, 'cancellation_window_hours' => 48]);
        $a = $this->register('a@example.test');
        $order = $this->pay($a, 'razorpay');
        $this->captureWebhook($this->razorpay, $order['order_id'], 'evt_paid');
        $waiting = $this->register('wait@example.test');
        self::assertSame('waitlisted', $waiting['status']);

        $result = $this->svc(EventRegistrationService::class)->cancelByClient($a['reference'], $a['access_token'], 'Plans changed');
        self::assertSame('cancelled', $result['status']);
        self::assertSame(['currency' => 'INR', 'amount_minor' => 55550], $result['refund']);

        $this->runJobs();
        self::assertSame(55550, $this->razorpay->refunds[0]['amount']);
        $offered = $this->registration($waiting);
        self::assertSame('pending_payment', $offered['status']);
        self::assertSame('2030-03-03 09:00:00', $offered['hold_expires_at']);
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE template_slug = 'event_waitlist_offer'"));
    }

    public function testCancellationInsideThePolicyWindowIsNotRefunded(): void
    {
        $this->event(['refund_on_cancel_percent' => 100, 'cancellation_window_hours' => 168]);
        $a = $this->register('a@example.test');
        $order = $this->pay($a, 'razorpay');
        $this->captureWebhook($this->razorpay, $order['order_id'], 'evt_paid');

        $this->travel('+8 days');
        $result = $this->svc(EventRegistrationService::class)->cancelByClient($a['reference'], $a['access_token'], null);
        self::assertNull($result['refund']);
        self::assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM jobs WHERE type = 'payment.refund'"));
    }

    public function testCancellingTheGatheringRefundsEveryPaidGuestInFull(): void
    {
        $this->event(['refund_on_cancel_percent' => 0]);
        $paid = [];
        foreach (['a@example.test', 'b@example.test'] as $email) {
            $r = $this->register($email);
            $order = $this->pay($r, 'razorpay');
            $this->captureWebhook($this->razorpay, $order['order_id'], 'evt_' . $email);
            $paid[] = $r;
        }
        $unpaid = $this->register('c@example.test');

        $this->db->run("UPDATE events SET status = 'cancelled' WHERE slug = 'test-gathering'");
        $this->svc(EventRegistrationService::class)->cancelForEvent($this->eventId(), $this->adminUser());
        $this->runJobs();

        self::assertSame([111100, 111100], array_column($this->razorpay->refunds, 'amount'));
        foreach ([...$paid, $unpaid] as $r) {
            self::assertSame('cancelled', $this->registration($r)['status']);
        }
    }

    /** @param array<string, mixed> $overrides */
    private function event(array $overrides = []): void
    {
        $id = $this->db->insert('events', $overrides + [
            'slug' => 'test-gathering',
            'title' => 'Test gathering',
            'category' => 'full_moon',
            'starts_at' => '2030-03-11 13:30:00',
            'ends_at' => '2030-03-11 15:30:00',
            'timezone' => 'Asia/Kolkata',
            'registration_mode' => 'open',
            'status' => 'published',
            'created_at' => $this->clock->nowString(),
            'updated_at' => $this->clock->nowString(),
        ]);
        $this->db->insert('event_prices', ['event_id' => $id, 'currency' => 'INR', 'amount_minor' => 111100]);
        $this->db->insert('event_prices', ['event_id' => $id, 'currency' => 'USD', 'amount_minor' => 1500]);
    }

    private function eventId(): int
    {
        return (int) $this->db->value("SELECT id FROM events WHERE slug = 'test-gathering'");
    }

    /** @return array<string, mixed> */
    private function register(string $email, int $seats = 1, string $currency = 'INR'): array
    {
        return $this->svc(EventRegistrationService::class)->register('test-gathering', [
            'name' => 'Test Guest',
            'email' => $email,
            'seats' => $seats,
            'currency' => $currency,
            'consent_privacy' => true,
        ]);
    }

    /** @param array<string, mixed> $registration @return array<string, mixed> */
    private function pay(array $registration, string $gateway): array
    {
        return $this->svc(PaymentService::class)->createOrder(['registration_reference' => $registration['reference'], 'access_token' => $registration['access_token'], 'gateway' => $gateway], null);
    }

    /** @param array<string, mixed> $registration @return array<string, mixed> */
    private function registration(array $registration): array
    {
        return $this->row('event_registrations', 'reference = ?', [$registration['reference']]);
    }

    private function assertConflict(string $code, callable $fn): void
    {
        try {
            $fn();
            self::fail("Expected a '{$code}' conflict.");
        } catch (HttpException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }
}
