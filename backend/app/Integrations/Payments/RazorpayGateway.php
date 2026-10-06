<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Razorpay Orders API + Standard Checkout.
 * Docs: https://razorpay.com/docs/api/orders/ and https://razorpay.com/docs/webhooks/
 */
final class RazorpayGateway implements PaymentGateway
{
    /** @param array{key_id: string, key_secret: string, webhook_secret: string, api_base: string, currencies?: list<string>} $config */
    public function __construct(private ClientInterface $http, private array $config)
    {
    }

    public function name(): string
    {
        return 'razorpay';
    }

    public function isConfigured(): bool
    {
        return $this->config['key_id'] !== '' && $this->config['key_secret'] !== '' && $this->config['webhook_secret'] !== '';
    }

    public function isLiveMode(): bool
    {
        return str_starts_with($this->config['key_id'], 'rzp_live_');
    }

    public function supportedCurrencies(): array
    {
        return $this->config['currencies'] ?? ['INR'];
    }

    public function healthCheck(): array
    {
        $mode = $this->isLiveMode() ? 'live' : 'test';
        try {
            $this->request('GET', 'orders?count=1');
        } catch (GatewayError $e) {
            return ['ok' => false, 'mode' => $mode, 'details' => ['error' => $e->getMessage()], 'warnings' => []];
        }
        $warnings = [];
        $international = array_values(array_diff($this->supportedCurrencies(), ['INR']));
        if ($international !== []) {
            // Razorpay exposes no API for account capabilities; activation must be confirmed in the dashboard.
            $warnings[] = 'Confirm International Payments are activated in the Razorpay dashboard for ' . implode(', ', $international) . '.';
        }

        return ['ok' => true, 'mode' => $mode, 'details' => ['key' => substr($this->config['key_id'], 0, 13) . '…', 'enabled_currencies' => implode(',', $this->supportedCurrencies())], 'warnings' => $warnings];
    }

    public function createOrder(PaymentIntent $intent): GatewayOrder
    {
        $order = $this->request('POST', 'orders', [
            'amount' => $intent->amountMinor,
            'currency' => $intent->currency,
            'receipt' => substr($intent->idempotencyKey, 0, 40),
            'notes' => $intent->metadata + ['reference' => $intent->reference],
        ]);

        return new GatewayOrder((string) $order['id'], [
            'gateway' => 'razorpay',
            'key_id' => $this->config['key_id'],
            'order_id' => $order['id'],
            'amount' => $intent->amountMinor,
            'currency' => $intent->currency,
            'description' => $intent->description,
        ]);
    }

    public function verifyClientPayload(array $payload): VerifiedPayment
    {
        $orderId = (string) ($payload['razorpay_order_id'] ?? '');
        $paymentId = (string) ($payload['razorpay_payment_id'] ?? '');
        $signature = (string) ($payload['razorpay_signature'] ?? '');
        if ($orderId === '' || $paymentId === '' || $signature === '') {
            throw new InvalidSignature('Incomplete Razorpay payload.');
        }

        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $this->config['key_secret']);
        if (!hash_equals($expected, $signature)) {
            throw new InvalidSignature('Razorpay payment signature mismatch.');
        }

        // Never trust the browser: confirm state and amount with the gateway.
        $payment = $this->request('GET', 'payments/' . rawurlencode($paymentId));
        if (($payment['order_id'] ?? null) !== $orderId) {
            throw new InvalidSignature('Payment does not belong to this order.');
        }
        if (($payment['status'] ?? '') === 'authorized') {
            $payment = $this->request('POST', 'payments/' . rawurlencode($paymentId) . '/capture', [
                'amount' => (int) $payment['amount'],
                'currency' => (string) $payment['currency'],
            ]);
        }

