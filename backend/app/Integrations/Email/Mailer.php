<?php

declare(strict_types=1);

namespace App\Integrations\Email;

interface Mailer
{
    /**
     * Sends a message and returns the provider message ID when available.
     *
     * @throws \RuntimeException on delivery failure (the outbox will retry)
     */
    public function send(string $to, string $subject, string $html, string $text): ?string;

    public function isConfigured(): bool;
}
