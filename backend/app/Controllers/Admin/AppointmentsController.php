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
use App\Jobs\JobQueue;
use App\Security\AuditLogger;
use App\Security\BlindIndex;
use App\Security\Crypto;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingService;
use App\Services\StatusHistory;

final class AppointmentsController extends Controller
{
    private const DECRYPT = ['client_name', 'client_email', 'client_phone', 'notes', 'meet_url'];

    public function __construct(
        private Database $db,
        private Crypto $crypto,
        private BlindIndex $blindIndex,
        private BookingService $bookings,
        private AvailabilityService $availability,
        private JobQueue $jobs,
        private AuditLogger $audit,
        private StatusHistory $history,
    ) {
    }

    public function index(Request $request): Response
    {
        [$page, $perPage] = $this->pagination($request, 25);
        $where = ['1 = 1'];
        $params = [];
        if ($status = $request->query('status')) {
            $where[] = 'a.status = ?';
            $params[] = (string) $status;
        }
        if ($type = $request->query('type')) {
            $where[] = 't.slug = ?';
            $params[] = (string) $type;
        }
        if ($from = $request->query('from')) {
            $where[] = 'a.starts_at >= ?';
            $params[] = Validator::parseDateTime((string) $from)?->format('Y-m-d H:i:s') ?? '1970-01-01';
        }
        if ($to = $request->query('to')) {
            $where[] = 'a.starts_at < ?';
            $params[] = Validator::parseDateTime((string) $to)?->format('Y-m-d H:i:s') ?? '2999-01-01';
        }
        if ($q = trim((string) $request->query('q', ''))) {
            if (filter_var($q, FILTER_VALIDATE_EMAIL)) {
                $where[] = 'a.client_email_bidx = ?';
                $params[] = $this->blindIndex->email($q);
            } else {
                $where[] = 'a.reference = ?';
                $params[] = strtoupper($q);
            }
        }
        $order = $request->query('sort') === '-starts_at' ? 'a.starts_at DESC' : 'a.starts_at ASC';
        $sql = ' FROM appointments a JOIN appointment_types t ON t.id = a.appointment_type_id WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->value('SELECT COUNT(*)' . $sql, $params);
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->all("SELECT a.id, a.reference, a.status, a.starts_at, a.ends_at, a.client_timezone, a.format, a.client_name_enc,
                a.currency, a.total_minor, a.calendar_sync_status, t.title AS type_title{$sql} ORDER BY {$order} LIMIT {$perPage} OFFSET {$offset}", $params);

        $items = array_map(fn (array $r) => [
            'id' => (int) $r['id'],
            'reference' => $r['reference'],
            'status' => $r['status'],
            'type_title' => $r['type_title'],
            'starts_at' => Clock::iso($r['starts_at']),
            'ends_at' => Clock::iso($r['ends_at']),
            'client_timezone' => $r['client_timezone'],
            'format' => $r['format'],
            'client_name' => $this->crypto->decrypt($r['client_name_enc'], 'appointments.client_name'),
            'currency' => $r['currency'],
            'total_minor' => (int) $r['total_minor'],
            'calendar_sync_status' => $r['calendar_sync_status'],
        ], $rows);

        return $this->ok($items, 200, $this->meta($page, $perPage, $total));
    }

    public function show(Request $request): Response
    {
        $row = $this->bookings->find($request->intParam('id'));
        $decrypted = $this->crypto->decryptFields($row, self::DECRYPT, 'appointments');
        $this->audit->record($this->userId($request), 'appointment.viewed', 'appointment', $row['id'], [], $request);

        return $this->ok([
            'id' => (int) $row['id'],
            'reference' => $row['reference'],
            'status' => $row['status'],
            'type' => ['id' => (int) $row['appointment_type_id'], 'slug' => $row['type_slug'], 'title' => $row['type_title']],
            'starts_at' => Clock::iso($row['starts_at']),
            'ends_at' => Clock::iso($row['ends_at']),
            'client_timezone' => $row['client_timezone'],
            'format' => $row['format'],
            'city' => $row['city_name'],
            'country' => $row['country'] ?? null,
            'application_id' => $row['application_id'] ? (int) $row['application_id'] : null,
            'client' => ['name' => $decrypted['client_name'], 'email' => $decrypted['client_email'], 'phone' => $decrypted['client_phone']],
            'notes' => $decrypted['notes'],
            'meet_url' => $decrypted['meet_url'],
            'amount' => ['currency' => $row['currency'], 'subtotal_minor' => (int) $row['amount_minor'], 'tax_minor' => (int) $row['tax_minor'], 'total_minor' => (int) $row['total_minor']],
            'calendar' => ['status' => $row['calendar_sync_status'], 'attempts' => (int) $row['calendar_attempts'], 'last_error' => $row['calendar_last_error'], 'event_id' => $row['google_event_id']],
            'reschedule_count' => (int) $row['reschedule_count'],
            'cancellation_reason' => $row['cancellation_reason'],
            'confirmed_at' => Clock::iso($row['confirmed_at']),
            'cancelled_at' => Clock::iso($row['cancelled_at']),
            'completed_at' => Clock::iso($row['completed_at']),
            'no_show_at' => Clock::iso($row['no_show_at'] ?? null),
            'expired_at' => Clock::iso($row['expired_at'] ?? null),
            'created_at' => Clock::iso($row['created_at']),
            'payments' => $this->db->all("SELECT id, reference, gateway, status, currency, amount_minor, refunded_minor, created_at FROM payments WHERE payable_type = 'appointment' AND payable_id = ? ORDER BY id DESC", [$row['id']]),
            'reminders' => $this->db->all('SELECT offset_minutes, run_at, status, sent_at FROM reminder_jobs WHERE appointment_id = ? ORDER BY run_at', [$row['id']]),
            'history' => $this->history->for('appointment', (int) $row['id']),
        ]);
    }

    /**
     * `PATCH /admin/appointments/{id}` — one change per request: a new `starts_at` (reschedule) or a
     * target `status` (cancelled, completed, no_show). Delegates to the dedicated actions.
     */
    public function update(Request $request): Response
    {
        $data = Validator::validate($request->json(), [
            'starts_at' => 'nullable|datetime',
            'status' => 'nullable|in:cancelled,completed,no_show',
            'reason' => 'nullable|string|max:500',
            'refund' => 'nullable|boolean',
        ]);
        if (isset($data['starts_at']) === isset($data['status'])) {
            throw HttpException::validation(['status' => ['Send either a new start time or a status.']]);
        }
        $row = $this->bookings->find($request->intParam('id'));
        $userId = $this->userId($request);
        match ($data['status'] ?? 'reschedule') {
            'reschedule' => $this->bookings->reschedule($row, (string) $data['starts_at'], true, $userId),
            'cancelled' => $this->bookings->cancel($row, $data['reason'] ?? null, true, $userId, array_key_exists('refund', $data) && $data['refund'] !== null ? (bool) $data['refund'] : null),
            'completed' => $this->bookings->complete($row, $userId),
            'no_show' => $this->bookings->markNoShow($row, $userId),
        };

        return Response::noContent();
    }

    public function noShow(Request $request): Response
    {
        $this->bookings->markNoShow($this->bookings->find($request->intParam('id')), $this->userId($request));

        return Response::noContent();
    }

    public function resendConfirmation(Request $request): Response
    {
        $this->bookings->resendConfirmation($this->bookings->find($request->intParam('id')), $this->userId($request));

        return Response::noContent();
    }

    public function store(Request $request): Response
    {
        $result = $this->bookings->create($request->json(), $this->userId($request));
        unset($result['access_token']);
        $result['id'] = (int) $this->db->value('SELECT id FROM appointments WHERE reference = ?', [$result['reference']]);

        return $this->ok($result, 201);
    }

    public function availability(Request $request): Response
    {
        $q = Validator::validate($request->allQuery(), ['type' => 'required|slug', 'from' => 'required|date', 'to' => 'required|date', 'timezone' => 'required|timezone', 'ignore' => 'nullable|integer']);
        $type = $this->bookings->activeType((string) $q['type']);

        return $this->ok($this->availability->forRange($type, (string) $q['from'], (string) $q['to'], (string) $q['timezone'], isset($q['ignore']) ? (int) $q['ignore'] : null));
    }

    public function reschedule(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['starts_at' => 'required|datetime']);
        $this->bookings->reschedule($this->bookings->find($request->intParam('id')), (string) $data['starts_at'], true, $this->userId($request));

        return Response::noContent();
    }

    public function cancel(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['reason' => 'nullable|string|max:500', 'refund' => 'nullable|boolean']);
        $this->bookings->cancel(
            $this->bookings->find($request->intParam('id')),
            $data['reason'] ?? null,
            true,
            $this->userId($request),
            array_key_exists('refund', $data) && $data['refund'] !== null ? (bool) $data['refund'] : null,
        );

        return Response::noContent();
    }

    public function complete(Request $request): Response
    {
        $this->bookings->complete($this->bookings->find($request->intParam('id')), $this->userId($request));

        return Response::noContent();
    }

    public function resyncCalendar(Request $request): Response
    {
        $row = $this->bookings->find($request->intParam('id'));
        $this->db->update('appointments', ['calendar_sync_status' => 'pending', 'calendar_attempts' => 0, 'calendar_last_error' => null], ['id' => $row['id']]);
        $this->jobs->push('calendar.sync', ['appointment_id' => (int) $row['id']], 'calendar.sync:' . $row['id']);
        $this->audit->record($this->userId($request), 'appointment.calendar_resync', 'appointment', $row['id'], [], $request);

        return Response::noContent();
    }
}
