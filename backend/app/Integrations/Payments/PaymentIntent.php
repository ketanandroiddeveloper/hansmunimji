<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

final class PaymentIntent
{
    /** @param array<string, string> $metadata */
    public function __construct(
        public readonly string $reference,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $description,
        public readonly string $customerEmail,
        public readonly string $successUrl,
        public readonly string $cancelUrl,
        public readonly string $idempotencyKey,
        public readonly array $metadata = [],
    ) {
    }
}
