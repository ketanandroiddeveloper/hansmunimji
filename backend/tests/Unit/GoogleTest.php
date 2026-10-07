<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Integrations\Email\GmailApiMailer;
use App\Integrations\Google\GoogleApiError;
use PHPUnit\Framework\TestCase;

final class GoogleTest extends TestCase
{
    public function testMimeMessageEncodesHeadersAndBothBodies(): void
    {
        $raw = GmailApiMailer::buildMime('Private Office', 'office@example.test', 'guest@example.test', 'Confirmation · Réservation', '<p>Hello</p>', 'Hello', 'b1', 0);

        self::assertStringContainsString("From: Private Office <office@example.test>\r\n", $raw);
        self::assertStringContainsString("To: guest@example.test\r\n", $raw);
        self::assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Confirmation · Réservation') . "?=\r\n", $raw);
        self::assertStringContainsString('Content-Type: multipart/alternative; boundary="b1"', $raw);
        self::assertStringContainsString(base64_encode('Hello'), $raw);
        self::assertStringContainsString(base64_encode('<p>Hello</p>'), $raw);
        self::assertStringEndsWith("--b1--\r\n", $raw);
    }

    public function testGmailScopeIsRequestedOnlyWhenGmailSendsTheEmail(): void
    {
        $previous = $_ENV['MAIL_DRIVER'] ?? null;
        try {
            $_ENV['MAIL_DRIVER'] = 'smtp';
            $smtp = (require dirname(__DIR__, 2) . '/config/google.php')['scopes'];
            $_ENV['MAIL_DRIVER'] = 'gmail';
            $gmail = (require dirname(__DIR__, 2) . '/config/google.php')['scopes'];
        } finally {
            if ($previous === null) {
                unset($_ENV['MAIL_DRIVER']);
            } else {
                $_ENV['MAIL_DRIVER'] = $previous;
            }
        }

        self::assertSame(['https://www.googleapis.com/auth/calendar.events', 'openid', 'email'], $smtp);
        self::assertSame(['https://www.googleapis.com/auth/calendar.events', 'https://www.googleapis.com/auth/gmail.send', 'openid', 'email'], $gmail);
    }

    public function testDisplayNameWithSpecialCharactersIsQuoted(): void
    {
        $raw = GmailApiMailer::buildMime('Office, Private', 'office@example.test', 'guest@example.test', 'Hi', '', '', 'b', 0);

        self::assertStringContainsString('From: "Office, Private" <office@example.test>', $raw);
    }

    public function testHeaderInjectionIsRejected(): void
    {
        $this->expectException(GoogleApiError::class);
        GmailApiMailer::buildMime('', 'office@example.test', "guest@example.test\r\nBcc: someone@example.test", 'Hi', '', '');
    }

    public function testNewlinesInSubjectCannotAddHeaders(): void
    {
        $raw = GmailApiMailer::buildMime('', 'office@example.test', 'guest@example.test', "Hi\r\nBcc: someone@example.test", '', '', 'b', 0);

        self::assertStringNotContainsString("\r\nBcc:", $raw);
    }

    public function testApiErrorsAreCategorised(): void
    {
        $reason = static fn (int $code, string $reason) => ['error' => ['code' => $code, 'errors' => [['reason' => $reason]]]];

        self::assertSame('insufficient_scope', GoogleApiError::categorize(403, $reason(403, 'insufficientPermissions')));
        self::assertSame('insufficient_scope', GoogleApiError::categorize(403, ['error' => ['details' => [['reason' => 'ACCESS_TOKEN_SCOPE_INSUFFICIENT']]]]));
        self::assertSame('api_disabled', GoogleApiError::categorize(403, $reason(403, 'accessNotConfigured')));
        self::assertSame('quota', GoogleApiError::categorize(403, $reason(403, 'userRateLimitExceeded')));
        self::assertSame('quota', GoogleApiError::categorize(429, []));
        self::assertSame('calendar_not_found', GoogleApiError::categorize(404, []));
        self::assertSame('server_error', GoogleApiError::categorize(503, []));
        self::assertSame('invalid_client', GoogleApiError::categorizeOAuth('invalid_client'));
        self::assertSame('revoked', GoogleApiError::categorizeOAuth('invalid_grant'));
    }

    public function testMessagesNeverEchoProviderText(): void
    {
        $error = new GoogleApiError('quota', null, 429);

        self::assertSame(GoogleApiError::MESSAGES['quota'], $error->getMessage());
        self::assertSame(429, $error->httpStatus);
    }
}
