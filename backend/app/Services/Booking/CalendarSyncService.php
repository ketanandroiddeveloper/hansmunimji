<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Integrations\Google\GoogleCalendarClient;
use App\Security\Crypto;
use App\Services\Notifications\NotificationService;
use App\Services\TimeFormatter;

/**
 * Reconciles an appointment with Google Calendar: create (with Meet) when active and missing,
 * patch on reschedule, delete on cancellation. Runs from the job queue; exceptions trigger retries.
 */
final class CalendarSyncService
{
    private const FORMATS = ['google_meet' => 'Google Meet', 'phone' => 'phone', 'in_person' => 'in person'];

    public function __construct(
        private Database $db,
        private Clock $clock,
        private Config $config,
        private Crypto $crypto,
        private GoogleCalendarClient $google,
        private NotificationService $notifications,
        private ReminderService $reminders,
    ) {
    }

    public function sync(int $appointmentId): void
    {
        $appointment = $this->db->first(
            'SELECT a.*, t.title AS type_title, t.duration_minutes, s.title AS service_title, c.name AS city_name FROM appointments a
             JOIN appointment_types t ON t.id = a.appointment_type_id LEFT JOIN services s ON s.id = t.service_id
             LEFT JOIN cities c ON c.id = a.city_id WHERE a.id = ?',
            [$appointmentId],
        );
        if ($appointment === null) {
            return;
        }

        $active = AppointmentStateMachine::isActive((string) $appointment['status']);
        if ($active && !$this->google->isReady()) {
            // Not connected (or Calendar access not granted): wait instead of burning retries.
            // Connecting Google re-queues every pending upcoming booking.
            $this->db->update('appointments', [
                'calendar_sync_status' => 'pending',
                'calendar_last_error' => 'Waiting for Google Calendar to be connected.',
            ], ['id' => $appointmentId]);

            return;
        }
        try {
            if (!$active) {
                if ($appointment['google_event_id']) {
                    $this->google->cancelEvent((string) $appointment['google_event_id'], (string) $appointment['reference']);
                }

                return;
            }

            $this->db->update('appointments', ['calendar_sync_status' => 'processing'], ['id' => $appointmentId]);
            $hadMeet = $appointment['meet_url_enc'] !== null;
            $result = $appointment['google_event_id']
                ? $this->google->updateEventTime(
                    (string) $appointment['google_event_id'],
                    Clock::iso((string) $appointment['starts_at']),
                    Clock::iso((string) $appointment['ends_at']),
                    (string) $appointment['client_timezone'],
                    (string) $appointment['reference'],
                )
                : $this->google->createEvent($this->eventPayload($appointment));

            $this->db->update('appointments', [
                'google_event_id' => $result['id'],
                'meet_url_enc' => $result['meet_url'] ? $this->crypto->encrypt($result['meet_url'], 'appointments.meet_url') : $appointment['meet_url_enc'],
                'calendar_sync_status' => 'synced',
                'calendar_attempts' => (int) $appointment['calendar_attempts'] + 1,
                'calendar_last_error' => null,
                'updated_at' => $this->clock->nowString(),
            ], ['id' => $appointmentId]);

            if (!$hadMeet && $result['meet_url']) {
                $email = (string) $this->crypto->decrypt($appointment['client_email_enc'], 'appointments.client_email');
                $this->notifications->queue('appointment_meeting_details', $email, [
                    'name' => (string) $this->crypto->decrypt($appointment['client_name_enc'], 'appointments.client_name'),
                    'reference' => (string) $appointment['reference'],
                    'type_title' => (string) $appointment['type_title'],
                    'starts_at_local' => TimeFormatter::forClient((string) $appointment['starts_at'], (string) $appointment['client_timezone']),
                    'meet_url' => $result['meet_url'],
                ], 'appointment', $appointmentId);
            }
        } catch (\Throwable $e) {
            // The job queue retries with backoff; onFinalFailure marks it failed when attempts run out.
            $this->db->update('appointments', [
                'calendar_sync_status' => $active ? 'retry_required' : $appointment['calendar_sync_status'],
                'calendar_attempts' => (int) $appointment['calendar_attempts'] + 1,
                'calendar_last_error' => mb_substr($e->getMessage(), 0, 250),
            ], ['id' => $appointmentId]);
            throw $e;
        }
    }

    /** Called once all retries are exhausted. The booking stays confirmed; the client is never re-charged. */
    public function onFinalFailure(int $appointmentId, \Throwable $e): void
    {
        $this->db->run(
            "UPDATE appointments SET calendar_sync_status = 'failed' WHERE id = ? AND calendar_sync_status IN ('pending','processing','retry_required')",
            [$appointmentId],
        );
        $reference = (string) $this->db->value('SELECT reference FROM appointments WHERE id = ?', [$appointmentId]);
        $this->notifications->queueAdmin('admin_integration_failure', [
            'integration' => 'Google Calendar',
            'reference' => $reference,
            'error' => mb_substr($e->getMessage(), 0, 200),
        ], 'appointment', $appointmentId);
    }

    /**
     * Guests see the description too, so it carries booking logistics only — never application
     * answers, notes or other confidential content. The Meet link is attached as conference data.
     *
     * @return array<string, mixed>
     */
    private function eventPayload(array $appointment): array
    {
        $name = (string) $this->crypto->decrypt($appointment['client_name_enc'], 'appointments.client_name');
        $manage = $this->config->get('app.frontend_url') . '/consultation/' . $appointment['reference'];
        $lines = array_filter([
            $appointment['service_title'] ? 'Service: ' . $appointment['service_title'] : null,
            'Appointment: ' . $appointment['type_title'] . ' (' . (int) $appointment['duration_minutes'] . ' minutes, ' . self::FORMATS[$appointment['format']] . ')',
            'Client: ' . $name,
            'Reference: ' . $appointment['reference'],
            'Payment: ' . $this->paymentStatus($appointment),
            $appointment['format'] === 'google_meet' ? 'Google Meet: use the “Join with Google Meet” link on this invitation.' : null,
            'Manage booking: ' . $manage,
        ]);

        return [
            'reference' => (string) $appointment['reference'],
            'summary' => $appointment['type_title'] . ' — ' . $name,
            'description' => implode("\n", $lines) . "\n\nThis meeting is confidential.",
            'starts_at' => Clock::iso((string) $appointment['starts_at']),
            'ends_at' => Clock::iso((string) $appointment['ends_at']),
            'timezone' => (string) $appointment['client_timezone'],
            'attendee_email' => (string) $this->crypto->decrypt($appointment['client_email_enc'], 'appointments.client_email'),
            'attendee_name' => $name,
            'with_meet' => $appointment['format'] === 'google_meet',
            'location' => $appointment['format'] === 'in_person' ? $appointment['city_name'] : null,
            'reminders' => $this->reminders->enabledOffsets(),
        ];
    }

    private function paymentStatus(array $appointment): string
    {
        $payment = $this->db->first(
            "SELECT status FROM payments WHERE payable_type = 'appointment' AND payable_id = ? ORDER BY (status IN ('captured','partially_refunded')) DESC, id DESC LIMIT 1",
            [$appointment['id']],
        );
        if ($payment === null) {
            return (int) $appointment['amount_minor'] > 0 ? 'Not yet received' : 'No payment required';
        }

        return match ($payment['status']) {
            'captured' => 'Paid',
            'partially_refunded' => 'Paid (partially refunded)',
            'refunded' => 'Refunded',
            'reconciliation_required' => 'Received, under review',
            default => 'Pending',
        };
    }
}
