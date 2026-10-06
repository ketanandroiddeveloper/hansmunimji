<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use App\Integrations\Payments\GatewayError;
use App\Integrations\Payments\GatewayRegistry;
use App\Integrations\Payments\PaymentIntent;
use App\Integrations\Payments\VerifiedPayment;
use App\Jobs\JobQueue;
use App\Security\AuditLogger;
use App\Security\Crypto;
use App\Security\Tokens;
use App\Services\Booking\BookingLock;
use App\Services\Booking\BookingService;
use App\Services\Events\EventRegistrationService;
use App\Services\Notifications\NotificationService;
use App\Services\SettingsService;
use App\Services\StatusHistory;
use App\Services\TimeFormatter;

final class PaymentService
{
    /** Payment states in which money is held by the merchant and may be refunded. */
    public const REFUNDABLE = ['captured', 'partially_refunded', 'reconciliation_required'];

    public function __construct(
        private Database $db,
        private Clock $clock,
        private Config $config,
        private GatewayRegistry $gateways,
        private PaymentRouter $router,
        private BookingService $bookings,
        private BookingLock $lock,
        private EventRegistrationService $registrations,
        private NotificationService $notifications,
        private SettingsService $settings,
        private Crypto $crypto,
        private JobQueue $jobs,
        private AuditLogger $audit,
        private StatusHistory $history,
        private Logger $logger,
    ) {
    }

    /** @return list<array{name: string, public_key: ?string, mode: string}> */
    public function availableGateways(string $currency, ?string $country = null): array
    {
        return array_map(fn ($g) => [
            'name' => $g->name(),
            'public_key' => $g->name() === 'razorpay' ? $this->config->get('payments.razorpay.key_id') : $this->config->get('payments.stripe.publishable_key'),
            'mode' => $g->isLiveMode() ? 'live' : 'test',
        ], $this->router->route($currency, $country));
    }

