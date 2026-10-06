<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;

/**
 * Local/staging only (`db:seed --demo`): activates appointment types with obviously placeholder
 * prices and weekday availability so booking and test-mode payments can be exercised end to end.
 */
final class DemoSeeder
{
    public function __construct(private Container $container)
    {
    }

    /** @return iterable<string> */
    public function run(): iterable
    {
        if ($this->container->get(Config::class)->isProduction()) {
            throw new \RuntimeException('Demo data cannot be seeded in production.');
        }
        $db = $this->container->get(Database::class);
        $now = $this->container->get(Clock::class)->nowString();

        // Placeholder amounts in minor units, chosen to be visibly non-real (e.g. ₹1,111.00).
        $prices = ['INR' => 111100, 'USD' => 1111, 'AED' => 4111, 'GBP' => 1111];
        foreach (['celestial-meditation-private', 'executive-mind-consultation'] as $slug) {
            $typeId = $db->value('SELECT id FROM appointment_types WHERE slug = ?', [$slug]);
            if (!$typeId) {
                continue;
            }
            $db->update('appointment_types', ['is_active' => 1, 'lead_time_hours' => 2, 'updated_at' => $now], ['id' => $typeId]);
            foreach ($prices as $currency => $amount) {
                $db->run(
                    'INSERT INTO appointment_type_prices (appointment_type_id, currency, amount_minor) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE amount_minor = amount_minor',
                    [$typeId, $currency, $amount],
                );
            }
        }

        if (!$db->value('SELECT 1 FROM availability_schedules LIMIT 1')) {
            foreach ([1, 2, 3, 4, 5, 6] as $weekday) {
                $db->insert('availability_schedules', [
                    'appointment_type_id' => null, 'weekday' => $weekday, 'start_time' => '10:00:00', 'end_time' => '18:00:00',
                    'timezone' => 'Asia/Kolkata', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        if (!$db->value("SELECT 1 FROM events WHERE slug = 'demo-full-moon-circle'")) {
            $start = Clock::utc($now)->modify('+21 days')->setTime(13, 30);
            $eventId = $db->insert('events', [
                'slug' => 'demo-full-moon-circle', 'title' => 'Full-Moon Meditation Circle (demo)', 'category' => 'full_moon',
                'summary' => 'Demonstration event for testing registration and payment. Not a real gathering.',
                'description' => '<p>This is demonstration content for staging. Replace or delete before launch.</p>',
                'cover_media_id' => $db->value("SELECT id FROM media WHERE original_name = 'IMG_9701.jpg'"),
                'city_id' => $db->value("SELECT id FROM cities WHERE name = 'Mumbai'"),
                'venue' => 'Venue to be confirmed', 'starts_at' => $start, 'ends_at' => $start->modify('+2 hours'),
                'timezone' => 'Asia/Kolkata', 'seat_quota' => 12, 'registration_mode' => 'open',
                'status' => 'published', 'is_featured' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            // Placeholder prices for testing multi-currency checkout only.
            foreach (['INR' => 111100, 'USD' => 1500, 'AED' => 5500, 'GBP' => 1200] as $currency => $amount) {
                $db->insert('event_prices', ['event_id' => $eventId, 'currency' => $currency, 'amount_minor' => $amount]);
            }
        }

        yield 'Demo: two appointment types activated with placeholder prices, Mon–Sat 10:00–18:00 IST availability, one demo event.';
    }
}
