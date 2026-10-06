<?php

declare(strict_types=1);

namespace App\Integrations\Email;

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

/** SMTP delivery via PHPMailer — compatible with Postmark, SES, SendGrid, Mailgun and Resend relays. */
final class SmtpMailer implements Mailer
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config)
    {
    }

    public function isConfigured(): bool
    {
        return $this->config['host'] !== '' && $this->config['from_address'] !== '';
    }

    public function send(string $to, string $subject, string $html, string $text): ?string
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = (string) $this->config['host'];
            $mail->Port = (int) $this->config['port'];
            $mail->SMTPAuth = $this->config['username'] !== '';
            $mail->Username = (string) $this->config['username'];
            $mail->Password = (string) $this->config['password'];
            $mail->SMTPSecure = $this->config['encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 15;
            $mail->setFrom((string) $this->config['from_address'], (string) $this->config['from_name']);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $text;
            $mail->send();

            return $mail->getLastMessageID() ?: null;
        } catch (MailerException $e) {
            throw new \RuntimeException('SMTP delivery failed: ' . $mail->ErrorInfo, 0, $e);
        }
    }
}
