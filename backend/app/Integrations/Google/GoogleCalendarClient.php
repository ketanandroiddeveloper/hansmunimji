<?php

declare(strict_types=1);

namespace App\Integrations\Google;

use App\Core\Clock;
use App\Core\Config;
use App\Services\IntegrationLog;

/**
 * Google Calendar API v3 over REST, with Meet conferences created through `conferenceData`
 * (the only supported way to obtain a Meet link). Authorisation lives in GoogleAccount.
 */
final class GoogleCalendarClient
{
    private const API = 'https://www.googleapis.com/calendar/v3/';
    private const TEST_PROPERTY = 'pa_integration_test';

    public function __construct(
        private GoogleAccount $account,
        private Config $config,
        private Clock $clock,
        private IntegrationLog $log,
    ) {
    }

    public function isReady(): bool
    {
        return $this->account->hasScope(GoogleAccount::SCOPE_CALENDAR);
    }

    /**
     * Creates an event; returns ['id' => …, 'meet_url' => …]. Idempotent: the event id is
     * derived from the booking reference and Meet creation is keyed by requestId.
     *
     * @param array{reference: string, summary: string, description: string, starts_at: string, ends_at: string, timezone: string, attendee_email: string, attendee_name: string, with_meet: bool, location?: string|null, reminders: list<int>} $event
     * @return array{id: string, meet_url: ?string}
     */
    public function createEvent(array $event): array
    {
        $id = $this->eventId($event['reference']);
        $body = [
            'id' => $id,
            'summary' => $event['summary'],
            'description' => $event['description'],
            'start' => ['dateTime' => $event['starts_at'], 'timeZone' => $event['timezone']],
            'end' => ['dateTime' => $event['ends_at'], 'timeZone' => $event['timezone']],
            'attendees' => [['email' => $event['attendee_email'], 'displayName' => $event['attendee_name']]],
            'visibility' => 'private',
            'guestsCanSeeOtherGuests' => false,
            'guestsCanInviteOthers' => false,
            'reminders' => [
                'useDefault' => false,
                'overrides' => array_map(static fn (int $m) => ['method' => 'popup', 'minutes' => $m], array_slice($event['reminders'], 0, 5)),
            ],
        ];
        if (!empty($event['location'])) {
            $body['location'] = $event['location'];
        }
        if ($event['with_meet']) {
            $body['conferenceData'] = self::conferenceRequest($event['reference']);
        }

        $ref = $event['reference'];
        [$status, $data] = $this->request('calendar.create', 'POST', $this->events('?conferenceDataVersion=1&sendUpdates=all'), $body, $ref, [409]);
        if ($status === 409) {
            // Already created by a previous attempt — fetch instead of duplicating.
            [, $data] = $this->request('calendar.get', 'GET', $this->events('/' . $id), null, $ref);
        }
        if ($event['with_meet']) {
            $data = $this->ensureConference($data, $ref);
        }

        return ['id' => (string) $data['id'], 'meet_url' => self::meetUrl($data)];
    }

    /** @return array{id: string, meet_url: ?string} */
    public function updateEventTime(string $eventId, string $startsAt, string $endsAt, string $timezone, ?string $reference = null): array
    {
        [, $data] = $this->request('calendar.update', 'PATCH', $this->events('/' . rawurlencode($eventId) . '?conferenceDataVersion=1&sendUpdates=all'), [
            'start' => ['dateTime' => $startsAt, 'timeZone' => $timezone],
            'end' => ['dateTime' => $endsAt, 'timeZone' => $timezone],
            'status' => 'confirmed',
        ], $reference);

        return ['id' => (string) $data['id'], 'meet_url' => self::meetUrl($data)];
    }

    public function cancelEvent(string $eventId, ?string $reference = null): void
    {
        // 404/410: already removed in Google Calendar, which is the desired end state.
        $this->request('calendar.cancel', 'DELETE', $this->events('/' . rawurlencode($eventId) . '?sendUpdates=all'), null, $reference, [404, 410]);
    }

    public function eventId(string $reference): string
    {
        // Google event ids allow base32hex characters (a–v, 0–9); hex is a safe subset.
        return 'pa' . substr(hash('sha256', $this->config->environment() . '|' . $reference), 0, 40);
    }

