<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Resources;

use App\Controllers\Admin\ResourceController;
use App\Core\HttpException;

final class AvailabilityController extends ResourceController
{
    protected function table(): string
    {
        return 'availability_schedules';
    }

    protected function rules(): array
    {
        return [
            'appointment_type_id' => 'nullable|integer',
            'weekday' => 'required|integer|between:1,7',
            'start_time' => 'required|time',
            'end_time' => 'required|time',
            'timezone' => 'required|timezone',
            'city_id' => 'nullable|integer',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date',
            'is_active' => 'nullable|boolean',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'weekday', 'start_time'];
    }

    protected function filterable(): array
    {
        return ['appointment_type_id', 'weekday', 'is_active'];
    }

    protected function defaultSort(): string
    {
        return 'weekday';
    }

    protected function affectsPublicSite(): bool
    {
        return false;
    }

    protected function beforeSave(array $data, ?array $existing): array
    {
        $start = $data['start_time'] ?? $existing['start_time'] ?? null;
        $end = $data['end_time'] ?? $existing['end_time'] ?? null;
        if ($start !== null && $end !== null && strcmp(substr((string) $end, 0, 5), substr((string) $start, 0, 5)) <= 0) {
            throw HttpException::validation(['end_time' => ['End time must be after start time.']]);
        }
        $from = $data['valid_from'] ?? $existing['valid_from'] ?? null;
        $until = $data['valid_until'] ?? $existing['valid_until'] ?? null;
        if ($from !== null && $until !== null && $until < $from) {
            throw HttpException::validation(['valid_until' => ['Must be on or after the start date.']]);
        }

        return $data;
    }
}
