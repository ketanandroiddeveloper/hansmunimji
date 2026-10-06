<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    /** @var array<string, mixed>|null */
    private ?array $json = null;

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers lower-cased header names
     * @param array<string, mixed> $server
     * @param array<string, mixed> $files
     * @param array<string, mixed> $post
     * @param array<string, string> $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private array $query,
        private array $headers,
        private string $rawBody,
        private array $server = [],
        private array $files = [],
        private array $post = [],
        private array $cookies = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $path = '/' . trim(rawurldecode($path), '/');

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $headers,
            (string) file_get_contents('php://input'),
            $_SERVER,
            $_FILES,
            $_POST,
            array_map('strval', $_COOKIE),
        );
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function allQuery(): array
    {
        return $this->query;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        if ($this->rawBody === '') {
            return $this->json = [];
        }
        $decoded = json_decode($this->rawBody, true, 32);
        if (!is_array($decoded)) {
            throw new HttpException(400, 'malformed_json', 'Request body must be a JSON object.');
        }

        return $this->json = $decoded;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $source = $this->isMultipart() ? $this->post : $this->json();

        return $source[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->isMultipart() ? $this->post : $this->json();
    }

    /** @return array<string, mixed>|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) ? $file : null;
    }

    public function isMultipart(): bool
    {
        return str_starts_with((string) $this->header('content-type'), 'multipart/form-data');
    }

    /**
     * Client IP, honouring X-Forwarded-For only when the direct peer is a trusted proxy.
     *
     * @param list<string> $trustedProxies
     */
    public function ip(array $trustedProxies = []): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        if ($trustedProxies !== [] && in_array($remote, $trustedProxies, true)) {
            $forwarded = $this->header('x-forwarded-for');
            if ($forwarded !== null) {
                $parts = array_map('trim', explode(',', $forwarded));
                for ($i = count($parts) - 1; $i >= 0; $i--) {
                    if (!in_array($parts[$i], $trustedProxies, true) && filter_var($parts[$i], FILTER_VALIDATE_IP)) {
                        return $parts[$i];
                    }
                }
            }
        }

        return $remote;
    }

    public function userAgent(): string
    {
        return mb_substr((string) $this->header('user-agent', ''), 0, 255);
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function param(string $key): string
    {
        $params = $this->attributes['route_params'] ?? [];

        return (string) ($params[$key] ?? '');
    }

    public function intParam(string $key): int
    {
        $value = $this->param($key);
        if (!ctype_digit($value)) {
            throw new HttpException(404, 'not_found', 'Resource not found.');
        }

        return (int) $value;
    }
}
