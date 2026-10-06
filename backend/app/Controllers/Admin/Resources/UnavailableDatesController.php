<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Resources;

use App\Controllers\Admin\ResourceController;
use App\Core\HttpException;
use App\Core\Validator;

final class UnavailableDatesController extends ResourceController
{
    protected function table(): string
    {
        return 'unavailable_dates';
    }

    protected function rules(): array
    {
        return [
            'appointment_type_id' => 'nullable|integer',
            'starts_at' => 'required|datetime',
            'ends_at' => 'required|datetime',
            'reason' => 'nullable|string|max:255',
        ];
    }

    protected function hasTimestamps(): bool
    {
        return false;
    }

    protected function sortable(): array
    {
        return ['id', 'starts_at'];
    }

    protected function filterable(): array
    {
        return ['appointment_type_id'];
    }

    protected function defaultSort(): string
    {
        return '-starts_at';
    }

    protected function affectsPublicSite(): bool
    {
        return false;
    }

    protected function beforeSave(array $data, ?array $existing): array
    {
        foreach (['starts_at', 'ends_at'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = Validator::parseDateTime((string) $data[$field])->format('Y-m-d H:i:s');
            }
        }
        $start = $data['starts_at'] ?? $existing['starts_at'] ?? null;
        $end = $data['ends_at'] ?? $existing['ends_at'] ?? null;
        if ($start !== null && $end !== null && $end <= $start) {
            throw HttpException::validation(['ends_at' => ['Must be after the start.']]);
        }
        if ($existing === null) {
            $data['created_at'] = $this->clock->nowString();
        }

        return $data;
    }
}
