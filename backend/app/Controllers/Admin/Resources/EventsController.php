<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Resources;

use App\Controllers\Admin\ResourceController;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Validator;
use App\Services\Events\EventRegistrationService;

final class EventsController extends ResourceController
{
    private EventRegistrationService $registrations;

    protected function boot(Container $container): void
    {
        $this->registrations = $container->get(EventRegistrationService::class);
    }

    protected function table(): string
    {
        return 'events';
    }

    protected function rules(): array
    {
        return [
            'slug' => 'required|slug|max:160',
            'title' => 'required|string|max:255',
            'category' => 'required|in:retreat,full_moon,amavasya,keynote,executive_gathering,group_advisory,summit',
            'summary' => 'nullable|string|max:2000',
            'description' => 'nullable|string|max:100000',
            'cover_media_id' => 'nullable|integer',
            'city_id' => 'nullable|integer',
            'venue' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'starts_at' => 'required|datetime',
            'ends_at' => 'required|datetime',
            'timezone' => 'required|timezone',
            'seat_quota' => 'nullable|integer|min:1',
            'waitlist_enabled' => 'nullable|boolean',
            'registration_mode' => 'required|in:open,application,invitation,closed',
            'registration_opens_at' => 'nullable|datetime',
            'registration_closes_at' => 'nullable|datetime',
            'requirements' => 'nullable|string|max:5000',
            'tax_label' => 'nullable|string|max:60',
            'tax_rate_bp' => 'nullable|integer|between:0,5000',
            'tax_inclusive' => 'nullable|boolean',
            'cancellation_policy' => 'nullable|string|max:5000',
            'cancellation_window_hours' => 'nullable|integer|between:0,2160',
            'refund_on_cancel_percent' => 'nullable|integer|between:0,100',
            'status' => 'required|in:draft,published,cancelled,archived',
            'is_featured' => 'nullable|boolean',
        ];
    }

    protected function htmlFields(): array
    {
        return ['description'];
    }

    protected function searchable(): array
    {
        return ['title', 'slug', 'venue'];
    }

    protected function sortable(): array
    {
        return ['id', 'title', 'starts_at', 'status'];
    }

    protected function filterable(): array
    {
        return ['status', 'category'];
    }

    protected function defaultSort(): string
    {
        return '-starts_at';
    }

    protected function beforeSave(array $data, ?array $existing): array
    {
        foreach (['starts_at', 'ends_at', 'registration_opens_at', 'registration_closes_at'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = Validator::parseDateTime((string) $data[$field])->format('Y-m-d H:i:s');
            }
        }
        $start = $data['starts_at'] ?? $existing['starts_at'] ?? null;
        $end = $data['ends_at'] ?? $existing['ends_at'] ?? null;
        if ($start !== null && $end !== null && $end <= $start) {
            throw HttpException::validation(['ends_at' => ['Must be after the start.']]);
        }
        $opens = array_key_exists('registration_opens_at', $data) ? $data['registration_opens_at'] : ($existing['registration_opens_at'] ?? null);
        $closes = array_key_exists('registration_closes_at', $data) ? $data['registration_closes_at'] : ($existing['registration_closes_at'] ?? null);
        if ($opens !== null && $closes !== null && $closes <= $opens) {
            throw HttpException::validation(['registration_closes_at' => ['Must be after registration opens.']]);
        }
        if ($closes !== null && $start !== null && $closes > $start) {
            throw HttpException::validation(['registration_closes_at' => ['Registration must close before the gathering starts.']]);
        }
        if ($existing !== null && $existing['status'] === 'cancelled' && isset($data['status']) && $data['status'] === 'published') {
            throw HttpException::conflict('invalid_state', 'A cancelled gathering cannot be re-published; its registrations were cancelled and refunded. Create a new gathering instead.');
        }

        return $data;
    }

    protected function afterSave(int $id, array $input, ?array $existing): void
    {
        if (array_key_exists('prices', $input)) {
            if (!is_array($input['prices'])) {
                throw HttpException::validation(['prices' => ['Must be a list.']]);
            }
            $this->db->delete('event_prices', ['event_id' => $id]);
            foreach ($input['prices'] as $price) {
                $p = Validator::validate((array) $price, ['currency' => 'required|currency', 'amount_minor' => 'required|integer|min:0']);
                if ((int) $p['amount_minor'] === 0) {
                    continue;
                }
                $this->db->run(
                    'INSERT INTO event_prices (event_id, currency, amount_minor) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE amount_minor = VALUES(amount_minor)',
                    [$id, $p['currency'], $p['amount_minor']],
                );
            }
        }

        $wasCancelled = $existing !== null && $existing['status'] === 'cancelled';
        if (!$wasCancelled && ($input['status'] ?? null) === 'cancelled') {
            $this->registrations->cancelForEvent($id);
        } elseif ($existing !== null && array_key_exists('seat_quota', $input)) {
            // A larger quota may make room for waitlisted guests.
            $this->registrations->promoteWaitlist($id);
        }
    }

    protected function present(array $row): array
    {
        $row = parent::present($row);
        $row['prices'] = $this->db->all('SELECT currency, amount_minor FROM event_prices WHERE event_id = ? ORDER BY currency', [$row['id']]);
        $row['seats_taken'] = $this->registrations->seatsTaken((int) $row['id']);
        $row['waitlist_count'] = (int) $this->db->value("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status = 'waitlisted'", [$row['id']]);

        return $row;
    }
}
