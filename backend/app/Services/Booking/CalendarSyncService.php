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
            'SELECT a.*, t.title AS type_title, c.name AS city_name FROM appointments a
             JOIN appointment_types t ON t.id = a.appointment_type_id LEFT JOIN cities c ON c.id = a.city_id WHERE a.id = ?',
            [$appointmentId],
        );
        if ($appointment === null) {
            return;
        }

        $active = AppointmentStateMachine::isActive((string) $appointment['status']);
        try {
            if (!$active) {
                if ($appointment['google_event_id']) {
                    $this->google->cancelEvent((string) $appointment['google_event_id']);
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

    /** @return array<string, mixed> */
    private function eventPayload(array $appointment): array
    {
        $name = (string) $this->crypto->decrypt($appointment['client_name_enc'], 'appointments.client_name');
        $manage = $this->config->get('app.frontend_url') . '/consultation/' . $appointment['reference'];

        return [
            'reference' => (string) $appointment['reference'],
            'summary' => $appointment['type_title'] . ' — ' . $name,
            'description' => "Private consultation · Reference {$appointment['reference']}\nManage: {$manage}\n\nThis meeting is confidential.",
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
}
