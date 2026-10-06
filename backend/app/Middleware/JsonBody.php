<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/** Enforces JSON content type and body size for state-changing requests (webhooks and uploads exempt). */
final class JsonBody implements Middleware
{
    public function __construct(private Config $config)
    {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && !str_contains($request->path, '/payments/webhook/')
            && !$request->isMultipart()
        ) {
            if (strlen($request->rawBody()) > (int) $this->config->get('app.max_json_bytes')) {
                throw new HttpException(413, 'payload_too_large', 'Request body is too large.');
            }
            $type = (string) $request->header('content-type', '');
            if ($request->rawBody() !== '' && !str_starts_with($type, 'application/json')) {
                throw new HttpException(415, 'unsupported_media_type', 'Use Content-Type: application/json.');
            }
        }

        return $next($request);
    }
}
