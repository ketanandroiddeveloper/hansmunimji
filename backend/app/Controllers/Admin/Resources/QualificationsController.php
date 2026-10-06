<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Resources;

use App\Controllers\Admin\ResourceController;

final class QualificationsController extends ResourceController
{
    protected function table(): string
    {
        return 'qualifications';
    }

    protected function rules(): array
    {
        return [
            'practitioner_id' => 'required|integer',
            'kind' => 'required|in:qualification,certification,experience,publication,interview,speaking,award',
            'title' => 'required|string|max:255',
            'institution' => 'nullable|string|max:255',
            'year' => 'nullable|integer|between:1900,2100',
            'description' => 'nullable|string|max:5000',
            'url' => 'nullable|url|max:500',
            'sort_order' => 'nullable|integer',
            'is_published' => 'nullable|boolean',
        ];
    }

    protected function searchable(): array
    {
        return ['title', 'institution'];
    }

    protected function sortable(): array
    {
        return ['id', 'kind', 'year', 'sort_order'];
    }

    protected function filterable(): array
    {
        return ['practitioner_id', 'kind', 'is_published'];
    }

    protected function defaultSort(): string
    {
        return 'sort_order';
    }
}
