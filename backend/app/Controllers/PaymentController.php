<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Integrations\Payments\InvalidSignature;
use App\Services\Payments\PaymentService;
use App\Services\Payments\WebhookService;

final class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments, private WebhookService $webhooks)
    {
    }

    public function gateways(Request $request): Response
    {
        $data = Validator::validate($request->allQuery(), ['currency' => 'required|currency', 'country' => 'nullable|country']);

        return $this->ok($this->payments->availableGateways((string) $data['currency'], $data['country'] ?? null));
    }

    public function createOrder(Request $request): Response
    {
        $data = Validator::validate($request->json(), [
            'appointment_reference' => 'nullable|string|max:24',
            'registration_reference' => 'nullable|string|max:24',
            'access_token' => 'required|string|max:128',
            'gateway' => 'required|in:razorpay,stripe',
        ]);

        return $this->ok($this->payments->createOrder($data, $this->idempotencyKey($request)), 201);
    }

    /** `POST /appointments/{reference}/payment` — same as create-order, addressed by the booking. */
    public function createForAppointment(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['gateway' => 'required|in:razorpay,stripe']);

        return $this->ok($this->payments->createOrder([
            'appointment_reference' => $request->param('reference'),
            'access_token' => $this->accessToken($request),
            'gateway' => $data['gateway'],
        ], $this->idempotencyKey($request)), 201);
    }

    /** `POST /event-registrations/{reference}/payment` */
    public function createForRegistration(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['gateway' => 'required|in:razorpay,stripe']);

        return $this->ok($this->payments->createOrder([
            'registration_reference' => $request->param('reference'),
            'access_token' => $this->accessToken($request),
            'gateway' => $data['gateway'],
        ], $this->idempotencyKey($request)), 201);
    }

    public function verify(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['gateway' => 'required|in:razorpay,stripe']);
        try {
            return $this->ok($this->payments->verifyFromClient((string) $data['gateway'], $request->json()));
        } catch (InvalidSignature) {
            throw new HttpException(400, 'verification_failed', 'The payment could not be verified. If you were charged, the booking will be reconciled automatically.');
        }
    }

    /** Client view of one payment attempt, authorised by the booking/registration access token. */
    public function show(Request $request): Response
    {
        return $this->ok($this->payments->statusForClient($request->param('reference'), $this->accessToken($request)));
    }

    /**
     * Starts a new checkout after a failed, cancelled or expired attempt — optionally with the other
     * gateway. Always creates a fresh gateway order; the earlier one is never reused.
     */
    public function retry(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['gateway' => 'nullable|in:razorpay,stripe']);

        return $this->ok($this->payments->retry($request->param('reference'), $this->accessToken($request), $data['gateway'] ?? null, $this->idempotencyKey($request)), 201);
    }

    public function webhook(Request $request): Response
    {
        return $this->handleWebhook($request, $request->param('gateway'));
    }

    public function stripeWebhook(Request $request): Response
    {
        return $this->handleWebhook($request, 'stripe');
    }

    public function razorpayWebhook(Request $request): Response
    {
        return $this->handleWebhook($request, 'razorpay');
    }

    private function handleWebhook(Request $request, string $gateway): Response
    {
        if (!in_array($gateway, ['razorpay', 'stripe'], true)) {
            throw HttpException::notFound();
        }
        $headers = [];
        foreach (['x-razorpay-signature', 'x-razorpay-event-id', 'stripe-signature'] as $name) {
            if ($request->header($name) !== null) {
                $headers[$name] = (string) $request->header($name);
            }
        }
        try {
            $outcome = $this->webhooks->handle($gateway, $request->rawBody(), $headers);
        } catch (InvalidSignature) {
            throw new HttpException(400, 'invalid_signature', 'Invalid signature.');
        }

        return $this->ok(['received' => true, 'outcome' => $outcome]);
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = (string) $request->header('idempotency-key', '');

        return preg_match('/^[A-Za-z0-9-]{16,64}$/', $key) ? $key : null;
    }
}
