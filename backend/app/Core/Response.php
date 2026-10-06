<?php

declare(strict_types=1);

namespace App\Core;

class Response
{
    /** @var array<string, string> */
    protected array $headers = [];

    /** @var list<string> */
    protected array $cookies = [];

    /** @var (callable(): void)|null */
    protected $streamer = null;

    public function __construct(protected string $body = '', protected int $status = 200, array $headers = [])
    {
        foreach ($headers as $name => $value) {
            $this->header($name, $value);
        }
    }

    /** @param array<string, mixed>|null $meta */
    public static function json(mixed $data, int $status = 200, ?array $meta = null): self
    {
        $payload = ['data' => $data];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return self::rawJson($payload, $status);
    }

    /** @param array<string, mixed> $payload */
    public static function rawJson(array $payload, int $status = 200): self
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return new self($body, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    /** @param callable(): void $streamer */
    public static function stream(callable $streamer, int $status, array $headers): self
    {
        $response = new self('', $status, $headers);
        $response->streamer = $streamer;

        return $response;
    }

    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$name]);
    }

    public function cookie(
        string $name,
        string $value,
        int $expires,
        bool $secure,
        string $sameSite = 'Strict',
        bool $httpOnly = true,
    ): static {
        $parts = [rawurlencode($name) . '=' . rawurlencode($value), 'Path=/'];
        if ($expires > 0) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', $expires);
            $parts[] = 'Max-Age=' . max(0, $expires - time());
        } elseif ($expires < 0) {
            $parts[] = 'Expires=Thu, 01 Jan 1970 00:00:00 GMT';
            $parts[] = 'Max-Age=0';
        }
        if ($secure) {
            $parts[] = 'Secure';
        }
        if ($httpOnly) {
            $parts[] = 'HttpOnly';
        }
        $parts[] = 'SameSite=' . $sameSite;
        $this->cookies[] = implode('; ', $parts);

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
            foreach ($this->cookies as $cookie) {
                header('Set-Cookie: ' . $cookie, false);
            }
        }
        if ($this->streamer !== null) {
            ($this->streamer)();

            return;
        }
        echo $this->body;
    }
}
