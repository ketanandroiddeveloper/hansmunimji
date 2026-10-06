<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Auth\SessionService;

final class Authenticate implements Middleware
{
    public function __construct(private SessionService $sessions, private Config $config)
    {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        $token = $request->cookie((string) $this->config->get('security.session.cookie'));
        $session = $token ? $this->sessions->resolve($token) : null;
        if ($session === null) {
            throw new HttpException(401, 'unauthenticated', 'Please sign in to continue.');
        }
        $request->withAttribute('session', $session);
        $request->withAttribute('user_id', (int) $session['user_id']);

        return $next($request);
    }
}
