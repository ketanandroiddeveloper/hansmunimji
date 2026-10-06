<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Resources;

use App\Controllers\Admin\ResourceController;

final class FaqsController extends ResourceController
{
    protected function table(): string
    {
        return 'faqs';
    }

    protected function rules(): array
    {
        return [
            'service_id' => 'nullable|integer',
            'question' => 'required|string|max:500',
            'answer' => 'required|string|max:5000',
            'sort_order' => 'nullable|integer',
            'is_published' => 'nullable|boolean',
        ];
    }

    protected function searchable(): array
    {
        return ['question'];
    }

    protected function sortable(): array
    {
        return ['id', 'sort_order'];
    }

    protected function filterable(): array
    {
        return ['service_id', 'is_published'];
    }

    protected function defaultSort(): string
    {
        return 'sort_order';
    }
}
