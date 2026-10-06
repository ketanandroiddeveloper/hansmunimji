<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Resources;

use App\Controllers\Admin\ResourceController;
use App\Core\Request;
use App\Core\Response;

/**
 * Pages hold structured `sections` (plain-text fields and media ids rendered by the frontend's
 * section components) and an optional sanitised rich-text `body` (used by legal pages).
 */
final class PagesController extends ResourceController
{
    protected function table(): string
    {
        return 'pages';
    }

    protected function rules(): array
    {
        return [
            'slug' => 'required|slug|max:120',
            'type' => 'required|in:page,legal',
            'title' => 'required|string|max:255',
            'sections' => 'nullable|array',
            'body' => 'nullable|string|max:300000',
            'status' => 'required|in:draft,published',
        ];
    }

    protected function htmlFields(): array
    {
        return ['body'];
    }

    protected function jsonFields(): array
    {
        return ['sections'];
    }

    protected function searchable(): array
    {
        return ['title', 'slug'];
    }

    protected function sortable(): array
    {
        return ['id', 'title', 'updated_at'];
    }

    protected function filterable(): array
    {
        return ['type', 'status'];
    }

    protected function defaultSort(): string
    {
        return 'title';
    }

    protected function beforeSave(array $data, ?array $existing): array
    {
        if (isset($data['sections'])) {
            $data['sections'] = self::stripMarkup($data['sections']);
        }
        if (($data['status'] ?? null) === 'published' && empty($existing['published_at'])) {
            $data['published_at'] = $this->clock->nowString();
        }

        return $data;
    }

    public function update(Request $request): Response
    {
        $response = parent::update($request);
        $this->db->update('pages', ['updated_by' => $this->userId($request) ?: null], ['id' => $request->intParam('id')]);

        return $response;
    }

    /** Section values are rendered as text by the frontend; markup is removed defensively. */
    private static function stripMarkup(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::stripMarkup(...), $value);
        }

        return is_string($value) ? trim(strip_tags($value)) : $value;
    }
}
