<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Resources;

use App\Controllers\Admin\ResourceController;

final class BlogCategoriesController extends ResourceController
{
    protected function table(): string
    {
        return 'blog_categories';
    }

    protected function rules(): array
    {
        return [
            'slug' => 'required|slug|max:120',
            'name' => 'required|string|max:190',
            'description' => 'nullable|string|max:2000',
        ];
    }

    protected function hasTimestamps(): bool
    {
        return false;
    }

    protected function sortable(): array
    {
        return ['id', 'name'];
    }

    protected function defaultSort(): string
    {
        return 'name';
    }
}
