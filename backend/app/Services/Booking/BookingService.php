<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Jobs\JobQueue;
use App\Security\AuditLogger;
use App\Security\BlindIndex;
use App\Security\Crypto;
use App\Security\Tokens;
use App\Services\Notifications\NotificationService;
use App\Services\SettingsService;
use App\Services\StatusHistory;
use App\Services\TimeFormatter;
use DateTimeImmutable;

final class BookingService
{
    public const ENCRYPTED = ['client_name', 'client_email', 'client_phone', 'notes', 'meet_url'];

    public function __construct(
        private Database $db,
        private Clock $clock,
        private Config $config,
        private Crypto $crypto,
        private BlindIndex $blindIndex,
        private AvailabilityService $availability,
        private BookingLock $lock,
        private JobQueue $jobs,
        private ReminderService $reminders,
        private NotificationService $notifications,
        private SettingsService $settings,
        private AuditLogger $audit,
        private StatusHistory $history,
    ) {
    }

    // ---------------------------------------------------------------- types

    /** @return array<string, mixed> */
    public function activeType(string $slug): array
    {
        $type = $this->db->first('SELECT * FROM appointment_types WHERE slug = ? AND is_active = 1', [$slug]);
        if ($type === null) {
            throw HttpException::notFound('This consultation type is not available.');
        }

        return $type;
    }

