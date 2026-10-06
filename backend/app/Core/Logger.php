<?php

declare(strict_types=1);

namespace App\Core;

/**
 * JSON-lines logger with recursive redaction of sensitive keys.
 */
final class Logger
{
    private const REDACT = '/(pass(word)?|secret|token|signature|authorization|cookie|card|cvv|otp|code|_enc$|email|phone|name|key)/i';

    public function __construct(private string $directory)
    {
    }

    /** @param array<string, mixed> $context */
    public function info(string $event, array $context = []): void
    {
        $this->write('info', $event, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $event, array $context = []): void
    {
        $this->write('warning', $event, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $event, array $context = []): void
    {
        $this->write('error', $event, $context);
    }

    /** @param array<string, mixed> $context */
    public function channel(string $channel, string $event, array $context = []): void
    {
        $this->write('info', $event, $context, $channel);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function redact(array $context): array
    {
        $safe = ['request_id', 'type', 'path', 'file', 'event', 'gateway', 'status', 'reference', 'message', 'template', 'job', 'attempts', 'code', 'error_code'];
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = self::redact($value);
            } elseif (is_string($key) && !in_array($key, $safe, true) && preg_match(self::REDACT, $key)) {
                $context[$key] = '[redacted]';
            }
        }

        return $context;
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $event, array $context, string $channel = 'app'): void
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0750, true);
        }
        $line = json_encode([
            'ts' => gmdate('c'),
            'level' => $level,
            'event' => $event,
            'context' => self::redact($context),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        @file_put_contents(
            $this->directory . '/' . $channel . '-' . gmdate('Y-m-d') . '.log',
            $line . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }
}
