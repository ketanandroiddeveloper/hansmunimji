<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Integrations\Email\GmailApiMailer;
use App\Integrations\Google\GoogleAccount;
use App\Integrations\Google\GoogleApiError;
use App\Integrations\Google\GoogleCalendarClient;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/** Google's endpoints are mocked; tokens and client credentials here are fake placeholders. */
final class GoogleIntegrationTest extends IntegrationTestCase
{
    private const ALL_SCOPES = 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/gmail.send';

    private MockHandler $google;
    /** @var list<array{request: RequestInterface}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->c->get(Config::class);
        $config->set('google.client_id', 'fake-client.apps.googleusercontent.com');
        $config->set('google.client_secret', 'fake-client-secret');
        $config->set('google.redirect_uri', 'http://127.0.0.1/api/v1/admin/integrations/google/callback');
        $config->set('google.account_email', '');
        $config->set('google.scopes', [GoogleAccount::SCOPE_CALENDAR, GoogleAccount::SCOPE_GMAIL, 'openid', 'email']);

        $this->google = new MockHandler();
        $stack = HandlerStack::create($this->google);
        $stack->push(Middleware::history($this->sent));
        $this->c->set(ClientInterface::class, static fn () => new Client(['handler' => $stack, 'http_errors' => false]));
    }

    public function testConnectEncryptsTokensAndReportsUngrantedScopes(): void
    {
        $this->google->append($this->tokenResponse('owner@example.test', 'openid https://www.googleapis.com/auth/userinfo.email ' . GoogleAccount::SCOPE_CALENDAR));

        $result = $this->svc(GoogleAccount::class)->connect('fake-code', $this->adminUser());

        self::assertSame('owner@example.test', $result['email']);
        self::assertSame([GoogleAccount::SCOPE_GMAIL], $result['missing_scopes']);
        $row = $this->row('calendar_integrations', "provider = 'google'");
        self::assertSame('connected', $row['status']);
        self::assertStringNotContainsString('fake-access', (string) $row['access_token_enc']);
        self::assertStringNotContainsString('fake-refresh', (string) $row['refresh_token_enc']);
        self::assertFalse($this->svc(GmailApiMailer::class)->isConfigured());
        self::assertTrue($this->svc(GoogleCalendarClient::class)->isReady());
        $this->assertLogsHoldNoSecrets();
    }

    public function testAnotherAccountIsRefusedAndItsTokenRevoked(): void
    {
        $this->c->get(Config::class)->set('google.account_email', 'owner@example.test');
        $this->google->append($this->tokenResponse('someone-else@example.test'), new Response(200));

        try {
            $this->svc(GoogleAccount::class)->connect('fake-code', $this->adminUser());
            self::fail('Expected the wrong account to be refused.');
        } catch (GoogleApiError $e) {
            self::assertSame('wrong_account', $e->category);
        }
        self::assertStringContainsString('oauth2.googleapis.com/revoke', (string) $this->sent[1]['request']->getUri());
        self::assertNull($this->db->first("SELECT id FROM calendar_integrations WHERE status = 'connected'"));
    }

    public function testGmailSendsAsTheConnectedAccountWithTheStoredToken(): void
    {
        $this->connect();
        $this->google->append(new Response(200, [], json_encode(['id' => 'msg-1'])));

        $id = $this->svc(GmailApiMailer::class)->send('guest@example.test', 'Your confirmation', '<p>Hi</p>', 'Hi');

        self::assertSame('gmail:msg-1', $id);
        $request = $this->sent[1]['request'];
        self::assertSame('Bearer fake-access', $request->getHeaderLine('Authorization'));
        $raw = base64_decode(strtr(json_decode((string) $request->getBody(), true)['raw'], '-_', '+/'));
        self::assertStringContainsString('<owner@example.test>', $raw);
        self::assertStringContainsString('To: guest@example.test', $raw);
        $this->assertLogsHoldNoSecrets();
    }

    public function testExpiredAccessTokenIsRefreshedAndUnauthorizedIsRetriedOnce(): void
    {
        $this->connect();
        $this->travel('+2 hours');
        $this->google->append(
            new Response(200, [], json_encode(['access_token' => 'fake-access-2', 'expires_in' => 3599, 'scope' => self::ALL_SCOPES])),
            new Response(401, [], json_encode(['error' => ['code' => 401]])),
            new Response(200, [], json_encode(['access_token' => 'fake-access-3', 'expires_in' => 3599, 'scope' => self::ALL_SCOPES])),
            new Response(200, [], json_encode(['id' => 'msg-2'])),
        );

        self::assertSame('gmail:msg-2', $this->svc(GmailApiMailer::class)->send('guest@example.test', 'Hi', '<p>Hi</p>', 'Hi'));
        self::assertSame('Bearer fake-access-3', $this->sent[4]['request']->getHeaderLine('Authorization'));
    }

    public function testRevokedAccessNeedsReauthorisationAndAlertsOnce(): void
    {
        $this->setting('notifications.admin_email', 'office@example.test');
        $this->connect();
        $this->travel('+2 hours');
        $this->google->append(new Response(400, [], json_encode(['error' => 'invalid_grant'])));

        foreach ([1, 2] as $attempt) {
            try {
                $this->svc(GmailApiMailer::class)->send('guest@example.test', 'Hi', '<p>Hi</p>', 'Hi');
                self::fail('Expected revoked access to fail.');
            } catch (GoogleApiError $e) {
                self::assertSame('revoked', $e->category);
            }
        }

        self::assertSame('needs_reauth', $this->row('calendar_integrations', "provider = 'google'")['status']);
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM jobs WHERE type = 'google.reauth_alert'"));
        $this->runJobs();
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE template_slug = 'admin_integration_failure'"));
    }

    public function testInsufficientScopeIsReportedOnTheIntegration(): void
    {
        $this->connect();
        $this->google->append(new Response(403, [], json_encode(['error' => ['code' => 403, 'errors' => [['reason' => 'insufficientPermissions']]]])));

        try {
            $this->svc(GmailApiMailer::class)->send('guest@example.test', 'Hi', '<p>Hi</p>', 'Hi');
            self::fail('Expected a scope error.');
        } catch (GoogleApiError $e) {
            self::assertSame('insufficient_scope', $e->category);
        }
        self::assertSame(GoogleApiError::MESSAGES['insufficient_scope'], $this->row('calendar_integrations', "provider = 'google'")['last_error']);
        self::assertSame('insufficient_scope', $this->row('integration_logs', "operation = 'gmail.send'")['error_category']);
    }

    public function testRetriedEventCreationFetchesTheExistingEventAndItsMeetLink(): void
    {
        $this->connect();
        $calendar = $this->svc(GoogleCalendarClient::class);
        $eventId = $calendar->eventId('AP-TEST-1');
        $this->google->append(
            new Response(409, [], json_encode(['error' => ['code' => 409]])),
            new Response(200, [], json_encode(['id' => $eventId, 'conferenceData' => ['createRequest' => ['status' => ['statusCode' => 'pending']]]])),
            new Response(200, [], json_encode(['id' => $eventId, 'hangoutLink' => 'https://meet.google.com/abc-defg-hij'])),
        );

        $result = $calendar->createEvent([
            'reference' => 'AP-TEST-1', 'summary' => 'Session', 'description' => 'Reference: AP-TEST-1',
            'starts_at' => '2030-03-02T10:00:00Z', 'ends_at' => '2030-03-02T11:00:00Z', 'timezone' => 'UTC',
            'attendee_email' => 'guest@example.test', 'attendee_name' => 'Guest', 'with_meet' => true, 'reminders' => [1440, 60, 15],
        ]);

        self::assertSame(['id' => $eventId, 'meet_url' => 'https://meet.google.com/abc-defg-hij'], $result);
        $create = json_decode((string) $this->sent[1]['request']->getBody(), true);
        self::assertSame($eventId, $create['id']);
        self::assertSame('AP-TEST-1', $create['conferenceData']['createRequest']['requestId']);
        self::assertStringContainsString('conferenceDataVersion=1', (string) $this->sent[1]['request']->getUri());
        self::assertSame('success', $this->row('integration_logs', "operation = 'calendar.meet'")['outcome']);
    }

    public function testCalendarTestCreatesAGuestlessEventAndDeletesItSilently(): void
    {
        $this->connect();
        $this->google->append(new Response(200, [], json_encode(['id' => 'patest1'])), new Response(204));

        $result = $this->svc(GoogleCalendarClient::class)->runTest(false);

        self::assertSame(['event_created' => true, 'meet_created' => null, 'cleaned_up' => true], $result);
        $body = json_decode((string) $this->sent[1]['request']->getBody(), true);
        self::assertArrayNotHasKey('attendees', $body);
        self::assertSame('transparent', $body['transparency']);
        self::assertSame('1', $body['extendedProperties']['private']['pa_integration_test']);
        self::assertSame('DELETE', $this->sent[2]['request']->getMethod());
        self::assertStringContainsString('sendUpdates=none', (string) $this->sent[2]['request']->getUri());
    }

    private function connect(): void
    {
        $this->google->append($this->tokenResponse('owner@example.test'));
        $this->svc(GoogleAccount::class)->connect('fake-code', $this->adminUser());
    }

    private function tokenResponse(string $email, string $scopes = self::ALL_SCOPES): Response
    {
        $idToken = 'h.' . rtrim(strtr(base64_encode(json_encode(['email' => $email, 'email_verified' => true])), '+/', '-_'), '=') . '.s';

        return new Response(200, [], json_encode([
            'access_token' => 'fake-access',
            'refresh_token' => 'fake-refresh',
            'expires_in' => 3599,
            'scope' => $scopes,
            'id_token' => $idToken,
        ]));
    }

    private function assertLogsHoldNoSecrets(): void
    {
        foreach ($this->db->all('SELECT * FROM integration_logs') as $row) {
            self::assertStringNotContainsString('fake-', json_encode($row));
        }
    }
}
