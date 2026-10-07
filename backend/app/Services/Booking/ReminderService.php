<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Core\Clock;
use App\Core\Database;
use App\Security\Crypto;
use App\Services\Notifications\NotificationService;
use App\Services\SettingsService;
use App\Services\TimeFormatter;

/**
 * Server-side email reminders (complementing Google Calendar's own popup reminders).
 * Reminders are re-planned on every reschedule and cancelled on cancellation; at send time the
 * appointment is re-checked so stale reminders are never delivered.
 */
final class ReminderService
{
    public function __construct(
        private Database $db,
        private Clock $clock,
        private SettingsService $settings,
        private Crypto $crypto,
        private NotificationService $notifications,
    ) {
    }

    /** @return list<int> enabled reminder offsets in minutes */
    public function enabledOffsets(): array
    {
        $config = $this->settings->get('reminders.schedule', [
            ['minutes' => 1440, 'enabled' => true],
            ['minutes' => 60, 'enabled' => true],
            ['minutes' => 15, 'enabled' => true],
        ]);
        $offsets = [];
        foreach ((array) $config as $item) {
            if (!empty($item['enabled']) && (int) ($item['minutes'] ?? 0) > 0) {
                $offsets[] = (int) $item['minutes'];
            }
        }
        rsort($offsets);

        return array_values(array_unique($offsets));
    }

    public function schedule(int $appointmentId): void
    {
        $this->cancelAll($appointmentId);
        $appointment = $this->db->first('SELECT starts_at, status FROM appointments WHERE id = ?', [$appointmentId]);
        if ($appointment === null || !AppointmentStateMachine::isActive((string) $appointment['status'])) {
            return;
        }
        $starts = Clock::utc((string) $appointment['starts_at']);
        foreach ($this->enabledOffsets() as $minutes) {
            $runAt = $starts->modify("-{$minutes} minutes");
            if ($runAt <= $this->clock->now()) {
                continue;
            }
            $this->db->run(
                "INSERT IGNORE INTO reminder_jobs (appointment_id, offset_minutes, channel, run_at, status, created_at)
                 VALUES (?, ?, 'email', ?, 'pending', ?)",
                [$appointmentId, $minutes, $runAt->format('Y-m-d H:i:s'), $this->clock->nowString()],
            );
        }
    }

    public function cancelAll(int $appointmentId): void
    {
        $this->db->run("UPDATE reminder_jobs SET status = 'cancelled' WHERE appointment_id = ? AND status = 'pending'", [$appointmentId]);
    }

    public function processDue(int $limit = 50): int
    {
        $due = $this->db->all(
            "SELECT r.*, a.starts_at, a.status AS appointment_status, a.client_timezone, a.client_email_enc, a.client_name_enc,
                    a.meet_url_enc, a.reference, a.format, t.title AS type_title
             FROM reminder_jobs r JOIN appointments a ON a.id = r.appointment_id JOIN appointment_types t ON t.id = a.appointment_type_id
             WHERE r.status = 'pending' AND r.run_at <= ? ORDER BY r.run_at LIMIT {$limit}",
            [$this->clock->nowString()],
        );

        foreach ($due as $row) {
            $expectedRunAt = Clock::utc((string) $row['starts_at'])->modify('-' . (int) $row['offset_minutes'] . ' minutes')->format('Y-m-d H:i:s');
            $stale = !AppointmentStateMachine::isActive((string) $row['appointment_status'])
                || $expectedRunAt !== (string) $row['run_at']
                || Clock::utc((string) $row['starts_at']) <= $this->clock->now();
            if ($stale) {
                $this->db->update('reminder_jobs', ['status' => 'cancelled'], ['id' => $row['id']]);
                continue;
            }

            // Overlapping workers (cron on shared hosting) may select the same row; only the claimant queues the email.
            $this->db->transaction(function () use ($row): void {
                $claimed = $this->db->run(
                    "UPDATE reminder_jobs SET status = 'sent', sent_at = ?, attempts = attempts + 1 WHERE id = ? AND status = 'pending'",
                    [$this->clock->nowString(), $row['id']],
                )->rowCount();
                if ($claimed !== 1) {
                    return;
                }
                $email = (string) $this->crypto->decrypt($row['client_email_enc'], 'appointments.client_email');
                $this->notifications->queue('appointment_reminder', $email, [
                    'name' => (string) $this->crypto->decrypt($row['client_name_enc'], 'appointments.client_name'),
                    'reference' => (string) $row['reference'],
                    'type_title' => (string) $row['type_title'],
                    'starts_at_local' => TimeFormatter::forClient((string) $row['starts_at'], (string) $row['client_timezone']),
                    'meet_url' => (string) ($this->crypto->decrypt($row['meet_url_enc'], 'appointments.meet_url') ?? ''),
                    'lead_time' => self::humanOffset((int) $row['offset_minutes']),
                ], 'appointment', (int) $row['appointment_id']);
            });
        }

        return count($due);
    }

    public static function humanOffset(int $minutes): string
    {
        return match (true) {
            $minutes % 1440 === 0 => ($minutes / 1440) . ' ' . ($minutes === 1440 ? 'day' : 'days'),
            $minutes % 60 === 0 => ($minutes / 60) . ' ' . ($minutes === 60 ? 'hour' : 'hours'),
            default => "{$minutes} minutes",
        };
    }
}
