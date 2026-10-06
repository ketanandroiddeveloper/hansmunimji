<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Stripe Checkout (hosted) — card data never touches our servers.
 * Docs: https://docs.stripe.com/payments/checkout and https://docs.stripe.com/webhooks
 */
final class StripeGateway implements PaymentGateway
{
    private ?StripeClient $client = null;

    /** @param array{secret_key: string, publishable_key: string, webhook_secret: string, webhook_tolerance: int, currencies?: list<string>, checkout_expiry_minutes?: int} $config */
    public function __construct(private array $config)
    {
    }

    public function name(): string
    {
        return 'stripe';
    }

    public function isConfigured(): bool
    {
        return $this->config['secret_key'] !== '' && $this->config['webhook_secret'] !== '';
    }

    public function isLiveMode(): bool
    {
        return str_starts_with($this->config['secret_key'], 'sk_live_') || str_starts_with($this->config['secret_key'], 'rk_live_');
    }

    public function supportedCurrencies(): array
    {
        return $this->config['currencies'] ?? ['USD', 'AED', 'GBP'];
    }

    public function createOrder(PaymentIntent $intent): GatewayOrder
    {
        try {
            $session = $this->client()->checkout->sessions->create([
                'mode' => 'payment',
                'customer_email' => $intent->customerEmail,
                'client_reference_id' => $intent->reference,
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($intent->currency),
                        'unit_amount' => $intent->amountMinor,
                        'product_data' => ['name' => $intent->description],
                    ],
                ]],
                'metadata' => $intent->metadata + ['reference' => $intent->reference],
                'payment_intent_data' => ['metadata' => $intent->metadata + ['reference' => $intent->reference]],
                'success_url' => $intent->successUrl,
                'cancel_url' => $intent->cancelUrl,
                'expires_at' => time() + 60 * (int) ($this->config['checkout_expiry_minutes'] ?? 31),
            ], ['idempotency_key' => $intent->idempotencyKey]);
        } catch (ApiErrorException $e) {
            throw new GatewayError('Stripe error: ' . $e->getMessage(), 0, $e);
        }

        return new GatewayOrder($session->id, [
            'gateway' => 'stripe',
            'checkout_url' => $session->url,
            'session_id' => $session->id,
        ]);
    }

    public function verifyClientPayload(array $payload): VerifiedPayment
    {
        $sessionId = (string) ($payload['session_id'] ?? '');
        if (!str_starts_with($sessionId, 'cs_')) {
            throw new InvalidSignature('Invalid Stripe session identifier.');
        }

        return $this->fetchByOrder($sessionId);
    }

    public function parseWebhook(string $rawBody, array $headers): WebhookEvent
    {
        try {
            $event = Webhook::constructEvent($rawBody, $headers['stripe-signature'] ?? '', $this->config['webhook_secret'], $this->config['webhook_tolerance']);
        } catch (SignatureVerificationException | \UnexpectedValueException $e) {
            throw new InvalidSignature('Stripe webhook signature mismatch.', 0, $e);
        }

        // A webhook endpoint misconfigured across modes must never confirm a booking.
        // Absent livemode counts as test mode, so it can never confirm against live keys.
        if ((isset($event->livemode) && (bool) $event->livemode) !== $this->isLiveMode()) {
            return new WebhookEvent($event->id, 'mode_mismatch:' . $event->type, WebhookEvent::IGNORED);
        }

        $object = $event->data->object;

        return match ($event->type) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => new WebhookEvent(
                $event->id, $event->type,
                $object->payment_status === 'paid' ? WebhookEvent::PAYMENT_CAPTURED : WebhookEvent::IGNORED,
                $object->id,
                is_string($object->payment_intent) ? $object->payment_intent : null,
                (int) $object->amount_total,
                strtoupper((string) $object->currency),
            ),
            'checkout.session.async_payment_failed' => new WebhookEvent(
                $event->id, $event->type, WebhookEvent::PAYMENT_FAILED, $object->id, null,
                (int) $object->amount_total, strtoupper((string) $object->currency), failureReason: 'Asynchronous payment failed.',
            ),
            'checkout.session.expired' => new WebhookEvent(
                $event->id, $event->type, WebhookEvent::CHECKOUT_EXPIRED, $object->id, null,
                (int) $object->amount_total, strtoupper((string) $object->currency),
            ),
            'refund.updated', 'refund.created' => new WebhookEvent(
                $event->id, $event->type,
                match ($object->status) {
                    'succeeded' => WebhookEvent::REFUND_PROCESSED,
                    'failed', 'canceled' => WebhookEvent::REFUND_FAILED,
                    default => WebhookEvent::IGNORED,
                },
                null,
                is_string($object->payment_intent) ? $object->payment_intent : null,
                (int) $object->amount,
                strtoupper((string) $object->currency),
                $object->id,
            ),
            default => new WebhookEvent($event->id, $event->type, WebhookEvent::IGNORED),
        };
    }

    public function fetchByOrder(string $orderId): VerifiedPayment
    {
        try {
            /** @var Session $session */
            $session = $this->client()->checkout->sessions->retrieve($orderId, []);
        } catch (ApiErrorException $e) {
            throw new GatewayError('Stripe error: ' . $e->getMessage(), 0, $e);
        }

        $status = match (true) {
            $session->payment_status === 'paid' => VerifiedPayment::CAPTURED,
            $session->status === 'expired' => VerifiedPayment::EXPIRED,
            default => VerifiedPayment::PENDING,
        };

        return new VerifiedPayment(
            $session->id,
            is_string($session->payment_intent) ? $session->payment_intent : null,
            $status,
            (int) $session->amount_total,
            strtoupper((string) $session->currency),
        );
    }

    public function refund(string $paymentId, int $amountMinor, string $currency, string $idempotencyKey, string $reason): GatewayRefund
    {
        try {
            $refund = $this->client()->refunds->create([
                'payment_intent' => $paymentId,
                'amount' => $amountMinor,
                'reason' => 'requested_by_customer',
                'metadata' => ['note' => mb_substr($reason, 0, 200)],
            ], ['idempotency_key' => $idempotencyKey]);
        } catch (ApiErrorException $e) {
            throw new GatewayError('Stripe error: ' . $e->getMessage(), 0, $e);
        }

        return new GatewayRefund($refund->id, $refund->status === 'succeeded' ? 'processed' : 'pending', (int) $refund->amount);
    }

    public function healthCheck(): array
    {
        $warnings = [];
        try {
            $account = $this->client()->accounts->retrieve();
        } catch (ApiErrorException | GatewayError $e) {
            return ['ok' => false, 'mode' => $this->isLiveMode() ? 'live' : 'test', 'details' => ['error' => $e->getMessage()], 'warnings' => []];
        }
        if (!$account->charges_enabled) {
            $warnings[] = 'Charges are not enabled on this Stripe account yet (complete account activation).';
        }
        $default = strtoupper((string) $account->default_currency);
        if ($this->config['publishable_key'] !== '' && str_contains($this->config['publishable_key'], '_live_') !== $this->isLiveMode()) {
            $warnings[] = 'The publishable key and secret key are from different modes.';
        }

        return [
            'ok' => true,
            'mode' => $this->isLiveMode() ? 'live' : 'test',
            'details' => [
                'account' => (string) $account->id,
                'country' => (string) $account->country,
                'default_currency' => $default,
                'charges_enabled' => (bool) $account->charges_enabled,
                'payouts_enabled' => (bool) $account->payouts_enabled,
                'enabled_currencies' => implode(',', $this->supportedCurrencies()),
            ],
            'warnings' => $warnings,
        ];
    }

    private function client(): StripeClient
    {
        if (!$this->isConfigured()) {
            throw new GatewayError('Stripe is not configured.');
        }

        return $this->client ??= new StripeClient(['api_key' => $this->config['secret_key'], 'max_network_retries' => 2]);
    }
}
