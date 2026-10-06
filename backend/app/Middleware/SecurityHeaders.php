<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

final class SecurityHeaders implements Middleware
{
    public function __construct(private Config $config)
    {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        return $this->apply($next($request));
    }

    public function apply(Response $response): Response
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(self)',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-site',
            // API responses are data, never documents.
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
        ];
        if (str_starts_with((string) $this->config->get('app.url'), 'https://')) {
            $headers['Strict-Transport-Security'] = 'max-age=63072000; includeSubDomains; preload';
        }
        if ($this->config->environment() !== 'production') {
            $headers['X-Robots-Tag'] = 'noindex, nofollow';
        }
        if (!$response->hasHeader('Cache-Control')) {
            $headers['Cache-Control'] = 'no-store';
        }
        foreach ($headers as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response->header($name, $value);
            }
        }

        return $response->header('X-Environment', $this->config->environment());
    }
}