    /**
     * Creates a gateway order for an appointment or event registration. The amount always comes from
     * the stored booking (calculated server-side at reservation); the browser only chooses the gateway.
     *
     * @param array{appointment_reference?: string, registration_reference?: string, access_token: string, gateway: string} $input
     * @return array<string, mixed> client payload for the chosen gateway
     */
    public function createOrder(array $input, ?string $idempotencyKey): array
    {
        [$payableType, $payable, $description, $email, $returnPath] = $this->resolvePayable($input);

        if ($idempotencyKey !== null) {
            $existing = $this->db->first('SELECT payable_type, payable_id, metadata FROM payments WHERE idempotency_key = ?', [$idempotencyKey]);
            if ($existing !== null) {
                if ($existing['payable_type'] !== $payableType || (int) $existing['payable_id'] !== (int) $payable['id']) {
                    throw HttpException::conflict('idempotency_conflict', 'This request key was already used for another payment.');
                }

                return json_decode((string) $existing['metadata'], true) ?: [];
            }
        }

        $currency = (string) $payable['currency'];
        $amount = (int) ($payableType === 'appointment' ? $payable['total_minor'] : $payable['amount_minor']);
        if ($amount <= 0) {
            throw HttpException::conflict('nothing_to_pay', 'No payment is required for this booking.');
        }

        $allowed = array_column($this->availableGateways($currency, $payable['country'] ?? null), 'name');
        if (!in_array($input['gateway'], $allowed, true)) {
            throw HttpException::validation(['gateway' => ['This payment method is not available for the selected currency.']]);
        }

        if ($payableType === 'appointment') {
            $this->bookings->ensureHold($payable);
        } else {
            $this->registrations->ensureHold((int) $payable['id']);
        }

        $gateway = $this->gateways->get($input['gateway']);
        $key = $idempotencyKey ?? Tokens::uuid();
        $reference = Tokens::reference('PY');
        $frontend = (string) $this->config->get('app.frontend_url');

        try {
            $order = $gateway->createOrder(new PaymentIntent(
                reference: (string) $payable['reference'],
                amountMinor: $amount,
                currency: $currency,
                description: $description,
                customerEmail: $email,
                successUrl: $frontend . $returnPath . '?checkout=success&session_id={CHECKOUT_SESSION_ID}',
                cancelUrl: $frontend . $returnPath . '?checkout=cancelled',
                idempotencyKey: $key,
                metadata: ['payable_type' => $payableType, 'payment_reference' => $reference, 'environment' => $this->config->environment()],
            ));
        } catch (GatewayError $e) {
            $this->logger->error('payment_order_failed', ['gateway' => $gateway->name(), 'message' => $e->getMessage()]);
            throw new HttpException(502, 'gateway_unavailable', 'The payment provider could not be reached. Please try again in a moment.');
        }

        $payload = $order->clientPayload + ['payment_reference' => $reference];
        $id = $this->db->insert('payments', [
            'reference' => $reference,
            'payable_type' => $payableType,
            'payable_id' => $payable['id'],
            'gateway' => $gateway->name(),
            'environment' => $this->config->environment(),
            'gateway_order_id' => $order->orderId,
            'currency' => $currency,
            'amount_minor' => $amount,
            'status' => 'created',
            'idempotency_key' => $key,
            'metadata' => $payload,
            'created_at' => $this->clock->nowString(),
            'updated_at' => $this->clock->nowString(),
        ]);
        $this->history->record('payment', $id, null, 'created', 'client', null, $gateway->name());

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public function verifyFromClient(string $gatewayName, array $payload): array
    {
        $gateway = $this->gateways->get($gatewayName);
        try {
            $verified = $gateway->verifyClientPayload($payload);
        } catch (GatewayError $e) {
            throw new HttpException(502, 'gateway_unavailable', 'We could not confirm the payment with the provider yet. If you were charged, your booking will be confirmed automatically.');
        }

        return $this->applyResult($gatewayName, $verified, 'verify');
    }

    /**
     * Single entry point for payment outcomes (browser verify, webhook, reconciliation).
     * Idempotent: repeated or out-of-order outcomes never re-confirm, re-charge or downgrade a payment.
     *
     * @return array{status: string, payable_type: string, reference: string, payment_status: string}
     */
    public function applyResult(string $gatewayName, VerifiedPayment $result, string $source = 'system'): array
    {
        // Same lock ordering as booking creation (named lock → transaction) so a capture can never
        // race a concurrent hold on the same time.
        $outcome = $this->lock->run(fn () => $this->db->transaction(function () use ($gatewayName, $result, $source) {
            $payment = $this->db->first('SELECT * FROM payments WHERE gateway = ? AND gateway_order_id = ? FOR UPDATE', [$gatewayName, $result->orderId]);
            if ($payment === null) {
                throw HttpException::notFound('Payment not found.');
            }
            $id = (int) $payment['id'];
            $now = $this->clock->nowString();
            $from = (string) $payment['status'];

            if ($result->status === VerifiedPayment::CAPTURED) {
                if (in_array($from, ['captured', 'refunded', 'partially_refunded', 'reconciliation_required'], true)) {
                    return ['effect' => 'none', 'payment' => $payment];
                }

                $problem = self::captureProblem($payment, $result, $this->config->environment());
                if ($problem !== null) {
                    $this->db->update('payments', [
                        'status' => 'reconciliation_required',
                        'gateway_payment_id' => $result->paymentId,
                        'reconciliation_note' => $problem,
                        'verified_at' => $now,
                        'updated_at' => $now,
                    ], ['id' => $id]);
                    $this->history->record('payment', $id, $from, 'reconciliation_required', $source, null, $problem);
                    $this->logger->error('payment_reconciliation_required', ['gateway' => $gatewayName, 'payment' => $payment['reference'], 'problem' => $problem]);
                    if ($payment['payable_type'] === 'appointment') {
                        $this->bookings->markPaymentVerification((int) $payment['payable_id'], $source);
                    }

                    return ['effect' => 'reconciliation', 'payment' => $payment + ['reconciliation_note' => $problem]];
                }

                $this->db->update('payments', [
                    'status' => 'captured',
                    'gateway_payment_id' => $result->paymentId,
                    'receipt_number' => 'R-' . gmdate('Ymd') . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT),
                    'verified_at' => $now,
                    'captured_at' => $now,
                    'failure_reason' => null,
                    'updated_at' => $now,
                ], ['id' => $id]);
                $this->history->record('payment', $id, $from, 'captured', $source);

                // A second checkout paid for the same booking (two tabs, two gateways): keep the first, refund this one.
                $duplicate = $this->db->value(
                    "SELECT 1 FROM payments WHERE payable_type = ? AND payable_id = ? AND id <> ? AND status IN ('captured','partially_refunded') LIMIT 1",
                    [$payment['payable_type'], $payment['payable_id'], $id],
                );
                if ($duplicate) {
                    $this->history->record('payment', $id, 'captured', 'captured', $source, null, 'Duplicate payment for an already-paid booking.');

                    return ['effect' => 'duplicate', 'payment' => $payment];
                }

                $confirmed = $payment['payable_type'] === 'appointment'
                    ? $this->bookings->confirmPaid((int) $payment['payable_id'], $source)
                    : $this->registrations->confirmPaid((int) $payment['payable_id'], $source);

                return ['effect' => $confirmed ? 'confirmed' : 'conflict', 'payment' => $payment];
            }

            if ($result->status === VerifiedPayment::FAILED && in_array($from, ['created', 'pending'], true)) {
                $this->db->update('payments', ['status' => 'failed', 'gateway_payment_id' => $result->paymentId, 'failure_reason' => $result->failureReason, 'updated_at' => $now], ['id' => $id]);
                $this->history->record('payment', $id, $from, 'failed', $source, null, $result->failureReason);
                if ($payment['payable_type'] === 'appointment') {
                    $this->bookings->markPaymentFailed((int) $payment['payable_id'], $source);
                }

                return ['effect' => 'failed', 'payment' => $payment];
            }

            if ($result->status === VerifiedPayment::EXPIRED && in_array($from, ['created', 'pending'], true)) {
                $this->db->update('payments', ['status' => 'cancelled', 'updated_at' => $now], ['id' => $id]);
                $this->history->record('payment', $id, $from, 'cancelled', $source, null, 'Checkout expired or abandoned.');

                return ['effect' => 'expired', 'payment' => $payment];
            }

            if ($result->status === VerifiedPayment::PENDING && $from === 'created' && $result->paymentId !== null) {
                $this->db->update('payments', ['status' => 'pending', 'gateway_payment_id' => $result->paymentId, 'updated_at' => $now], ['id' => $id]);
                $this->history->record('payment', $id, $from, 'pending', $source);
            }

            return ['effect' => 'none', 'payment' => $payment];
        }));

        $payment = $outcome['payment'];
        $this->afterResult((string) $outcome['effect'], $payment);

        $table = $payment['payable_type'] === 'appointment' ? 'appointments' : 'event_registrations';
        $payable = $this->db->first("SELECT reference, status FROM {$table} WHERE id = ?", [$payment['payable_id']]);

        return [
            'status' => (string) ($payable['status'] ?? ''),
            'payable_type' => (string) $payment['payable_type'],
            'reference' => (string) ($payable['reference'] ?? ''),
            'payment_status' => (string) $this->db->value('SELECT status FROM payments WHERE id = ?', [$payment['id']]),
        ];
    }

