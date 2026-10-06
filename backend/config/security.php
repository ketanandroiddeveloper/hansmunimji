<?php

declare(strict_types=1);

use App\Core\Env;

$decode = static function (string $value): string {
    if (str_starts_with($value, 'base64:')) {
        $decoded = base64_decode(substr($value, 7), true);

        return $decoded === false ? '' : $decoded;
    }

    return $value;
};

$keys = [];
foreach (Env::list('ENCRYPTION_KEYS') as $pair) {
    [$version, $material] = array_pad(explode(':', $pair, 2), 2, '');
    $binary = base64_decode($material, true);
    if (ctype_digit($version) && $binary !== false && strlen($binary) === 32) {
        $keys[(int) $version] = $binary;
    }
}

return [
    'app_key' => $decode(Env::string('APP_KEY')),
    'blind_index_key' => $decode(Env::string('BLIND_INDEX_KEY')),
    'encryption_keys' => $keys,
    'encryption_active_key' => Env::int('ENCRYPTION_ACTIVE_KEY', $keys === [] ? 0 : max(array_keys($keys))),
    'session' => [
        'cookie' => Env::bool('SESSION_SECURE_COOKIE', true) ? '__Host-pa_session' : 'pa_session',
        'secure' => Env::bool('SESSION_SECURE_COOKIE', true),
        'idle_minutes' => Env::int('SESSION_IDLE_MINUTES', 30),
        'absolute_hours' => Env::int('SESSION_ABSOLUTE_HOURS', 12),
    ],
    'require_two_factor' => Env::bool('ADMIN_REQUIRE_TWO_FACTOR', true),
    'password' => [
        'min_length' => 12,
        'argon_memory_kib' => Env::int('ARGON_MEMORY_KIB', 65536),
        'argon_time_cost' => Env::int('ARGON_TIME_COST', 4),
        'argon_threads' => 1,
    ],
    'lockout' => [
        'threshold' => 5,
        'base_minutes' => 15,
        'max_minutes' => 1440,
    ],
    'access_tokens' => [
        'application_days' => 30,
        'appointment_days_after' => 7,
        'invite_days' => 14,
    ],
    'slot_hold_minutes' => Env::int('SLOT_HOLD_MINUTES', 10),
    'payment_hold_minutes' => Env::int('PAYMENT_HOLD_MINUTES', 30),
];
