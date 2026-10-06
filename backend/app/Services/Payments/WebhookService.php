<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Logger;
use App\Integrations\Payments\GatewayRegistry;
use App\Integrations\Payments\VerifiedPayment;
use App\Integrations\Payments\WebhookEvent;

/**
 * Verifies, deduplicates and applies gateway webhooks. A (gateway, event_id) pair is processed
 * at most once successfully; failed attempts may be retried by the gateway.
 */
final class WebhookService
{
    public function __construct(
        private Database $db,
        private Clock $clock,
        private GatewayRegistry $gateways,
        private PaymentService $payments,
        private Logger $logger,
    ) {
    }

    /**
     * @param array<string, string> $headers
     * @return string outcome: processed | duplicate | ignored
     */
    public function handle(string $gatewayName, string $rawBody, array $headers): string
    {
        $gateway = $this->gateways->get($gatewayName);
        $event = $gateway->parseWebhook($rawBody, $headers); // throws InvalidSignature

        $this->db->run(
            'INSERT IGNORE INTO webhook_events (gateway, event_id, event_type, payload_hash, status, received_at) VALUES (?, ?, ?, ?, \'received\', ?)',
            [$gatewayName, $event->eventId, $event->rawType, hash('sha256', $rawBody), $this->clock->nowString()],
        );
        $record = $this->db->first('SELECT id, status FROM webhook_events WHERE gateway = ? AND event_id = ?', [$gatewayName, $event->eventId]);
        if ($record !== null && in_array($record['status'], ['processed', 'ignored'], true)) {
            return 'duplicate';
        }

        try {
            $outcome = $this->apply($gatewayName, $event);
            $this->db->update('webhook_events', ['status' => $outcome, 'processed_at' => $this->clock->nowString(), 'error' => null], ['id' => $record['id']]);

            return $outcome;
        } catch (\Throwable $e) {
            $this->db->update('webhook_events', ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)], ['id' => $record['id']]);
            $this->logger->error('webhook_failed', ['gateway' => $gatewayName, 'type' => $event->rawType, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function apply(string $gatewayName, WebhookEvent $event): string
    {
        switch ($event->kind) {
            case WebhookEvent::PAYMENT_CAPTURED:
            case WebhookEvent::PAYMENT_FAILED:
            case WebhookEvent::CHECKOUT_EXPIRED:
                if (!$event->orderId || !$this->db->value('SELECT 1 FROM payments WHERE gateway = ? AND gateway_order_id = ?', [$gatewayName, $event->orderId])) {
                    return 'ignored'; // Not ours (e.g. another integration on the same account).
                }
                $status = match ($event->kind) {
                    WebhookEvent::PAYMENT_CAPTURED => VerifiedPayment::CAPTURED,
                    WebhookEvent::PAYMENT_FAILED => VerifiedPayment::FAILED,
                    default => VerifiedPayment::EXPIRED,
                };
                $this->payments->applyResult($gatewayName, new VerifiedPayment(
                    $event->orderId, $event->paymentId, $status, $event->amountMinor, (string) $event->currency, $event->failureReason,
                ), 'webhook');

                return 'processed';

            case WebhookEvent::REFUND_PROCESSED:
                if ($event->refundId) {
                    $this->payments->markRefundProcessed($gatewayName, $event->refundId, $event->paymentId, $event->amountMinor);
                }

                return 'processed';

            case WebhookEvent::REFUND_FAILED:
                if ($event->refundId) {
                    $this->payments->markRefundFailed($event->refundId);
                }

                return 'processed';

            default:
                return 'ignored';
        }
    }
}
