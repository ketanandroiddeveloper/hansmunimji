<?php

declare(strict_types=1);

use App\Core\Clock;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Env;
use App\Core\EnvironmentGuard;
use App\Core\Logger;
use App\Core\Router;
use App\Integrations\Email\GmailApiMailer;
use App\Integrations\Email\LogMailer;
use App\Integrations\Email\Mailer;
use App\Integrations\Email\SmtpMailer;
use App\Integrations\Payments\GatewayRegistry;
use App\Integrations\Payments\RazorpayGateway;
use App\Integrations\Payments\StripeGateway;
use App\Security\AuditLogger;
use App\Security\BlindIndex;
use App\Security\Crypto;
use App\Security\HtmlSanitizer;
use App\Security\PasswordHasher;
use App\Security\RateLimiter;
use App\Security\SignedUrl;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$basePath = dirname(__DIR__);

Env::load($basePath);
date_default_timezone_set('UTC');

$config = Config::fromDirectory($basePath . '/config');
EnvironmentGuard::assert($config);

$container = new Container();
$container->instance(Config::class, $config);
$container->instance(Container::class, $container);
$container->instance('base_path', $basePath);

$storage = (string) $config->get('app.storage_path');

$container->set(Clock::class, static fn () => new Clock());
$container->set(Logger::class, static fn () => new Logger($storage . '/logs'));
$container->set(Database::class, static fn () => Database::connect($config->get('database')));

$container->set(Crypto::class, static fn () => new Crypto(
    $config->get('security.encryption_keys', []),
    (int) $config->get('security.encryption_active_key', 0),
));
$container->set(BlindIndex::class, static fn () => new BlindIndex((string) $config->get('security.blind_index_key')));
$container->set(SignedUrl::class, static fn () => new SignedUrl((string) $config->get('security.app_key')));
$container->set(PasswordHasher::class, static fn () => new PasswordHasher(
    (int) $config->get('security.password.argon_memory_kib'),
    (int) $config->get('security.password.argon_time_cost'),
    (int) $config->get('security.password.min_length'),
));
$container->set(RateLimiter::class, static fn (Container $c) => new RateLimiter(
    $c->get(Database::class),
    $c->get(Clock::class),
    (string) $config->get('security.app_key'),
));
$container->set(AuditLogger::class, static fn (Container $c) => new AuditLogger(
    $c->get(Database::class),
    $c->get(Clock::class),
    (string) $config->get('security.app_key'),
));
$container->set(HtmlSanitizer::class, static fn () => new HtmlSanitizer($storage . '/cache/htmlpurifier'));

$container->set(HttpClient::class, static fn () => new HttpClient([
    'timeout' => 15,
    'connect_timeout' => 5,
    'http_errors' => false,
    'headers' => ['User-Agent' => 'PrivateAdvisoryPlatform/1.0'],
]));
$container->set(ClientInterface::class, static fn (Container $c) => $c->get(HttpClient::class));

$container->set(Mailer::class, static fn (Container $c) => match ($config->get('mail.driver')) {
    'smtp' => new SmtpMailer($config->get('mail')),
    'gmail' => $c->get(GmailApiMailer::class),
    default => new LogMailer($c->get(Logger::class)),
});

$container->set(GatewayRegistry::class, static fn (Container $c) => new GatewayRegistry([
    'razorpay' => new RazorpayGateway($c->get(HttpClient::class), $config->get('payments.razorpay')),
    'stripe' => new StripeGateway($config->get('payments.stripe')),
]));

$container->set(Router::class, static function () use ($basePath) {
    $router = new Router();
    (require $basePath . '/routes/api.php')($router);

    return $router;
});

return $container;
