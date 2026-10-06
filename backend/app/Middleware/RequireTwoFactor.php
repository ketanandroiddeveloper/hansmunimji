<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * Blocks admin APIs until the signed-in administrator has enrolled in two-factor authentication
 * (when ADMIN_REQUIRE_TWO_FACTOR is on). Must run after `auth`; `/auth/*` stays reachable for enrolment.
 */
final class RequireTwoFactor implements Middleware
{
    public function __construct(private Config $config, private Database $db)
    {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        if ($this->config->get('security.require_two_factor')) {
            $enabled = (bool) $this->db->value('SELECT totp_enabled FROM users WHERE id = ?', [(int) $request->attribute('user_id', 0)]);
            if (!$enabled) {
                throw new HttpException(403, 'two_factor_setup_required', 'Set up two-factor authentication to access the admin.');
            }
        }

        return $next($request);
    }
}
