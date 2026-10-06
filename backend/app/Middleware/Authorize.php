<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Security\Rbac;

/** Usage: `can:permission.slug` — must run after `auth`. */
final class Authorize implements Middleware
{
    public function __construct(private Rbac $rbac)
    {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        $userId = (int) $request->attribute('user_id', 0);
        foreach ($args as $permission) {
            if ($userId === 0 || !$this->rbac->can($userId, $permission)) {
                throw HttpException::forbidden();
            }
        }

        return $next($request);
    }
}
