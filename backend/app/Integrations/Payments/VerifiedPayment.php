<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

final class VerifiedPayment
{
    public const CAPTURED = 'captured';
    public const PENDING = 'pending';
    public const FAILED = 'failed';
    public const EXPIRED = 'expired';

    public function __construct(
        public readonly string $orderId,
        public readonly ?string $paymentId,
        public readonly string $status,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?string $failureReason = null,
    ) {
    }
}
