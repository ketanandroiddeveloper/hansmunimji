<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Integrations\Payments\GatewayError;
use App\Integrations\Payments\InvalidSignature;
use App\Integrations\Payments\PaymentIntent;
use App\Integrations\Payments\StripeGateway;
use App\Integrations\Payments\VerifiedPayment;
use App\Integrations\Payments\WebhookEvent;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;

/** Exercises the real Stripe SDK against a scripted HTTP layer with fake keys — no network, no real credentials. */
final class StripeGatewayTest extends TestCase
{
    private const CONFIG = ['secret_key' => 'sk_test_FAKE', 'publishable_key' => 'pk_test_FAKE', 'webhook_secret' => 'whsec_fake', 'webhook_tolerance' => 300];

    private ScriptedStripeHttp $http;

    protected function setUp(): void
    {
        $this->http = new ScriptedStripeHttp();
        ApiRequestor::setHttpClient($this->http);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    public function testCheckoutSessionIsCreatedFromServerSideAmountWithIdempotencyKey(): void
    {
        $this->http->queue(200, ['id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']);

        $order = (new StripeGateway(self::CONFIG))->createOrder(new PaymentIntent(
            reference: 'AP-123',
            amountMinor: 6000,
            currency: 'USD',
            description: 'Private session',
            customerEmail: 'client@example.test',
            successUrl: 'https://site.test/booking/AP-123?checkout=success&session_id={CHECKOUT_SESSION_ID}',
            cancelUrl: 'https://site.test/booking/AP-123?checkout=cancelled',
            idempotencyKey: 'idem-key-0000000001',
            metadata: ['payment_reference' => 'PY-1'],
        ));

        self::assertSame('cs_test_1', $order->orderId);
        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $order->clientPayload['checkout_url']);

        [$method, $url, $headers, $params] = $this->http->requests[0];
        self::assertSame('post', $method);
        self::assertSame('https://api.stripe.com/v1/checkout/sessions', $url);
        self::assertContains('Idempotency-Key: idem-key-0000000001', $headers);
        self::assertSame('payment', $params['mode']);
        self::assertSame(6000, $params['line_items'][0]['price_data']['unit_amount']);
        self::assertSame('usd', $params['line_items'][0]['price_data']['currency']);
        self::assertSame('AP-123', $params['client_reference_id']);
        self::assertSame('AP-123', $params['metadata']['reference']);
        self::assertStringContainsString('{CHECKOUT_SESSION_ID}', $params['success_url']);
    }

    public function testStripeApiErrorsSurfaceAsGatewayErrors(): void
    {
        $this->http->queue(500, ['error' => ['type' => 'api_error', 'message' => 'Something went wrong on Stripe\'s end.']]);

        $this->expectException(GatewayError::class);
        (new StripeGateway(self::CONFIG))->fetchByOrder('cs_test_1');
    }

    public function testNetworkFailureSurfacesAsGatewayError(): void
    {
        $this->http->fail(new ApiConnectionException('Could not connect to Stripe.'));

        $this->expectException(GatewayError::class);
        (new StripeGateway(self::CONFIG))->refund('pi_1', 100, 'USD', 'refund-key-0000001', 'Duplicate payment');
    }

