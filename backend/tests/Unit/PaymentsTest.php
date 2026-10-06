<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Integrations\Payments\InvalidSignature;
use App\Integrations\Payments\RazorpayGateway;
use App\Integrations\Payments\StripeGateway;
use App\Integrations\Payments\VerifiedPayment;
use App\Integrations\Payments\WebhookEvent;
use App\Services\Booking\BookingService;
use App\Services\Payments\PaymentRouter;
use App\Services\Payments\PaymentService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/** Uses fake, locally generated secrets and a mocked HTTP layer — never real gateway credentials. */
final class PaymentsTest extends TestCase
{
    private const RZP = ['key_id' => 'rzp_test_FAKE', 'key_secret' => 'fake_secret', 'webhook_secret' => 'fake_webhook', 'api_base' => 'https://api.razorpay.com/v1/'];

    private function razorpay(array $responses = []): RazorpayGateway
    {
        return new RazorpayGateway(new Client(['handler' => HandlerStack::create(new MockHandler($responses)), 'http_errors' => false]), self::RZP);
    }

    public function testRazorpayClientSignatureIsVerifiedThenConfirmedWithGateway(): void
    {
        $payment = ['id' => 'pay_1', 'order_id' => 'order_1', 'status' => 'captured', 'amount' => 250000, 'currency' => 'INR'];
        $gateway = $this->razorpay([new Response(200, [], json_encode($payment))]);
        $signature = hash_hmac('sha256', 'order_1|pay_1', 'fake_secret');

        $verified = $gateway->verifyClientPayload(['razorpay_order_id' => 'order_1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => $signature]);

        self::assertSame('captured', $verified->status);
        self::assertSame(250000, $verified->amountMinor);
    }

    public function testRazorpayForgedClientSignatureIsRejectedWithoutNetworkCall(): void
    {
        $this->expectException(InvalidSignature::class);
        $this->razorpay()->verifyClientPayload(['razorpay_order_id' => 'order_1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => 'forged']);
    }

    public function testRazorpayPaymentFromAnotherOrderIsRejected(): void
    {
        $gateway = $this->razorpay([new Response(200, [], json_encode(['id' => 'pay_1', 'order_id' => 'order_OTHER', 'status' => 'captured', 'amount' => 1, 'currency' => 'INR']))]);

        $this->expectException(InvalidSignature::class);
        $gateway->verifyClientPayload(['razorpay_order_id' => 'order_1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => hash_hmac('sha256', 'order_1|pay_1', 'fake_secret')]);
    }

    public function testRazorpayWebhookSignatureAndMapping(): void
    {
        $body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_9', 'order_id' => 'order_9', 'amount' => 5000, 'currency' => 'INR']]]]);
        $event = $this->razorpay()->parseWebhook($body, ['x-razorpay-signature' => hash_hmac('sha256', $body, 'fake_webhook'), 'x-razorpay-event-id' => 'evt_9']);

        self::assertSame(WebhookEvent::PAYMENT_CAPTURED, $event->kind);
        self::assertSame('order_9', $event->orderId);
        self::assertSame('evt_9', $event->eventId);

        $this->expectException(InvalidSignature::class);
        $this->razorpay()->parseWebhook($body, ['x-razorpay-signature' => hash_hmac('sha256', $body . ' ', 'fake_webhook')]);
    }

    public function testStripeWebhookRequiresValidTimestampedSignature(): void
    {
        $gateway = new StripeGateway(['secret_key' => 'sk_test_FAKE', 'publishable_key' => 'pk_test_FAKE', 'webhook_secret' => 'whsec_fake', 'webhook_tolerance' => 300]);
        $body = json_encode(['id' => 'evt_1', 'object' => 'event', 'livemode' => false, 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_1', 'object' => 'checkout.session', 'payment_status' => 'paid', 'amount_total' => 1111, 'currency' => 'usd', 'payment_intent' => 'pi_1',
        ]]]);
        $t = time();
        $good = "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$body}", 'whsec_fake');

        $event = $gateway->parseWebhook($body, ['stripe-signature' => $good]);
        self::assertSame(WebhookEvent::PAYMENT_CAPTURED, $event->kind);
        self::assertSame('cs_1', $event->orderId);
        self::assertSame('USD', $event->currency);

        $stale = $t - 3600;
        $this->expectException(InvalidSignature::class);
        $gateway->parseWebhook($body, ['stripe-signature' => "t={$stale},v1=" . hash_hmac('sha256', "{$stale}.{$body}", 'whsec_fake')]);
    }

    public function testStripeWebhookFromTheOtherModeIsIgnored(): void
    {
        $gateway = new StripeGateway(['secret_key' => 'sk_test_FAKE', 'publishable_key' => 'pk_test_FAKE', 'webhook_secret' => 'whsec_fake', 'webhook_tolerance' => 300]);
        $body = json_encode(['id' => 'evt_2', 'object' => 'event', 'livemode' => true, 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_2', 'object' => 'checkout.session', 'payment_status' => 'paid', 'amount_total' => 1111, 'currency' => 'usd', 'payment_intent' => 'pi_2',
        ]]]);
        $t = time();

        $event = $gateway->parseWebhook($body, ['stripe-signature' => "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$body}", 'whsec_fake')]);

        self::assertSame(WebhookEvent::IGNORED, $event->kind);
        self::assertNull($event->orderId);
    }

