<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Integrations\Email\SmtpMailer;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;

final class SmtpMailerTest extends TestCase
{
    private function headers(array $overrides): string
    {
        $mailer = new SmtpMailer($overrides + [
            'host' => 'mail.example.test', 'port' => 465, 'username' => 'noreply@example.test', 'password' => 'x',
            'encryption' => 'ssl', 'from_address' => 'noreply@example.test', 'from_name' => 'Office', 'reply_to' => '',
        ]);
        $mail = new PHPMailer(true);
        $mailer->compose($mail, 'client@example.test', 'Subject', '<p>Hi</p>', 'Hi');
        $mail->preSend();

        return $mail->getSentMIMEMessage();
    }

    public function testRepliesGoToTheConfiguredMailboxWhenTheSenderIsUnattended(): void
    {
        $headers = $this->headers(['reply_to' => 'info@example.test']);

        self::assertStringContainsString('From: Office <noreply@example.test>', $headers);
        self::assertStringContainsString('Reply-To: Office <info@example.test>', $headers);
    }

    public function testNoReplyToHeaderWithoutOneConfiguredOrWhenItEqualsTheSender(): void
    {
        self::assertStringNotContainsString('Reply-To:', $this->headers([]));
        self::assertStringNotContainsString('Reply-To:', $this->headers(['reply_to' => 'noreply@example.test']));
    }
}
