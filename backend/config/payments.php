<?php

declare(strict_types=1);

use App\Core\Env;

$currencies = static function (string $key, string $default): array {
    $list = Env::list($key) ?: explode(',', $default);

    return array_values(array_intersect(array_map('strtoupper', $list), ['INR', 'USD', 'AED', 'GBP']));
};

return [
    // Currencies are only offered through a gateway once the merchant account is confirmed to accept
    // them (Razorpay international payments and Stripe presentment currencies need activation).
    'razorpay' => [
        'key_id' => Env::string('RAZORPAY_KEY_ID'),
        'key_secret' => Env::string('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => Env::string('RAZORPAY_WEBHOOK_SECRET'),
        'api_base' => 'https://api.razorpay.com/v1/',
        'currencies' => $currencies('RAZORPAY_CURRENCIES', 'INR'),
    ],
    'stripe' => [
        'secret_key' => Env::string('STRIPE_SECRET_KEY'),
        'publishable_key' => Env::string('STRIPE_PUBLISHABLE_KEY'),
        'webhook_secret' => Env::string('STRIPE_WEBHOOK_SECRET'),
        'webhook_tolerance' => 300,
        'currencies' => $currencies('STRIPE_CURRENCIES', 'USD,AED,GBP'),
        // Stripe requires 30 minutes to 24 hours.
        'checkout_expiry_minutes' => min(1440, max(30, Env::int('PAYMENT_HOLD_MINUTES', 30) + 1)),
    ],
    'supported_currencies' => ['INR', 'USD', 'AED', 'GBP'],
];
