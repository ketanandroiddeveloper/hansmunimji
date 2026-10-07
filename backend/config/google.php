<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'client_id' => Env::string('GOOGLE_CLIENT_ID'),
    'client_secret' => Env::string('GOOGLE_CLIENT_SECRET'),
    'redirect_uri' => Env::string('GOOGLE_REDIRECT_URI'),
    'calendar_id' => Env::string('GOOGLE_CALENDAR_ID', 'primary'),
    // Optional: when set, connecting any other Google account is refused.
    'account_email' => Env::string('GOOGLE_ACCOUNT_EMAIL'),
    // Minimum set: create/update/delete events (with Meet), the account's email address, and send-only
    // Gmail only when Gmail delivers the notifications (MAIL_DRIVER=gmail).
    'scopes' => array_values(array_filter([
        'https://www.googleapis.com/auth/calendar.events',
        Env::string('MAIL_DRIVER', 'log') === 'gmail' ? 'https://www.googleapis.com/auth/gmail.send' : null,
        'openid',
        'email',
    ])),
];
