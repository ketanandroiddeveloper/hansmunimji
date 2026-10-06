<?php

declare(strict_types=1);

namespace App\Core;

class HttpException extends \RuntimeException
{
    /** @param array<string, list<string>> $fields */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message, $status);
    }

    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function forbidden(string $message = 'You do not have permission to perform this action.'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }

    /** @param array<string, list<string>> $fields */
    public static function validation(array $fields, string $message = 'Please review the highlighted fields.'): self
    {
        return new self(422, 'validation_failed', $message, $fields);
    }
}
