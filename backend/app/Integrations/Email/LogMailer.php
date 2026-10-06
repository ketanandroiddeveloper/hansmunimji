<?php

declare(strict_types=1);

namespace App\Integrations\Email;

use App\Core\Logger;

/**
 * Development driver: writes the subject and plain-text body to storage/logs/mail-*.log
 * with the recipient domain only. Never use in production.
 */
final class LogMailer implements Mailer
{
    public function __construct(private Logger $logger)
    {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $to, string $subject, string $html, string $text): ?string
    {
        $id = 'log-' . bin2hex(random_bytes(6));
        $this->logger->channel('mail', 'mail.sent', [
            'message' => $subject,
            'to_domain' => substr((string) strrchr($to, '@'), 1),
            'body' => $text,
            'id' => $id,
        ]);

        return $id;
    }
}
