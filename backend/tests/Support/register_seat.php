<?php

declare(strict_types=1);

// Child process for the concurrent-registration test: boots its own container (and so its own
// database connection), waits for a shared start time, then tries to take a seat.
// Usage: php register_seat.php <event-slug> <email> <unix-start-time-float>

use App\Core\HttpException;
use App\Services\Events\EventRegistrationService;

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing') {
    exit(2);
}

$container = require dirname(__DIR__, 2) . '/app/bootstrap.php';
$service = $container->get(EventRegistrationService::class);

$startAt = (float) $argv[3];
while (microtime(true) < $startAt) {
    usleep(500);
}

try {
    $result = $service->register($argv[1], ['name' => 'Concurrent Guest', 'email' => $argv[2], 'seats' => 1, 'currency' => 'INR', 'consent_privacy' => true]);
    echo $result['status'];
} catch (HttpException $e) {
    echo 'error:' . $e->errorCode;
}
