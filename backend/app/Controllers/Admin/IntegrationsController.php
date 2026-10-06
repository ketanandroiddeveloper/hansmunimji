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
use App\Integrations\Email\Mailer;
use App\Integrations\Google\GoogleCalendarClient;
use App\Integrations\Payments\GatewayRegistry;
use App\Jobs\JobQueue;
use App\Security\AuditLogger;
use App\Security\SignedUrl;
use App\Security\Tokens;

/**
 * Integration status and the Google OAuth connection. Credentials themselves are environment-managed
 * and never returned — only whether each integration is configured and in which mode.
 */
final class IntegrationsController extends Controller
{
    public function __construct(
        private Config $config,
        private Database $db,
        private Clock $clock,
        private GoogleCalendarClient $google,
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
        $integration = $this->google->integration();
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
            'google_calendar' => [
                'configured' => $this->google->isConfigured(),
                'status' => $integration['status'] ?? 'disconnected',
                'account_email' => $integration['account_email'] ?? null,
                'calendar_id' => $integration['calendar_id'] ?? null,
                'last_error' => $integration['last_error'] ?? null,
                'last_synced_at' => Clock::iso($integration['last_synced_at'] ?? null),
                'redirect_uri' => $this->config->get('google.redirect_uri'),
            ],
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

    public function googleCallback(Request $request): Response
    {
        $adminUrl = rtrim((string) $this->config->get('app.admin_url'), '/') . '/integrations';
        $claims = $this->signer->verify((string) $request->query('state', ''));
        if ($claims === null || ($claims['purpose'] ?? null) !== 'google_oauth' || ($claims['env'] ?? null) !== $this->config->environment()) {
            return Response::redirect($adminUrl . '?google=invalid_state');
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
            return Response::redirect($adminUrl . '?google=unauthorized');
        }
        if ($request->query('error')) {
            return Response::redirect($adminUrl . '?google=denied');
        }
        try {
            $email = $this->google->connect((string) $request->query('code', ''), (int) $claims['uid']);
        } catch (\Throwable $e) {
            $this->logger->error('google_connect_failed', ['message' => $e->getMessage()]);

            return Response::redirect($adminUrl . '?google=failed');
        }
        $this->audit->record((int) $claims['uid'], 'integration.google_connected', 'calendar_integration', null, ['account' => $email], $request);

        // Bookings that failed to sync while disconnected are retried now.
        foreach ($this->db->all("SELECT id FROM appointments WHERE calendar_sync_status IN ('pending','failed','retry_required') AND status IN ('confirmed','rescheduled') AND starts_at > ?", [$this->clock->nowString()]) as $row) {
            $this->db->update('appointments', ['calendar_sync_status' => 'pending', 'calendar_attempts' => 0], ['id' => $row['id']]);
            $this->jobs->push('calendar.sync', ['appointment_id' => (int) $row['id']], 'calendar.sync:' . $row['id']);
        }

        return Response::redirect($adminUrl . '?google=connected');
    }

    public function googleDisconnect(Request $request): Response
    {
        $this->google->disconnect();
        $this->audit->record($this->userId($request), 'integration.google_disconnected', 'calendar_integration', null, [], $request);

        return Response::noContent();
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