    /**
     * Why a capture reported by the gateway cannot be accepted automatically, or null when it matches
     * the order exactly.
     *
     * @param array<string, mixed> $payment
     */
    public static function captureProblem(array $payment, VerifiedPayment $result, string $environment): ?string
    {
        if ($result->amountMinor !== (int) $payment['amount_minor']) {
            return "Captured amount {$result->amountMinor} differs from the order amount {$payment['amount_minor']}.";
        }
        if (strtoupper($result->currency) !== (string) $payment['currency']) {
            return 'Captured currency ' . strtoupper($result->currency) . " differs from the order currency {$payment['currency']}.";
        }
        if ((string) $payment['environment'] !== $environment) {
            return "Payment was created in '{$payment['environment']}' but reported in '{$environment}'.";
        }

        return null;
    }

    /**
     * Admin decision on a payment flagged for reconciliation after confirming it with the gateway
     * dashboard. Accepting confirms the booking as if the capture had matched.
     */
    public function acceptReconciled(int $paymentId, int $userId): array
    {
        $outcome = $this->lock->run(fn () => $this->db->transaction(function () use ($paymentId, $userId) {
            $payment = $this->db->first('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]) ?? throw HttpException::notFound();
            if ($payment['status'] !== 'reconciliation_required') {
                throw HttpException::conflict('invalid_state', 'Only payments awaiting reconciliation can be accepted.');
            }
            $now = $this->clock->nowString();
            $this->db->update('payments', [
                'status' => 'captured',
                'receipt_number' => 'R-' . gmdate('Ymd') . '-' . str_pad((string) $paymentId, 6, '0', STR_PAD_LEFT),
                'captured_at' => $now,
                'updated_at' => $now,
            ], ['id' => $paymentId]);
            $this->history->record('payment', $paymentId, 'reconciliation_required', 'captured', 'admin', $userId, 'Accepted after manual reconciliation.');
            $confirmed = $payment['payable_type'] === 'appointment'
                ? $this->bookings->confirmPaid((int) $payment['payable_id'], 'admin', $userId)
                : $this->registrations->confirmPaid((int) $payment['payable_id'], 'admin', $userId);

            return ['effect' => $confirmed ? 'confirmed' : 'conflict', 'payment' => $payment];
        }));
        $this->audit->record($userId, 'payment.reconciliation_accepted', 'payment', $paymentId);
        $this->afterResult((string) $outcome['effect'], $outcome['payment']);

        return ['status' => (string) $this->db->value('SELECT status FROM payments WHERE id = ?', [$paymentId])];
    }

    /** Issues a refund through the original gateway. Safe to retry (idempotency key per call site). */
    public function refund(int $paymentId, int $amountMinor, string $reason, ?int $userId, string $idempotencyKey): array
    {
        $payment = $this->db->first('SELECT * FROM payments WHERE id = ?', [$paymentId]) ?? throw HttpException::notFound('Payment not found.');
        $existing = $this->db->first('SELECT * FROM refunds WHERE idempotency_key = ?', [$idempotencyKey]);
        if ($existing !== null && $existing['status'] !== 'failed') {
            return $existing;
        }
        if (!in_array($payment['status'], self::REFUNDABLE, true) || !$payment['gateway_payment_id']) {
            throw HttpException::conflict('not_refundable', 'Only captured payments can be refunded.');
        }

        // Refunds still pending at the gateway are not yet in refunded_minor but must not be refunded twice.
        $pending = (int) $this->db->value("SELECT COALESCE(SUM(amount_minor), 0) FROM refunds WHERE payment_id = ? AND status = 'pending'", [$paymentId]);
        $remaining = (int) $payment['amount_minor'] - (int) $payment['refunded_minor'] - $pending;
        if ($amountMinor <= 0 || $amountMinor > $remaining) {
            throw HttpException::validation(['amount_minor' => [$remaining > 0 ? "Enter an amount between 1 and {$remaining}." : 'Nothing remains to refund on this payment.']]);
        }
        $refundId = $existing['id'] ?? $this->db->insert('refunds', [
            'payment_id' => $paymentId,
            'amount_minor' => $amountMinor,
            'currency' => $payment['currency'],
            'status' => 'pending',
            'reason' => mb_substr($reason, 0, 255),
            'initiated_by' => $userId,
            'idempotency_key' => $idempotencyKey,
            'created_at' => $this->clock->nowString(),
            'updated_at' => $this->clock->nowString(),
        ]);
        $this->history->record('refund', (int) $refundId, null, 'pending', $userId ? 'admin' : 'system', $userId, mb_substr($reason, 0, 120));

        try {
            $result = $this->gateways->get((string) $payment['gateway'])->refund((string) $payment['gateway_payment_id'], $amountMinor, (string) $payment['currency'], $idempotencyKey, $reason);
        } catch (GatewayError $e) {
            $this->db->update('refunds', ['status' => 'failed', 'updated_at' => $this->clock->nowString()], ['id' => $refundId]);
            $this->history->record('refund', (int) $refundId, 'pending', 'failed', 'system', null, 'Gateway rejected or unreachable.');
            throw new HttpException(502, 'gateway_unavailable', 'The refund could not be submitted to the payment provider. Please retry.');
        }

        $this->db->update('refunds', ['gateway_refund_id' => $result->refundId, 'updated_at' => $this->clock->nowString()], ['id' => $refundId]);
        $this->audit->record($userId, 'payment.refund_initiated', 'payment', $paymentId, ['amount_minor' => $amountMinor]);
        $contact = $this->payableContact($payment);
        if ($contact !== null) {
            $this->notifications->queue('refund_initiated', $contact['email'], [
                'name' => $contact['name'],
                'reference' => $contact['reference'],
                'refund_amount' => TimeFormatter::money($amountMinor, (string) $payment['currency']),
            ], (string) $payment['payable_type'], (int) $payment['payable_id']);
        }
        // Only the gateway's confirmation (immediately or later by webhook) completes a refund.
        if ($result->status === 'processed') {
            $this->markRefundProcessed((string) $payment['gateway'], $result->refundId);
        }

        return $this->db->first('SELECT * FROM refunds WHERE id = ?', [$refundId]) ?? [];
    }

    public function markRefundProcessed(string $gateway, string $gatewayRefundId, ?string $gatewayPaymentId = null, int $amountMinor = 0): void
    {
        $notify = $this->db->transaction(function () use ($gateway, $gatewayRefundId, $gatewayPaymentId, $amountMinor) {
            $refund = $this->db->first('SELECT * FROM refunds WHERE gateway_refund_id = ? FOR UPDATE', [$gatewayRefundId]);
            if ($refund === null && $gatewayPaymentId !== null) {
                // Refund issued directly in the gateway dashboard — record it for reconciliation.
                $payment = $this->db->first('SELECT * FROM payments WHERE gateway = ? AND gateway_payment_id = ?', [$gateway, $gatewayPaymentId]);
                if ($payment === null) {
                    return null;
                }
                $id = $this->db->insert('refunds', [
                    'payment_id' => $payment['id'], 'gateway_refund_id' => $gatewayRefundId, 'amount_minor' => $amountMinor,
                    'currency' => $payment['currency'], 'status' => 'pending', 'reason' => 'Issued in gateway dashboard',
                    'idempotency_key' => 'external:' . $gatewayRefundId, 'created_at' => $this->clock->nowString(), 'updated_at' => $this->clock->nowString(),
                ]);
                $refund = $this->db->first('SELECT * FROM refunds WHERE id = ? FOR UPDATE', [$id]);
            }
            if ($refund === null || $refund['status'] === 'processed') {
                return null;
            }
            $now = $this->clock->nowString();
            $this->db->update('refunds', ['status' => 'processed', 'updated_at' => $now], ['id' => $refund['id']]);
            $this->history->record('refund', (int) $refund['id'], (string) $refund['status'], 'processed', 'webhook');
            $payment = $this->db->first('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$refund['payment_id']]);
            $refunded = (int) $payment['refunded_minor'] + (int) $refund['amount_minor'];
            $status = $refunded >= (int) $payment['amount_minor'] ? 'refunded' : 'partially_refunded';
            $this->db->update('payments', ['refunded_minor' => $refunded, 'status' => $status, 'updated_at' => $now], ['id' => $payment['id']]);
            $this->history->record('payment', (int) $payment['id'], (string) $payment['status'], $status, 'webhook');

            // Refunding a duplicate payment must not mark a booking refunded while another payment still stands.
            $otherPaid = $this->db->value(
                "SELECT 1 FROM payments WHERE payable_type = ? AND payable_id = ? AND id <> ? AND status IN ('captured','partially_refunded') LIMIT 1",
                [$payment['payable_type'], $payment['payable_id'], $payment['id']],
            );
            if (!$otherPaid && $payment['payable_type'] === 'appointment') {
                $this->bookings->markRefunded((int) $payment['payable_id'], $status === 'refunded');
            } elseif (!$otherPaid) {
                $this->registrations->markRefunded((int) $payment['payable_id']);
            }

            return ['payment' => $payment, 'refund' => $refund];
        });

        if ($notify !== null) {
            $contact = $this->payableContact($notify['payment']);
            if ($contact !== null) {
                $this->notifications->queue('refund_confirmation', $contact['email'], [
                    'name' => $contact['name'],
                    'reference' => $contact['reference'],
                    'refund_amount' => TimeFormatter::money((int) $notify['refund']['amount_minor'], (string) $notify['refund']['currency']),
                ], (string) $notify['payment']['payable_type'], (int) $notify['payment']['payable_id']);
            }
        }
    }

    public function markRefundFailed(string $gatewayRefundId): void
    {
        $refund = $this->db->first("SELECT id FROM refunds WHERE gateway_refund_id = ? AND status = 'pending'", [$gatewayRefundId]);
        if ($refund === null) {
            return;
        }
        $this->db->update('refunds', ['status' => 'failed', 'updated_at' => $this->clock->nowString()], ['id' => $refund['id']]);
        $this->history->record('refund', (int) $refund['id'], 'pending', 'failed', 'webhook');
        $this->notifications->queueAdmin('admin_integration_failure', ['integration' => 'Refund', 'reference' => $gatewayRefundId, 'error' => 'The gateway reported a failed refund.']);
    }

    /** Reconciles stale pending orders with the gateway (scheduler). */
    public function reconcilePending(int $olderThanMinutes = 20, int $limit = 20): int
    {
        $rows = $this->db->all(
            "SELECT gateway, gateway_order_id FROM payments WHERE status IN ('created','pending') AND created_at < ? AND created_at > ? ORDER BY id LIMIT {$limit}",
            [$this->clock->now()->modify("-{$olderThanMinutes} minutes")->format('Y-m-d H:i:s'), $this->clock->now()->modify('-2 days')->format('Y-m-d H:i:s')],
        );
        foreach ($rows as $row) {
            try {
                $this->applyResult((string) $row['gateway'], $this->gateways->get((string) $row['gateway'])->fetchByOrder((string) $row['gateway_order_id']), 'scheduler');
            } catch (\Throwable $e) {
                $this->logger->warning('reconcile_failed', ['gateway' => $row['gateway'], 'message' => $e->getMessage()]);
            }
        }

        return count($rows);
    }

    /**
     * Client-facing payment status, authorised by the booking's own access token.
     *
     * @return array<string, mixed>
     */
    public function statusForClient(string $paymentReference, string $accessToken): array
    {
        $payment = $this->db->first('SELECT * FROM payments WHERE reference = ?', [$paymentReference]) ?? throw HttpException::notFound('Payment not found.');
        $payable = $payment['payable_type'] === 'appointment'
            ? $this->bookings->findForClient((string) $this->db->value('SELECT reference FROM appointments WHERE id = ?', [$payment['payable_id']]), $accessToken)
            : $this->registrations->findForClient((string) $this->db->value('SELECT reference FROM event_registrations WHERE id = ?', [$payment['payable_id']]), $accessToken);

        return [
            'reference' => $payment['reference'],
            'gateway' => $payment['gateway'],
            'status' => $payment['status'],
            'currency' => $payment['currency'],
            'amount_minor' => (int) $payment['amount_minor'],
            'refunded_minor' => (int) $payment['refunded_minor'],
            'receipt_number' => $payment['receipt_number'],
            'payable' => ['type' => $payment['payable_type'], 'reference' => $payable['reference'], 'status' => $payable['status']],
            'created_at' => Clock::iso((string) $payment['created_at']),
            'verified_at' => Clock::iso($payment['verified_at']),
        ];
    }

    /**
     * Starts a fresh checkout for the booking behind an earlier payment (failed, cancelled or abandoned).
     * A new gateway order is always created; an earlier order is never reused across gateways.
     *
     * @return array<string, mixed>
     */
    public function retry(string $paymentReference, string $accessToken, ?string $gateway, ?string $idempotencyKey): array
    {
        $payment = $this->db->first('SELECT payable_type, payable_id, gateway, status FROM payments WHERE reference = ?', [$paymentReference]) ?? throw HttpException::notFound('Payment not found.');
        // Authorise before revealing anything about the payment's state.
        $this->statusForClient($paymentReference, $accessToken);
        if (in_array($payment['status'], ['captured', 'refunded', 'partially_refunded', 'reconciliation_required'], true)) {
            throw HttpException::conflict('already_paid', 'This payment has already been received; no new checkout is needed.');
        }
        $key = $payment['payable_type'] === 'appointment' ? 'appointment_reference' : 'registration_reference';
        $table = $payment['payable_type'] === 'appointment' ? 'appointments' : 'event_registrations';
        $gateway ??= (string) $payment['gateway'];

        return $this->createOrder([
            $key => (string) $this->db->value("SELECT reference FROM {$table} WHERE id = ?", [$payment['payable_id']]),
            'access_token' => $accessToken,
            'gateway' => $gateway,
        ], $idempotencyKey) + [
            'previous_payment_reference' => $paymentReference,
            'gateway_changed' => $gateway !== $payment['gateway'],
        ];
    }

    /** @param array<string, mixed> $payment */
    private function afterResult(string $effect, array $payment): void
    {
        $type = (string) $payment['payable_type'];
        $payableId = (int) $payment['payable_id'];

        if ($effect === 'confirmed') {
            if ($type === 'appointment') {
                $appointment = $this->bookings->find($payableId);
                $email = (string) $this->crypto->decrypt($appointment['client_email_enc'], 'appointments.client_email');
                $this->notifications->queue('payment_confirmation', $email, $this->bookings->emailVars($appointment), 'appointment', $payableId);
                $this->bookings->afterConfirmed($appointment);
            } else {
                $this->registrations->afterConfirmed($payableId);
            }

            return;
        }

        if ($effect === 'failed' && $type === 'appointment') {
            $appointment = $this->bookings->find($payableId);
            $email = (string) $this->crypto->decrypt($appointment['client_email_enc'], 'appointments.client_email');
            $this->notifications->queue('payment_failed', $email, $this->bookings->emailVars($appointment), 'appointment', $payableId);

            return;
        }

        if ($effect === 'reconciliation') {
            $this->notifications->queueAdmin('admin_integration_failure', [
                'integration' => 'Payment reconciliation',
                'reference' => (string) $payment['reference'],
                'error' => 'A captured payment did not match its order and was not applied automatically: ' . ($payment['reconciliation_note'] ?? '') . ' Review it under Payments.',
            ], 'payment', (int) $payment['id']);

            return;
        }

        if ($effect === 'conflict' || $effect === 'duplicate') {
            $payableReference = (string) $this->db->value(
                $type === 'appointment' ? 'SELECT reference FROM appointments WHERE id = ?' : 'SELECT reference FROM event_registrations WHERE id = ?',
                [$payableId],
            );
            $autoRefund = (bool) $this->settings->get('payments.auto_refund_conflicts', true);
            $why = $effect === 'duplicate'
                ? 'A second payment was captured for a booking that was already paid.'
                : ($type === 'appointment'
                    ? 'Payment captured after the reserved time was released and re-booked.'
                    : 'Payment captured after the seat hold lapsed and the gathering filled.');
            $this->notifications->queueAdmin('admin_integration_failure', [
                'integration' => 'Booking',
                'reference' => $payableReference,
                'error' => $why . ' ' . ($autoRefund ? 'An automatic refund has been queued.' : 'Automatic refunds are off — review and refund or rebook manually.'),
            ], $type, $payableId);
            if ($autoRefund) {
                $this->jobs->push('payment.refund', [
                    'payment_id' => (int) $payment['id'],
                    'amount_minor' => (int) $payment['amount_minor'],
                    'reason' => ($effect === 'duplicate' ? 'Duplicate payment for ' : 'Booking conflict for ') . $payableReference,
                    'user_id' => null,
                ], 'payment.refund:' . $effect . ':' . $payment['id']);
            }
        }
    }

    /**
     * @param array<string, mixed> $payment
     * @return array{email: string, name: string, reference: string}|null
     */
    private function payableContact(array $payment): ?array
    {
        if ($payment['payable_type'] === 'appointment') {
            $row = $this->db->first('SELECT client_email_enc, client_name_enc, reference FROM appointments WHERE id = ?', [$payment['payable_id']]);

            return $row === null ? null : [
                'email' => (string) $this->crypto->decrypt($row['client_email_enc'], 'appointments.client_email'),
                'name' => (string) $this->crypto->decrypt($row['client_name_enc'], 'appointments.client_name'),
                'reference' => (string) $row['reference'],
            ];
        }
        $row = $this->db->first('SELECT email_enc, name_enc, reference FROM event_registrations WHERE id = ?', [$payment['payable_id']]);

        return $row === null ? null : [
            'email' => (string) $this->crypto->decrypt($row['email_enc'], 'event_registrations.email'),
            'name' => (string) $this->crypto->decrypt($row['name_enc'], 'event_registrations.name'),
            'reference' => (string) $row['reference'],
        ];
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: string, 3: string, 4: string} */
    private function resolvePayable(array $input): array
    {
        if (!empty($input['appointment_reference'])) {
            $appointment = $this->bookings->findForClient((string) $input['appointment_reference'], (string) $input['access_token']);
            if (!in_array($appointment['status'], ['pending_payment', 'payment_failed'], true)) {
                throw HttpException::conflict('invalid_state', 'This booking does not require payment.');
            }
            $email = (string) $this->crypto->decrypt($appointment['client_email_enc'], 'appointments.client_email');

            return ['appointment', $appointment, (string) $appointment['type_title'], $email, '/consultation/' . $appointment['reference']];
        }
        if (!empty($input['registration_reference'])) {
            $registration = $this->registrations->findForClient((string) $input['registration_reference'], (string) $input['access_token']);
            // An expired hold may be re-acquired at checkout if the seat is still free (ensureHold decides).
            if (!in_array($registration['status'], ['pending_payment', 'expired'], true)) {
                throw HttpException::conflict('invalid_state', 'This registration does not require payment.');
            }
            $email = (string) $this->crypto->decrypt($registration['email_enc'], 'event_registrations.email');

            return ['event_registration', $registration, (string) $registration['event_title'], $email, '/gatherings/registration/' . $registration['reference']];
        }

        throw HttpException::validation(['appointment_reference' => ['A booking reference is required.']]);
    }
}
