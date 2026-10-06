<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Resources;

use App\Controllers\Admin\ResourceController;
use App\Core\HttpException;

final class SeoMetadataController extends ResourceController
{
    private const ROBOTS = ['index,follow', 'noindex,follow', 'noindex,nofollow', 'index,nofollow'];

    protected function table(): string
    {
        return 'seo_metadata';
    }

    protected function rules(): array
    {
        return [
            'path' => 'required|string|max:255',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:500',
            'canonical' => 'nullable|url|max:500',
            'og_image_media_id' => 'nullable|integer',
            'robots' => 'nullable|string|max:60',
        ];
    }

    protected function hasTimestamps(): bool
    {
        return false;
    }

    protected function searchable(): array
    {
        return ['path', 'title'];
    }

    protected function sortable(): array
    {
        return ['id', 'path'];
    }

    protected function defaultSort(): string
    {
        return 'path';
    }

    protected function beforeSave(array $data, ?array $existing): array
    {
        if (isset($data['path'])) {
            if (!preg_match('#^/[a-z0-9/_-]*$#', (string) $data['path'])) {
                throw HttpException::validation(['path' => ['Use a site path such as /practice/executive-mind-architecture.']]);
            }
            $clash = $this->db->value('SELECT id FROM seo_metadata WHERE path = ? AND id <> ?', [$data['path'], $existing['id'] ?? 0]);
            if ($clash) {
                throw HttpException::validation(['path' => ['Metadata for this path already exists.']]);
            }
        }
        if (isset($data['robots']) && !in_array($data['robots'], self::ROBOTS, true)) {
            throw HttpException::validation(['robots' => ['Choose one of: ' . implode(' | ', self::ROBOTS) . '.']]);
        }
        $data['updated_at'] = $this->clock->nowString();

        return $data;
    }
}