    /**
     * Creates a private, non-blocking test event with no guests (and, for Meet, a conference),
     * verifies it, then deletes it without notifying anyone. Never touches booking events.
     *
     * @return array{event_created: bool, meet_created: ?bool, cleaned_up: bool}
     */
    public function runTest(bool $withMeet): array
    {
        $operation = $withMeet ? 'test.meet' : 'test.calendar';
        $id = 'patest' . bin2hex(random_bytes(10));
        $start = $this->clock->now()->modify('+1 day')->setTime((int) $this->clock->now()->format('H'), 0);
        $body = [
            'id' => $id,
            'summary' => 'Integration test (safe to delete)',
            'description' => 'Created by the Google Workspace test in the administration panel and removed automatically.',
            'start' => ['dateTime' => $start->format('Y-m-d\TH:i:s\Z'), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $start->modify('+15 minutes')->format('Y-m-d\TH:i:s\Z'), 'timeZone' => 'UTC'],
            'visibility' => 'private',
            'transparency' => 'transparent',
            'reminders' => ['useDefault' => false, 'overrides' => []],
            'extendedProperties' => ['private' => [self::TEST_PROPERTY => '1']],
        ];
        if ($withMeet) {
            $body['conferenceData'] = self::conferenceRequest($id);
        }

        [, $data] = $this->request($operation, 'POST', $this->events('?conferenceDataVersion=1&sendUpdates=none'), $body, 'test');
        $meet = null;
        $meetError = null;
        if ($withMeet) {
            try {
                $data = $this->ensureConference($data, 'test');
                $meet = true;
            } catch (GoogleApiError $e) {
                $meet = false;
                $meetError = $e;
            }
        }

        $cleaned = true;
        try {
            $this->request('test.cleanup', 'DELETE', $this->events('/' . rawurlencode((string) $data['id']) . '?sendUpdates=none'), null, 'test', [404, 410]);
        } catch (GoogleApiError) {
            $cleaned = false;
        }
        if ($meetError !== null) {
            throw $meetError;
        }

        return ['event_created' => true, 'meet_created' => $meet, 'cleaned_up' => $cleaned];
    }

    /** Deletes any leftover test events (found by their private marker); returns how many were removed. */
    public function cleanupTestEvents(): int
    {
        $query = http_build_query(['privateExtendedProperty' => self::TEST_PROPERTY . '=1', 'maxResults' => 50, 'showDeleted' => 'false', 'singleEvents' => 'true']);
        [, $data] = $this->request('test.cleanup_list', 'GET', $this->events('?' . $query), null, 'test');
        $removed = 0;
        foreach ((array) ($data['items'] ?? []) as $item) {
            if (($item['extendedProperties']['private'][self::TEST_PROPERTY] ?? null) !== '1') {
                continue;
            }
            $this->request('test.cleanup', 'DELETE', $this->events('/' . rawurlencode((string) $item['id']) . '?sendUpdates=none'), null, 'test', [404, 410]);
            $removed++;
        }

        return $removed;
    }

    /**
     * Meet conferences can be created asynchronously; waits briefly for a pending request and asks
     * again (with a fresh requestId) when Google reports a failure.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function ensureConference(array $event, string $reference): array
    {
        $event = $this->awaitConference($event, $reference);
        if (self::meetUrl($event) !== null) {
            $this->log->record('google', 'calendar.meet', true, null, null, $reference);

            return $event;
        }
        [, $event] = $this->request(
            'calendar.meet_retry',
            'PATCH',
            $this->events('/' . rawurlencode((string) $event['id']) . '?conferenceDataVersion=1&sendUpdates=none'),
            ['conferenceData' => self::conferenceRequest($reference . '-' . bin2hex(random_bytes(4)))],
            $reference,
        );
        $event = $this->awaitConference($event, $reference);
        $created = self::meetUrl($event) !== null;
        $this->log->record('google', 'calendar.meet', $created, null, $created ? null : 'meet_failed', $reference);
        if (!$created) {
            throw new GoogleApiError('meet_failed');
        }

        return $event;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function awaitConference(array $event, string $reference): array
    {
        for ($i = 0; $i < 3 && self::meetUrl($event) === null && ($event['conferenceData']['createRequest']['status']['statusCode'] ?? null) === 'pending'; $i++) {
            usleep(800_000);
            [, $event] = $this->request('calendar.get', 'GET', $this->events('/' . rawurlencode((string) $event['id'])), null, $reference);
        }

        return $event;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param list<int> $allow
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function request(string $operation, string $method, string $url, ?array $body, ?string $reference, array $allow = []): array
    {
        return $this->account->call($operation, GoogleAccount::SCOPE_CALENDAR, $method, $url, $body !== null ? ['json' => $body] : [], $reference, $allow);
    }

    private function events(string $suffix): string
    {
        $calendar = (string) ($this->account->integration()['calendar_id'] ?? $this->config->get('google.calendar_id', 'primary'));

        return self::API . 'calendars/' . rawurlencode($calendar) . '/events' . $suffix;
    }

    /** @return array{createRequest: array{requestId: string, conferenceSolutionKey: array{type: string}}} */
    private static function conferenceRequest(string $requestId): array
    {
        return ['createRequest' => ['requestId' => $requestId, 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]];
    }

    /** @param array<string, mixed> $event */
    public static function meetUrl(array $event): ?string
    {
        if (!empty($event['hangoutLink'])) {
            return (string) $event['hangoutLink'];
        }
        foreach ($event['conferenceData']['entryPoints'] ?? [] as $entry) {
            if (($entry['entryPointType'] ?? '') === 'video') {
                return (string) $entry['uri'];
            }
        }

        return null;
    }
}
