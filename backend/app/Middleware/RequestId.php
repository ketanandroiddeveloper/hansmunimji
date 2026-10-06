<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

final class RequestId implements Middleware
{
    public function handle(Request $request, callable $next, string ...$args): Response
    {
        $incoming = (string) $request->header('x-request-id', '');
        $id = preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming) ? $incoming : bin2hex(random_bytes(12));
        $request->withAttribute('request_id', $id);

        return $next($request)->header('X-Request-Id', $id);
    }
}
