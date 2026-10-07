<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Integrations\Email\GmailApiMailer;
use App\Integrations\Email\Mailer;
use App\Integrations\Google\GoogleAccount;
use App\Integrations\Google\GoogleApiError;
use App\Integrations\Google\GoogleCalendarClient;
use App\Integrations\Payments\GatewayRegistry;
use App\Jobs\JobQueue;
use App\Security\AuditLogger;
use App\Security\SignedUrl;
use App\Security\Tokens;
use App\Services\IntegrationLog;
use App\Services\SettingsService;

/**
 * Integration status and the Google OAuth connection. Credentials themselves are environment-managed
 * and never returned — only whether each integration is configured and in which mode.
 */
final class IntegrationsController extends Controller
{
    private const CALENDAR_OPERATIONS = ['calendar.create', 'calendar.get', 'calendar.update', 'calendar.cancel', 'test.calendar', 'test.meet', 'test.cleanup', 'test.cleanup_list'];

    public function __construct(
        private Config $config,
        private Database $db,
        private Clock $clock,
        private GoogleAccount $google,
        private GoogleCalendarClient $calendar,
        private GmailApiMailer $gmail,
        private IntegrationLog $integrationLog,
        private SettingsService $settings,
        private GatewayRegistry $gateways,
        private Mailer $mailer,
        private SignedUrl $signer,
        private AuditLogger $audit,
        private JobQueue $jobs,
        private Logger $logger,
    ) {
    }

