<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Resources;

use App\Controllers\Admin\ResourceController;
use App\Core\HttpException;

/** Testimonials may only be published once the client's written authorisation is recorded. */
final class TestimonialsController extends ResourceController
{
    protected function table(): string
    {
        return 'testimonials';
    }

    protected function rules(): array
    {
        return [
            'quote' => 'required|string|max:2000',
            'attribution' => 'required|string|max:190',
            'role' => 'nullable|string|max:190',
            'consent_reference' => 'nullable|string|max:190',
            'is_authorized' => 'nullable|boolean',
            'is_published' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'sort_order'];
    }

    protected function filterable(): array
    {
        return ['is_published', 'is_authorized'];
    }

    protected function defaultSort(): string
    {
        return 'sort_order';
    }

    protected function beforeSave(array $data, ?array $existing): array
    {
        $published = (bool) ($data['is_published'] ?? $existing['is_published'] ?? false);
        $authorized = (bool) ($data['is_authorized'] ?? $existing['is_authorized'] ?? false);
        $consent = trim((string) ($data['consent_reference'] ?? $existing['consent_reference'] ?? ''));
        if ($published && (!$authorized || $consent === '')) {
            throw HttpException::validation(['is_published' => ['Record the client\'s written authorisation and a consent reference before publishing.']]);
        }

        return $data;
    }
}
