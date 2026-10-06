<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Integrations\Payments\GatewayRegistry;
use App\Integrations\Payments\PaymentGateway;
use App\Services\SettingsService;

/**
 * Chooses which gateways are offered for a payment and in which order.
 *
 *  - `payments.routing` (admin setting): currency => ordered gateways allowed for that currency.
 *  - `payments.country_routing` (admin setting): ISO country => preferred gateways, tried first.
 *  - A gateway is only offered when it is configured and enabled for the currency
 *    (RAZORPAY_CURRENCIES / STRIPE_CURRENCIES, confirmed against the merchant account).
 */
final class PaymentRouter
{
    public const DEFAULT_ROUTING = [
        'INR' => ['razorpay', 'stripe'],
        'USD' => ['stripe', 'razorpay'],
        'AED' => ['stripe', 'razorpay'],
        'GBP' => ['stripe', 'razorpay'],
    ];

    public function __construct(private GatewayRegistry $gateways, private SettingsService $settings)
    {
    }

    /** @return list<PaymentGateway> */
    public function route(string $currency, ?string $country = null): array
    {
        $order = self::order(
            (array) $this->settings->get('payments.routing', self::DEFAULT_ROUTING),
            (array) $this->settings->get('payments.country_routing', []),
            $currency,
            $country,
        );

        return $this->gateways->forCurrency($currency, [$currency => $order]);
    }

    /**
     * Preference order for a currency: the country's preferred gateways first (when they are allowed
     * for the currency), then the currency's remaining gateways.
     *
     * @param array<string, mixed> $routing
     * @param array<string, mixed> $countryRouting
     * @return list<string>
     */
    public static function order(array $routing, array $countryRouting, string $currency, ?string $country): array
    {
        $allowed = array_values(array_filter((array) ($routing[$currency] ?? self::DEFAULT_ROUTING[$currency] ?? []), 'is_string'));
        $preferred = $country !== null ? array_values(array_filter((array) ($countryRouting[strtoupper($country)] ?? []), 'is_string')) : [];

        return array_values(array_unique([...array_intersect($preferred, $allowed), ...$allowed]));
    }
}
