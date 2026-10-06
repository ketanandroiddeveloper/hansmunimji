<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

final class WebhookEvent
{
    public const PAYMENT_CAPTURED = 'payment_captured';
    public const PAYMENT_FAILED = 'payment_failed';
    public const CHECKOUT_EXPIRED = 'checkout_expired';
    public const REFUND_PROCESSED = 'refund_processed';
    public const REFUND_FAILED = 'refund_failed';
    public const IGNORED = 'ignored';

    public function __construct(
        public readonly string $eventId,
        public readonly string $rawType,
        public readonly string $kind,
        public readonly ?string $orderId = null,
        public readonly ?string $paymentId = null,
        public readonly int $amountMinor = 0,
        public readonly ?string $currency = null,
        public readonly ?string $refundId = null,
        public readonly ?string $failureReason = null,
    ) {
    }
}
