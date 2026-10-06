<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Container;
use App\Services\SettingsService;

final class SettingsSeeder
{
    /** key => [value, is_public]. Contact details are intentionally blank until the practice provides them. */
    private const DEFAULTS = [
        'site.name' => ['Hansmuniji', true],
        'site.tagline' => ['Private advisory for the inner life of leadership', true],
        'site.description' => ['Private meditation, contemplative practice and executive advisory, offered in confidence to leaders, founders and families.', true],
        'contact.email' => ['', true],
        'contact.phone' => ['', true],
        'contact.whatsapp' => ['', true],
        'social.links' => [[], true],
        'currencies.enabled' => [['INR', 'USD', 'AED', 'GBP'], true],
        'currencies.default' => ['INR', true],
        'analytics.ga_measurement_id' => ['', true],
        'consent.banner_text' => ['We use strictly necessary storage to run this site. With your permission we also use privacy-respecting analytics to understand which pages are useful. Confidential information you share with us is never sent to analytics providers.', true],
        'notifications.admin_email' => ['', false],
        'privacy.policy_version' => ['1.0', true],
        'privacy.retention_rejected_days' => [180, false],
        'booking.max_reschedules' => [2, false],
        'reminders.schedule' => [[['minutes' => 1440, 'enabled' => true], ['minutes' => 60, 'enabled' => true]], false],
        'payments.routing' => [['INR' => ['razorpay', 'stripe'], 'USD' => ['stripe'], 'AED' => ['stripe'], 'GBP' => ['stripe']], false],
        'payments.auto_refund_conflicts' => [true, false],
    ];

    public function __construct(private Container $container)
    {
    }

    /** @return iterable<string> */
    public function run(): iterable
    {
        $settings = $this->container->get(SettingsService::class);
        $added = 0;
        foreach (self::DEFAULTS as $key => [$value, $public]) {
            if (!array_key_exists($key, $settings->all())) {
                $settings->set($key, $value, $public);
                $added++;
            }
        }
        yield "Settings: {$added} added.";
    }
}
