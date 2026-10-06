<?php

declare(strict_types=1);

namespace App\Core;

use Dotenv\Dotenv;

/**
 * Loads environment configuration.
 *
 * Resolution order:
 *  1. Variables injected by the platform (process env / FastCGI params) always win.
 *  2. If APP_ENV is injected (e.g. "staging"), `.env.staging` is loaded from ENV_PATH when present.
 *  3. Otherwise `.env` is loaded (local development).
 *
 * ENV_PATH defaults to the backend root, which sits outside the public web root.
 */
final class Env
{
    private static bool $loaded = false;

    public static function load(string $basePath): void
    {
        if (self::$loaded) {
            return;
        }

        $declared = self::raw('APP_ENV');
        $path = self::raw('ENV_PATH') ?? $basePath;
        $file = $declared !== null ? ".env.{$declared}" : '.env';

        if (is_file($path . DIRECTORY_SEPARATOR . $file)) {
            Dotenv::createImmutable($path, $file)->safeLoad();
        }

        if ($declared !== null && self::get('APP_ENV') !== $declared) {
            throw new \RuntimeException('APP_ENV in the environment file does not match the declared environment.');
        }

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::raw($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return is_string($value) ? $value : (string) ($value ?? $default);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return list<string> */
    public static function list(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', self::string($key)))));
    }

    private static function raw(string $key): ?string
    {
        if (array_key_exists($key, $_ENV)) {
            return (string) $_ENV[$key];
        }
        if (array_key_exists($key, $_SERVER) && is_scalar($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        $value = getenv($key);

        return $value === false ? null : $value;
    }
}