        return $this->toVerified($orderId, $payment);
    }

    public function parseWebhook(string $rawBody, array $headers): WebhookEvent
    {
        $signature = $headers['x-razorpay-signature'] ?? '';
        $expected = hash_hmac('sha256', $rawBody, $this->config['webhook_secret']);
        if ($signature === '' || !hash_equals($expected, $signature)) {
            throw new InvalidSignature('Razorpay webhook signature mismatch.');
        }

        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            throw new InvalidSignature('Malformed webhook body.');
        }
        $type = (string) ($event['event'] ?? '');
        $eventId = (string) ($headers['x-razorpay-event-id'] ?? hash('sha256', $rawBody));
        $payment = $event['payload']['payment']['entity'] ?? null;
        $refund = $event['payload']['refund']['entity'] ?? null;

        return match ($type) {
            'payment.captured', 'order.paid' => new WebhookEvent(
                $eventId, $type, WebhookEvent::PAYMENT_CAPTURED,
                (string) ($payment['order_id'] ?? $event['payload']['order']['entity']['id'] ?? ''),
                isset($payment['id']) ? (string) $payment['id'] : null,
                (int) ($payment['amount'] ?? 0),
                isset($payment['currency']) ? (string) $payment['currency'] : null,
            ),
            'payment.failed' => new WebhookEvent(
                $eventId, $type, WebhookEvent::PAYMENT_FAILED,
                (string) ($payment['order_id'] ?? ''),
                isset($payment['id']) ? (string) $payment['id'] : null,
                (int) ($payment['amount'] ?? 0),
                isset($payment['currency']) ? (string) $payment['currency'] : null,
                failureReason: isset($payment['error_description']) ? mb_substr((string) $payment['error_description'], 0, 200) : null,
            ),
            'refund.processed', 'refund.failed' => new WebhookEvent(
                $eventId, $type, $type === 'refund.processed' ? WebhookEvent::REFUND_PROCESSED : WebhookEvent::REFUND_FAILED,
                null,
                isset($refund['payment_id']) ? (string) $refund['payment_id'] : null,
                (int) ($refund['amount'] ?? 0),
                isset($refund['currency']) ? (string) $refund['currency'] : null,
                isset($refund['id']) ? (string) $refund['id'] : null,
            ),
            default => new WebhookEvent($eventId, $type, WebhookEvent::IGNORED),
        };
    }

    public function fetchByOrder(string $orderId): VerifiedPayment
    {
        $payments = $this->request('GET', 'orders/' . rawurlencode($orderId) . '/payments');
        $items = $payments['items'] ?? [];
        foreach ($items as $payment) {
            if (($payment['status'] ?? '') === 'captured') {
                return $this->toVerified($orderId, $payment);
            }
        }
        $order = $this->request('GET', 'orders/' . rawurlencode($orderId));

        return new VerifiedPayment($orderId, null, $items === [] ? VerifiedPayment::PENDING : VerifiedPayment::FAILED, (int) $order['amount'], (string) $order['currency']);
    }

    public function refund(string $paymentId, int $amountMinor, string $currency, string $idempotencyKey, string $reason): GatewayRefund
    {
        $refund = $this->request('POST', 'payments/' . rawurlencode($paymentId) . '/refund', [
            'amount' => $amountMinor,
            'speed' => 'normal',
            'receipt' => substr($idempotencyKey, 0, 40),
            'notes' => ['reason' => mb_substr($reason, 0, 200)],
        ]);

        return new GatewayRefund((string) $refund['id'], ($refund['status'] ?? '') === 'processed' ? 'processed' : 'pending', (int) $refund['amount']);
    }

    /** @param array<string, mixed> $payment */
    private function toVerified(string $orderId, array $payment): VerifiedPayment
    {
        $status = match ($payment['status'] ?? '') {
            'captured' => VerifiedPayment::CAPTURED,
            'failed' => VerifiedPayment::FAILED,
            default => VerifiedPayment::PENDING,
        };

        return new VerifiedPayment(
            $orderId,
            (string) $payment['id'],
            $status,
            (int) $payment['amount'],
            (string) $payment['currency'],
            isset($payment['error_description']) ? mb_substr((string) $payment['error_description'], 0, 200) : null,
        );
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        if (!$this->isConfigured()) {
            throw new GatewayError('Razorpay is not configured.');
        }
        try {
            $options = ['auth' => [$this->config['key_id'], $this->config['key_secret']]];
            if ($body !== null) {
                $options['json'] = $body;
            }
            $response = $this->http->request($method, $this->config['api_base'] . $path, $options);
        } catch (GuzzleException $e) {
            throw new GatewayError('Razorpay is unreachable: ' . $e->getMessage(), 0, $e);
        }
        $data = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() >= 400 || !is_array($data)) {
            $description = is_array($data) ? (string) ($data['error']['description'] ?? 'Unknown error') : 'Invalid response';
            throw new GatewayError("Razorpay error ({$response->getStatusCode()}): {$description}");
        }

        return $data;
    }
}
