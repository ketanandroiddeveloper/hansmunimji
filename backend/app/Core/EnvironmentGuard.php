<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Refuses to boot when configuration is inconsistent with the declared environment,
 * e.g. live payment keys on staging or test keys / debug mode on production.
 */
final class EnvironmentGuard
{
    /** @return list<string> problems found (empty when safe) */
    public static function problems(Config $config): array
    {
        $env = $config->environment();
        $problems = [];

        if (!in_array($env, ['local', 'testing', 'staging', 'production'], true)) {
            $problems[] = "Unknown APP_ENV '{$env}'.";
        }

        $keys = [
            'razorpay key' => (string) $config->get('payments.razorpay.key_id', ''),
            'stripe secret' => (string) $config->get('payments.stripe.secret_key', ''),
            'stripe publishable' => (string) $config->get('payments.stripe.publishable_key', ''),
        ];

        foreach ($keys as $label => $value) {
            if ($value === '') {
                continue;
            }
            $isLive = str_contains($value, '_live_');
            if ($env === 'production' && !$isLive) {
                $problems[] = "Production must use a live {$label}.";
            }
            if ($env !== 'production' && $isLive) {
                $problems[] = "Live {$label} is not allowed in '{$env}'.";
            }
        }

        if ($env === 'production') {
            if ($config->get('app.debug')) {
                $problems[] = 'APP_DEBUG must be false in production.';
            }
            if (!str_starts_with((string) $config->get('app.url'), 'https://')) {
                $problems[] = 'APP_URL must use HTTPS in production.';
            }
            if (!str_starts_with((string) $config->get('app.frontend_url'), 'https://')) {
                $problems[] = 'FRONTEND_URL must use HTTPS in production.';
            }
            if ($config->get('mail.driver') !== 'smtp') {
                $problems[] = 'MAIL_DRIVER must be smtp in production (the log driver writes messages to disk).';
            }
        }

        if (in_array($env, ['staging', 'production'], true) && !$config->get('security.session.secure')) {
            $problems[] = 'SESSION_SECURE_COOKIE must be true outside local development.';
        }

        if (in_array($env, ['staging', 'production'], true) && !$config->get('security.require_two_factor')) {
            $problems[] = 'ADMIN_REQUIRE_TWO_FACTOR must be true outside local development.';
        }

        if (in_array($env, ['staging', 'production'], true)) {
            foreach (['security.app_key', 'security.blind_index_key'] as $required) {
                if (strlen((string) $config->get($required, '')) < 32) {
                    $problems[] = "{$required} must be configured with at least 32 bytes.";
                }
            }
            if ($config->get('security.encryption_keys', []) === []) {
                $problems[] = 'ENCRYPTION_KEYS must be configured.';
            }
        }

        return $problems;
    }

    public static function assert(Config $config): void
    {
        $problems = self::problems($config);
        if ($problems !== []) {
            throw new \RuntimeException("Environment configuration rejected:\n - " . implode("\n - ", $problems));
        }
    }
}
