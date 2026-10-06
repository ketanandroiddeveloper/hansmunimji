<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Resources;

use App\Controllers\Admin\ResourceController;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Notifications\TemplateRenderer;

/**
 * Templates are seeded per slug; admins edit copy only (no create/delete).
 * Variables are escaped at render time by TemplateRenderer, so authored HTML cannot inject client data unescaped.
 */
final class EmailTemplatesController extends ResourceController
{
    private TemplateRenderer $renderer;

    protected function boot(Container $container): void
    {
        $this->renderer = $container->get(TemplateRenderer::class);
    }

    protected function table(): string
    {
        return 'email_templates';
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:190',
            'subject' => 'required|string|max:255',
            'body_html' => 'required|string|max:100000',
            'body_text' => 'nullable|string|max:50000',
        ];
    }

    protected function hasTimestamps(): bool
    {
        return false;
    }

    protected function affectsPublicSite(): bool
    {
        return false;
    }

    protected function jsonFields(): array
    {
        return ['variables'];
    }

    protected function searchable(): array
    {
        return ['name', 'slug'];
    }

    protected function sortable(): array
    {
        return ['id', 'name', 'slug'];
    }

    protected function defaultSort(): string
    {
        return 'name';
    }

    protected function beforeSave(array $data, ?array $existing): array
    {
        if (isset($data['body_html']) && preg_match('/<\s*(script|iframe|object|embed|form)\b|\son\w+\s*=|javascript:/i', (string) $data['body_html'])) {
            throw HttpException::validation(['body_html' => ['Scripts, forms, embeds and inline event handlers are not allowed in email templates.']]);
        }
        $data['updated_at'] = $this->clock->nowString();

        return $data;
    }

    public function store(Request $request): Response
    {
        throw new HttpException(405, 'method_not_allowed', 'Email templates are managed per notification type and cannot be created.');
    }

    public function destroy(Request $request): Response
    {
        throw new HttpException(405, 'method_not_allowed', 'Email templates cannot be deleted.');
    }

    /** POST /admin/email-templates/{id}/preview — renders with sample values from the declared variables. */
    public function preview(Request $request): Response
    {
        $template = $this->findOrFail($request->intParam('id'));
        $input = $request->json();
        $vars = [];
        foreach (json_decode((string) ($template['variables'] ?? '[]'), true) ?: [] as $name) {
            $vars[$name] = '[' . $name . ']';
        }
        $rendered = $this->renderer->renderSource(
            (string) ($input['subject'] ?? $template['subject']),
            (string) ($input['body_html'] ?? $template['body_html']),
            isset($input['body_text']) ? (string) $input['body_text'] : $template['body_text'],
            $vars,
        );

        return $this->ok($rendered);
    }
}
