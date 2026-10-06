<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Resources;

use App\Controllers\Admin\ResourceController;

final class ServicesController extends ResourceController
{
    protected function table(): string
    {
        return 'services';
    }

    protected function rules(): array
    {
        return [
            'category_id' => 'nullable|integer',
            'slug' => 'required|slug|max:120',
            'numeral' => 'nullable|string|max:8',
            'title' => 'required|string|max:190',
            'subtitle' => 'nullable|string|max:255',
            'summary' => 'required|string|max:1000',
            'body' => 'nullable|string|max:100000',
            'highlights' => 'nullable|array',
            'offerings' => 'nullable|array',
            'cover_media_id' => 'nullable|integer',
            'booking_mode' => 'required|in:direct,application,inquiry',
            'duration_label' => 'nullable|string|max:120',
            'price_display' => 'nullable|string|max:120',
            'is_published' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ];
    }

    protected function htmlFields(): array
    {
        return ['body'];
    }

    protected function jsonFields(): array
    {
        return ['highlights', 'offerings'];
    }

    protected function searchable(): array
    {
        return ['title', 'slug'];
    }

    protected function sortable(): array
    {
        return ['id', 'title', 'sort_order', 'updated_at'];
    }

    protected function filterable(): array
    {
        return ['is_published', 'category_id'];
    }

    protected function defaultSort(): string
    {
        return 'sort_order';
    }
}
