<?php

declare(strict_types=1);

namespace App\Integrations\Email;

use App\Core\Config;
use App\Integrations\Google\GoogleAccount;
use App\Integrations\Google\GoogleApiError;

/**
 * Sends through the Gmail API (users.messages.send, `gmail.send` scope only) as the Google account
 * connected under Integrations. Messages appear in that account's Sent folder.
 */
final class GmailApiMailer implements Mailer
{
    private const SEND_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    public function __construct(private GoogleAccount $account, private Config $config)
    {
    }

    public function isConfigured(): bool
    {
        return $this->account->hasScope(GoogleAccount::SCOPE_GMAIL);
    }

    /** The address messages are sent from: always the connected account, as Gmail rewrites any other From. */
    public function senderAddress(): ?string
    {
        return $this->account->accountEmail();
    }

    public function send(string $to, string $subject, string $html, string $text): ?string
    {
        return $this->deliver($to, $subject, $html, $text, null);
    }

    /** Same delivery path, recorded in the integration log as a test. */
    public function sendTest(string $to, string $subject, string $html, string $text): ?string
    {
        return $this->deliver($to, $subject, $html, $text, 'test');
    }

    private function deliver(string $to, string $subject, string $html, string $text, ?string $reference): ?string
    {
        $from = $this->senderAddress() ?? throw new GoogleApiError('not_connected');
        $raw = self::buildMime((string) $this->config->get('mail.from_name', ''), $from, $to, $subject, $html, $text);

        [, $data] = $this->account->call('gmail.send', GoogleAccount::SCOPE_GMAIL, 'POST', self::SEND_URL, [
            'json' => ['raw' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=')],
        ], $reference);

        return isset($data['id']) ? 'gmail:' . $data['id'] : null;
    }

    /** RFC 5322 multipart/alternative message with UTF-8 encoded headers. */
    public static function buildMime(string $fromName, string $fromAddress, string $to, string $subject, string $html, string $text, ?string $boundary = null, ?int $timestamp = null): string
    {
        foreach ([$fromAddress, $to] as $address) {
            if (preg_match('/[\r\n]/', $address) || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new GoogleApiError('invalid_recipient');
            }
        }
        $boundary ??= 'pa_' . bin2hex(random_bytes(12));
        $fromName = trim((string) preg_replace('/[\r\n]+/', ' ', $fromName));
        $displayName = self::encodeHeader($fromName);
        if ($displayName === $fromName && preg_match('/[()<>@,;:\\\\".\[\]]/', $fromName)) {
            $displayName = '"' . addcslashes($fromName, '"\\') . '"';
        }
        $headers = [
            'From: ' . ($fromName !== '' ? $displayName . ' <' . $fromAddress . '>' : $fromAddress),
            'To: ' . $to,
            'Subject: ' . self::encodeHeader($subject),
            'Date: ' . gmdate('D, d M Y H:i:s', $timestamp ?? time()) . ' +0000',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $part = static fn (string $type, string $body) => "--{$boundary}\r\n"
            . "Content-Type: {$type}; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . rtrim(chunk_split(base64_encode($body), 76, "\r\n")) . "\r\n";

        return implode("\r\n", $headers) . "\r\n\r\n"
            . $part('text/plain', $text)
            . $part('text/html', $html)
            . "--{$boundary}--\r\n";
    }

    private static function encodeHeader(string $value): string
    {
        $value = trim((string) preg_replace('/[\r\n]+/', ' ', $value));
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
