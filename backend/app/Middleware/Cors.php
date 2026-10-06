<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/** Exact-match origin allowlist. Unknown origins get no CORS headers (browser blocks). */
final class Cors implements Middleware
{
    public function __construct(private Config $config)
    {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        if ($request->method === 'OPTIONS') {
            return $this->apply($request, Response::noContent())
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, X-CSRF-Token, X-Access-Token, X-Request-Id, Idempotency-Key')
                ->header('Access-Control-Max-Age', '600');
        }

        return $this->apply($request, $next($request));
    }

    public function apply(Request $request, Response $response): Response
    {
        $origin = (string) $request->header('origin', '');
        $allowed = $this->config->get('app.cors_allowed_origins', []);
        if ($origin !== '' && in_array($origin, $allowed, true)) {
            $response->header('Access-Control-Allow-Origin', $origin)
                ->header('Access-Control-Allow-Credentials', 'true')
                ->header('Access-Control-Expose-Headers', 'X-Request-Id, Retry-After')
                ->header('Vary', 'Origin');
        }

        return $response;
    }
}
