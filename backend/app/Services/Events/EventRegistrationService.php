<?php

declare(strict_types=1);

namespace App\Services\Events;

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
use App\Services\Booking\BookingService;
use App\Services\Notifications\NotificationService;
use App\Services\SettingsService;
use App\Services\StatusHistory;
use App\Services\TimeFormatter;

/**
 * Event registrations with per-currency pricing, a registration window, seat quotas enforced under an
 * event row lock (no overselling), an optional waitlist with automatic promotion, policy-based client
 * cancellation, cancellation cascades when an event is cancelled, and reminders.
 *
 * Lock order is always events → event_registrations to avoid deadlocks between concurrent checkouts.
 */
final class EventRegistrationService
{
    public const ENCRYPTED = ['name', 'email', 'phone', 'notes'];

    /** Registrations that occupy (or may occupy) a seat and can still be cancelled. */
    private const OPEN_STATUSES = ['pending_application', 'pending_payment', 'confirmed', 'waitlisted'];

    public function __construct(
        private Database $db,
        private Clock $clock,
        private Config $config,
        private Crypto $crypto,
        private BlindIndex $blindIndex,
        private NotificationService $notifications,
        private SettingsService $settings,
        private StatusHistory $history,
        private JobQueue $jobs,
        private AuditLogger $audit,
    ) {
    }

    // ---------------------------------------------------------------- availability

    public function seatsTaken(int $eventId, ?int $excludeRegistrationId = null): int
    {
        return (int) $this->db->value(
            "SELECT COALESCE(SUM(seats), 0) FROM event_registrations
             WHERE event_id = ? AND id <> ? AND (status = 'confirmed' OR (status = 'pending_payment' AND hold_expires_at > ?))",
            [$eventId, $excludeRegistrationId ?? 0, $this->clock->nowString()],
        );
    }

    /** @return list<array{currency: string, amount_minor: int}> */
    public function prices(int $eventId): array
    {
        return array_map(
            static fn (array $p) => ['currency' => (string) $p['currency'], 'amount_minor' => (int) $p['amount_minor']],
            $this->db->all('SELECT currency, amount_minor FROM event_prices WHERE event_id = ? AND amount_minor > 0 ORDER BY currency', [$eventId]),
        );
    }

    /**
     * Public registration state. `open` / `waitlist` accept self-registration; the rest explain why not.
     *
     * @param array<string, mixed> $event
     */
    public function registrationState(array $event, ?int $seatsTaken = null): string
    {
        $now = $this->clock->now();
        if ($event['status'] === 'cancelled') {
            return 'cancelled';
        }
        if (Clock::utc((string) $event['starts_at']) <= $now) {
            return 'closed';
        }
        if ($event['registration_mode'] === 'closed') {
            return 'closed';
        }
        if ($event['registration_mode'] === 'invitation') {
            return 'invitation';
        }
        if (!empty($event['registration_opens_at']) && Clock::utc((string) $event['registration_opens_at']) > $now) {
            return 'not_yet_open';
        }
        if (!empty($event['registration_closes_at']) && Clock::utc((string) $event['registration_closes_at']) <= $now) {
            return 'closed';
        }
        if ($event['seat_quota'] !== null) {
            $taken = $seatsTaken ?? $this->seatsTaken((int) $event['id']);
            if ($taken >= (int) $event['seat_quota']) {
                return (int) $event['waitlist_enabled'] === 1 ? 'waitlist' : 'full';
            }
        }

        return $event['registration_mode'] === 'application' ? 'application' : 'open';
    }

