<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingService;
use App\Services\Payments\PaymentService;

final class AppointmentController extends Controller
{
    public function __construct(private BookingService $bookings, private AvailabilityService $availability, private PaymentService $payments)
    {
    }

    public function types(Request $request): Response
    {
        return $this->ok($this->bookings->bookableTypes(
            $request->query('service') ? (string) $request->query('service') : null,
            (string) $request->header('x-invite-token', '') ?: null,
        ));
    }

    public function availability(Request $request): Response
    {
        $q = Validator::validate($request->allQuery(), [
            'type' => 'required|slug',
            'from' => 'required|date',
            'to' => 'required|date',
            'timezone' => 'required|timezone',
        ]);
        $type = $this->bookings->activeType((string) $q['type']);
        $bookable = $this->bookings->bookableTypes(null, (string) $request->header('x-invite-token', '') ?: null);
        if (!in_array($type['slug'], array_column($bookable, 'slug'), true) && !$this->holdsBookingOfType($request, (int) $type['id'])) {
            throw HttpException::forbidden('This consultation is available by private invitation only.');
        }

        return $this->ok($this->availability->forRange($type, (string) $q['from'], (string) $q['to'], (string) $q['timezone']))
            ->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): Response
    {
        $key = (string) $request->header('idempotency-key', '');
        $key = preg_match('/^[A-Za-z0-9-]{16,64}$/', $key) ? $key : null;
        $input = $request->json();

        // Never hold a slot the client cannot pay for.
        if (is_string($input['type'] ?? null) && $input['type'] !== '') {
            $type = $this->bookings->activeType($input['type']);
            $currency = is_string($input['currency'] ?? null) ? $input['currency'] : '';
            if ($type['requires_payment'] && in_array($currency, Validator::CURRENCIES, true) && $this->payments->availableGateways($currency) === []) {
                throw HttpException::conflict('payments_unavailable', 'Online payment is not available in this currency at present. Please choose another currency or contact the private office.');
            }
        }

        return $this->ok($this->bookings->create($input + ['invite_token' => $request->header('x-invite-token')], null, $key), 201);
    }

    public function show(Request $request): Response
    {
        $appointment = $this->bookings->findForClient($request->param('reference'), $this->accessToken($request));

        return $this->ok($this->bookings->presentForClient($appointment));
    }

    public function reschedule(Request $request): Response
    {
        $appointment = $this->bookings->findForClient($request->param('reference'), $this->accessToken($request));
        $data = Validator::validate($request->json(), ['starts_at' => 'required|datetime']);
        $updated = $this->bookings->reschedule($appointment, (string) $data['starts_at']);

        return $this->ok($this->bookings->presentForClient($updated));
    }

    public function cancel(Request $request): Response
    {
        $appointment = $this->bookings->findForClient($request->param('reference'), $this->accessToken($request));
        $data = Validator::validate($request->json(), ['reason' => 'nullable|string|max:500']);
        $updated = $this->bookings->cancel($appointment, $data['reason'] ?? null);

        return $this->ok($this->bookings->presentForClient($updated));
    }

    /** Clients rescheduling an invitation-only booking prove access with the booking's own token. */
    private function holdsBookingOfType(Request $request, int $typeId): bool
    {
        $reference = (string) $request->query('appointment', '');
        $token = $this->accessToken($request);
        if ($reference === '' || $token === '') {
            return false;
        }
        try {
            return (int) $this->bookings->findForClient($reference, $token)['appointment_type_id'] === $typeId;
        } catch (HttpException) {
            return false;
        }
    }
}
