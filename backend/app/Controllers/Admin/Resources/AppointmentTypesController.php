<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Resources;

use App\Controllers\Admin\ResourceController;
use App\Core\HttpException;
use App\Core\Validator;

final class AppointmentTypesController extends ResourceController
{
    protected function table(): string
    {
        return 'appointment_types';
    }

    protected function rules(): array
    {
        return [
            'service_id' => 'nullable|integer',
            'slug' => 'required|slug|max:120',
            'title' => 'required|string|max:190',
            'description' => 'nullable|string|max:5000',
            'duration_minutes' => 'required|integer|between:15,600',
            'formats' => 'required|array',
            'slot_interval_minutes' => 'nullable|integer|between:5,240',
            'buffer_before_minutes' => 'nullable|integer|between:0,240',
            'buffer_after_minutes' => 'nullable|integer|between:0,240',
            'lead_time_hours' => 'nullable|integer|between:0,720',
            'max_advance_days' => 'nullable|integer|between:1,365',
            'cancellation_window_hours' => 'nullable|integer|between:0,720',
            'reschedule_window_hours' => 'nullable|integer|between:0,720',
            'refund_on_cancel_percent' => 'nullable|integer|between:0,100',
            'cancellation_policy' => 'nullable|string|max:5000',
            'reschedule_policy' => 'nullable|string|max:5000',
            'requires_payment' => 'nullable|boolean',
            'requires_approval' => 'nullable|boolean',
            'tax_label' => 'nullable|string|max:60',
            'tax_rate_bp' => 'nullable|integer|between:0,5000',
            'tax_inclusive' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'is_public' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ];
    }

    protected function jsonFields(): array
    {
        return ['formats'];
    }

    protected function searchable(): array
    {
        return ['title', 'slug'];
    }

    protected function sortable(): array
    {
        return ['id', 'title', 'sort_order'];
    }

    protected function filterable(): array
    {
        return ['is_active', 'service_id'];
    }

    protected function defaultSort(): string
    {
        return 'sort_order';
    }

    protected function beforeSave(array $data, ?array $existing): array
    {
        if (isset($data['formats'])) {
            $formats = array_values(array_unique(array_filter($data['formats'], static fn ($f) => in_array($f, ['google_meet', 'phone', 'in_person'], true))));
            if ($formats === []) {
                throw HttpException::validation(['formats' => ['Choose at least one consultation format.']]);
            }
            $data['formats'] = $formats;
        }

        return $data;
    }

    protected function afterSave(int $id, array $input, ?array $existing): void
    {
        if (!array_key_exists('prices', $input)) {
            return;
        }
        if (!is_array($input['prices'])) {
            throw HttpException::validation(['prices' => ['Must be a list.']]);
        }
        $this->db->delete('appointment_type_prices', ['appointment_type_id' => $id]);
        foreach ($input['prices'] as $i => $price) {
            $p = Validator::validate((array) $price, ['currency' => 'required|in:INR,USD,AED,GBP', 'amount_minor' => 'required|integer|min:0']);
            $this->db->run(
                'INSERT INTO appointment_type_prices (appointment_type_id, currency, amount_minor) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE amount_minor = VALUES(amount_minor)',
                [$id, $p['currency'], $p['amount_minor']],
            );
        }
    }

    protected function present(array $row): array
    {
        $row = parent::present($row);
        $row['prices'] = $this->db->all('SELECT currency, amount_minor FROM appointment_type_prices WHERE appointment_type_id = ? ORDER BY currency', [$row['id']]);

        return $row;
    }
}
