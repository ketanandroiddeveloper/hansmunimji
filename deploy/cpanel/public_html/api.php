<?php

// Entry point for /api/*, /sitemap.xml and /robots.txt (see .htaccess). The backend, its secrets and
// its storage live outside the web root, in ~/advisory next to ~/public_html.

declare(strict_types=1);

$advisory = dirname(__DIR__) . '/advisory';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'production';
$_SERVER['ENV_PATH'] = $_ENV['ENV_PATH'] = $advisory . '/shared';

require $advisory . '/current/backend/public/index.php';
