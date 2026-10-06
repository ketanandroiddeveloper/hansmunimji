<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

interface PaymentGateway
{
    public function name(): string;

    public function isConfigured(): bool;

    /** Whether the configured credentials are live (production) credentials. */
    public function isLiveMode(): bool;

    /** @return list<string> ISO-4217 codes this gateway is enabled for */
    public function supportedCurrencies(): array;

    public function createOrder(PaymentIntent $intent): GatewayOrder;

    /**
     * Verifies the payload returned to the browser after checkout and confirms the result with the gateway.
     *
     * @param array<string, mixed> $payload
     */
    public function verifyClientPayload(array $payload): VerifiedPayment;

    /**
     * Verifies the webhook signature and normalises the event.
     *
     * @param array<string, string> $headers lower-cased
     * @throws InvalidSignature
     */
    public function parseWebhook(string $rawBody, array $headers): WebhookEvent;

    public function fetchByOrder(string $orderId): VerifiedPayment;

    public function refund(string $paymentId, int $amountMinor, string $currency, string $idempotencyKey, string $reason): GatewayRefund;

    /**
     * Confirms the credentials work and reports what the account can be checked for.
     *
     * @return array{ok: bool, mode: string, details: array<string, scalar|null>, warnings: list<string>}
     */
    public function healthCheck(): array;
}