    public function index(Request $request): Response
    {
        $apiBase = rtrim((string) $this->config->get('app.url'), '/') . '/api/v1';
        $payments = [];
        foreach ($this->gateways->all() as $name => $gateway) {
            $payments[$name] = [
                'configured' => $gateway->isConfigured(),
                'mode' => $this->paymentMode($name),
                'currencies' => $gateway->supportedCurrencies(),
                'webhook_url' => "{$apiBase}/payments/webhook/{$name}",
            ];
        }

        return $this->ok([
            'environment' => $this->config->environment(),
            'google' => $this->googleStatus(),
            'payments' => $payments,
            'email' => ['configured' => $this->mailer->isConfigured(), 'driver' => $this->config->get('mail.driver')],
            'frontend_rebuild' => ['configured' => (string) $this->config->get('app.rebuild_hook_url') !== ''],
            'queues' => [
                'jobs_pending' => (int) $this->db->value("SELECT COUNT(*) FROM jobs WHERE status IN ('pending','reserved')"),
                'jobs_failed' => (int) $this->db->value("SELECT COUNT(*) FROM jobs WHERE status = 'failed'"),
                'emails_queued' => (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE status IN ('queued','sending')"),
                'emails_failed' => (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE status = 'failed'"),
            ],
            'failed_jobs' => $this->db->all("SELECT id, type, attempts, last_error, finished_at FROM jobs WHERE status = 'failed' ORDER BY id DESC LIMIT 20"),
        ]);
    }

    public function googleConnect(Request $request): Response
    {
        if (!$this->google->isConfigured()) {
            throw HttpException::conflict('not_configured', 'Google OAuth credentials are not configured for this environment. See docs/integrations.md.');
        }
        $session = $request->attribute('session');
        // SameSite=Strict session cookies are not sent on the cross-site redirect back from Google,
        // so the callback authenticates via this short-lived signed state bound to the live session.
        $state = $this->signer->sign([
            'purpose' => 'google_oauth',
            'uid' => $this->userId($request),
            'sid' => (int) $session['id'],
            'env' => $this->config->environment(),
            'nonce' => Tokens::random(8),
        ], 600);

        return $this->ok(['authorization_url' => $this->google->authorizationUrl($state)]);
    }

    /**
     * Google returns in the URL fragment (see GoogleAccount::authorizationUrl), which the browser never
     * sends, so this serves a page that posts code and state back. A query-string return is still accepted.
     */
    public function googleCallback(Request $request): Response
    {
        if ($request->query('code') === null && $request->query('error') === null) {
            return $this->googleRelayPage();
        }
        $outcome = $this->completeGoogleConnect(
            $request,
            (string) $request->query('state', ''),
            (string) $request->query('code', ''),
            (string) $request->query('error', ''),
        );

        return Response::redirect($this->googleAdminUrl($outcome));
    }

    public function googleCallbackRelay(Request $request): Response
    {
        $field = static fn (string $key): string => is_string($request->input($key)) ? $request->input($key) : '';
        $outcome = $this->completeGoogleConnect($request, $field('state'), $field('code'), $field('error'));

        return $this->ok(['redirect' => $this->googleAdminUrl($outcome)]);
    }

    private function googleAdminUrl(string $outcome): string
    {
        return rtrim((string) $this->config->get('app.admin_url'), '/') . '/settings/integrations/google?google=' . $outcome;
    }

    private function googleRelayPage(): Response
    {
        $failed = json_encode($this->googleAdminUrl('failed'), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $script = <<<JS
            (function () {
              var p = new URLSearchParams(location.hash.slice(1));
              history.replaceState(null, '', location.pathname);
              fetch(location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ code: p.get('code') || '', state: p.get('state') || '', error: p.get('error') || '' })
              })
                .then(function (r) { return r.json(); })
                .then(function (b) { location.replace(b.data.redirect); })
                .catch(function () { location.replace({$failed}); });
            })();
            JS;
        $hash = base64_encode(hash('sha256', $script, true));
        $html = "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"robots\" content=\"noindex\"><title>Connecting Google</title></head>"
            . "<body><p>Connecting your Google account…</p><noscript><p>JavaScript is required to finish connecting Google.</p></noscript>"
            . "<script>{$script}</script></body></html>";

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "default-src 'none'; script-src 'sha256-{$hash}'; connect-src 'self'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'",
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /** @return string the outcome reported to the admin page as ?google=… */
    private function completeGoogleConnect(Request $request, string $state, string $code, string $error): string
    {
        $claims = $this->signer->verify($state);
        if ($claims === null || ($claims['purpose'] ?? null) !== 'google_oauth' || ($claims['env'] ?? null) !== $this->config->environment()) {
            return 'invalid_state';
        }
        $session = $this->db->first(
            "SELECT s.id FROM user_sessions s JOIN users u ON u.id = s.user_id
             WHERE s.id = ? AND s.user_id = ? AND s.revoked_at IS NULL AND s.expires_at > ? AND u.status = 'active'",
            [(int) $claims['sid'], (int) $claims['uid'], $this->clock->nowString()],
        );
        $allowed = $session !== null && (bool) $this->db->value(
            "SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             LEFT JOIN role_permissions rp ON rp.role_id = r.id LEFT JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = ? AND (r.slug = 'super-admin' OR p.slug = 'integrations.manage') LIMIT 1",
            [(int) $claims['uid']],
        );
        if (!$allowed) {
            return 'unauthorized';
        }
        if ($error !== '') {
            // access_denied: the user cancelled, or (while the app is in Testing) is not a listed test user.
            $this->integrationLog->record('google', 'oauth.consent', false, null, 'access_denied');

            return 'denied';
        }
        try {
            $result = $this->google->connect($code, (int) $claims['uid']);
        } catch (GoogleApiError $e) {
            $this->logger->error('google_connect_failed', ['error_code' => $e->category, 'status' => $e->httpStatus]);
            $known = ['invalid_client', 'redirect_uri_mismatch', 'invalid_code', 'no_refresh_token', 'wrong_account', 'network'];

            return in_array($e->category, $known, true) ? $e->category : 'failed';
        } catch (\Throwable $e) {
            $this->logger->error('google_connect_failed', ['type' => $e::class]);

            return 'failed';
        }
        $this->audit->record((int) $claims['uid'], 'integration.google_connected', 'calendar_integration', null, ['account' => $result['email'], 'missing_scopes' => $result['missing_scopes']], $request);

        // Bookings that could not sync while disconnected are synced now.
        foreach ($this->db->all("SELECT id FROM appointments WHERE calendar_sync_status IN ('pending','failed','retry_required') AND status IN ('confirmed','rescheduled') AND starts_at > ?", [$this->clock->nowString()]) as $row) {
            $this->db->update('appointments', ['calendar_sync_status' => 'pending', 'calendar_attempts' => 0], ['id' => $row['id']]);
            $this->jobs->push('calendar.sync', ['appointment_id' => (int) $row['id']], 'calendar.sync:' . $row['id']);
        }

        return $result['missing_scopes'] === [] ? 'connected' : 'partial';
    }

    public function googleDisconnect(Request $request): Response
    {
        $this->google->disconnect();
        $this->audit->record($this->userId($request), 'integration.google_disconnected', 'calendar_integration', null, [], $request);

        return Response::noContent();
    }

    /** Sends a test message through the Gmail API to the operational alert address (or the admin's own). */
    public function googleTestGmail(Request $request): Response
    {
        $to = (string) ($this->settings->get('notifications.admin_email') ?: $this->config->get('mail.admin_address'));
        if ($to === '') {
            $to = (string) $this->db->value('SELECT email FROM users WHERE id = ?', [$this->userId($request)]);
        }

        return $this->runGoogleTest($request, 'gmail', function () use ($to) {
            $this->gmail->sendTest(
                $to,
                'Google Workspace test',
                '<p>This is a test message sent through the Gmail API from the private office platform.</p><p>No action is needed.</p>',
                "This is a test message sent through the Gmail API from the private office platform.\n\nNo action is needed.",
            );

            return ['sent_to' => $to, 'sent_from' => $this->gmail->senderAddress()];
        });
    }

    public function googleTestCalendar(Request $request): Response
    {
        return $this->runGoogleTest($request, 'calendar', fn () => $this->calendar->runTest(false));
    }

    public function googleTestMeet(Request $request): Response
    {
        return $this->runGoogleTest($request, 'meet', fn () => $this->calendar->runTest(true));
    }

    public function googleCleanupTests(Request $request): Response
    {
        return $this->runGoogleTest($request, 'cleanup', fn () => ['removed' => $this->calendar->cleanupTestEvents()]);
    }

    public function retryJob(Request $request): Response
    {
        $id = $request->intParam('id');
        $updated = $this->db->run(
            "UPDATE jobs SET status = 'pending', attempts = 0, available_at = ?, finished_at = NULL WHERE id = ? AND status = 'failed'",
            [$this->clock->nowString(), $id],
        )->rowCount();
        if ($updated === 0) {
            throw HttpException::notFound();
        }
        $this->audit->record($this->userId($request), 'job.retried', 'job', $id, [], $request);

        return Response::noContent();
    }

    public function sendTestEmail(Request $request): Response
    {
        $user = $this->db->first('SELECT email, name FROM users WHERE id = ?', [$this->userId($request)]);
        $this->mailer->send((string) $user['email'], 'Test message', '<p>This is a test message from the private office platform.</p>', 'This is a test message from the private office platform.');
        $this->audit->record($this->userId($request), 'integration.test_email', null, null, [], $request);

        return Response::noContent();
    }

    /** @param callable(): array<string, mixed> $test */
    private function runGoogleTest(Request $request, string $service, callable $test): Response
    {
        try {
            $result = $test();
        } catch (GoogleApiError $e) {
            $this->audit->record($this->userId($request), 'integration.google_test', 'calendar_integration', null, ['service' => $service, 'outcome' => 'failure', 'category' => $e->category], $request);

            throw new HttpException(502, 'google_' . $e->category, $e->getMessage());
        }
        $this->audit->record($this->userId($request), 'integration.google_test', 'calendar_integration', null, ['service' => $service, 'outcome' => 'success'], $request);

        return $this->ok($result);
    }

    /** @return array<string, mixed> */
    private function googleStatus(): array
    {
        $integration = $this->google->integration();
        $status = (string) ($integration['status'] ?? 'disconnected');
        $granted = $this->google->grantedScopes($integration);
        $service = function (string $scope, array $operations) use ($status, $granted): string {
            if ($status !== 'connected') {
                return $status === 'needs_reauth' ? 'reauth_required' : 'disconnected';
            }
            if (!in_array($scope, $granted, true)) {
                return 'missing_permission';
            }
            $last = $this->integrationLog->latest('google', $operations);

            return $last !== null && $last['outcome'] === 'failure' ? 'error' : 'connected';
        };

        return [
            'configured' => $this->google->isConfigured(),
            'status' => $status,
            'account_email' => $integration['account_email'] ?? null,
            'expected_account' => ((string) $this->config->get('google.account_email')) ?: null,
            'calendar_id' => $integration['calendar_id'] ?? $this->config->get('google.calendar_id'),
            'last_error' => $integration['last_error'] ?? null,
            'last_synced_at' => Clock::iso($integration['last_synced_at'] ?? null),
            'redirect_uri' => $this->config->get('google.redirect_uri'),
            'missing_scopes' => $status === 'connected' ? $this->google->missingScopes($integration) : [],
            'services' => [
                'gmail' => [
                    'status' => $this->config->get('mail.driver') !== 'gmail' && !in_array(GoogleAccount::SCOPE_GMAIL, $granted, true)
                        ? 'not_used'
                        : $service(GoogleAccount::SCOPE_GMAIL, ['gmail.send']),
                    'mail_driver' => $this->config->get('mail.driver') === 'gmail',
                ],
                'calendar' => ['status' => $service(GoogleAccount::SCOPE_CALENDAR, self::CALENDAR_OPERATIONS)],
                'meet' => ['status' => $service(GoogleAccount::SCOPE_CALENDAR, ['calendar.meet'])],
            ],
            'logs' => array_map(static fn (array $row) => [
                'operation' => $row['operation'],
                'outcome' => $row['outcome'],
                'http_status' => $row['http_status'] !== null ? (int) $row['http_status'] : null,
                'error_category' => $row['error_category'],
                'reference' => $row['reference'],
                'duration_ms' => $row['duration_ms'] !== null ? (int) $row['duration_ms'] : null,
                'created_at' => Clock::iso((string) $row['created_at']),
            ], $this->integrationLog->recent('google', 25)),
        ];
    }

    private function paymentMode(string $gateway): string
    {
        $key = match ($gateway) {
            'razorpay' => (string) $this->config->get('payments.razorpay.key_id'),
            'stripe' => (string) $this->config->get('payments.stripe.secret_key'),
            default => '',
        };
        if ($key === '') {
            return 'unconfigured';
        }

        return str_contains($key, '_live_') ? 'live' : 'test';
    }
}
