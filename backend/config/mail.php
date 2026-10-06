<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'driver' => Env::string('MAIL_DRIVER', 'log'),
    'host' => Env::string('MAIL_HOST'),
    'port' => Env::int('MAIL_PORT', 587),
    'username' => Env::string('MAIL_USERNAME'),
    'password' => Env::string('MAIL_PASSWORD'),
    'encryption' => Env::string('MAIL_ENCRYPTION', 'tls'),
    'from_address' => Env::string('MAIL_FROM_ADDRESS', 'concierge@example.com'),
    'from_name' => Env::string('MAIL_FROM_NAME', 'Private Office'),
    'admin_address' => Env::string('MAIL_ADMIN_ADDRESS'),
];