    public function testGatewayModeAndCurrenciesComeFromConfiguration(): void
    {
        $stripe = new StripeGateway(['secret_key' => 'sk_live_FAKE', 'publishable_key' => 'pk_live_FAKE', 'webhook_secret' => 'whsec_fake', 'webhook_tolerance' => 300, 'currencies' => ['GBP']]);
        self::assertTrue($stripe->isLiveMode());
        self::assertSame(['GBP'], $stripe->supportedCurrencies());

        $razorpay = new RazorpayGateway(new Client(), self::RZP + ['currencies' => ['INR', 'USD']]);
        self::assertFalse($razorpay->isLiveMode());
        self::assertSame(['INR', 'USD'], $razorpay->supportedCurrencies());
    }

    public function testCaptureMismatchesAreFlaggedForReconciliation(): void
    {
        $payment = ['amount_minor' => 150000, 'currency' => 'INR', 'environment' => 'staging'];

        self::assertNull(PaymentService::captureProblem($payment, new VerifiedPayment('o', 'p', VerifiedPayment::CAPTURED, 150000, 'inr'), 'staging'));
        self::assertStringContainsString('amount', (string) PaymentService::captureProblem($payment, new VerifiedPayment('o', 'p', VerifiedPayment::CAPTURED, 100, 'INR'), 'staging'));
        self::assertStringContainsString('currency', (string) PaymentService::captureProblem($payment, new VerifiedPayment('o', 'p', VerifiedPayment::CAPTURED, 150000, 'USD'), 'staging'));
        self::assertStringContainsString('production', (string) PaymentService::captureProblem($payment, new VerifiedPayment('o', 'p', VerifiedPayment::CAPTURED, 150000, 'INR'), 'production'));
    }

    public function testRoutingPrefersCountryGatewaysOnlyWhenAllowedForTheCurrency(): void
    {
        $routing = PaymentRouter::DEFAULT_ROUTING;

        self::assertSame(['razorpay', 'stripe'], PaymentRouter::order($routing, [], 'INR', null));
        self::assertSame(['stripe', 'razorpay'], PaymentRouter::order($routing, [], 'USD', 'US'));
        // An Indian client paying in USD can be offered Razorpay first.
        self::assertSame(['razorpay', 'stripe'], PaymentRouter::order($routing, ['IN' => ['razorpay']], 'USD', 'in'));
        // A country preference never adds a gateway the currency does not allow.
        self::assertSame(['stripe'], PaymentRouter::order(['GBP' => ['stripe']] + $routing, ['GB' => ['razorpay']], 'GBP', 'GB'));
    }

    public function testAmountComputationForInclusiveAndExclusiveTax(): void
    {
        // Inclusive: the 18% tax is carved out of the listed price (100000 / 1.18 = 84745.76 → 84746 net).
        self::assertSame(['amount' => 84746, 'tax' => 15254, 'total' => 100000], BookingService::computeAmounts(100000, 1800, true));
        self::assertSame(['amount' => 100000, 'tax' => 18000, 'total' => 118000], BookingService::computeAmounts(100000, 1800, false));
        self::assertSame(['amount' => 999, 'tax' => 0, 'total' => 999], BookingService::computeAmounts(999, 0, false));
    }
}