    // ---------------------------------------------------------------- client

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function register(string $slug, array $input): array
    {
        $data = Validator::validate($input, [
            'name' => 'required|string|min:2|max:190',
            'email' => 'required|email',
            'phone' => 'nullable|phone',
            'country' => 'nullable|country',
            'notes' => 'nullable|string|max:2000',
            'seats' => 'nullable|integer|between:1,4',
            'currency' => 'nullable|currency',
            'consent_privacy' => 'accepted',
        ]);
        $seats = (int) ($data['seats'] ?? 1);
        $token = Tokens::random(32);
        $reference = Tokens::reference('EV');

        $result = $this->db->transaction(function () use ($slug, $data, $seats, $token, $reference) {
            $event = $this->db->first("SELECT * FROM events WHERE slug = ? AND status = 'published' FOR UPDATE", [$slug]);
            if ($event === null) {
                throw HttpException::notFound('This gathering is not available.');
            }
            $state = $this->registrationState($event);
            match ($state) {
                'not_yet_open' => throw HttpException::conflict('registration_not_open', 'Registration for this gathering opens on ' . TimeFormatter::forClient((string) $event['registration_opens_at'], (string) $event['timezone']) . '.'),
                'full' => throw HttpException::conflict('sold_out', 'All seats for this gathering have been taken.'),
                'closed', 'cancelled', 'invitation' => throw HttpException::conflict('registration_closed', 'Registration for this gathering is closed.'),
                default => null,
            };
            $bidx = $this->blindIndex->email((string) $data['email']);
            if ($this->db->value("SELECT 1 FROM event_registrations WHERE event_id = ? AND email_bidx = ? AND status NOT IN ('cancelled','refunded','expired')", [$event['id'], $bidx])) {
                throw HttpException::conflict('already_registered', 'A registration already exists for this email address.');
            }

            [$currency, $amounts] = $this->priceFor($event, $data['currency'] ?? null, $seats);
            $paid = $amounts['total'] > 0;
            $full = $event['seat_quota'] !== null && $this->seatsTaken((int) $event['id']) + $seats > (int) $event['seat_quota'];
            if ($full && (int) $event['waitlist_enabled'] !== 1) {
                throw HttpException::conflict('sold_out', 'Not enough seats remain for this request.');
            }
            $status = match (true) {
                $full => 'waitlisted',
                $event['registration_mode'] === 'application' => 'pending_application',
                $paid => 'pending_payment',
                default => 'confirmed',
            };
            $now = $this->clock->nowString();

            // Drop stale rows so the unique (event, email) constraint allows re-registration.
            $this->db->run(
                "DELETE FROM event_registrations WHERE event_id = ? AND email_bidx = ? AND status IN ('cancelled','refunded','expired')
                   AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.payable_type = 'event_registration' AND p.payable_id = event_registrations.id)",
                [$event['id'], $bidx],
            );
            if ($this->db->value('SELECT 1 FROM event_registrations WHERE event_id = ? AND email_bidx = ?', [$event['id'], $bidx])) {
                // Kept for the payment record; re-registration is handled by the private office.
                throw HttpException::conflict('already_registered', 'A previous registration exists for this email address. Please contact the private office.');
            }
            $id = $this->db->insert('event_registrations', [
                'event_id' => $event['id'],
                'reference' => $reference,
                'access_token_hash' => Tokens::hash($token),
                'email_bidx' => $bidx,
                'seats' => $seats,
                'country' => $data['country'] ?? null,
                'status' => $status,
                'currency' => $paid ? $currency : null,
                'subtotal_minor' => $amounts['amount'],
                'tax_minor' => $amounts['tax'],
                'amount_minor' => $amounts['total'],
                'hold_expires_at' => $status === 'pending_payment' ? $this->holdUntil() : null,
                'confirmed_at' => $status === 'confirmed' ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ] + $this->crypto->encryptFields($data, self::ENCRYPTED, 'event_registrations'));
            $this->history->record('event_registration', $id, null, $status, 'client');

            return ['id' => $id, 'status' => $status, 'event' => $event, 'currency' => $paid ? $currency : null, 'amounts' => $amounts];
        });

        $id = (int) $result['id'];
        if ($result['status'] === 'confirmed') {
            $this->afterConfirmed($id, $token);
        } else {
            if ($result['status'] === 'pending_payment') {
                $this->notifications->queue('event_payment_request', (string) $data['email'], $this->emailVars($this->findById($id), $token), 'event_registration', $id);
            } elseif ($result['status'] === 'waitlisted') {
                $this->notifications->queue('event_waitlisted', (string) $data['email'], $this->emailVars($this->findById($id), $token), 'event_registration', $id);
            }
            $this->notifications->queueAdmin('admin_new_booking', [
                'reference' => $reference,
                'type_title' => 'Registration: ' . $result['event']['title'],
                'starts_at_local' => TimeFormatter::forClient((string) $result['event']['starts_at'], (string) $result['event']['timezone']),
            ], 'event_registration', $id);
        }

