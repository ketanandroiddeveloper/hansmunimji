<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'host' => Env::string('DB_HOST', '127.0.0.1'),
    'port' => Env::int('DB_PORT', 3306),
    'database' => Env::string('DB_DATABASE', 'private_advisory'),
    'username' => Env::string('DB_USERNAME', 'root'),
    'password' => Env::string('DB_PASSWORD', ''),
    'socket' => Env::string('DB_SOCKET', ''),
    'ssl_ca' => Env::string('DB_SSL_CA', ''),
    // Optional elevated credentials used only by `bin/console migrate`.
    'migration_username' => Env::string('DB_MIGRATION_USERNAME', ''),
    'migration_password' => Env::string('DB_MIGRATION_PASSWORD', ''),
];
