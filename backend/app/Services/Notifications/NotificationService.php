<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Integrations\Email\Mailer;
use App\Security\Crypto;
use App\Services\SettingsService;

/**
 * Transactional email outbox. Recipient and variables are encrypted at rest; the worker
 * renders and delivers with exponential backoff.
 */
final class NotificationService
{
    private const MAX_ATTEMPTS = 5;
    private const BACKOFF_MINUTES = [1, 5, 15, 60, 240];

    public function __construct(
        private Database $db,
        private Clock $clock,
        private Crypto $crypto,
        private Mailer $mailer,
        private TemplateRenderer $renderer,
        private SettingsService $settings,
        private Config $config,
        private Logger $logger,
    ) {
    }

    /** @param array<string, scalar|null> $vars */
    public function queue(string $template, string $recipient, array $vars, ?string $relatedType = null, ?int $relatedId = null, int $delaySeconds = 0): int
    {
        return $this->db->insert('notifications', [
            'template_slug' => $template,
            'recipient_enc' => $this->crypto->encrypt($recipient, 'notifications.recipient'),
            'payload_enc' => $this->crypto->encrypt(json_encode($vars, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'notifications.payload'),
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'status' => 'queued',
            'available_at' => $this->clock->now()->modify("+{$delaySeconds} seconds")->format('Y-m-d H:i:s'),
            'created_at' => $this->clock->nowString(),
        ]);
    }

    /** @param array<string, scalar|null> $vars */
    public function queueAdmin(string $template, array $vars, ?string $relatedType = null, ?int $relatedId = null): void
    {
        $address = (string) ($this->settings->get('notifications.admin_email') ?: $this->config->get('mail.admin_address'));
        if ($address === '') {
            $this->logger->warning('admin_notification_skipped', ['template' => $template]);

            return;
        }
        $vars['admin_url'] ??= (string) $this->config->get('app.admin_url');
        $this->queue($template, $address, $vars, $relatedType, $relatedId);
    }

    public function cancelFor(string $relatedType, int $relatedId, string $template): void
    {
        $this->db->run(
            "UPDATE notifications SET status = 'cancelled' WHERE related_type = ? AND related_id = ? AND template_slug = ? AND status = 'queued'",
            [$relatedType, $relatedId, $template],
        );
    }

    /** Delivers due notifications; returns the number processed. */
    public function processDue(int $limit = 25): int
    {
        $rows = $this->db->transaction(function () use ($limit) {
            $rows = $this->db->all(
                "SELECT * FROM notifications WHERE status = 'queued' AND available_at <= ? ORDER BY id LIMIT {$limit} FOR UPDATE SKIP LOCKED",
                [$this->clock->nowString()],
            );
            foreach ($rows as $row) {
                $this->db->update('notifications', ['status' => 'sending'], ['id' => $row['id']]);
            }

            return $rows;
        });

        foreach ($rows as $row) {
            $this->deliver($row);
        }

        return count($rows);
    }

    /** @param array<string, mixed> $row */
    private function deliver(array $row): void
    {
        $attempts = (int) $row['attempts'] + 1;
        try {
            $recipient = (string) $this->crypto->decrypt($row['recipient_enc'], 'notifications.recipient');
            $vars = json_decode((string) $this->crypto->decrypt($row['payload_enc'], 'notifications.payload'), true) ?: [];
            $message = $this->renderer->render((string) $row['template_slug'], $vars);
            $messageId = $this->mailer->send($recipient, $message['subject'], $message['html'], $message['text']);

            $this->db->update('notifications', [
                'status' => 'sent',
                'attempts' => $attempts,
                'sent_at' => $this->clock->nowString(),
                'provider_message_id' => $messageId,
                'last_error' => null,
            ], ['id' => $row['id']]);
        } catch (\Throwable $e) {
            $final = $attempts >= self::MAX_ATTEMPTS;
            $delay = self::BACKOFF_MINUTES[min($attempts - 1, count(self::BACKOFF_MINUTES) - 1)];
            $this->db->update('notifications', [
                'status' => $final ? 'failed' : 'queued',
                'attempts' => $attempts,
                'available_at' => $this->clock->now()->modify("+{$delay} minutes")->format('Y-m-d H:i:s'),
                'last_error' => mb_substr($e->getMessage(), 0, 250),
            ], ['id' => $row['id']]);
            $this->logger->error('notification_failed', ['template' => $row['template_slug'], 'attempts' => $attempts, 'message' => $e->getMessage()]);
        }
    }
}
