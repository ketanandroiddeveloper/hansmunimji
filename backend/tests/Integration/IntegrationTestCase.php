<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Console\Application;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Migrator;
use App\Integrations\Payments\GatewayRegistry;
use App\Jobs\JobQueue;
use App\Services\Payments\WebhookService;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeGateway;

/**
 * Boots the real application container against a dedicated MySQL test database.
 *
 * Safety: runs only when APP_ENV=testing, `.env.testing` exists and the database name ends in
 * "_test"; otherwise every test is skipped. All tables in that database are dropped and migrated
 * once per run, and emptied before each test. Payments go through FakeGateway; no real provider,
 * credentials or client data are involved.
 */
abstract class IntegrationTestCase extends TestCase
{
    private static bool $migrated = false;
    private static ?string $skip = null;

    protected Container $c;
    protected Database $db;
    protected Clock $clock;
    protected FakeGateway $razorpay;
    protected FakeGateway $stripe;

    public static function setUpBeforeClass(): void
    {
        if (self::$migrated || self::$skip !== null) {
            return;
        }
        $base = dirname(__DIR__, 2);
        if (getenv('APP_ENV') !== 'testing' || !is_file($base . '/.env.testing')) {
            self::$skip = 'Integration tests need backend/.env.testing (see .env.testing.example).';

            return;
        }
        // Throwaway keys for this run only, so no key material is ever stored for tests.
        foreach ([
            'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)),
            'BLIND_INDEX_KEY' => 'base64:' . base64_encode(random_bytes(32)),
            'ENCRYPTION_KEYS' => '1:' . base64_encode(random_bytes(32)),
            'STORAGE_PATH' => sys_get_temp_dir() . '/pa-integration-tests',
        ] as $key => $value) {
            if (getenv($key) === false && !isset($_ENV[$key])) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
        @mkdir(sys_get_temp_dir() . '/pa-integration-tests/logs', 0700, true);

        $container = require $base . '/app/bootstrap.php';
        $config = $container->get(Config::class);
        $name = (string) $config->get('database.database');
        if ($config->environment() !== 'testing' || !str_ends_with($name, '_test')) {
            self::$skip = "Refusing to run against database '{$name}': the name must end in _test.";

            return;
        }

        $db = Database::connect($config->get('database'));
        $db->run('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($db->all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ?', [$name]) as $row) {
            $db->run('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $row['t']) . '`');
        }
        $db->run('SET FOREIGN_KEY_CHECKS = 1');
        (new Migrator($db, $base . '/database/migrations'))->migrate();
        self::$migrated = true;
    }

    protected function setUp(): void
    {
        if (self::$skip !== null) {
            self::markTestSkipped(self::$skip);
        }
        $this->c = require dirname(__DIR__, 2) . '/app/bootstrap.php';
        $this->razorpay = new FakeGateway('razorpay', ['INR', 'USD']);
        $this->stripe = new FakeGateway('stripe', ['USD', 'AED', 'GBP']);
        $this->c->set(GatewayRegistry::class, fn () => new GatewayRegistry(['razorpay' => $this->razorpay, 'stripe' => $this->stripe]));
        $this->db = $this->c->get(Database::class);
        $this->clock = $this->c->get(Clock::class);
        $this->clock->freeze(new \DateTimeImmutable('2030-03-01 09:00:00', new \DateTimeZone('UTC')));

        $schema = (string) $this->c->get(Config::class)->get('database.database');
        $this->db->run('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ?', [$schema]) as $row) {
            if (!in_array($row['t'], ['migrations', 'email_templates'], true)) {
                $this->db->run('TRUNCATE TABLE `' . str_replace('`', '', (string) $row['t']) . '`');
            }
        }
        $this->db->run('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @template T of object @param class-string<T> $class @return T */
    protected function svc(string $class): object
    {
        return $this->c->get($class);
    }

    protected function travel(string $modifier): void
    {
        $this->clock->freeze($this->clock->now()->modify($modifier));
    }

    /** Sends a correctly signed webhook through the real webhook pipeline. */
    protected function webhook(string $gateway, array $event): string
    {
        [$body, $headers] = FakeGateway::sign($event);

        return $this->svc(WebhookService::class)->handle($gateway, $body, $headers);
    }

    protected function captureWebhook(FakeGateway $gateway, string $orderId, string $eventId, ?int $amount = null, ?string $currency = null): string
    {
        $intent = $gateway->orders[$orderId];

        return $this->webhook($gateway->name(), [
            'id' => $eventId,
            'kind' => 'payment_captured',
            'order_id' => $orderId,
            'payment_id' => $gateway->name() . '_pay_' . substr($orderId, strrpos($orderId, '_') + 1),
            'amount' => $amount ?? $intent->amountMinor,
            'currency' => $currency ?? $intent->currency,
        ]);
    }

    /** Runs due background jobs with the application's real handlers. */
    protected function runJobs(): int
    {
        $app = new Application($this->c);
        $handlers = (new \ReflectionMethod($app, 'jobHandlers'))->invoke($app);

        return $this->svc(JobQueue::class)->processDue($handlers, 50);
    }

    protected function adminUser(): int
    {
        return $this->db->insert('users', [
            'name' => 'Test Admin',
            'email' => 'admin+' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => 'unusable-test-hash',
            'created_at' => $this->clock->nowString(),
            'updated_at' => $this->clock->nowString(),
        ]);
    }

    protected function setting(string $key, mixed $value): void
    {
        $this->db->insert('settings', ['key' => $key, 'value' => json_encode($value), 'is_public' => 0, 'updated_at' => $this->clock->nowString()]);
    }

    /** @return array<string, mixed> */
    protected function row(string $table, string $where, array $params = []): array
    {
        return $this->db->first("SELECT * FROM {$table} WHERE {$where}", $params) ?? $this->fail("No {$table} row for {$where}");
    }

    protected function historyTo(string $type, int $id): array
    {
        return array_column($this->db->all('SELECT to_status FROM status_history WHERE subject_type = ? AND subject_id = ? ORDER BY id', [$type, $id]), 'to_status');
    }
}