    public function testSessionLookupReportsPaidExpiredAndOpenSessions(): void
    {
        $gateway = new StripeGateway(self::CONFIG);
        $this->http->queue(200, ['id' => 'cs_1', 'object' => 'checkout.session', 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_1', 'amount_total' => 6000, 'currency' => 'usd']);
        $this->http->queue(200, ['id' => 'cs_2', 'object' => 'checkout.session', 'status' => 'expired', 'payment_status' => 'unpaid', 'payment_intent' => null, 'amount_total' => 6000, 'currency' => 'usd']);
        $this->http->queue(200, ['id' => 'cs_3', 'object' => 'checkout.session', 'status' => 'open', 'payment_status' => 'unpaid', 'payment_intent' => null, 'amount_total' => 6000, 'currency' => 'usd']);

        $paid = $gateway->fetchByOrder('cs_1');
        self::assertSame(VerifiedPayment::CAPTURED, $paid->status);
        self::assertSame('pi_1', $paid->paymentId);
        self::assertSame(6000, $paid->amountMinor);
        self::assertSame('USD', $paid->currency);
        self::assertSame(VerifiedPayment::EXPIRED, $gateway->fetchByOrder('cs_2')->status);
        self::assertSame(VerifiedPayment::PENDING, $gateway->fetchByOrder('cs_3')->status);
    }

    public function testHealthCheckReportsAccountReadinessAndModeMismatch(): void
    {
        $this->http->queue(200, ['id' => 'acct_1', 'object' => 'account', 'country' => 'US', 'default_currency' => 'usd', 'charges_enabled' => false, 'payouts_enabled' => false]);

        $health = (new StripeGateway(['publishable_key' => 'pk_live_FAKE'] + self::CONFIG))->healthCheck();

        self::assertTrue($health['ok']);
        self::assertSame('test', $health['mode']);
        self::assertSame('https://api.stripe.com/v1/account', $this->http->requests[0][1]);
        self::assertCount(2, $health['warnings']);

        $this->http->queue(401, ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided.']]);
        self::assertFalse((new StripeGateway(self::CONFIG))->healthCheck()['ok']);
    }

    public function testBrowserCanOnlySubmitCheckoutSessionIds(): void
    {
        try {
            (new StripeGateway(self::CONFIG))->verifyClientPayload(['session_id' => 'pi_123']);
            self::fail('Only Checkout Session IDs may be verified.');
        } catch (InvalidSignature) {
        }
        self::assertSame([], $this->http->requests);
    }

    public function testWebhookEventsMapToPaymentOutcomes(): void
    {
        $session = ['object' => 'checkout.session', 'amount_total' => 6000, 'currency' => 'usd', 'payment_intent' => 'pi_1'];
        $refund = ['id' => 're_1', 'object' => 'refund', 'amount' => 6000, 'currency' => 'usd', 'payment_intent' => 'pi_1'];

        $cases = [
            ['checkout.session.completed', ['id' => 'cs_1', 'payment_status' => 'paid'] + $session, WebhookEvent::PAYMENT_CAPTURED],
            // Delayed payment methods complete the session before the money arrives.
            ['checkout.session.completed', ['id' => 'cs_1', 'payment_status' => 'unpaid'] + $session, WebhookEvent::IGNORED],
            ['checkout.session.async_payment_succeeded', ['id' => 'cs_1', 'payment_status' => 'paid'] + $session, WebhookEvent::PAYMENT_CAPTURED],
            ['checkout.session.async_payment_failed', ['id' => 'cs_1', 'payment_status' => 'unpaid'] + $session, WebhookEvent::PAYMENT_FAILED],
            ['checkout.session.expired', ['id' => 'cs_1', 'payment_status' => 'unpaid'] + $session, WebhookEvent::CHECKOUT_EXPIRED],
            ['refund.updated', ['status' => 'succeeded'] + $refund, WebhookEvent::REFUND_PROCESSED],
            ['refund.updated', ['status' => 'failed'] + $refund, WebhookEvent::REFUND_FAILED],
            ['refund.created', ['status' => 'pending'] + $refund, WebhookEvent::IGNORED],
            ['customer.created', ['id' => 'cus_1', 'object' => 'customer'], WebhookEvent::IGNORED],
        ];

        foreach ($cases as $i => [$type, $object, $expected]) {
            $event = (new StripeGateway(self::CONFIG))->parseWebhook(...$this->signed("evt_{$i}", $type, $object));
            self::assertSame($expected, $event->kind, $type);
            self::assertSame("evt_{$i}", $event->eventId);
        }
    }

    public function testSignatureIsCheckedAgainstTheUnmodifiedBody(): void
    {
        [$body, $headers] = $this->signed('evt_1', 'checkout.session.completed', ['id' => 'cs_1', 'object' => 'checkout.session', 'payment_status' => 'paid', 'amount_total' => 1, 'currency' => 'usd', 'payment_intent' => 'pi_1']);
        $reencoded = json_encode(json_decode($body, true), JSON_PRETTY_PRINT);

        $this->expectException(InvalidSignature::class);
        (new StripeGateway(self::CONFIG))->parseWebhook($reencoded, $headers);
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function signed(string $id, string $type, array $object): array
    {
        $body = json_encode(['id' => $id, 'object' => 'event', 'livemode' => false, 'type' => $type, 'data' => ['object' => $object]]);
        $t = time();

        return [$body, ['stripe-signature' => "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$body}", 'whsec_fake')]];
    }
}

final class ScriptedStripeHttp implements ClientInterface
{
    /** @var list<array{0: string, 1: string, 2: array<int, string>, 3: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<array{0: string, 1: int, 2: array<string, string>}|\Throwable> */
    private array $responses = [];

    public function queue(int $status, array $body): void
    {
        $this->responses[] = [json_encode($body), $status, ['request-id' => 'req_test']];
    }

    public function fail(\Throwable $e): void
    {
        $this->responses[] = $e;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1')
    {
        $this->requests[] = [$method, $absUrl, $headers, $params];
        $next = array_shift($this->responses) ?? throw new \LogicException("Unexpected Stripe request: {$method} {$absUrl}");
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
