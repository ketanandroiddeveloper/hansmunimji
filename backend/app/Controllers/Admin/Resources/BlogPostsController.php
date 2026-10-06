<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Resources;

use App\Controllers\Admin\ResourceController;
use App\Core\Validator;

final class BlogPostsController extends ResourceController
{
    protected function table(): string
    {
        return 'blog_posts';
    }

    protected function rules(): array
    {
        return [
            'category_id' => 'nullable|integer',
            'author_id' => 'nullable|integer',
            'slug' => 'required|slug|max:160',
            'title' => 'required|string|max:255',
            'excerpt' => 'nullable|string|max:1000',
            'body' => 'nullable|string|max:200000',
            'cover_media_id' => 'nullable|integer',
            'status' => 'required|in:draft,published,archived',
            'published_at' => 'nullable|datetime',
            'tags' => 'nullable|array',
        ];
    }

    protected function htmlFields(): array
    {
        return ['body'];
    }

    protected function jsonFields(): array
    {
        return ['tags'];
    }

    protected function searchable(): array
    {
        return ['title', 'slug'];
    }

    protected function sortable(): array
    {
        return ['id', 'title', 'published_at', 'updated_at'];
    }

    protected function filterable(): array
    {
        return ['status', 'category_id'];
    }

    protected function beforeSave(array $data, ?array $existing): array
    {
        if (!empty($data['published_at'])) {
            $data['published_at'] = Validator::parseDateTime((string) $data['published_at'])->format('Y-m-d H:i:s');
        } elseif (($data['status'] ?? null) === 'published' && empty($existing['published_at'])) {
            $data['published_at'] = $this->clock->nowString();
        }
        if (array_key_exists('body', $data)) {
            $words = str_word_count(strip_tags((string) $data['body']));
            $data['reading_minutes'] = max(1, (int) ceil($words / 220));
        }

        return $data;
    }
}
