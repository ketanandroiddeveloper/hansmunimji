<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Integrations\Payments\GatewayError;
use App\Integrations\Payments\GatewayOrder;
use App\Integrations\Payments\GatewayRefund;
use App\Integrations\Payments\InvalidSignature;
use App\Integrations\Payments\PaymentGateway;
use App\Integrations\Payments\PaymentIntent;
use App\Integrations\Payments\VerifiedPayment;
use App\Integrations\Payments\WebhookEvent;

/**
 * In-memory gateway for integration tests only. It lives under tests/ and is never registered by
 * the application. Tests decide what the "provider" reports for each order and sign webhooks with
 * a throwaway secret, so signature checking is still exercised.
 */
final class FakeGateway implements PaymentGateway
{
    public const WEBHOOK_SECRET = 'fake-webhook-secret-for-tests';

    /** @var array<string, PaymentIntent> orderId => intent */
    public array $orders = [];

    /** @var list<array{payment_id: string, amount: int, currency: string, key: string}> */
    public array $refunds = [];

    public string $refundStatus = 'pending';
    public bool $failRefunds = false;
    /** Simulates the provider's API being unreachable for orders and lookups (webhooks still arrive). */
    public bool $unavailable = false;

    /** @var array<string, VerifiedPayment> */
    private array $outcomes = [];
    private int $sequence = 0;

    /** @param list<string> $currencies */
    public function __construct(private string $name, private array $currencies)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function isLiveMode(): bool
    {
        return false;
    }

    public function supportedCurrencies(): array
    {
        return $this->currencies;
    }

    public function createOrder(PaymentIntent $intent): GatewayOrder
    {
        if ($this->unavailable) {
            throw new GatewayError('Provider unavailable (test).');
        }
        $orderId = $this->name . '_order_' . (++$this->sequence);
        $this->orders[$orderId] = $intent;

        return new GatewayOrder($orderId, ['gateway' => $this->name, 'order_id' => $orderId]);
    }

    /** What the provider will report for this order on verification or reconciliation. */
    public function settle(string $orderId, string $status, ?int $amountMinor = null, ?string $currency = null): VerifiedPayment
    {
        $intent = $this->orders[$orderId] ?? throw new \LogicException("Unknown order {$orderId}");

        return $this->outcomes[$orderId] = new VerifiedPayment(
            $orderId,
            $status === VerifiedPayment::EXPIRED ? null : $this->name . '_pay_' . substr($orderId, strrpos($orderId, '_') + 1),
            $status,
            $amountMinor ?? $intent->amountMinor,
            $currency ?? $intent->currency,
            $status === VerifiedPayment::FAILED ? 'Card declined (test)' : null,
        );
    }

    public function verifyClientPayload(array $payload): VerifiedPayment
    {
        if ($this->unavailable) {
            throw new GatewayError('Provider unavailable (test).');
        }

        return $this->outcomes[(string) ($payload['order_id'] ?? '')] ?? throw new InvalidSignature('Unknown order.');
    }

    public function fetchByOrder(string $orderId): VerifiedPayment
    {
        if ($this->unavailable) {
            throw new GatewayError('Provider unavailable (test).');
        }
        $intent = $this->orders[$orderId] ?? throw new GatewayError('Unknown order.');

        return $this->outcomes[$orderId] ?? new VerifiedPayment($orderId, null, VerifiedPayment::PENDING, $intent->amountMinor, $intent->currency);
    }

    /** @param array<string, mixed> $event */
    public static function sign(array $event): array
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);

        return [$body, ['x-fake-signature' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET)]];
    }

    public function parseWebhook(string $rawBody, array $headers): WebhookEvent
    {
        if (!hash_equals(hash_hmac('sha256', $rawBody, self::WEBHOOK_SECRET), $headers['x-fake-signature'] ?? '')) {
            throw new InvalidSignature('Bad signature.');
        }
        $e = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);

        return new WebhookEvent(
            (string) $e['id'],
            (string) $e['kind'],
            (string) $e['kind'],
            $e['order_id'] ?? null,
            $e['payment_id'] ?? null,
            (int) ($e['amount'] ?? 0),
            $e['currency'] ?? null,
            $e['refund_id'] ?? null,
        );
    }

    public function refund(string $paymentId, int $amountMinor, string $currency, string $idempotencyKey, string $reason): GatewayRefund
    {
        if ($this->failRefunds) {
            throw new GatewayError('Refund rejected (test).');
        }
        $this->refunds[] = ['payment_id' => $paymentId, 'amount' => $amountMinor, 'currency' => $currency, 'key' => $idempotencyKey];

        return new GatewayRefund($this->name . '_rfnd_' . count($this->refunds), $this->refundStatus, $amountMinor);
    }

    public function healthCheck(): array
    {
        return ['ok' => true, 'mode' => 'test', 'details' => [], 'warnings' => []];
    }
}
