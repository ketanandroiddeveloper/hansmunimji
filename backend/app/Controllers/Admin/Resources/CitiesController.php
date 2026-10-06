<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Resources;

use App\Controllers\Admin\ResourceController;
use App\Core\HttpException;

final class CitiesController extends ResourceController
{
    protected function table(): string
    {
        return 'cities';
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'country' => 'required|string|min:2|max:2',
            'timezone' => 'required|timezone',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ];
    }

    protected function hasTimestamps(): bool
    {
        return false;
    }

    protected function sortable(): array
    {
        return ['id', 'name', 'sort_order'];
    }

    protected function filterable(): array
    {
        return ['is_active'];
    }

    protected function defaultSort(): string
    {
        return 'sort_order';
    }

    protected function beforeSave(array $data, ?array $existing): array
    {
        if (isset($data['country'])) {
            $data['country'] = strtoupper((string) $data['country']);
            if (!preg_match('/^[A-Z]{2}$/', $data['country'])) {
                throw HttpException::validation(['country' => ['Use a two-letter ISO country code.']]);
            }
        }

        return $data;
    }
}
