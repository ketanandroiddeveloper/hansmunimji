<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'name' => Env::string('APP_NAME', 'Private Advisory'),
    'env' => Env::string('APP_ENV', 'local'),
    'debug' => Env::bool('APP_DEBUG', false),
    'url' => rtrim(Env::string('APP_URL', 'http://127.0.0.1:8080'), '/'),
    'frontend_url' => rtrim(Env::string('FRONTEND_URL', 'http://localhost:5173'), '/'),
    'admin_url' => rtrim(Env::string('ADMIN_URL', Env::string('FRONTEND_URL', 'http://localhost:5173') . '/admin'), '/'),
    'timezone' => 'UTC',
    'practice_timezone' => Env::string('PRACTICE_TIMEZONE', 'Asia/Kolkata'),
    'trusted_proxies' => Env::list('TRUSTED_PROXIES'),
    'cors_allowed_origins' => Env::list('CORS_ALLOWED_ORIGINS'),
    'max_json_bytes' => Env::int('MAX_JSON_BYTES', 262144),
    'storage_path' => Env::string('STORAGE_PATH', dirname(__DIR__) . '/storage'),
    'public_media_url' => rtrim(Env::string('PUBLIC_MEDIA_URL', Env::string('APP_URL', 'http://127.0.0.1:8080') . '/media'), '/'),
    'rebuild_hook_url' => Env::string('FRONTEND_REBUILD_HOOK_URL', ''),
];
