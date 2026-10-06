<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'client_id' => Env::string('GOOGLE_CLIENT_ID'),
    'client_secret' => Env::string('GOOGLE_CLIENT_SECRET'),
    'redirect_uri' => Env::string('GOOGLE_REDIRECT_URI'),
    'calendar_id' => Env::string('GOOGLE_CALENDAR_ID', 'primary'),
    'scopes' => ['https://www.googleapis.com/auth/calendar.events', 'openid', 'email'],
];
