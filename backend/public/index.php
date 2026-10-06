<?php

declare(strict_types=1);

use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;

// Local development only: let `php -S` serve public media from storage/public.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    if (str_starts_with($path, '/media/')) {
        $root = realpath(dirname(__DIR__) . '/storage/public');
        $file = realpath($root . substr($path, strlen('/media')));
        if ($root !== false && $file !== false && str_starts_with($file, $root) && is_file($file)) {
            header('Content-Type: ' . (mime_content_type($file) ?: 'application/octet-stream'));
            header('Cache-Control: public, max-age=31536000, immutable');
            header('X-Content-Type-Options: nosniff');
            readfile($file);

            return true;
        }
        http_response_code(404);

        return true;
    }
}

try {
    $container = require dirname(__DIR__) . '/app/bootstrap.php';
} catch (\Throwable $e) {
    error_log('[boot] ' . $e->getMessage());
    (Response::rawJson(['error' => ['code' => 'service_unavailable', 'message' => 'Service temporarily unavailable.']], 503))->send();

    return;
}

$container->get(Kernel::class)->handle(Request::fromGlobals())->send();
