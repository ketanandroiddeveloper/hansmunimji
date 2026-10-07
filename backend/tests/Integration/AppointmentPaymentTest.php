<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\HttpException;
use App\Integrations\Email\Mailer;
use App\Integrations\Payments\InvalidSignature;
use App\Integrations\Payments\VerifiedPayment;
use App\Services\Booking\BookingService;
use App\Services\Booking\ReminderService;
use App\Services\Notifications\NotificationService;
use App\Services\Payments\PaymentService;

final class AppointmentPaymentTest extends IntegrationTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->adminUser();
        $typeId = $this->db->insert('appointment_types', [
            'slug' => 'test-session',
            'title' => 'Test session',
            'duration_minutes' => 60,
            'formats' => json_encode(['google_meet']),
            'is_active' => 1,
            'requires_payment' => 1,
            'created_at' => $this->clock->nowString(),
            'updated_at' => $this->clock->nowString(),
        ]);
        $this->db->insert('appointment_type_prices', ['appointment_type_id' => $typeId, 'currency' => 'INR', 'amount_minor' => 500000]);
        $this->db->insert('appointment_type_prices', ['appointment_type_id' => $typeId, 'currency' => 'USD', 'amount_minor' => 6000]);
    }

    public function testVerifiedPaymentConfirmsOnceAndDuplicateWebhooksAreIgnored(): void
    {
        $this->setting('notifications.admin_email', 'office@example.test');
        [$ref, $token, $id] = $this->book('INR');
        $order = $this->pay($ref, $token, 'razorpay');

        $this->razorpay->settle($order['order_id'], VerifiedPayment::CAPTURED);
        $result = $this->svc(PaymentService::class)->verifyFromClient('razorpay', ['order_id' => $order['order_id']]);

        self::assertSame('confirmed', $result['status']);
        self::assertSame('processed', $this->captureWebhook($this->razorpay, $order['order_id'], 'evt_1'));
        self::assertSame('duplicate', $this->captureWebhook($this->razorpay, $order['order_id'], 'evt_1'));

        $payment = $this->row('payments', 'reference = ?', [$order['payment_reference']]);
        self::assertSame('captured', $payment['status']);
        self::assertSame(['created', 'captured'], $this->historyTo('payment', (int) $payment['id']));
        self::assertSame(['pending_payment', 'confirmed'], $this->historyTo('appointment', $id));
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE template_slug = 'payment_confirmation'"));
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE template_slug = 'admin_payment_succeeded'"));
        self::assertSame('booked', $this->row('appointment_slots', 'appointment_id = ?', [$id])['status']);

        // Google is not connected in tests: the calendar sync waits instead of failing and alerting.
        $this->runJobs();
        self::assertSame('pending', $this->row('appointments', 'id = ?', [$id])['calendar_sync_status']);
        self::assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM jobs WHERE status = 'failed'"));
    }

    public function testRetriedRequestWithSameIdempotencyKeyReusesTheOrder(): void
    {
        [$ref, $token] = $this->book('INR');
        $first = $this->pay($ref, $token, 'razorpay', 'same-key-0000000001');
        $second = $this->pay($ref, $token, 'razorpay', 'same-key-0000000001');

        self::assertSame($first['order_id'], $second['order_id']);
        self::assertCount(1, $this->razorpay->orders);
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM payments'));
    }

    public function testAmountMismatchRequiresReconciliationInsteadOfConfirming(): void
    {
        [$ref, $token, $id] = $this->book('INR');
        $order = $this->pay($ref, $token, 'razorpay');

        $this->captureWebhook($this->razorpay, $order['order_id'], 'evt_short', 100);

        $payment = $this->row('payments', 'reference = ?', [$order['payment_reference']]);
        self::assertSame('reconciliation_required', $payment['status']);
        self::assertStringContainsString('differs from the order amount', (string) $payment['reconciliation_note']);
        self::assertSame('payment_verification', $this->row('appointments', 'id = ?', [$id])['status']);

        $this->svc(PaymentService::class)->acceptReconciled((int) $payment['id'], $this->admin);
        self::assertSame('captured', $this->row('payments', 'id = ?', [$payment['id']])['status']);
        self::assertSame('confirmed', $this->row('appointments', 'id = ?', [$id])['status']);
    }

    public function testCurrencyMismatchRequiresReconciliation(): void
    {
        [$ref, $token, $id] = $this->book('INR');
        $order = $this->pay($ref, $token, 'razorpay');

        $this->captureWebhook($this->razorpay, $order['order_id'], 'evt_ccy', null, 'USD');

        self::assertSame('reconciliation_required', $this->row('payments', 'reference = ?', [$order['payment_reference']])['status']);
        self::assertSame('payment_verification', $this->row('appointments', 'id = ?', [$id])['status']);
    }

    public function testWebhookWithInvalidSignatureIsRejectedAndNotRecorded(): void
    {
        [$ref, $token] = $this->book('INR');
        $order = $this->pay($ref, $token, 'razorpay');
        [$body] = \Tests\Support\FakeGateway::sign(['id' => 'evt_forged', 'kind' => 'payment_captured', 'order_id' => $order['order_id'], 'amount' => 500000, 'currency' => 'INR']);

        try {
            $this->svc(\App\Services\Payments\WebhookService::class)->handle('razorpay', $body, ['x-fake-signature' => str_repeat('0', 64)]);
            self::fail('A forged webhook must be rejected.');
        } catch (InvalidSignature) {
        }
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM webhook_events'));
        self::assertSame('created', $this->row('payments', 'reference = ?', [$order['payment_reference']])['status']);
    }

    public function testFailedPaymentCanBeRetriedWithTheOtherGatewayAsANewOrder(): void
    {
        [$ref, $token, $id] = $this->book('USD');
        $first = $this->pay($ref, $token, 'razorpay');
        $this->webhook('razorpay', ['id' => 'evt_fail', 'kind' => 'payment_failed', 'order_id' => $first['order_id'], 'payment_id' => 'razorpay_pay_x']);

        self::assertSame('failed', $this->row('payments', 'reference = ?', [$first['payment_reference']])['status']);
        self::assertSame('payment_failed', $this->row('appointments', 'id = ?', [$id])['status']);

        $retry = $this->svc(PaymentService::class)->retry($first['payment_reference'], $token, 'stripe', 'retry-key-000000001');
        self::assertTrue($retry['gateway_changed']);
        self::assertSame($first['payment_reference'], $retry['previous_payment_reference']);
        self::assertNotSame($first['payment_reference'], $retry['payment_reference']);
        self::assertArrayHasKey($retry['order_id'], $this->stripe->orders);

        $this->captureWebhook($this->stripe, $retry['order_id'], 'evt_stripe_ok');
        self::assertSame('confirmed', $this->row('appointments', 'id = ?', [$id])['status']);
        self::assertSame('failed', $this->row('payments', 'reference = ?', [$first['payment_reference']])['status']);
    }

    public function testRetryIsRefusedOnceThePaymentHasBeenReceived(): void
    {
        [$ref, $token] = $this->book('INR');
        $order = $this->pay($ref, $token, 'razorpay');
        $this->captureWebhook($this->razorpay, $order['order_id'], 'evt_ok');

        try {
            $this->svc(PaymentService::class)->retry($order['payment_reference'], $token, 'razorpay', null);
            self::fail('Retrying a received payment must be refused.');
        } catch (HttpException $e) {
            self::assertSame('already_paid', $e->errorCode);
        }
        self::assertCount(1, $this->razorpay->orders);
    }

    public function testSecondCaptureForAPaidBookingIsRefundedAndBookingStaysConfirmed(): void
    {
        [$ref, $token, $id] = $this->book('USD');
        $a = $this->pay($ref, $token, 'razorpay');
        $b = $this->pay($ref, $token, 'stripe');

        $this->captureWebhook($this->razorpay, $a['order_id'], 'evt_a');
        $this->captureWebhook($this->stripe, $b['order_id'], 'evt_b');
        $this->captureWebhook($this->stripe, $b['order_id'], 'evt_b_resent');

        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM jobs WHERE type = 'payment.refund'"));
        $this->runJobs();
        self::assertSame('done', $this->db->value("SELECT status FROM jobs WHERE type = 'payment.refund'"));
        self::assertCount(1, $this->stripe->refunds);
        self::assertSame(6000, $this->stripe->refunds[0]['amount']);

        $this->webhook('stripe', ['id' => 'evt_rf', 'kind' => 'refund_processed', 'refund_id' => 'stripe_rfnd_1']);
        self::assertSame('refunded', $this->row('payments', 'reference = ?', [$b['payment_reference']])['status']);
        self::assertSame('captured', $this->row('payments', 'reference = ?', [$a['payment_reference']])['status']);
        self::assertSame('confirmed', $this->row('appointments', 'id = ?', [$id])['status']);
    }

    public function testUnknownWebhookEventIsAcknowledgedOnceAndChangesNothing(): void
    {
        [$ref, $token, $id] = $this->book('USD');
        $order = $this->pay($ref, $token, 'stripe');

        self::assertSame('ignored', $this->webhook('stripe', ['id' => 'evt_unknown', 'kind' => 'customer.created', 'order_id' => $order['order_id']]));
        self::assertSame('duplicate', $this->webhook('stripe', ['id' => 'evt_unknown', 'kind' => 'customer.created', 'order_id' => $order['order_id']]));

        self::assertSame('ignored', $this->db->value("SELECT status FROM webhook_events WHERE event_id = 'evt_unknown'"));
        self::assertSame('created', $this->row('payments', 'reference = ?', [$order['payment_reference']])['status']);
        self::assertSame('pending_payment', $this->row('appointments', 'id = ?', [$id])['status']);
    }

    public function testCancelledCheckoutLeavesTheBookingPayableAndCanBeRetried(): void
    {
        [$ref, $token, $id] = $this->book('USD');
        $order = $this->pay($ref, $token, 'stripe');

        // Returning via the cancel URL changes nothing server-side; the session stays open until Stripe expires it.
        self::assertSame('created', $this->row('payments', 'reference = ?', [$order['payment_reference']])['status']);

        self::assertSame('processed', $this->webhook('stripe', ['id' => 'evt_expired', 'kind' => 'checkout_expired', 'order_id' => $order['order_id']]));
        self::assertSame('cancelled', $this->row('payments', 'reference = ?', [$order['payment_reference']])['status']);
        self::assertSame('pending_payment', $this->row('appointments', 'id = ?', [$id])['status']);

        // A late "paid" for the expired session would still be honoured, but a new checkout is the normal path.
        $retry = $this->svc(PaymentService::class)->retry($order['payment_reference'], $token, null, 'retry-after-cancel-01');
        self::assertFalse($retry['gateway_changed']);
        self::assertNotSame($order['order_id'], $retry['order_id']);

        $this->captureWebhook($this->stripe, $retry['order_id'], 'evt_paid_after_retry');
        self::assertSame('confirmed', $this->row('appointments', 'id = ?', [$id])['status']);
        self::assertSame('cancelled', $this->row('payments', 'reference = ?', [$order['payment_reference']])['status']);
    }

    public function testProviderOutageCreatesNoPaymentAndNeverConfirmsFromTheBrowser(): void
    {
        [$ref, $token, $id] = $this->book('USD');
        $this->stripe->unavailable = true;

        try {
            $this->pay($ref, $token, 'stripe');
            self::fail('An unreachable provider must surface as an error.');
        } catch (HttpException $e) {
            self::assertSame(502, $e->status);
            self::assertSame('gateway_unavailable', $e->errorCode);
        }
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM payments'));
        self::assertSame('pending_payment', $this->row('appointments', 'id = ?', [$id])['status']);

        $this->stripe->unavailable = false;
        $order = $this->pay($ref, $token, 'stripe');
        $this->stripe->settle($order['order_id'], VerifiedPayment::CAPTURED);
        $this->stripe->unavailable = true;

        try {
            $this->svc(PaymentService::class)->verifyFromClient('stripe', ['order_id' => $order['order_id']]);
            self::fail('Verification must fail while the provider is unreachable.');
        } catch (HttpException $e) {
            self::assertSame('gateway_unavailable', $e->errorCode);
        }
        self::assertSame('created', $this->row('payments', 'reference = ?', [$order['payment_reference']])['status']);

        // The webhook remains authoritative and confirms the booking regardless.
        $this->captureWebhook($this->stripe, $order['order_id'], 'evt_during_outage');
        self::assertSame('confirmed', $this->row('appointments', 'id = ?', [$id])['status']);
    }

    public function testLatePaymentForATimeBookedByAnotherClientIsRefundedAutomatically(): void
    {
        $startsAt = $this->clock->now()->modify('+3 days')->format('Y-m-d\TH:i:s\Z');
        [$ref, $token, $id] = $this->book('INR', $startsAt);
        $order = $this->pay($ref, $token, 'razorpay');

        $this->travel('+45 minutes');
        [, , $other] = $this->book('INR', $startsAt, 'second@example.test');
        $this->captureWebhook($this->razorpay, $order['order_id'], 'evt_late');

        self::assertSame('payment_verification', $this->row('appointments', 'id = ?', [$id])['status']);
        self::assertSame('pending_payment', $this->row('appointments', 'id = ?', [$other])['status']);
        $this->runJobs();
        self::assertCount(1, $this->razorpay->refunds);
        self::assertSame(500000, $this->razorpay->refunds[0]['amount']);
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM refunds WHERE status = 'pending'"));
    }

    public function testUnpaidReservationExpiresAndALateCaptureStillConfirmsIfTheTimeIsFree(): void
    {
        [$ref, $token, $id] = $this->book('INR');
        $order = $this->pay($ref, $token, 'razorpay');

        $this->travel('+2 days');
        $this->db->run("UPDATE appointment_slots SET status = 'released' WHERE appointment_id = ? AND held_until < ?", [$id, $this->clock->nowString()]);
        self::assertSame(1, $this->svc(BookingService::class)->expireUnpaid());

        $appointment = $this->row('appointments', 'id = ?', [$id]);
        self::assertSame('expired', $appointment['status']);
        self::assertNotNull($appointment['expired_at']);

        $this->captureWebhook($this->razorpay, $order['order_id'], 'evt_very_late');
        self::assertSame('confirmed', $this->row('appointments', 'id = ?', [$id])['status']);
        self::assertSame(['pending_payment', 'expired', 'confirmed'], $this->historyTo('appointment', $id));
    }

    public function testRecentCheckoutPreventsExpiry(): void
    {
        [$ref, $token, $id] = $this->book('INR');
        $this->travel('+26 hours');
        $this->db->run("UPDATE appointment_slots SET status = 'released' WHERE appointment_id = ?", [$id]);
        $this->pay($ref, $token, 'razorpay');

        self::assertSame(0, $this->svc(BookingService::class)->expireUnpaid());
        self::assertSame('pending_payment', $this->row('appointments', 'id = ?', [$id])['status']);
    }

    public function testEmailOutageAfterPaymentKeepsThePaymentConfirmedAndTheEmailRetryable(): void
    {
        $this->c->set(Mailer::class, static fn () => new class implements Mailer {
            public function send(string $to, string $subject, string $html, string $text): ?string
            {
                throw new \RuntimeException('SMTP delivery failed: connection refused');
            }

            public function isConfigured(): bool
            {
                return true;
            }
        });
        [$ref, $token, $id] = $this->book('INR');
        $order = $this->pay($ref, $token, 'razorpay');
        $this->captureWebhook($this->razorpay, $order['order_id'], 'evt_mail_down');

        $sent = $this->svc(NotificationService::class)->processDue();

        self::assertGreaterThan(0, $sent);
        self::assertSame('captured', $this->row('payments', 'reference = ?', [$order['payment_reference']])['status']);
        self::assertSame('confirmed', $this->row('appointments', 'id = ?', [$id])['status']);
        $mail = $this->row('notifications', "template_slug = 'payment_confirmation'");
        self::assertSame('queued', $mail['status']);
        self::assertSame(1, (int) $mail['attempts']);
        self::assertGreaterThan($this->clock->nowString(), $mail['available_at']);
    }

    public function testEmailStuckInSendingAfterAWorkerCrashIsQueuedAgain(): void
    {
        $notifications = $this->svc(NotificationService::class);
        $stuck = $notifications->queue('payment_confirmation', 'client@example.test', ['reference' => 'PY-TEST']);
        $this->db->update('notifications', ['status' => 'sending', 'available_at' => $this->clock->nowString()], ['id' => $stuck]);

        $this->travel('+10 minutes');
        self::assertSame(0, $notifications->releaseStale());

        $this->travel('+6 minutes');
        self::assertSame(1, $notifications->releaseStale());
        self::assertSame('queued', $this->row('notifications', 'id = ?', [$stuck])['status']);
    }

    public function testEachReminderIsQueuedOnce(): void
    {
        [$ref, $token, $id] = $this->book('INR');
        $order = $this->pay($ref, $token, 'razorpay');
        $this->captureWebhook($this->razorpay, $order['order_id'], 'evt_reminder');
        self::assertSame(3, (int) $this->db->value("SELECT COUNT(*) FROM reminder_jobs WHERE appointment_id = ? AND status = 'pending'", [$id]));

        $this->travel('+2 days 1 minute');
        $reminders = $this->svc(ReminderService::class);
        $reminders->processDue();
        $reminders->processDue();

        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE template_slug = 'appointment_reminder'"));
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM reminder_jobs WHERE appointment_id = ? AND status = 'sent'", [$id]));
    }

    /** @return array{0: string, 1: string, 2: int} reference, access token, appointment id */
    private function book(string $currency, ?string $startsAt = null, string $email = 'client@example.test'): array
    {
        $created = $this->svc(BookingService::class)->create([
            'type' => 'test-session',
            'starts_at' => $startsAt ?? $this->clock->now()->modify('+3 days')->format('Y-m-d\TH:i:s\Z'),
            'timezone' => 'UTC',
            'format' => 'google_meet',
            'currency' => $currency,
            'name' => 'Test Client',
            'email' => $email,
        ], $this->admin);

        return [$created['reference'], $created['access_token'], (int) $this->db->value('SELECT id FROM appointments WHERE reference = ?', [$created['reference']])];
    }

    /** @return array<string, mixed> */
    private function pay(string $ref, string $token, string $gateway, ?string $key = null): array
    {
        return $this->svc(PaymentService::class)->createOrder(['appointment_reference' => $ref, 'access_token' => $token, 'gateway' => $gateway], $key);
    }
}