    /**
     * Public-facing appointment types. Approval-gated types only appear with a valid invite.
     *
     * @return list<array<string, mixed>>
     */
    public function bookableTypes(?string $serviceSlug, ?string $inviteToken): array
    {
        $invite = $inviteToken ? $this->findInvite($inviteToken) : null;
        $params = [];
        $sql = 'SELECT t.*, s.slug AS service_slug, s.title AS service_title FROM appointment_types t
                LEFT JOIN services s ON s.id = t.service_id WHERE t.is_active = 1';
        if ($serviceSlug) {
            $sql .= ' AND s.slug = ?';
            $params[] = $serviceSlug;
        }
        $sql .= ' ORDER BY t.sort_order, t.id';

        $out = [];
        foreach ($this->db->all($sql, $params) as $type) {
            $allowed = (!$type['requires_approval'] && $type['is_public'])
                || ($invite !== null && ($invite['invited_appointment_type_id'] === null || (int) $invite['invited_appointment_type_id'] === (int) $type['id']));
            if ($allowed) {
                $out[] = $this->presentType($type);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function presentType(array $type): array
    {
        $prices = $this->db->all('SELECT currency, amount_minor FROM appointment_type_prices WHERE appointment_type_id = ? ORDER BY currency', [$type['id']]);

        return [
            'slug' => $type['slug'],
            'title' => $type['title'],
            'description' => $type['description'],
            'service' => isset($type['service_slug']) ? ['slug' => $type['service_slug'], 'title' => $type['service_title']] : null,
            'duration_minutes' => (int) $type['duration_minutes'],
            'formats' => json_decode((string) $type['formats'], true) ?: [],
            'requires_payment' => (bool) $type['requires_payment'],
            'requires_approval' => (bool) $type['requires_approval'],
            'prices' => array_map(static fn ($p) => ['currency' => $p['currency'], 'amount_minor' => (int) $p['amount_minor']], $prices),
            'tax' => ['label' => $type['tax_label'], 'rate_bp' => (int) $type['tax_rate_bp'], 'inclusive' => (bool) $type['tax_inclusive']],
            'cancellation_policy' => $type['cancellation_policy'],
            'reschedule_policy' => $type['reschedule_policy'],
            'cancellation_window_hours' => (int) $type['cancellation_window_hours'],
            'reschedule_window_hours' => (int) $type['reschedule_window_hours'],
            'max_advance_days' => (int) $type['max_advance_days'],
        ];
    }

    // ---------------------------------------------------------------- create

    /**
     * Holds the slot and creates the appointment.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input, ?int $adminUserId = null, ?string $idempotencyKey = null): array
    {
        $data = Validator::validate($input, [
            'type' => 'required|slug',
            'starts_at' => 'required|datetime',
            'timezone' => 'required|timezone',
            'format' => 'required|in:google_meet,phone,in_person',
            'currency' => 'nullable|currency',
            'name' => 'required|string|min:2|max:190',
            'email' => 'required|email',
            'phone' => 'nullable|phone',
            'country' => 'nullable|country',
            'notes' => 'nullable|string|max:2000',
            'city_id' => 'nullable|integer',
            'invite_token' => 'nullable|string|max:128',
            'consent_privacy' => $adminUserId ? 'nullable|boolean' : 'accepted',
            'payment_exempt' => 'nullable|boolean',
        ]);

        if ($idempotencyKey !== null && $this->db->value('SELECT 1 FROM appointments WHERE idempotency_key = ?', [$idempotencyKey])) {
            throw HttpException::conflict('duplicate_request', 'This booking request was already received. Please check your email.');
        }

        $type = $this->activeType((string) $data['type']);
        $formats = json_decode((string) $type['formats'], true) ?: [];
        if (!in_array($data['format'], $formats, true)) {
            throw HttpException::validation(['format' => ['This format is not offered for the selected consultation.']]);
        }
        if ($data['format'] === 'in_person' && empty($data['city_id'])) {
            throw HttpException::validation(['city_id' => ['Choose a city for an in-person meeting.']]);
        }

        $application = null;
        if ($adminUserId === null && ($type['requires_approval'] || !$type['is_public'])) {
            $application = $data['invite_token'] ? $this->findInvite((string) $data['invite_token']) : null;
            if ($application === null || ($application['invited_appointment_type_id'] !== null && (int) $application['invited_appointment_type_id'] !== (int) $type['id'])) {
                throw HttpException::forbidden('This consultation is available by private invitation only.');
            }
        }

        $requiresPayment = (bool) $type['requires_payment'] && !($adminUserId !== null && !empty($data['payment_exempt']));
        $currency = null;
        $amounts = ['amount' => 0, 'tax' => 0, 'total' => 0];
        if ($requiresPayment) {
            $currency = (string) ($data['currency'] ?? '');
            $price = $this->db->first('SELECT amount_minor FROM appointment_type_prices WHERE appointment_type_id = ? AND currency = ?', [$type['id'], $currency]);
            if ($price === null) {
                throw HttpException::validation(['currency' => ['This consultation is not offered in the selected currency.']]);
            }
            $amounts = self::computeAmounts((int) $price['amount_minor'], (int) $type['tax_rate_bp'], (bool) $type['tax_inclusive']);
        }

        $startsAt = Validator::parseDateTime((string) $data['starts_at']);
        $endsAt = $startsAt->modify('+' . (int) $type['duration_minutes'] . ' minutes');
        $token = Tokens::random(32);
        $reference = Tokens::reference('PC');

        $appointmentId = $this->lock->run(function () use ($type, $startsAt, $endsAt, $data, $requiresPayment, $currency, $amounts, $token, $reference, $application, $adminUserId, $idempotencyKey) {
            if ($adminUserId === null && !$this->availability->isAvailable($type, $startsAt)) {
                throw HttpException::conflict('slot_unavailable', 'That time has just been reserved. Please choose another.');
            }
            [$blockStart, $blockEnd] = $this->blockFor($type, $startsAt, $endsAt);
            if ($adminUserId !== null && $this->overlaps($blockStart, $blockEnd)) {
                throw HttpException::conflict('slot_unavailable', 'This time overlaps another booking.');
            }

            return $this->db->transaction(function () use ($type, $startsAt, $endsAt, $data, $requiresPayment, $currency, $amounts, $token, $reference, $application, $adminUserId, $idempotencyKey, $blockStart, $blockEnd) {
                $now = $this->clock->now();
                $status = $requiresPayment ? 'pending_payment' : 'confirmed';
                $id = $this->db->insert('appointments', [
                    'reference' => $reference,
                    'appointment_type_id' => $type['id'],
                    'application_id' => $application['id'] ?? null,
                    'status' => $status,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'client_timezone' => $data['timezone'],
                    'format' => $data['format'],
                    'city_id' => $data['city_id'] ?? null,
                    'country' => $data['country'] ?? null,
                    'client_email_bidx' => $this->blindIndex->email((string) $data['email']),
                    'currency' => $currency,
                    'amount_minor' => $amounts['amount'],
                    'tax_minor' => $amounts['tax'],
                    'total_minor' => $amounts['total'],
                    'access_token_hash' => Tokens::hash($token),
                    'idempotency_key' => $idempotencyKey,
                    'calendar_sync_status' => 'pending',
                    'created_by' => $adminUserId,
                    'confirmed_at' => $status === 'confirmed' ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ] + $this->crypto->encryptFields([
                    'client_name' => $data['name'],
                    'client_email' => $data['email'],
                    'client_phone' => $data['phone'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ], self::ENCRYPTED, 'appointments'));

                $this->db->insert('appointment_slots', [
                    'appointment_id' => $id,
                    'appointment_type_id' => $type['id'],
                    'block_starts_at' => $blockStart,
                    'block_ends_at' => $blockEnd,
                    'status' => $requiresPayment ? 'held' : 'booked',
                    'held_until' => $requiresPayment ? $now->modify('+' . (int) $this->config->get('security.slot_hold_minutes') . ' minutes') : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if (!empty($data['consent_privacy'])) {
                    $this->db->insert('consent_records', [
                        'subject_type' => 'appointment',
                        'subject_id' => $id,
                        'consent_type' => 'privacy',
                        'policy_version' => (string) $this->settings->get('privacy.policy_version', '1.0'),
                        'granted' => 1,
                        'created_at' => $now,
                    ]);
                }

                return $id;
            });
        });

        if ($adminUserId !== null) {
            $this->audit->record($adminUserId, 'appointment.created_manually', 'appointment', $appointmentId);
        }

        $appointment = $this->find($appointmentId);
        $this->history->record('appointment', $appointmentId, null, (string) $appointment['status'], $adminUserId ? 'admin' : 'client', $adminUserId, $requiresPayment ? 'Time held pending payment.' : null);
        if (!$requiresPayment) {
            $this->afterConfirmed($appointment, $token);
        } elseif ($adminUserId !== null) {
            // The slot is re-held when the client opens the link and starts payment (ensureHold).
            $this->notifications->queue('appointment_payment_request', (string) $data['email'], $this->emailVars($appointment, $token), 'appointment', $appointmentId);
        } else {
            // Carries the only emailed copy of the access link, so it is sent even if payment follows at once.
            $this->notifications->queue('appointment_reserved', (string) $data['email'], $this->emailVars($appointment, $token), 'appointment', $appointmentId);
        }

        return [
            'reference' => $reference,
            'access_token' => $token,
            'status' => $appointment['status'],
            'hold_expires_at' => $requiresPayment ? Clock::iso((string) $this->db->value('SELECT held_until FROM appointment_slots WHERE appointment_id = ?', [$appointmentId])) : null,
            'amount' => ['currency' => $currency, 'subtotal_minor' => $amounts['amount'], 'tax_minor' => $amounts['tax'], 'total_minor' => $amounts['total']],
        ];
    }

    /** @return array{amount: int, tax: int, total: int} */
    public static function computeAmounts(int $priceMinor, int $taxRateBp, bool $inclusive): array
    {
        if ($taxRateBp <= 0) {
            return ['amount' => $priceMinor, 'tax' => 0, 'total' => $priceMinor];
        }
        if ($inclusive) {
            $tax = (int) round($priceMinor * $taxRateBp / (10000 + $taxRateBp));

            return ['amount' => $priceMinor - $tax, 'tax' => $tax, 'total' => $priceMinor];
        }
        $tax = (int) round($priceMinor * $taxRateBp / 10000);

        return ['amount' => $priceMinor, 'tax' => $tax, 'total' => $priceMinor + $tax];
    }

    // ---------------------------------------------------------------- lookups

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        $row = $this->db->first(
            'SELECT a.*, t.title AS type_title, t.slug AS type_slug, t.cancellation_window_hours, t.reschedule_window_hours,
                    t.refund_on_cancel_percent, t.cancellation_policy, t.reschedule_policy, t.duration_minutes,
                    t.buffer_before_minutes, t.buffer_after_minutes, t.max_advance_days, c.name AS city_name
             FROM appointments a JOIN appointment_types t ON t.id = a.appointment_type_id
             LEFT JOIN cities c ON c.id = a.city_id WHERE a.id = ?',
            [$id],
        );

        return $row ?? throw HttpException::notFound('Booking not found.');
    }

    /** @return array<string, mixed> */
    public function findForClient(string $reference, string $token): array
    {
        $id = $this->db->value('SELECT id FROM appointments WHERE reference = ?', [$reference]);
        $row = $id ? $this->find((int) $id) : null;
        if ($row === null || $token === '' || !Tokens::equals((string) $row['access_token_hash'], $token)) {
            throw HttpException::notFound('Booking not found.');
        }
        $expiry = Clock::utc((string) $row['ends_at'])->modify('+' . (int) $this->config->get('security.access_tokens.appointment_days_after') . ' days');
        if ($this->clock->now() > $expiry) {
            throw new HttpException(410, 'link_expired', 'This booking link has expired.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    public function presentForClient(array $row): array
    {
        $decrypted = $this->crypto->decryptFields($row, ['client_name', 'meet_url'], 'appointments');
        $now = $this->clock->now();
        $starts = Clock::utc((string) $row['starts_at']);
        $hoursUntil = ($starts->getTimestamp() - $now->getTimestamp()) / 3600;
        $active = AppointmentStateMachine::isActive((string) $row['status']);
        $hold = $row['status'] === 'pending_payment'
            ? $this->db->value("SELECT held_until FROM appointment_slots WHERE appointment_id = ? AND status = 'held'", [$row['id']])
            : null;
        $payment = $this->db->first(
            "SELECT reference, gateway, status FROM payments WHERE payable_type = 'appointment' AND payable_id = ? ORDER BY id DESC LIMIT 1",
            [$row['id']],
        );

        return [
            'reference' => $row['reference'],
            'status' => $row['status'],
            'country' => $row['country'] ?? null,
            'payment' => $payment === null ? null : ['reference' => $payment['reference'], 'gateway' => $payment['gateway'], 'status' => $payment['status']],
            'type' => ['slug' => $row['type_slug'], 'title' => $row['type_title'], 'duration_minutes' => (int) $row['duration_minutes'], 'max_advance_days' => (int) $row['max_advance_days']],
            'starts_at' => Clock::iso((string) $row['starts_at']),
            'ends_at' => Clock::iso((string) $row['ends_at']),
            'timezone' => $row['client_timezone'],
            'format' => $row['format'],
            'city' => $row['city_name'],
            'client_name' => $decrypted['client_name'],
            'meet_url' => $active ? $decrypted['meet_url'] : null,
            'calendar_sync_status' => $row['calendar_sync_status'],
            'amount' => ['currency' => $row['currency'], 'subtotal_minor' => (int) $row['amount_minor'], 'tax_minor' => (int) $row['tax_minor'], 'total_minor' => (int) $row['total_minor']],
            'hold_expires_at' => Clock::iso($hold ? (string) $hold : null),
            'can_reschedule' => $active && $hoursUntil >= (int) $row['reschedule_window_hours'] && (int) $row['reschedule_count'] < (int) $this->settings->get('booking.max_reschedules', 2),
            'can_cancel' => ($active || in_array($row['status'], ['pending_payment', 'payment_failed'], true)) && $hoursUntil > 0,
            'refund_eligible' => $active && $hoursUntil >= (int) $row['cancellation_window_hours'],
            'cancellation_policy' => $row['cancellation_policy'],
            'reschedule_policy' => $row['reschedule_policy'],
        ];
    }

    // ---------------------------------------------------------------- payment hooks (called inside PaymentService transaction)

    /**
     * Confirms after verified payment. Returns false if the slot was lost while the hold had
     * expired — the booking then waits in payment_verification for admin action and auto-refund.
     */
    public function confirmPaid(int $appointmentId, string $source = 'system', ?int $userId = null): bool
    {
        $row = $this->db->first('SELECT * FROM appointments WHERE id = ? FOR UPDATE', [$appointmentId]) ?? throw HttpException::notFound();
        $from = (string) $row['status'];
        if (AppointmentStateMachine::isActive($from)) {
            return true; // Already confirmed by the other channel (verify vs webhook).
        }
        // A payment arriving for an expired reservation can still confirm it if the time is free.
        if (!in_array($from, ['pending_payment', 'payment_failed', 'payment_verification', 'expired'], true)) {
            return false;
        }

        $slot = $this->db->first('SELECT * FROM appointment_slots WHERE appointment_id = ? FOR UPDATE', [$appointmentId]);
        $conflict = $slot === null
            || Clock::utc((string) $row['starts_at']) <= $this->clock->now()
            || $this->overlaps(Clock::utc((string) $slot['block_starts_at']), Clock::utc((string) $slot['block_ends_at']), $appointmentId);
        $now = $this->clock->nowString();

        if ($conflict) {
            $this->db->update('appointments', ['status' => 'payment_verification', 'updated_at' => $now], ['id' => $appointmentId]);
            $this->history->record('appointment', $appointmentId, $from, 'payment_verification', $source, $userId, 'Paid, but the reserved time is no longer available.');

            return false;
        }

        $this->db->update('appointment_slots', ['status' => 'booked', 'held_until' => null, 'updated_at' => $now], ['appointment_id' => $appointmentId]);
        $this->db->update('appointments', ['status' => 'confirmed', 'confirmed_at' => $now, 'expired_at' => null, 'updated_at' => $now], ['id' => $appointmentId]);
        $this->history->record('appointment', $appointmentId, $from, 'confirmed', $source, $userId, 'Payment verified.');

        return true;
    }

    public function markPaymentFailed(int $appointmentId, string $source = 'system'): void
    {
        $from = $this->db->value('SELECT status FROM appointments WHERE id = ?', [$appointmentId]);
        $changed = $this->db->run(
            "UPDATE appointments SET status = 'payment_failed', updated_at = ? WHERE id = ? AND status IN ('pending_payment','payment_verification')",
            [$this->clock->nowString(), $appointmentId],
        )->rowCount();
        if ($changed > 0) {
            $this->history->record('appointment', $appointmentId, (string) $from, 'payment_failed', $source);
        }
    }

    /** A captured payment needs manual reconciliation before the booking can be confirmed. */
    public function markPaymentVerification(int $appointmentId, string $source = 'system'): void
    {
        $from = $this->db->value('SELECT status FROM appointments WHERE id = ?', [$appointmentId]);
        $changed = $this->db->run(
            "UPDATE appointments SET status = 'payment_verification', updated_at = ? WHERE id = ? AND status IN ('pending_payment','payment_failed','expired')",
            [$this->clock->nowString(), $appointmentId],
        )->rowCount();
        if ($changed > 0) {
            $this->history->record('appointment', $appointmentId, (string) $from, 'payment_verification', $source, null, 'Payment awaiting reconciliation.');
        }
    }

    /** A goodwill partial refund on a held session keeps its completed / no-show outcome. */
    public function markRefunded(int $appointmentId, bool $fullRefund): void
    {
        $from = $this->db->value('SELECT status FROM appointments WHERE id = ?', [$appointmentId]);
        $statuses = $fullRefund ? "'cancelled','payment_verification','expired','completed','no_show'" : "'cancelled','payment_verification','expired'";
        $changed = $this->db->run(
            "UPDATE appointments SET status = 'refunded', updated_at = ? WHERE id = ? AND status IN ({$statuses})",
            [$this->clock->nowString(), $appointmentId],
        )->rowCount();
        if ($changed > 0) {
            $this->history->record('appointment', $appointmentId, (string) $from, 'refunded', 'webhook');
        }
    }

    /** Post-commit side effects of a confirmation. `$accessToken` is only known at creation time. */
    public function afterConfirmed(array $appointment, ?string $accessToken = null): void
    {
        if ($appointment['application_id']) {
            $converted = $this->db->run(
                "UPDATE applications SET status = 'converted', converted_at = ?, updated_at = ? WHERE id = ? AND status IN ('approved','invited')",
                [$this->clock->nowString(), $this->clock->nowString(), $appointment['application_id']],
            )->rowCount();
            if ($converted > 0) {
                $this->history->record('application', (int) $appointment['application_id'], 'invited', 'converted', 'system', null, 'Booked ' . $appointment['reference'] . '.');
            }
        }
        $this->db->run(
            "UPDATE appointments SET calendar_sync_status = 'pending' WHERE id = ? AND calendar_sync_status IN ('failed','retry_required')",
            [$appointment['id']],
        );
        $this->jobs->push('calendar.sync', ['appointment_id' => (int) $appointment['id']], 'calendar.sync:' . $appointment['id']);
        $this->reminders->schedule((int) $appointment['id']);
        $vars = $this->emailVars($appointment, $accessToken);
        $email = (string) $this->crypto->decrypt($appointment['client_email_enc'], 'appointments.client_email');
        $this->notifications->queue('appointment_confirmation', $email, $vars, 'appointment', (int) $appointment['id']);
        $this->notifications->queueAdmin('admin_new_booking', [
            'reference' => $appointment['reference'],
            'type_title' => $appointment['type_title'] ?? '',
            'starts_at_local' => TimeFormatter::forClient((string) $appointment['starts_at'], (string) $this->config->get('app.practice_timezone')),
        ], 'appointment', (int) $appointment['id']);
    }

    // ---------------------------------------------------------------- changes

    public function reschedule(array $appointment, string $startsAtIso, bool $asAdmin = false, ?int $userId = null): array
    {
        if (!AppointmentStateMachine::isActive((string) $appointment['status'])) {
            throw HttpException::conflict('invalid_state', 'Only confirmed bookings can be rescheduled.');
        }
        $startsAt = Validator::parseDateTime($startsAtIso) ?? throw HttpException::validation(['starts_at' => ['Use an ISO-8601 date and time.']]);
        $hoursUntil = (Clock::utc((string) $appointment['starts_at'])->getTimestamp() - $this->clock->now()->getTimestamp()) / 3600;
        if (!$asAdmin) {
            if ($hoursUntil < (int) $appointment['reschedule_window_hours']) {
                throw HttpException::conflict('outside_window', 'Rescheduling is no longer possible online for this booking. Please contact the private office.');
            }
            if ((int) $appointment['reschedule_count'] >= (int) $this->settings->get('booking.max_reschedules', 2)) {
                throw HttpException::conflict('reschedule_limit', 'This booking has reached its rescheduling limit. Please contact the private office.');
            }
        }

        $type = $this->db->first('SELECT * FROM appointment_types WHERE id = ?', [$appointment['appointment_type_id']]);
        $endsAt = $startsAt->modify('+' . (int) $type['duration_minutes'] . ' minutes');

        $this->lock->run(function () use ($appointment, $type, $startsAt, $endsAt, $asAdmin) {
            [$blockStart, $blockEnd] = $this->blockFor($type, $startsAt, $endsAt);
            $ok = $asAdmin
                ? !$this->overlaps($blockStart, $blockEnd, (int) $appointment['id'])
                : $this->availability->isAvailable($type, $startsAt, (int) $appointment['id']);
            if (!$ok) {
                throw HttpException::conflict('slot_unavailable', 'That time is not available. Please choose another.');
            }
            $this->db->transaction(function () use ($appointment, $startsAt, $endsAt, $blockStart, $blockEnd) {
                $now = $this->clock->nowString();
                $this->db->update('appointment_slots', ['block_starts_at' => $blockStart, 'block_ends_at' => $blockEnd, 'updated_at' => $now], ['appointment_id' => $appointment['id']]);
                $this->db->run(
                    "UPDATE appointments SET starts_at = ?, ends_at = ?, status = 'rescheduled', reschedule_count = reschedule_count + 1,
                        calendar_sync_status = IF(calendar_sync_status = 'not_required', 'not_required', 'pending'), updated_at = ? WHERE id = ?",
                    [$startsAt->format('Y-m-d H:i:s'), $endsAt->format('Y-m-d H:i:s'), $now, $appointment['id']],
                );
            });
        });

        $updated = $this->find((int) $appointment['id']);
        $this->history->record('appointment', (int) $updated['id'], (string) $appointment['status'], 'rescheduled', $asAdmin ? 'admin' : 'client', $userId);
        $this->jobs->push('calendar.sync', ['appointment_id' => (int) $updated['id']], 'calendar.sync:' . $updated['id']);
        $this->reminders->schedule((int) $updated['id']);
        $email = (string) $this->crypto->decrypt($updated['client_email_enc'], 'appointments.client_email');
        $this->notifications->queue('appointment_rescheduled', $email, $this->emailVars($updated), 'appointment', (int) $updated['id']);
        $this->audit->record($userId, $asAdmin ? 'appointment.rescheduled_by_admin' : 'appointment.rescheduled_by_client', 'appointment', $updated['id']);

        return $updated;
    }

    public function cancel(array $appointment, ?string $reason, bool $asAdmin = false, ?int $userId = null, ?bool $forceRefund = null): array
    {
        $status = (string) $appointment['status'];
        if (!AppointmentStateMachine::canTransition($status, 'cancelled')) {
            throw HttpException::conflict('invalid_state', 'This booking can no longer be cancelled.');
        }
        $hoursUntil = (Clock::utc((string) $appointment['starts_at'])->getTimestamp() - $this->clock->now()->getTimestamp()) / 3600;
        if (!$asAdmin && $hoursUntil <= 0) {
            throw HttpException::conflict('outside_window', 'This booking has already started.');
        }

        $this->db->transaction(function () use ($appointment, $reason) {
            $now = $this->clock->nowString();
            $this->db->update('appointment_slots', ['status' => 'released', 'held_until' => null, 'updated_at' => $now], ['appointment_id' => $appointment['id']]);
            $this->db->update('appointments', [
                'status' => 'cancelled',
                'cancelled_at' => $now,
                'cancellation_reason' => $reason === null ? null : mb_substr($reason, 0, 500),
                'updated_at' => $now,
            ], ['id' => $appointment['id']]);
        });

        $this->history->record('appointment', (int) $appointment['id'], $status, 'cancelled', $asAdmin ? 'admin' : 'client', $userId);
        $this->reminders->cancelAll((int) $appointment['id']);
        if ($appointment['google_event_id']) {
            $this->jobs->push('calendar.sync', ['appointment_id' => (int) $appointment['id']], 'calendar.sync:' . $appointment['id']);
        }

        // Paid-but-unconfirmed bookings never received a session, so the policy percentage does not apply.
        $neverConfirmed = $status === 'payment_verification';
        $refundEligible = $forceRefund ?? ($neverConfirmed || (AppointmentStateMachine::isActive($status) && $hoursUntil >= (int) $appointment['cancellation_window_hours']));
        $payment = $this->db->first("SELECT id, amount_minor, refunded_minor FROM payments WHERE payable_type = 'appointment' AND payable_id = ? AND status IN ('captured','partially_refunded') ORDER BY id DESC LIMIT 1", [$appointment['id']]);
        $refundMinor = 0;
        if ($refundEligible && $payment !== null) {
            $percent = $neverConfirmed ? 100 : (int) $appointment['refund_on_cancel_percent'];
            $refundMinor = (int) floor(((int) $payment['amount_minor'] - (int) $payment['refunded_minor']) * $percent / 100);
            if ($refundMinor > 0) {
                $this->jobs->push('payment.refund', [
                    'payment_id' => (int) $payment['id'],
                    'amount_minor' => $refundMinor,
                    'reason' => 'Cancellation of ' . $appointment['reference'],
                    'user_id' => $userId,
                ], 'payment.refund:cancel:' . $appointment['id']);
            }
        }

        $updated = $this->find((int) $appointment['id']);
        $email = (string) $this->crypto->decrypt($updated['client_email_enc'], 'appointments.client_email');
        $this->notifications->queue('appointment_cancelled', $email, $this->emailVars($updated) + [
            'refund_amount' => $refundMinor > 0 ? TimeFormatter::money($refundMinor, (string) $updated['currency']) : '',
        ], 'appointment', (int) $updated['id']);
        $this->audit->record($userId, $asAdmin ? 'appointment.cancelled_by_admin' : 'appointment.cancelled_by_client', 'appointment', $updated['id'], ['refund_minor' => $refundMinor]);

        return $updated;
    }

    public function complete(array $appointment, int $userId): void
    {
        AppointmentStateMachine::assert((string) $appointment['status'], 'completed');
        $now = $this->clock->nowString();
        $this->db->update('appointments', ['status' => 'completed', 'completed_at' => $now, 'updated_at' => $now], ['id' => $appointment['id']]);
        $this->history->record('appointment', (int) $appointment['id'], (string) $appointment['status'], 'completed', 'admin', $userId);
        $this->audit->record($userId, 'appointment.completed', 'appointment', $appointment['id']);
    }

    /** The client did not attend. Only possible once the session has started; payment is retained. */
    public function markNoShow(array $appointment, int $userId): void
    {
        AppointmentStateMachine::assert((string) $appointment['status'], 'no_show');
        if (Clock::utc((string) $appointment['starts_at']) > $this->clock->now()) {
            throw HttpException::conflict('not_started', 'A no-show can only be recorded once the session has started.');
        }
        $now = $this->clock->nowString();
        $this->db->update('appointments', ['status' => 'no_show', 'no_show_at' => $now, 'updated_at' => $now], ['id' => $appointment['id']]);
        $this->reminders->cancelAll((int) $appointment['id']);
        $this->history->record('appointment', (int) $appointment['id'], (string) $appointment['status'], 'no_show', 'admin', $userId);
        $this->audit->record($userId, 'appointment.no_show', 'appointment', $appointment['id']);
    }

    /** Re-sends the confirmation (with meeting details) to the client. Never issues a new access link. */
    public function resendConfirmation(array $appointment, int $userId): void
    {
        if (!AppointmentStateMachine::isActive((string) $appointment['status'])) {
            throw HttpException::conflict('invalid_state', 'Only confirmed bookings have a confirmation to resend.');
        }
        $email = (string) $this->crypto->decrypt($appointment['client_email_enc'], 'appointments.client_email');
        $this->notifications->queue('appointment_confirmation', $email, $this->emailVars($appointment), 'appointment', (int) $appointment['id']);
        $this->audit->record($userId, 'appointment.confirmation_resent', 'appointment', $appointment['id']);
    }

    /** Releases expired holds so their time becomes bookable again. */
    public function releaseExpiredHolds(): int
    {
        return $this->db->run(
            "UPDATE appointment_slots SET status = 'released', updated_at = ? WHERE status = 'held' AND held_until <= ?
               AND appointment_id IN (SELECT id FROM appointments WHERE status IN ('pending_payment','payment_failed'))",
            [$this->clock->nowString(), $this->clock->nowString()],
        )->rowCount();
    }

    /**
     * Unpaid reservations become `expired` once their start time passes, or once their hold has been
     * released for longer than `booking.unpaid_expiry_hours`. A late verified payment can still confirm
     * an expired reservation if the time remains free (see confirmPaid); otherwise it is refunded.
     */
    public function expireUnpaid(): int
    {
        $now = $this->clock->now();
        $graceHours = max(1, (int) $this->settings->get('booking.unpaid_expiry_hours', 24));
        $rows = $this->db->all(
            "SELECT a.id, a.status FROM appointments a JOIN appointment_slots s ON s.appointment_id = a.id
             WHERE a.status IN ('pending_payment','payment_failed')
               AND (a.starts_at <= ? OR (s.status = 'released' AND s.held_until IS NOT NULL AND s.held_until <= ?))
               AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.payable_type = 'appointment' AND p.payable_id = a.id AND p.status IN ('created','pending') AND p.created_at > ?)
             LIMIT 200",
            [$now->format('Y-m-d H:i:s'), $now->modify("-{$graceHours} hours")->format('Y-m-d H:i:s'), $now->modify('-1 hour')->format('Y-m-d H:i:s')],
        );
        foreach ($rows as $row) {
            $this->db->transaction(function () use ($row) {
                $now = $this->clock->nowString();
                $changed = $this->db->run(
                    "UPDATE appointments SET status = 'expired', expired_at = ?, updated_at = ? WHERE id = ? AND status IN ('pending_payment','payment_failed')",
                    [$now, $now, $row['id']],
                )->rowCount();
                if ($changed > 0) {
                    $this->db->update('appointment_slots', ['status' => 'released', 'updated_at' => $now], ['appointment_id' => $row['id']]);
                    $this->history->record('appointment', (int) $row['id'], (string) $row['status'], 'expired', 'scheduler', null, 'Payment not completed.');
                }
            });
        }

        return count($rows);
    }

    /** Re-holds (or extends) the slot before a payment attempt. */
    public function ensureHold(array $appointment): void
    {
        $this->lock->run(function () use ($appointment) {
            $slot = $this->db->first('SELECT * FROM appointment_slots WHERE appointment_id = ?', [$appointment['id']]);
            if ($slot === null) {
                throw HttpException::conflict('slot_unavailable', 'This time is no longer reserved. Please choose another.');
            }
            $start = Clock::utc((string) $slot['block_starts_at']);
            $end = Clock::utc((string) $slot['block_ends_at']);
            if ($this->overlaps($start, $end, (int) $appointment['id'])) {
                throw HttpException::conflict('slot_unavailable', 'This time has been reserved by someone else. Please choose another.');
            }
            $this->db->update('appointment_slots', [
                'status' => 'held',
                'held_until' => $this->clock->now()->modify('+' . (int) $this->config->get('security.payment_hold_minutes') . ' minutes'),
                'updated_at' => $this->clock->nowString(),
            ], ['appointment_id' => $appointment['id']]);
        });
    }

    /** @return array<string, string> */
    public function emailVars(array $appointment, ?string $accessToken = null): array
    {
        $decrypted = $this->crypto->decryptFields($appointment, ['client_name', 'meet_url'], 'appointments');
        $manage = $this->config->get('app.frontend_url') . '/consultation/' . $appointment['reference'];

        return [
            'name' => (string) $decrypted['client_name'],
            'reference' => (string) $appointment['reference'],
            'type_title' => (string) ($appointment['type_title'] ?? ''),
            'starts_at_local' => TimeFormatter::forClient((string) $appointment['starts_at'], (string) $appointment['client_timezone']),
            'timezone' => (string) $appointment['client_timezone'],
            'format' => match ($appointment['format']) { 'google_meet' => 'Google Meet', 'phone' => 'Telephone', default => 'In person' },
            'city' => (string) ($appointment['city_name'] ?? ''),
            'meet_url' => (string) ($decrypted['meet_url'] ?? ''),
            'amount' => TimeFormatter::money((int) $appointment['total_minor'], $appointment['currency']),
            'payment_status' => (int) $appointment['total_minor'] > 0 ? 'Paid' : 'Not applicable',
            'manage_url' => $accessToken ? $manage . '#access=' . $accessToken : $manage,
            'cancellation_policy' => (string) ($appointment['cancellation_policy'] ?? ''),
        ];
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{0: DateTimeImmutable, 1: DateTimeImmutable} */
    private function blockFor(array $type, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return [
            $start->modify('-' . (int) $type['buffer_before_minutes'] . ' minutes'),
            $end->modify('+' . (int) $type['buffer_after_minutes'] . ' minutes'),
        ];
    }

    private function overlaps(DateTimeImmutable $start, DateTimeImmutable $end, ?int $ignoreAppointmentId = null): bool
    {
        return (bool) $this->db->value(
            "SELECT 1 FROM appointment_slots
             WHERE block_starts_at < ? AND block_ends_at > ?
               AND (status IN ('booked','blocked') OR (status = 'held' AND held_until > ?))
               AND (appointment_id IS NULL OR appointment_id <> ?) LIMIT 1",
            [$end->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s'), $this->clock->nowString(), $ignoreAppointmentId ?? 0],
        );
    }

    /** @return array<string, mixed>|null */
    private function findInvite(string $token): ?array
    {
        $row = $this->db->first(
            "SELECT id, invited_appointment_type_id, invite_expires_at FROM applications WHERE invite_token_hash = ? AND status IN ('approved','invited')",
            [Tokens::hash($token)],
        );
        if ($row === null || ($row['invite_expires_at'] && Clock::utc((string) $row['invite_expires_at']) < $this->clock->now())) {
            return null;
        }

        return $row;
    }
}
