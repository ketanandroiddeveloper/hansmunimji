<?php

declare(strict_types=1);

namespace App\Integrations\Google;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Jobs\JobQueue;
use App\Security\Crypto;
use App\Services\IntegrationLog;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * The single Google OAuth 2.0 (web server flow) connection shared by Calendar, Meet and Gmail.
 * Tokens are stored encrypted in `calendar_integrations`, one connection per environment, and
 * never leave this class except as an Authorization header on requests to Google.
 */
final class GoogleAccount
{
    public const SCOPE_CALENDAR = 'https://www.googleapis.com/auth/calendar.events';
    public const SCOPE_GMAIL = 'https://www.googleapis.com/auth/gmail.send';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /** Failures that affect every request until an administrator acts, so they are surfaced on the integration. */
    private const ACCOUNT_ERRORS = ['insufficient_scope', 'api_disabled', 'unauthorized', 'calendar_not_found', 'invalid_client'];

    public function __construct(
        private ClientInterface $http,
        private Config $config,
        private Database $db,
        private Crypto $crypto,
        private Clock $clock,
        private IntegrationLog $log,
        private JobQueue $jobs,
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

    public function status(): string
    {
        return (string) ($this->integration()['status'] ?? 'disconnected');
    }

    public function isConnected(): bool
    {
        return $this->status() === 'connected';
    }

    /** @return list<string> */
    public function grantedScopes(?array $integration = null): array
    {
        $integration ??= $this->integration();

        return array_values(array_filter(explode(' ', (string) ($integration['scopes'] ?? ''))));
    }

    public function hasScope(string $scope): bool
    {
        $integration = $this->integration();

        return ($integration['status'] ?? null) === 'connected' && in_array($scope, $this->grantedScopes($integration), true);
    }

    /**
     * API scopes this environment requests but the connection was not granted (Google lets users
     * untick individual permissions on the consent screen).
     *
     * @return list<string>
     */
    public function missingScopes(?array $integration = null): array
    {
        $granted = $this->grantedScopes($integration);
        $required = array_filter((array) $this->config->get('google.scopes', []), static fn (string $s) => str_starts_with($s, 'https://www.googleapis.com/auth/') && !str_contains($s, 'userinfo'));

        return array_values(array_diff($required, $granted));
    }

    public function authorizationUrl(string $state): string
    {
        $params = [
            'client_id' => $this->config->get('google.client_id'),
            'redirect_uri' => $this->config->get('google.redirect_uri'),
            'response_type' => 'code',
            // Google appends iss=https://accounts.google.com and full scope URLs to the return, and shared-host
            // firewalls (ModSecurity) reject query values starting with https://. In the fragment they never
            // reach the server; the callback page relays only code and state.
            'response_mode' => 'fragment',
            'scope' => implode(' ', (array) $this->config->get('google.scopes')),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ];
        if ((string) $this->config->get('google.account_email') !== '') {
            $params['login_hint'] = (string) $this->config->get('google.account_email');
        }

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchanges the authorization code and stores the tokens encrypted.
     *
     * @return array{email: ?string, missing_scopes: list<string>}
     */
    public function connect(string $code, int $userId): array
    {
        try {
            $tokens = $this->tokenRequest('oauth.connect', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->config->get('google.redirect_uri')]);
        } catch (GoogleApiError $e) {
            // At the code exchange, invalid_grant means the code expired, was reused or the redirect URI differs.
            throw $e->category === 'revoked' ? new GoogleApiError('invalid_code', null, $e->httpStatus, $e) : $e;
        }
        if (empty($tokens['refresh_token'])) {
            $this->revoke((string) $tokens['access_token']);
            throw new GoogleApiError('no_refresh_token');
        }

        $email = $this->verifiedEmail((string) ($tokens['id_token'] ?? ''));
        $expected = strtolower((string) $this->config->get('google.account_email'));
        if ($expected !== '' && strtolower((string) $email) !== $expected) {
            $this->revoke((string) $tokens['refresh_token']);
            $this->log->record('google', 'oauth.connect', false, null, 'wrong_account');
            throw new GoogleApiError('wrong_account');
        }

        $now = $this->clock->nowString();
        $this->db->run(
            "INSERT INTO calendar_integrations
                (provider, environment, account_email, calendar_id, access_token_enc, refresh_token_enc, token_expires_at, scopes, status, connected_by, last_error, last_synced_at, created_at, updated_at)
             VALUES ('google', :env, :email, :calendar, :access, :refresh, :expires, :scopes, 'connected', :user, NULL, :synced, :now1, :now2)
             ON DUPLICATE KEY UPDATE account_email = VALUES(account_email), calendar_id = VALUES(calendar_id), access_token_enc = VALUES(access_token_enc),
                refresh_token_enc = VALUES(refresh_token_enc), token_expires_at = VALUES(token_expires_at), scopes = VALUES(scopes),
                status = 'connected', connected_by = VALUES(connected_by), last_error = NULL, last_synced_at = VALUES(last_synced_at), updated_at = VALUES(updated_at)",
            [
                'env' => $this->config->environment(),
                'email' => $email,
                'calendar' => $this->config->get('google.calendar_id', 'primary'),
                'access' => $this->crypto->encrypt((string) $tokens['access_token'], 'calendar_integrations.access_token'),
                'refresh' => $this->crypto->encrypt((string) $tokens['refresh_token'], 'calendar_integrations.refresh_token'),
                'expires' => $this->expiry($tokens),
                'scopes' => mb_substr((string) ($tokens['scope'] ?? ''), 0, 500),
                'user' => $userId,
                'synced' => $now,
                'now1' => $now,
                'now2' => $now,
            ],
        );

        return ['email' => $email, 'missing_scopes' => $this->missingScopes()];
    }

    public function disconnect(): void
    {
        $integration = $this->integration();
        if ($integration === null) {
            return;
        }
        $refresh = $this->crypto->decrypt($integration['refresh_token_enc'], 'calendar_integrations.refresh_token');
        if ($refresh) {
            $this->revoke((string) $refresh);
        }
        $this->db->update('calendar_integrations', [
            'access_token_enc' => null,
            'refresh_token_enc' => null,
            'token_expires_at' => null,
            'status' => 'disconnected',
            'last_error' => null,
            'updated_at' => $this->clock->nowString(),
        ], ['id' => $integration['id']]);
    }

    /**
     * Sends an authorised request to a Google API, refreshing the access token when needed and
     * retrying once on 401. Every call is recorded in the integration log (without content).
     *
     * @param array<string, mixed> $options Guzzle request options
     * @param list<int> $allowStatuses error statuses the caller handles itself
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function call(string $operation, string $scope, string $method, string $url, array $options = [], ?string $reference = null, array $allowStatuses = []): array
    {
        $started = microtime(true);
        $this->requireScope($scope, $operation, $reference);

        $attempt = 0;
        do {
            $options['headers']['Authorization'] = 'Bearer ' . $this->accessToken($attempt > 0);
            try {
                $response = $this->http->request($method, $url, $options);
            } catch (GuzzleException $e) {
                $this->log->record('google', $operation, false, null, 'network', $reference, $started);
                throw new GoogleApiError('network', null, null, $e);
            }
            $status = $response->getStatusCode();
        } while ($status === 401 && ++$attempt === 1);

        $data = json_decode((string) $response->getBody(), true);
        $data = is_array($data) ? $data : [];

        if ($status >= 400 && !in_array($status, $allowStatuses, true)) {
            $category = GoogleApiError::categorize($status, $data);
            $this->log->record('google', $operation, false, $status, $category, $reference, $started);
            if (in_array($category, self::ACCOUNT_ERRORS, true)) {
                $this->recordError(GoogleApiError::MESSAGES[$category]);
            }
            throw new GoogleApiError($category, null, $status);
        }

        $this->log->record('google', $operation, true, $status, null, $reference, $started);
        $this->db->run(
            "UPDATE calendar_integrations SET last_synced_at = ?, last_error = NULL WHERE provider = 'google' AND environment = ?",
            [$this->clock->nowString(), $this->config->environment()],
        );

        return [$status, $data];
    }

    public function accountEmail(): ?string
    {
        $email = $this->integration()['account_email'] ?? null;

        return $email !== null ? (string) $email : null;
    }

    private function requireScope(string $scope, string $operation, ?string $reference): void
    {
        $integration = $this->integration();
        $status = $integration['status'] ?? 'disconnected';
        if ($status !== 'connected') {
            $category = $status === 'needs_reauth' ? 'revoked' : ($this->isConfigured() ? 'not_connected' : 'not_configured');
            $this->log->record('google', $operation, false, null, $category, $reference);
            throw new GoogleApiError($category);
        }
        if (!in_array($scope, $this->grantedScopes($integration), true)) {
            $this->log->record('google', $operation, false, null, 'insufficient_scope', $reference);
            throw new GoogleApiError('insufficient_scope');
        }
    }

    private function accessToken(bool $forceRefresh = false): string
    {
        $integration = $this->integration() ?? throw new GoogleApiError('not_connected');
        $expires = $integration['token_expires_at'] ? Clock::utc((string) $integration['token_expires_at']) : null;
        if (!$forceRefresh && $expires !== null && $expires > $this->clock->now()->modify('+2 minutes')) {
            return (string) $this->crypto->decrypt($integration['access_token_enc'], 'calendar_integrations.access_token');
        }

        $refresh = (string) $this->crypto->decrypt($integration['refresh_token_enc'], 'calendar_integrations.refresh_token');
        try {
            $tokens = $this->tokenRequest('oauth.refresh', ['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
        } catch (GoogleApiError $e) {
            if ($e->category === 'revoked') {
                $this->markNeedsReauth((int) $integration['id']);
            } elseif ($e->category === 'invalid_client') {
                $this->recordError(GoogleApiError::MESSAGES['invalid_client']);
            }
            throw $e;
        }

        $update = [
            'access_token_enc' => $this->crypto->encrypt((string) $tokens['access_token'], 'calendar_integrations.access_token'),
            'token_expires_at' => $this->expiry($tokens),
            'updated_at' => $this->clock->nowString(),
        ];
        // A refresh reports the scopes still granted, so individually revoked permissions show up here.
        if (!empty($tokens['scope'])) {
            $update['scopes'] = mb_substr((string) $tokens['scope'], 0, 500);
        }
        $this->db->update('calendar_integrations', $update, ['id' => $integration['id']]);

        return (string) $tokens['access_token'];
    }

    private function markNeedsReauth(int $integrationId): void
    {
        $changed = $this->db->run(
            "UPDATE calendar_integrations SET status = 'needs_reauth', last_error = ?, updated_at = ? WHERE id = ? AND status = 'connected'",
            [GoogleApiError::MESSAGES['revoked'], $this->clock->nowString(), $integrationId],
        )->rowCount();
        if ($changed > 0) {
            // Queued rather than sent here: when Gmail is the mail driver, the mailer depends on this class.
            $this->jobs->push('google.reauth_alert', ['environment' => $this->config->environment()], 'google.reauth_alert');
        }
    }

    private function recordError(string $message): void
    {
        $this->db->run(
            "UPDATE calendar_integrations SET last_error = ? WHERE provider = 'google' AND environment = ?",
            [mb_substr($message, 0, 255), $this->config->environment()],
        );
    }

    private function revoke(string $token): void
    {
        $started = microtime(true);
        try {
            $response = $this->http->request('POST', self::REVOKE_URL, ['form_params' => ['token' => $token]]);
            $this->log->record('google', 'oauth.revoke', $response->getStatusCode() < 400, $response->getStatusCode(), $response->getStatusCode() < 400 ? null : 'unknown', null, $started);
        } catch (GuzzleException) {
            // Revocation is best-effort; local tokens are destroyed regardless.
            $this->log->record('google', 'oauth.revoke', false, null, 'network', null, $started);
        }
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function tokenRequest(string $operation, array $params): array
    {
        if (!$this->isConfigured()) {
            throw new GoogleApiError('not_configured');
        }
        $started = microtime(true);
        try {
            $response = $this->http->request('POST', self::TOKEN_URL, ['form_params' => $params + [
                'client_id' => $this->config->get('google.client_id'),
                'client_secret' => $this->config->get('google.client_secret'),
            ]]);
        } catch (GuzzleException $e) {
            $this->log->record('google', $operation, false, null, 'network', null, $started);
            throw new GoogleApiError('network', null, null, $e);
        }
        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);
        if ($status >= 400 || !is_array($data) || empty($data['access_token'])) {
            $category = is_array($data) ? GoogleApiError::categorizeOAuth((string) ($data['error'] ?? '')) : 'unknown';
            if ($category === 'unknown' && $status >= 500) {
                $category = 'server_error';
            }
            $this->log->record('google', $operation, false, $status, $category, null, $started);
            throw new GoogleApiError($category, null, $status);
        }
        $this->log->record('google', $operation, true, $status, null, null, $started);

        return $data;
    }

    /** @param array<string, mixed> $tokens */
    private function expiry(array $tokens): string
    {
        return $this->clock->now()->modify('+' . max(60, (int) ($tokens['expires_in'] ?? 3600)) . ' seconds')->format('Y-m-d H:i:s');
    }

    private function verifiedEmail(string $idToken): ?string
    {
        // The ID token comes straight from Google's token endpoint over TLS, so its payload is trusted here.
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!is_array($payload) || !isset($payload['email']) || ($payload['email_verified'] ?? true) === false) {
            return null;
        }

        return (string) $payload['email'];
    }
}