        return [
            'reference' => $reference,
            'access_token' => $token,
            'status' => $result['status'],
            'hold_expires_at' => $result['status'] === 'pending_payment' ? Clock::iso((string) $this->db->value('SELECT hold_expires_at FROM event_registrations WHERE id = ?', [$id])) : null,
            'amount' => [
                'currency' => $result['currency'],
                'subtotal_minor' => $result['amounts']['amount'],
                'tax_minor' => $result['amounts']['tax'],
                'total_minor' => $result['amounts']['total'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function findForClient(string $reference, string $token): array
    {
        $id = $this->db->value('SELECT id FROM event_registrations WHERE reference = ?', [strtoupper($reference)]);
        $row = $id ? $this->findById((int) $id) : null;
        if ($row === null || $token === '' || !Tokens::equals((string) $row['access_token_hash'], $token)) {
            throw HttpException::notFound('Registration not found.');
        }

        return $row;
    }

    /**
     * Client cancellation. Confirmed paid seats are refunded per the event's policy; the freed seats
     * are offered to the waitlist.
     *
     * @return array<string, mixed>
     */
    public function cancelByClient(string $reference, string $token, ?string $reason): array
    {
        $row = $this->findForClient($reference, $token);
        if (!in_array($row['status'], self::OPEN_STATUSES, true)) {
            throw HttpException::conflict('invalid_state', 'This registration can no longer be cancelled.');
        }
        if (Clock::utc((string) $row['starts_at']) <= $this->clock->now()) {
            throw HttpException::conflict('outside_window', 'This gathering has already started.');
        }
        $refundMinor = $this->cancel($row, 'client', null, $reason, null);

        return $this->presentForClient($this->findById((int) $row['id'])) + [
            'refund' => $refundMinor > 0 ? ['currency' => $row['currency'], 'amount_minor' => $refundMinor] : null,
        ];
    }

    // ---------------------------------------------------------------- payment hooks

    /**
     * Ensures a seat is held for the checkout about to start. Re-acquires an expired hold only if the
     * seat is still free; otherwise the client is told before any money moves.
     */
    public function ensureHold(int $registrationId): void
    {
        $this->db->transaction(function () use ($registrationId) {
            $eventId = (int) $this->db->value('SELECT event_id FROM event_registrations WHERE id = ?', [$registrationId]);
            $event = $this->db->first('SELECT * FROM events WHERE id = ? FOR UPDATE', [$eventId]) ?? throw HttpException::notFound();
            $row = $this->db->first('SELECT * FROM event_registrations WHERE id = ? FOR UPDATE', [$registrationId]) ?? throw HttpException::notFound();
            if (!in_array($row['status'], ['pending_payment', 'expired'], true)) {
                throw HttpException::conflict('invalid_state', 'This registration does not require payment.');
            }
            if ($event['status'] !== 'published' || Clock::utc((string) $event['starts_at']) <= $this->clock->now()) {
                throw HttpException::conflict('registration_closed', 'Registration for this gathering is closed.');
            }
            $holdValid = $row['status'] === 'pending_payment' && $row['hold_expires_at'] !== null && Clock::utc((string) $row['hold_expires_at']) > $this->clock->now();
            if (!$holdValid && $event['seat_quota'] !== null
                && $this->seatsTaken($eventId, $registrationId) + (int) $row['seats'] > (int) $event['seat_quota']) {
                throw HttpException::conflict('sold_out', 'Your seat reservation expired and the gathering is now full.');
            }
            // Keep a longer waitlist-offer hold rather than shortening it.
            $hold = $this->holdUntil();
            if ($holdValid && Clock::utc((string) $row['hold_expires_at']) > $hold) {
                $hold = Clock::utc((string) $row['hold_expires_at']);
            }
            $this->db->update('event_registrations', ['status' => 'pending_payment', 'hold_expires_at' => $hold, 'updated_at' => $this->clock->nowString()], ['id' => $registrationId]);
            if ($row['status'] === 'expired') {
                $this->history->record('event_registration', $registrationId, 'expired', 'pending_payment', 'client', null, 'Seat re-held for checkout.');
            }
        });
    }

    /**
     * Confirms after a verified payment (runs inside PaymentService's transaction). Returns false when
     * the seat can no longer be honoured — the hold lapsed and the event filled, the event was
     * cancelled, or it has started — so the payment is refunded instead of overselling.
     */
    public function confirmPaid(int $registrationId, string $source = 'system', ?int $userId = null): bool
    {
        $eventId = (int) $this->db->value('SELECT event_id FROM event_registrations WHERE id = ?', [$registrationId]);
        $event = $this->db->first('SELECT * FROM events WHERE id = ? FOR UPDATE', [$eventId]);
        $row = $this->db->first('SELECT * FROM event_registrations WHERE id = ? FOR UPDATE', [$registrationId]);
        if ($row === null || $event === null) {
            return false;
        }
        $from = (string) $row['status'];
        if ($from === 'confirmed') {
            return true;
        }
        if (!in_array($from, ['pending_payment', 'expired'], true)) {
            return false;
        }

        $holdValid = $from === 'pending_payment' && $row['hold_expires_at'] !== null && Clock::utc((string) $row['hold_expires_at']) > $this->clock->now();
        $seatFree = $event['seat_quota'] === null || $this->seatsTaken($eventId, $registrationId) + (int) $row['seats'] <= (int) $event['seat_quota'];
        $honourable = $event['status'] === 'published' && Clock::utc((string) $event['starts_at']) > $this->clock->now() && ($holdValid || $seatFree);
        $now = $this->clock->nowString();

        if (!$honourable) {
            if ($from === 'pending_payment') {
                $this->db->update('event_registrations', ['status' => 'expired', 'hold_expires_at' => null, 'updated_at' => $now], ['id' => $registrationId]);
            }
            $this->history->record('event_registration', $registrationId, $from, 'expired', $source, $userId, 'Paid after the seat was released; refund required.');

            return false;
        }

        $this->db->update('event_registrations', ['status' => 'confirmed', 'hold_expires_at' => null, 'confirmed_at' => $now, 'updated_at' => $now], ['id' => $registrationId]);
        $this->history->record('event_registration', $registrationId, $from, 'confirmed', $source, $userId, 'Payment verified.');

        return true;
    }

    public function markRefunded(int $registrationId): void
    {
        $from = $this->db->value('SELECT status FROM event_registrations WHERE id = ?', [$registrationId]);
        $changed = $this->db->run(
            "UPDATE event_registrations SET status = 'refunded', updated_at = ? WHERE id = ? AND status IN ('cancelled','expired')",
            [$this->clock->nowString(), $registrationId],
        )->rowCount();
        if ($changed > 0) {
            $this->history->record('event_registration', $registrationId, (string) $from, 'refunded', 'webhook');
        }
    }

    public function afterConfirmed(int $registrationId, ?string $accessToken = null): void
    {
        $row = $this->findById($registrationId);
        if ($row === null) {
            return;
        }
        $this->notifications->queue('event_registration_confirmation', (string) $this->crypto->decrypt($row['email_enc'], 'event_registrations.email'), $this->emailVars($row, $accessToken), 'event_registration', $registrationId);
    }

    // ---------------------------------------------------------------- admin

    /** Admin: move a registration between states. Cancelling a paid seat refunds it only when $refund is true. */
    public function setStatus(int $registrationId, string $status, int $userId, ?bool $refund = null): void
    {
        if (!in_array($status, ['pending_payment', 'confirmed', 'cancelled', 'waitlisted'], true)) {
            throw HttpException::validation(['status' => ['Select a valid status.']]);
        }
        $row = $this->findById($registrationId) ?? throw HttpException::notFound();
        $from = (string) $row['status'];
        if ($from === $status) {
            return;
        }
        if ($status === 'cancelled') {
            if (!in_array($from, self::OPEN_STATUSES, true)) {
                throw HttpException::conflict('invalid_state', 'This registration is already closed.');
            }
            $this->cancel($row, 'admin', $userId, null, $refund);

            return;
        }
        if (in_array($from, ['refunded', 'cancelled', 'expired'], true) && $status === 'confirmed') {
            throw HttpException::conflict('invalid_state', 'Re-open closed registrations by offering a payment link or asking the guest to register again.');
        }

        if ($status === 'pending_payment') {
            $this->offerSeat($row, 'admin', $userId, (int) $this->settings->get('events.waitlist_offer_hours', 48));

            return;
        }

        if ($status === 'confirmed') {
            $this->confirmManually($row, 'admin', $userId);
            $this->afterConfirmed((int) $row['id']);

            return;
        }

        // waitlisted
        $this->db->transaction(function () use ($row, $from, $userId) {
            $this->db->update('event_registrations', ['status' => 'waitlisted', 'hold_expires_at' => null, 'updated_at' => $this->clock->nowString()], ['id' => $row['id']]);
            $this->history->record('event_registration', (int) $row['id'], $from, 'waitlisted', 'admin', $userId);
        });
        if ($from === 'confirmed' || $from === 'pending_payment') {
            $this->promoteWaitlist((int) $row['event_id']);
        }
    }

    /** Confirms without payment (free seat or admin decision), still bounded by the seat quota. */
    private function confirmManually(array $row, string $source, ?int $userId): void
    {
        $this->db->transaction(function () use ($row, $source, $userId) {
            $event = $this->db->first('SELECT * FROM events WHERE id = ? FOR UPDATE', [$row['event_id']]);
            if ($event['seat_quota'] !== null
                && $this->seatsTaken((int) $event['id'], (int) $row['id']) + (int) $row['seats'] > (int) $event['seat_quota']) {
                throw HttpException::conflict('sold_out', 'Confirming this registration would exceed the seat quota. Increase the quota first.');
            }
            $now = $this->clock->nowString();
            $this->db->update('event_registrations', ['status' => 'confirmed', 'hold_expires_at' => null, 'confirmed_at' => $now, 'updated_at' => $now], ['id' => $row['id']]);
            $this->history->record('event_registration', (int) $row['id'], (string) $row['status'], 'confirmed', $source, $userId);
        });
    }

    /** Cancels every open registration when the event itself is cancelled, refunding paid seats in full. */
    public function cancelForEvent(int $eventId, ?int $userId = null): int
    {
        $rows = $this->db->all("SELECT id FROM event_registrations WHERE event_id = ? AND status IN ('pending_application','pending_payment','confirmed','waitlisted')", [$eventId]);
        foreach ($rows as $r) {
            $row = $this->findById((int) $r['id']);
            if ($row !== null) {
                $this->cancel($row, $userId ? 'admin' : 'system', $userId, 'The gathering has been cancelled.', true, false);
            }
        }

        return count($rows);
    }

    // ---------------------------------------------------------------- scheduler

    /** Unpaid holds lapse to `expired`; freed seats go to the waitlist. */
    public function expireHolds(): int
    {
        $now = $this->clock->now();
        $rows = $this->db->all(
            "SELECT r.id, r.event_id FROM event_registrations r
             WHERE r.status = 'pending_payment' AND r.hold_expires_at <= ?
               AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.payable_type = 'event_registration' AND p.payable_id = r.id AND p.status IN ('created','pending') AND p.created_at > ?)
             LIMIT 200",
            [$now->format('Y-m-d H:i:s'), $now->modify('-1 hour')->format('Y-m-d H:i:s')],
        );
        $events = [];
        foreach ($rows as $r) {
            $changed = $this->db->run(
                "UPDATE event_registrations SET status = 'expired', updated_at = ? WHERE id = ? AND status = 'pending_payment' AND hold_expires_at <= ?",
                [$now->format('Y-m-d H:i:s'), $r['id'], $now->format('Y-m-d H:i:s')],
            )->rowCount();
            if ($changed > 0) {
                $this->history->record('event_registration', (int) $r['id'], 'pending_payment', 'expired', 'scheduler', null, 'Payment not completed.');
                $events[(int) $r['event_id']] = true;
            }
        }
        foreach (array_keys($events) as $eventId) {
            $this->promoteWaitlist($eventId);
        }

        return count($rows);
    }

    /**
     * Offers freed seats to waitlisted guests in registration order. Paid events send a time-limited
     * payment link (the seat is held meanwhile); free events confirm directly.
     */
    public function promoteWaitlist(int $eventId): int
    {
        if (!(bool) $this->settings->get('events.auto_promote_waitlist', true)) {
            return 0;
        }
        $promoted = 0;
        $hours = (int) $this->settings->get('events.waitlist_offer_hours', 48);
        foreach ($this->db->all("SELECT id FROM event_registrations WHERE event_id = ? AND status = 'waitlisted' ORDER BY id LIMIT 20", [$eventId]) as $r) {
            $row = $this->findById((int) $r['id']);
            if ($row === null || Clock::utc((string) $row['starts_at']) <= $this->clock->now()) {
                break;
            }
            try {
                if ($this->prices($eventId) === []) {
                    $this->confirmManually($row, 'scheduler', null);
                    $this->afterConfirmed((int) $row['id']);
                } else {
                    $this->offerSeat($row, 'scheduler', null, $hours);
                }
                $promoted++;
            } catch (HttpException $e) {
                if ($e->errorCode === 'sold_out') {
                    break; // No more room; later guests stay waitlisted in order.
                }
                throw $e;
            }
        }

        return $promoted;
    }

    /** Sends each confirmed guest the single most relevant due reminder (never a stale 24h one after the 1h one). */
    public function sendReminders(): int
    {
        $offsets = array_values(array_unique(array_filter(array_map('intval', (array) $this->settings->get('events.reminder_offsets_minutes', [1440, 60])), static fn (int $o) => $o > 0)));
        if ($offsets === []) {
            return 0;
        }
        rsort($offsets);
        $now = $this->clock->now();
        $rows = $this->db->all(
            "SELECT r.id, e.starts_at FROM event_registrations r JOIN events e ON e.id = r.event_id
             WHERE r.status = 'confirmed' AND e.status = 'published' AND e.starts_at > ? AND e.starts_at <= ?",
            [$now->format('Y-m-d H:i:s'), $now->modify('+' . max($offsets) . ' minutes')->format('Y-m-d H:i:s')],
        );
        $sent = 0;
        foreach ($rows as $r) {
            $minutesUntil = (Clock::utc((string) $r['starts_at'])->getTimestamp() - $now->getTimestamp()) / 60;
            $due = array_values(array_filter($offsets, static fn (int $o) => $minutesUntil <= $o));
            if ($due === []) {
                continue;
            }
            $already = array_map('intval', array_column($this->db->all('SELECT offset_minutes FROM event_reminders_sent WHERE registration_id = ?', [$r['id']]), 'offset_minutes'));
            $smallest = min($due);
            if (in_array($smallest, $already, true)) {
                continue;
            }
            foreach ($due as $offset) {
                $this->db->run('INSERT IGNORE INTO event_reminders_sent (registration_id, offset_minutes, sent_at) VALUES (?, ?, ?)', [$r['id'], $offset, $now->format('Y-m-d H:i:s')]);
            }
            $row = $this->findById((int) $r['id']);
            if ($row !== null) {
                $this->notifications->queue('event_reminder', (string) $this->crypto->decrypt($row['email_enc'], 'event_registrations.email'), $this->emailVars($row), 'event_registration', (int) $r['id']);
                $sent++;
            }
        }

        return $sent;
    }

    // ---------------------------------------------------------------- presentation

    /** @return array<string, mixed> */
    public function presentForClient(array $row): array
    {
        $now = $this->clock->now();
        $hoursUntil = (Clock::utc((string) $row['starts_at'])->getTimestamp() - $now->getTimestamp()) / 3600;
        $payment = $this->db->first(
            "SELECT reference, gateway, status FROM payments WHERE payable_type = 'event_registration' AND payable_id = ? ORDER BY id DESC LIMIT 1",
            [$row['id']],
        );
        $paid = $payment !== null && in_array($payment['status'], ['captured', 'partially_refunded'], true);

        return [
            'reference' => $row['reference'],
            'status' => $row['status'],
            'seats' => (int) $row['seats'],
            'country' => $row['country'] ?? null,
            'name' => $this->crypto->decrypt($row['name_enc'], 'event_registrations.name'),
            'event' => [
                'title' => $row['event_title'],
                'slug' => $row['event_slug'],
                'starts_at' => Clock::iso((string) $row['starts_at']),
                'ends_at' => Clock::iso((string) $row['ends_at']),
                'timezone' => $row['timezone'],
                'venue' => $row['venue'],
                'status' => $row['event_status'],
            ],
            'amount' => [
                'currency' => $row['currency'],
                'subtotal_minor' => (int) $row['subtotal_minor'],
                'tax_minor' => (int) $row['tax_minor'],
                'total_minor' => (int) $row['amount_minor'],
                'tax_label' => $row['tax_label'],
            ],
            'payment' => $payment === null ? null : ['reference' => $payment['reference'], 'gateway' => $payment['gateway'], 'status' => $payment['status']],
            'hold_expires_at' => $row['status'] === 'pending_payment' ? Clock::iso($row['hold_expires_at']) : null,
            'waitlist_position' => $row['status'] === 'waitlisted'
                ? 1 + (int) $this->db->value("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status = 'waitlisted' AND id < ?", [$row['event_id'], $row['id']])
                : null,
            'can_cancel' => in_array($row['status'], self::OPEN_STATUSES, true) && $hoursUntil > 0,
            'refund_eligible' => $row['status'] === 'confirmed' && $paid && $hoursUntil >= (int) $row['cancellation_window_hours'] && (int) $row['refund_on_cancel_percent'] > 0,
            'refund_on_cancel_percent' => (int) $row['refund_on_cancel_percent'],
            'cancellation_window_hours' => (int) $row['cancellation_window_hours'],
            'cancellation_policy' => $row['cancellation_policy'],
        ];
    }

    // ---------------------------------------------------------------- internals

    /** @return array<string, mixed>|null */
    private function findById(int $id): ?array
    {
        return $this->db->first(
            'SELECT r.*, e.title AS event_title, e.slug AS event_slug, e.starts_at, e.ends_at, e.timezone, e.venue, e.status AS event_status,
                    e.cancellation_policy, e.cancellation_window_hours, e.refund_on_cancel_percent, e.tax_label, e.tax_rate_bp, e.tax_inclusive
             FROM event_registrations r JOIN events e ON e.id = r.event_id WHERE r.id = ?',
            [$id],
        );
    }

    /**
     * @param array<string, mixed> $event
     * @return array{0: ?string, 1: array{amount: int, tax: int, total: int}}
     */
    private function priceFor(array $event, ?string $requestedCurrency, int $seats): array
    {
        $prices = array_column($this->prices((int) $event['id']), 'amount_minor', 'currency');
        if ($prices === []) {
            return [null, ['amount' => 0, 'tax' => 0, 'total' => 0]];
        }
        $currency = $requestedCurrency ?? (count($prices) === 1 ? (string) array_key_first($prices) : null);
        if ($currency === null || !isset($prices[$currency])) {
            throw HttpException::validation(['currency' => ['Choose one of: ' . implode(', ', array_keys($prices)) . '.']]);
        }

        return [$currency, BookingService::computeAmounts((int) $prices[$currency] * $seats, (int) $event['tax_rate_bp'], (bool) $event['tax_inclusive'])];
    }

    private function holdUntil(): \DateTimeImmutable
    {
        return $this->clock->now()->modify('+' . (int) $this->config->get('security.payment_hold_minutes') . ' minutes');
    }

    /**
     * Moves a registration to pending_payment with a fresh access link and a seat hold of $hours.
     *
     * @param array<string, mixed> $row
     */
    private function offerSeat(array $row, string $source, ?int $userId, int $hours): void
    {
        $token = Tokens::random(32);
        $this->db->transaction(function () use ($row, $source, $userId, $hours, $token) {
            $event = $this->db->first('SELECT * FROM events WHERE id = ? FOR UPDATE', [$row['event_id']]);
            if ($event['seat_quota'] !== null && $this->seatsTaken((int) $event['id'], (int) $row['id']) + (int) $row['seats'] > (int) $event['seat_quota']) {
                throw HttpException::conflict('sold_out', 'No seat is free to offer. Increase the quota or wait for a cancellation.');
            }
            $prices = array_column($this->prices((int) $event['id']), 'amount_minor', 'currency');
            if ($prices === []) {
                throw HttpException::validation(['status' => ['This gathering is free; confirm the registration instead.']]);
            }
            // Keep the guest's chosen currency while it is still offered.
            $preferred = $row['currency'] && isset($prices[$row['currency']]) ? (string) $row['currency'] : (string) array_key_first($prices);
            [$currency, $amounts] = $this->priceFor($event, $preferred, (int) $row['seats']);
            $hold = $this->clock->now()->modify('+' . max(1, $hours) . ' hours');
            $starts = Clock::utc((string) $event['starts_at']);
            $this->db->update('event_registrations', [
                'status' => 'pending_payment',
                'access_token_hash' => Tokens::hash($token),
                'currency' => $currency,
                'subtotal_minor' => $amounts['amount'],
                'tax_minor' => $amounts['tax'],
                'amount_minor' => $amounts['total'],
                'hold_expires_at' => $hold < $starts ? $hold : $starts,
                'updated_at' => $this->clock->nowString(),
            ], ['id' => $row['id']]);
            $this->history->record('event_registration', (int) $row['id'], (string) $row['status'], 'pending_payment', $source, $userId, $row['status'] === 'waitlisted' ? 'Seat offered from the waitlist.' : 'Payment link issued.');
        });
        $fresh = $this->findById((int) $row['id']);
        $this->notifications->queue(
            $row['status'] === 'waitlisted' ? 'event_waitlist_offer' : 'event_payment_request',
            (string) $this->crypto->decrypt($row['email_enc'], 'event_registrations.email'),
            $this->emailVars($fresh, $token),
            'event_registration',
            (int) $row['id'],
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param bool|null $forceRefund null = apply the event's refund policy
     * @return int refund amount queued, in minor units
     */
    private function cancel(array $row, string $source, ?int $userId, ?string $reason, ?bool $forceRefund, bool $promote = true): int
    {
        $from = (string) $row['status'];
        $now = $this->clock->nowString();
        $this->db->run(
            "UPDATE event_registrations SET status = 'cancelled', hold_expires_at = NULL, cancelled_at = ?, updated_at = ? WHERE id = ? AND status IN ('pending_application','pending_payment','confirmed','waitlisted')",
            [$now, $now, $row['id']],
        );
        $this->history->record('event_registration', (int) $row['id'], $from, 'cancelled', $source, $userId, $reason === null ? null : mb_substr($reason, 0, 250));

        $refundMinor = 0;
        $payment = $this->db->first(
            "SELECT id, amount_minor, refunded_minor FROM payments WHERE payable_type = 'event_registration' AND payable_id = ? AND status IN ('captured','partially_refunded') ORDER BY id DESC LIMIT 1",
            [$row['id']],
        );
        if ($payment !== null) {
            $hoursUntil = (Clock::utc((string) $row['starts_at'])->getTimestamp() - $this->clock->now()->getTimestamp()) / 3600;
            $percent = match (true) {
                $forceRefund === true => 100,
                $forceRefund === false => 0,
                $hoursUntil >= (int) $row['cancellation_window_hours'] => (int) $row['refund_on_cancel_percent'],
                default => 0,
            };
            $refundMinor = (int) floor(((int) $payment['amount_minor'] - (int) $payment['refunded_minor']) * $percent / 100);
            if ($refundMinor > 0) {
                $this->jobs->push('payment.refund', [
                    'payment_id' => (int) $payment['id'],
                    'amount_minor' => $refundMinor,
                    'reason' => 'Cancellation of ' . $row['reference'],
                    'user_id' => $userId,
                ], 'payment.refund:event-cancel:' . $row['id']);
            }
        }

        $this->notifications->cancelFor('event_registration', (int) $row['id'], 'event_payment_request');
        $this->notifications->queue('event_registration_cancelled', (string) $this->crypto->decrypt($row['email_enc'], 'event_registrations.email'), $this->emailVars($row) + [
            'refund_amount' => $refundMinor > 0 ? TimeFormatter::money($refundMinor, (string) $row['currency']) : '',
            'reason' => (string) ($reason ?? ''),
        ], 'event_registration', (int) $row['id']);
        if ($userId !== null) {
            $this->audit->record($userId, 'event_registration.cancelled', 'event_registration', (int) $row['id'], ['refund_minor' => $refundMinor]);
        }
        if ($promote && in_array($from, ['confirmed', 'pending_payment'], true)) {
            $this->promoteWaitlist((int) $row['event_id']);
        }

        return $refundMinor;
    }

    /** @return array<string, string> */
    private function emailVars(array $row, ?string $accessToken = null): array
    {
        $manage = $this->config->get('app.frontend_url') . '/gatherings/registration/' . $row['reference'];

        return [
            'name' => (string) $this->crypto->decrypt($row['name_enc'], 'event_registrations.name'),
            'reference' => (string) $row['reference'],
            'event_title' => (string) $row['event_title'],
            'starts_at_local' => TimeFormatter::forClient((string) $row['starts_at'], (string) $row['timezone']),
            'venue' => (string) ($row['venue'] ?? ''),
            'seats' => (string) $row['seats'],
            'amount' => (int) $row['amount_minor'] > 0 && $row['currency'] ? TimeFormatter::money((int) $row['amount_minor'], (string) $row['currency']) : '',
            'hold_expires_local' => $row['hold_expires_at'] ? TimeFormatter::forClient((string) $row['hold_expires_at'], (string) $row['timezone']) : '',
            'payment_url' => $accessToken ? $manage . '#access=' . $accessToken : $manage,
            'manage_url' => $accessToken ? $manage . '#access=' . $accessToken : $manage,
            'cancellation_policy' => (string) ($row['cancellation_policy'] ?? ''),
        ];
    }
}
