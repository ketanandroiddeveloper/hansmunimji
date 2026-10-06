<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

final class GatewayOrder
{
    /** @param array<string, mixed> $clientPayload safe to send to the browser */
    public function __construct(
        public readonly string $orderId,
        public readonly array $clientPayload,
    ) {
    }
}
