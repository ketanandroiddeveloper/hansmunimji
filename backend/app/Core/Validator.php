<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Declarative input validation. Only keys declared in the rules are returned, so unexpected
 * input never reaches persistence (mass-assignment protection).
 *
 * Rules: required, sometimes, nullable, string, integer, numeric, boolean, accepted, array,
 * email, url, slug, phone, timezone, currency, date, datetime, time, min:n, max:n, in:a,b,c,
 * between:a,b
 */
final class Validator
{
    public const CURRENCIES = ['INR', 'USD', 'AED', 'GBP'];

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $rules
     * @return array<string, mixed>
     */
    public static function validate(array $input, array $rules): array
    {
        $errors = [];
        $out = [];

        foreach ($rules as $field => $ruleString) {
            $list = explode('|', $ruleString);
            $present = array_key_exists($field, $input);
            $value = $input[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }

            if (in_array('sometimes', $list, true) && !$present) {
                continue;
            }

            $empty = $value === null || $value === '' || $value === [];
            if ($empty) {
                if (in_array('required', $list, true)) {
                    $errors[$field][] = 'This field is required.';
                } elseif (in_array('accepted', $list, true)) {
                    $errors[$field][] = 'This must be accepted.';
                } elseif ($present || in_array('nullable', $list, true)) {
                    $out[$field] = in_array('boolean', $list, true) ? false : null;
                }
                continue;
            }

            $fieldErrors = [];
            foreach ($list as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $error = self::check($name, $arg, $value, $list);
                if ($error !== null) {
                    $fieldErrors[] = $error;
                    break;
                }
            }

            if ($fieldErrors !== []) {
                $errors[$field] = $fieldErrors;
                continue;
            }

            $out[$field] = self::cast($value, $list);
        }

        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return $out;
    }

    /** @param list<string> $rules */
    private static function check(string $rule, ?string $arg, mixed $value, array $rules): ?string
    {
        $isNumericRule = in_array('integer', $rules, true) || in_array('numeric', $rules, true);

        return match ($rule) {
            'required', 'sometimes', 'nullable' => null,
            'string' => is_string($value) ? null : 'Must be text.',
            'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false ? null : 'Must be a whole number.',
            'numeric' => is_numeric($value) ? null : 'Must be a number.',
            'boolean' => in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true) ? null : 'Must be true or false.',
            'accepted' => in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true) ? null : 'This must be accepted.',
            'array' => is_array($value) ? null : 'Must be a list.',
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) && mb_strlen($value) <= 190 ? null : 'Enter a valid email address.',
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $value) ? null : 'Enter a valid URL.',
            'slug' => is_string($value) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) ? null : 'Use lowercase letters, numbers and hyphens.',
            'phone' => is_string($value) && preg_match('/^\+[1-9][0-9 ()-]{6,20}$/', $value) ? null : 'Enter a phone number with country code, e.g. +971 50 123 4567.',
            // Includes legacy aliases (e.g. Asia/Calcutta) that some browsers still report.
            'timezone' => is_string($value) && in_array($value, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true) ? null : 'Unknown time zone.',
            'currency' => is_string($value) && in_array($value, self::CURRENCIES, true) ? null : 'Choose one of: ' . implode(', ', self::CURRENCIES) . '.',
            'country' => is_string($value) && preg_match('/^[A-Z]{2}$/', $value) ? null : 'Choose a country.',
            'date' => is_string($value) && \DateTimeImmutable::createFromFormat('!Y-m-d', $value) !== false ? null : 'Use the format YYYY-MM-DD.',
            'time' => is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value) ? null : 'Use the format HH:MM.',
            'datetime' => is_string($value) && self::parseDateTime($value) !== null ? null : 'Use an ISO-8601 date and time.',
            'min' => self::size($value, $isNumericRule) >= (float) $arg ? null : ($isNumericRule ? "Must be at least {$arg}." : "Must be at least {$arg} characters."),
            'max' => self::size($value, $isNumericRule) <= (float) $arg ? null : ($isNumericRule ? "Must be at most {$arg}." : "Must be at most {$arg} characters."),
            'between' => (static function () use ($value, $arg, $isNumericRule) {
                [$lo, $hi] = array_map('floatval', explode(',', (string) $arg));
                $size = self::size($value, $isNumericRule);

                return $size >= $lo && $size <= $hi ? null : "Must be between {$lo} and {$hi}.";
            })(),
            'in' => in_array((string) (is_bool($value) ? (int) $value : $value), explode(',', (string) $arg), true) ? null : 'Select a valid option.',
            default => throw new \LogicException("Unknown validation rule '{$rule}'."),
        };
    }

    private static function size(mixed $value, bool $numeric): float
    {
        if ($numeric && is_numeric($value)) {
            return (float) $value;
        }
        if (is_array($value)) {
            return count($value);
        }

        return mb_strlen((string) $value);
    }

    /** @param list<string> $rules */
    private static function cast(mixed $value, array $rules): mixed
    {
        if (in_array('integer', $rules, true)) {
            return (int) $value;
        }
        if (in_array('numeric', $rules, true)) {
            return (float) $value;
        }
        if (in_array('boolean', $rules, true) || in_array('accepted', $rules, true)) {
            return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
        }
        if (in_array('email', $rules, true)) {
            return mb_strtolower((string) $value);
        }

        return $value;
    }

    public static function parseDateTime(string $value): ?\DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/', $value)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
