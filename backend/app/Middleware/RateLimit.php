<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Security\RateLimiter;

/** Usage: `throttle:bucket,maxHits,windowSeconds` — keyed by client IP. */
final class RateLimit implements Middleware
{
    public function __construct(private RateLimiter $limiter, private Config $config)
    {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        [$bucket, $max, $window] = $args + ['default', '60', '60'];
        $wait = $this->limiter->hit($bucket, $request->ip($this->config->get('app.trusted_proxies', [])), (int) $max, (int) $window);
        if ($wait > 0) {
            throw new HttpException(429, 'rate_limited', 'Too many requests. Please wait a moment and try again.', [], ['Retry-After' => (string) $wait]);
        }

        return $next($request);
    }
}
