<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

final class GatewayRefund
{
    public function __construct(
        public readonly string $refundId,
        public readonly string $status,
        public readonly int $amountMinor,
    ) {
    }
}
