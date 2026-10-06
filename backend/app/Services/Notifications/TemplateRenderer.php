<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Core\Database;
use App\Services\SettingsService;

/**
 * Renders admin-editable email templates. Placeholders use `{{variable}}` and are always
 * HTML-escaped; `{{#if variable}}…{{/if}}` blocks render only when the variable is non-empty.
 */
final class TemplateRenderer
{
    public function __construct(private Database $db, private SettingsService $settings)
    {
    }

    /**
     * @param array<string, scalar|null> $vars
     * @return array{subject: string, html: string, text: string}
     */
    public function render(string $slug, array $vars): array
    {
        $template = $this->db->first('SELECT subject, body_html, body_text FROM email_templates WHERE slug = ?', [$slug]);
        if ($template === null) {
            throw new \RuntimeException("Email template '{$slug}' does not exist.");
        }

        return $this->renderSource((string) $template['subject'], (string) $template['body_html'], $template['body_text'] ?: null, $vars);
    }

    /**
     * @param array<string, scalar|null> $vars
     * @return array{subject: string, html: string, text: string}
     */
    public function renderSource(string $subjectSource, string $htmlSource, ?string $textSource, array $vars): array
    {
        $vars += [
            'site_name' => (string) $this->settings->get('site.name', ''),
            'contact_email' => (string) $this->settings->get('contact.email', ''),
        ];

        $subject = $this->interpolate($subjectSource, $vars, false);
        $bodyHtml = $this->interpolate($htmlSource, $vars, true);
        $text = $textSource
            ? $this->interpolate($textSource, $vars, false)
            : trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $bodyHtml))));

        return ['subject' => $subject, 'html' => $this->layout($subject, $bodyHtml, $vars), 'text' => $text];
    }

    /** @param array<string, scalar|null> $vars */
    public function interpolate(string $source, array $vars, bool $html): string
    {
        $source = (string) preg_replace_callback(
            '/\{\{#if ([a-z_]+)\}\}(.*?)\{\{\/if\}\}/s',
            static fn (array $m) => ($vars[$m[1]] ?? '') !== '' && ($vars[$m[1]] ?? null) !== null ? $m[2] : '',
            $source,
        );

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/',
            static function (array $m) use ($vars, $html) {
                $value = (string) ($vars[$m[1]] ?? '');

                return $html ? htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $value;
            },
            $source,
        );
    }

    /** @param array<string, scalar|null> $vars */
    private function layout(string $title, string $body, array $vars): string
    {
        $site = htmlspecialchars((string) $vars['site_name'], ENT_QUOTES, 'UTF-8');
        $contact = htmlspecialchars((string) $vars['contact_email'], ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>{$title}</title></head>
<body style="margin:0;background:#0B1220;color:#E9E2D3;font-family:Georgia,'Times New Roman',serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0B1220;padding:48px 16px;">
<tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;">
<tr><td style="padding:0 0 32px;text-align:center;letter-spacing:0.32em;font-size:11px;color:#C9B07A;font-family:Helvetica,Arial,sans-serif;text-transform:uppercase;">{$site}</td></tr>
<tr><td style="border-top:1px solid rgba(201,176,122,0.35);padding:40px 8px;font-size:17px;line-height:1.7;color:#E9E2D3;">{$body}</td></tr>
<tr><td style="border-top:1px solid rgba(201,176,122,0.2);padding:24px 8px 0;font-family:Helvetica,Arial,sans-serif;font-size:11px;line-height:1.6;color:#8A94A6;">
This message is confidential and intended solely for the addressee. If you received it in error, please notify {$contact} and delete it.
</td></tr>
</table></td></tr></table></body></html>
HTML;
    }
}
