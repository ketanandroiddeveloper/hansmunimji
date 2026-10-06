<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Clock;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Security\AuditLogger;
use App\Security\Crypto;
use App\Services\Events\EventRegistrationService;
use App\Services\StatusHistory;

final class EventRegistrationsController extends Controller
{
    public function __construct(
        private Database $db,
        private Crypto $crypto,
        private EventRegistrationService $registrations,
        private AuditLogger $audit,
        private StatusHistory $history,
    ) {
    }

    public function index(Request $request): Response
    {
        $eventId = $request->intParam('id');
        $rows = $this->db->all('SELECT * FROM event_registrations WHERE event_id = ? ORDER BY created_at', [$eventId]);
        $this->audit->record($this->userId($request), 'event.registrations_viewed', 'event', $eventId, [], $request);
        $payments = [];
        foreach ($this->db->all(
            "SELECT payable_id, reference, gateway, status, refunded_minor FROM payments WHERE payable_type = 'event_registration'
               AND payable_id IN (SELECT id FROM event_registrations WHERE event_id = ?) ORDER BY id",
            [$eventId],
        ) as $p) {
            $payments[(int) $p['payable_id']] = ['reference' => $p['reference'], 'gateway' => $p['gateway'], 'status' => $p['status'], 'refunded_minor' => (int) $p['refunded_minor']];
        }

        return $this->ok(array_map(function (array $r) use ($payments) {
            $d = $this->crypto->decryptFields($r, EventRegistrationService::ENCRYPTED, 'event_registrations');

            return [
                'id' => (int) $r['id'],
                'reference' => $r['reference'],
                'status' => $r['status'],
                'seats' => (int) $r['seats'],
                'name' => $d['name'],
                'email' => $d['email'],
                'phone' => $d['phone'],
                'country' => $r['country'],
                'notes' => $d['notes'],
                'currency' => $r['currency'],
                'subtotal_minor' => (int) $r['subtotal_minor'],
                'tax_minor' => (int) $r['tax_minor'],
                'amount_minor' => (int) $r['amount_minor'],
                'hold_expires_at' => Clock::iso($r['hold_expires_at']),
                'payment' => $payments[(int) $r['id']] ?? null,
                'confirmed_at' => Clock::iso($r['confirmed_at']),
                'cancelled_at' => Clock::iso($r['cancelled_at']),
                'created_at' => Clock::iso($r['created_at']),
            ];
        }, $rows));
    }

    public function history(Request $request): Response
    {
        $id = $request->intParam('registration');
        $this->db->value('SELECT id FROM event_registrations WHERE id = ? AND event_id = ?', [$id, $request->intParam('id')]) ?: throw HttpException::notFound();

        return $this->ok($this->history->for('event_registration', $id));
    }

    public function updateStatus(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['status' => 'required|in:pending_payment,confirmed,cancelled,waitlisted', 'refund' => 'nullable|boolean']);
        $id = $request->intParam('registration');
        $this->db->value('SELECT id FROM event_registrations WHERE id = ? AND event_id = ?', [$id, $request->intParam('id')]) ?: throw HttpException::notFound();
        $userId = $this->userId($request);
        $this->registrations->setStatus($id, (string) $data['status'], $userId, array_key_exists('refund', $data) && $data['refund'] !== null ? (bool) $data['refund'] : null);
        $this->audit->record($userId, 'event_registration.status_changed', 'event_registration', $id, ['status' => $data['status']], $request);

        return Response::noContent();
    }
}
