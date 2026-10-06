<?php

declare(strict_types=1);

namespace App\Integrations\Google;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Security\Crypto;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Google OAuth 2.0 (web server flow) + Calendar API v3 over REST.
 * Tokens are stored encrypted in `calendar_integrations`, one connection per environment.
 */
final class GoogleCalendarClient
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    private const API = 'https://www.googleapis.com/calendar/v3/';

    public function __construct(
        private ClientInterface $http,
        private Config $config,
        private Database $db,
        private Crypto $crypto,
        private Clock $clock,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->config->get('google.client_id') !== '' && $this->config->get('google.client_secret') !== '' && $this->config->get('google.redirect_uri') !== '';
    }

    /** @return array<string, mixed>|null */
    public function integration(): ?array
    {
        return $this->db->first(
            "SELECT * FROM calendar_integrations WHERE provider = 'google' AND environment = ?",
            [$this->config->environment()],
        );
    }

    public function isConnected(): bool
    {
        return ($this->integration()['status'] ?? null) === 'connected';
    }

    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $this->config->get('google.client_id'),
            'redirect_uri' => $this->config->get('google.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', $this->config->get('google.scopes')),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    public function connect(string $code, int $userId): string
    {
        $tokens = $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->config->get('google.redirect_uri')]);
        if (empty($tokens['refresh_token'])) {
            throw new GoogleApiError('Google did not return a refresh token. Remove the app from your Google account permissions and connect again.');
        }
        $email = $this->emailFromIdToken((string) ($tokens['id_token'] ?? ''));
        $now = $this->clock->nowString();

        $this->db->run(
            "INSERT INTO calendar_integrations
                (provider, environment, account_email, calendar_id, access_token_enc, refresh_token_enc, token_expires_at, scopes, status, connected_by, last_error, created_at, updated_at)
             VALUES ('google', :env, :email, :calendar, :access, :refresh, :expires, :scopes, 'connected', :user, NULL, :now1, :now2)
             ON DUPLICATE KEY UPDATE account_email = VALUES(account_email), calendar_id = VALUES(calendar_id), access_token_enc = VALUES(access_token_enc),
                refresh_token_enc = VALUES(refresh_token_enc), token_expires_at = VALUES(token_expires_at), scopes = VALUES(scopes),
                status = 'connected', connected_by = VALUES(connected_by), last_error = NULL, updated_at = VALUES(updated_at)",
            [
                'env' => $this->config->environment(),
                'email' => $email,
                'calendar' => $this->config->get('google.calendar_id', 'primary'),
                'access' => $this->crypto->encrypt((string) $tokens['access_token'], 'calendar_integrations.access_token'),
                'refresh' => $this->crypto->encrypt((string) $tokens['refresh_token'], 'calendar_integrations.refresh_token'),
                'expires' => $this->clock->now()->modify('+' . (int) $tokens['expires_in'] . ' seconds')->format('Y-m-d H:i:s'),
                'scopes' => (string) ($tokens['scope'] ?? ''),
                'user' => $userId,
                'now1' => $now,
                'now2' => $now,
            ],
        );

        return $email;
    }

    public function disconnect(): void
    {
        $integration = $this->integration();
        if ($integration === null) {
            return;
        }
        $refresh = $this->crypto->decrypt($integration['refresh_token_enc'], 'calendar_integrations.refresh_token');
        if ($refresh) {
            try {
                $this->http->request('POST', self::REVOKE_URL, ['form_params' => ['token' => $refresh]]);
            } catch (GuzzleException) {
                // Revocation is best-effort; local tokens are destroyed regardless.
            }
        }
        $this->db->update('calendar_integrations', [
            'access_token_enc' => null,
            'refresh_token_enc' => null,
            'token_expires_at' => null,
            'status' => 'disconnected',
            'updated_at' => $this->clock->nowString(),
        ], ['id' => $integration['id']]);
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
            $body['conferenceData'] = ['createRequest' => [
                'requestId' => $event['reference'],
                'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
            ]];
        }

        $calendar = rawurlencode((string) ($this->integration()['calendar_id'] ?? 'primary'));
        [$status, $data] = $this->api('POST', "calendars/{$calendar}/events?conferenceDataVersion=1&sendUpdates=all", $body);
        if ($status === 409) {
            // Already created by a previous attempt — fetch instead of duplicating.
            [$status, $data] = $this->api('GET', "calendars/{$calendar}/events/{$id}");
        }
        if ($status >= 400) {
            throw new GoogleApiError('Calendar event creation failed (' . $status . '): ' . $this->errorMessage($data));
        }

        return ['id' => (string) $data['id'], 'meet_url' => $this->meetUrl($data)];
    }

    /** @return array{id: string, meet_url: ?string} */
    public function updateEventTime(string $eventId, string $startsAt, string $endsAt, string $timezone): array
    {
        $calendar = rawurlencode((string) ($this->integration()['calendar_id'] ?? 'primary'));
        [$status, $data] = $this->api('PATCH', "calendars/{$calendar}/events/" . rawurlencode($eventId) . '?conferenceDataVersion=1&sendUpdates=all', [
            'start' => ['dateTime' => $startsAt, 'timeZone' => $timezone],
            'end' => ['dateTime' => $endsAt, 'timeZone' => $timezone],
            'status' => 'confirmed',
        ]);
        if ($status >= 400) {
            throw new GoogleApiError('Calendar event update failed (' . $status . '): ' . $this->errorMessage($data));
        }

        return ['id' => (string) $data['id'], 'meet_url' => $this->meetUrl($data)];
    }

    public function cancelEvent(string $eventId): void
    {
        $calendar = rawurlencode((string) ($this->integration()['calendar_id'] ?? 'primary'));
        [$status, $data] = $this->api('DELETE', "calendars/{$calendar}/events/" . rawurlencode($eventId) . '?sendUpdates=all');
        if ($status >= 400 && !in_array($status, [404, 410], true)) {
            throw new GoogleApiError('Calendar event cancellation failed (' . $status . '): ' . $this->errorMessage($data));
        }
    }

    public function eventId(string $reference): string
    {
        // Google event ids allow base32hex characters (a–v, 0–9); hex is a safe subset.
        return 'pa' . substr(hash('sha256', $this->config->environment() . '|' . $reference), 0, 40);
    }

    private function accessToken(): string
    {
        $integration = $this->integration();
        if ($integration === null || $integration['status'] !== 'connected') {
            throw new GoogleApiError('Google Calendar is not connected.');
        }
        $expires = $integration['token_expires_at'] ? Clock::utc((string) $integration['token_expires_at']) : null;
        if ($expires !== null && $expires > $this->clock->now()->modify('+2 minutes')) {
            return (string) $this->crypto->decrypt($integration['access_token_enc'], 'calendar_integrations.access_token');
        }

        $refresh = (string) $this->crypto->decrypt($integration['refresh_token_enc'], 'calendar_integrations.refresh_token');
        try {
            $tokens = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
        } catch (GoogleApiError $e) {
            if (str_contains($e->getMessage(), 'invalid_grant')) {
                $this->db->update('calendar_integrations', ['status' => 'needs_reauth', 'last_error' => 'Authorization revoked or expired.', 'updated_at' => $this->clock->nowString()], ['id' => $integration['id']]);
            }
            throw $e;
        }

        $this->db->update('calendar_integrations', [
            'access_token_enc' => $this->crypto->encrypt((string) $tokens['access_token'], 'calendar_integrations.access_token'),
            'token_expires_at' => $this->clock->now()->modify('+' . (int) $tokens['expires_in'] . ' seconds')->format('Y-m-d H:i:s'),
            'updated_at' => $this->clock->nowString(),
        ], ['id' => $integration['id']]);

        return (string) $tokens['access_token'];
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function tokenRequest(array $params): array
    {
        try {
            $response = $this->http->request('POST', self::TOKEN_URL, ['form_params' => $params + [
                'client_id' => $this->config->get('google.client_id'),
                'client_secret' => $this->config->get('google.client_secret'),
            ]]);
        } catch (GuzzleException $e) {
            throw new GoogleApiError('Google OAuth is unreachable.', 0, $e);
        }
        $data = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() >= 400 || !is_array($data) || empty($data['access_token'])) {
            $error = is_array($data) ? (string) ($data['error'] ?? 'unknown') : 'invalid_response';
            throw new GoogleApiError("Google OAuth token request failed: {$error}");
        }

        return $data;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function api(string $method, string $path, ?array $body = null): array
    {
        $options = ['headers' => ['Authorization' => 'Bearer ' . $this->accessToken()]];
        if ($body !== null) {
            $options['json'] = $body;
        }
        try {
            $response = $this->http->request($method, self::API . $path, $options);
        } catch (GuzzleException $e) {
            throw new GoogleApiError('Google Calendar is unreachable.', 0, $e);
        }
        $data = json_decode((string) $response->getBody(), true);
        $this->db->run("UPDATE calendar_integrations SET last_synced_at = ? WHERE provider = 'google' AND environment = ?", [$this->clock->nowString(), $this->config->environment()]);

        return [$response->getStatusCode(), is_array($data) ? $data : []];
    }

    /** @param array<string, mixed> $event */
    private function meetUrl(array $event): ?string
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

    /** @param array<string, mixed> $data */
    private function errorMessage(array $data): string
    {
        return mb_substr((string) ($data['error']['message'] ?? 'unknown error'), 0, 180);
    }

    private function emailFromIdToken(string $idToken): ?string
    {
        // The ID token comes straight from Google's token endpoint over TLS, so its payload is trusted here.
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);

        return is_array($payload) && isset($payload['email']) ? (string) $payload['email'] : null;
    }
}
