<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Security\Tokens;

/** Synchronizer-token CSRF check for authenticated, state-changing requests. Must run after `auth`. */
final class Csrf implements Middleware
{
    public function handle(Request $request, callable $next, string ...$args): Response
    {
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }
        $session = $request->attribute('session');
        $token = (string) $request->header('x-csrf-token', '');
        if (!is_array($session) || $token === '' || !Tokens::equals((string) $session['csrf_hash'], $token)) {
            throw new HttpException(403, 'csrf_mismatch', 'Your session security token is invalid. Please refresh and try again.');
        }

        return $next($request);
    }
}
