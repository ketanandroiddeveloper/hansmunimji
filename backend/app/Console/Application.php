<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Migrator;
use App\Core\Router;
use App\Jobs\JobQueue;
use App\Security\Crypto;
use App\Security\PasswordHasher;
use App\Security\Rbac;
use App\Services\Applications\ApplicationService;
use App\Services\Booking\BookingService;
use App\Services\Booking\CalendarSyncService;
use App\Services\Booking\ReminderService;
use App\Services\Events\EventRegistrationService;
use App\Integrations\Payments\GatewayRegistry;
use App\Services\Notifications\NotificationService;
use App\Services\Payments\PaymentService;
use GuzzleHttp\Client as HttpClient;

/**
 * Operational commands. Usage: `php bin/console <command> [--option=value]`.
 * Run `php bin/console help` for the list.
 */
final class Application
{
    /** Every encrypted column, as table => [field => AAD context]. Used by keys:rotate. */
    public const ENCRYPTED_COLUMNS = [
        'users' => ['totp_secret' => 'users.totp_secret'],
        'applications' => [
            'full_name' => 'applications.full_name', 'email' => 'applications.email', 'phone' => 'applications.phone',
            'city' => 'applications.city', 'professional_background' => 'applications.professional_background',
            'designation' => 'applications.designation', 'organization' => 'applications.organization',
            'core_objective' => 'applications.core_objective', 'preferred_availability' => 'applications.preferred_availability',
            'referral_details' => 'applications.referral_details', 'confidential_notes' => 'applications.confidential_notes',
            'admin_notes' => 'applications.admin_notes', 'info_request' => 'applications.info_request',
        ],
        'appointments' => [
            'client_name' => 'appointments.client_name', 'client_email' => 'appointments.client_email',
            'client_phone' => 'appointments.client_phone', 'notes' => 'appointments.notes', 'meet_url' => 'appointments.meet_url',
        ],
        'event_registrations' => [
            'name' => 'event_registrations.name', 'email' => 'event_registrations.email',
            'phone' => 'event_registrations.phone', 'notes' => 'event_registrations.notes',
        ],
        'notifications' => ['recipient' => 'notifications.recipient', 'payload' => 'notifications.payload'],
        'data_requests' => ['email' => 'data_requests.email'],
        'calendar_integrations' => ['access_token' => 'calendar_integrations.access_token', 'refresh_token' => 'calendar_integrations.refresh_token'],
    ];

    private const COMMANDS = [
        'migrate' => 'Run pending migrations (uses DB_MIGRATION_* credentials when set). --seed to seed afterwards.',
        'migrate:rollback' => 'Roll back the last batch of migrations (refused in production).',
        'db:seed' => 'Seed reference data and initial content (idempotent; never overwrites edited content).',
        'admin:create' => 'Create an administrator interactively: --email= --name= [--role=super-admin] [--password-stdin].',
        'keys:generate' => 'Print fresh APP_KEY, BLIND_INDEX_KEY and an ENCRYPTION_KEYS entry for a secrets manager.',
        'keys:rotate' => 'Re-encrypt every encrypted column with ENCRYPTION_ACTIVE_KEY. --dry-run to count only.',
        'worker' => 'Process email, jobs and reminders continuously. --once to run a single pass; --sleep=5.',
        'schedule:run' => 'Housekeeping (run every minute from cron): holds, reconciliation, expiry, event reminders, retention, cleanup.',
        'payments:check' => 'Verify gateway credentials, test/live mode and enabled currencies against each provider (read-only).',
        'routes' => 'List registered HTTP routes.',
    ];

    public function __construct(private ?Container $container)
    {
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $options = $this->parseOptions(array_slice($argv, 2));

        try {
            return match ($command) {
                'migrate' => $this->migrate($options),
                'migrate:rollback' => $this->rollback(),
                'db:seed' => $this->seed(),
                'admin:create' => $this->createAdmin($options),
                'keys:generate' => $this->generateKeys(),
                'keys:rotate' => $this->rotateKeys($options),
                'worker' => $this->worker($options),
                'schedule:run' => $this->schedule(),
                'payments:check' => $this->checkPayments(),
                'routes' => $this->routes(),
                default => $this->help(),
            };
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $this->container?->get(Logger::class)->error('console_failed', ['command' => $command, 'message' => $e->getMessage()]);

            return 1;
        }
    }

    // ------------------------------------------------------------------ schema

    /** @param array<string, string|bool> $options */
    private function migrate(array $options): int
    {
        $config = $this->config();
        $db = Database::connect($config->get('database'), asMigrator: true);
        $ran = (new Migrator($db, $this->basePath() . '/database/migrations'))->migrate();
        $this->line($ran === [] ? 'Nothing to migrate.' : 'Migrated: ' . implode(', ', $ran));

        return isset($options['seed']) ? $this->seed() : 0;
    }

    private function rollback(): int
    {
        if ($this->config()->isProduction()) {
            $this->error('Rollback is disabled in production. Restore from backup or ship a forward migration.');

            return 1;
        }
        $db = Database::connect($this->config()->get('database'), asMigrator: true);
        $rolled = (new Migrator($db, $this->basePath() . '/database/migrations'))->rollback();
        $this->line($rolled === [] ? 'Nothing to roll back.' : 'Rolled back: ' . implode(', ', $rolled));

        return 0;
    }

    private function seed(): int
    {
        $demo = in_array('--demo', $_SERVER['argv'] ?? [], true);
        if ($demo && $this->config()->isProduction()) {
            throw new \RuntimeException('Demo data cannot be seeded in production.');
        }
        $seeder = new \Database\Seeds\DatabaseSeeder($this->container);
        foreach ($seeder->run($demo) as $message) {
            $this->line($message);
        }

        return 0;
    }

    // ------------------------------------------------------------------ admin bootstrap

    /** @param array<string, string|bool> $options */
    private function createAdmin(array $options): int
    {
        $db = $this->container->get(Database::class);
        $hasher = $this->container->get(PasswordHasher::class);
        $clock = $this->container->get(Clock::class);

        $email = mb_strtolower(trim((string) ($options['email'] ?? $this->prompt('Email: '))));
        $name = trim((string) ($options['name'] ?? $this->prompt('Full name: ')));
        $role = (string) ($options['role'] ?? 'super-admin');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($name) < 2) {
            throw new \InvalidArgumentException('A valid --email and --name are required.');
        }
        if (!array_key_exists($role, Rbac::ROLES)) {
            throw new \InvalidArgumentException('Unknown role. Choose one of: ' . implode(', ', array_keys(Rbac::ROLES)));
        }
        if ($db->value('SELECT 1 FROM users WHERE email = ?', [$email])) {
            throw new \RuntimeException('An administrator with this email already exists.');
        }

        if (isset($options['password-stdin'])) {
            $password = rtrim((string) fgets(STDIN), "\r\n");
        } else {
            $password = $this->secret('Password (min 12 chars, not shown): ');
            if ($password !== $this->secret('Confirm password: ')) {
                throw new \InvalidArgumentException('Passwords do not match.');
            }
        }
        $hasher->assertStrong($password, 'password', $email);

        $roleId = $db->value('SELECT id FROM roles WHERE slug = ?', [$role]) ?? throw new \RuntimeException('Roles are not seeded. Run migrations first.');
        $now = $clock->nowString();
        $id = $db->transaction(function () use ($db, $email, $name, $hasher, $password, $now, $roleId) {
            $id = $db->insert('users', [
                'email' => $email,
                'name' => $name,
                'password_hash' => $hasher->hash($password),
                'status' => 'active',
                'password_changed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $db->insert('user_roles', ['user_id' => $id, 'role_id' => $roleId]);

            return $id;
        });
        $db->insert('audit_logs', ['user_id' => $id, 'action' => 'user.created_via_cli', 'entity_type' => 'user', 'entity_id' => (string) $id, 'metadata' => ['role' => $role], 'created_at' => $now]);
        $this->line("Administrator #{$id} created with role {$role}. Enable two-factor authentication after first sign-in.");

        return 0;
    }

    // ------------------------------------------------------------------ keys

    private function generateKeys(): int
    {
        $this->line('# Store these in your secrets manager or the environment file OUTSIDE the web root. Never commit them.');
        $this->line('APP_KEY=base64:' . base64_encode(random_bytes(32)));
        $this->line('BLIND_INDEX_KEY=base64:' . base64_encode(random_bytes(32)));
        $this->line('ENCRYPTION_KEYS=1:' . Crypto::generateKey());
        $this->line('ENCRYPTION_ACTIVE_KEY=1');
        $this->line('');
        $this->line('# To rotate: append a new version (e.g. "1:...,2:<new>"), set ENCRYPTION_ACTIVE_KEY=2, deploy, then run keys:rotate.');
        $this->line('# BLIND_INDEX_KEY cannot be rotated without recomputing every *_bidx column.');

        return 0;
    }

    /** @param array<string, string|bool> $options */
    private function rotateKeys(array $options): int
    {
        $db = $this->container->get(Database::class);
        $crypto = $this->container->get(Crypto::class);
        $dry = isset($options['dry-run']);
        $total = 0;

        foreach (self::ENCRYPTED_COLUMNS as $table => $fields) {
            $columns = array_map(static fn ($f) => "`{$f}_enc`", array_keys($fields));
            $lastId = 0;
            $count = 0;
            do {
                $rows = $db->all('SELECT id, ' . implode(', ', $columns) . " FROM `{$table}` WHERE id > ? ORDER BY id LIMIT 200", [$lastId]);
                foreach ($rows as $row) {
                    $lastId = (int) $row['id'];
                    $update = [];
                    foreach ($fields as $field => $context) {
                        $value = $row["{$field}_enc"];
                        if ($value !== null && $crypto->needsRotation($value)) {
                            $update["{$field}_enc"] = $crypto->encrypt($crypto->decrypt($value, $context), $context);
                        }
                    }
                    if ($update !== []) {
                        $count++;
                        if (!$dry) {
                            $db->update($table, $update, ['id' => $row['id']]);
                        }
                    }
                }
            } while (count($rows) === 200);
            $this->line(sprintf('%-24s %d row(s) %s', $table, $count, $dry ? 'need rotation' : 're-encrypted'));
            $total += $count;
        }
        $this->line("Total: {$total}. Retire old key versions only after this reports 0 in --dry-run.");

        return 0;
    }

    // ------------------------------------------------------------------ background processing

    /** @param array<string, string|bool> $options */
    private function worker(array $options): int
    {
        $once = isset($options['once']);
        $sleep = max(1, (int) ($options['sleep'] ?? 5));
        $stop = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () use (&$stop) { $stop = true; });
            pcntl_signal(SIGINT, static function () use (&$stop) { $stop = true; });
        }
        $started = time();
        $logger = $this->container->get(Logger::class);

        do {
            $did = 0;
            try {
                $did += $this->container->get(NotificationService::class)->processDue();
                $did += $this->container->get(JobQueue::class)->processDue($this->jobHandlers(), 10, $this->onJobFailure(...));
                $did += $this->container->get(ReminderService::class)->processDue();
            } catch (\Throwable $e) {
                $logger->error('worker_iteration_failed', ['message' => $e->getMessage()]);
                // A dropped DB connection is unrecoverable within this process; exit so the supervisor restarts us.
                if ($e instanceof \PDOException) {
                    return 1;
                }
            }
            if ($once) {
                $this->line("Processed {$did} item(s).");
                break;
            }
            // Recycle periodically to release memory and pick up deployments.
            if (time() - $started > 3600) {
                break;
            }
            if ($did === 0 && !$stop) {
                sleep($sleep);
            }
        } while (!$stop);

        return 0;
    }

    /** @return array<string, callable(array<string, mixed>): void> */
    private function jobHandlers(): array
    {
        $c = $this->container;

        return [
            'calendar.sync' => static fn (array $p) => $c->get(CalendarSyncService::class)->sync((int) $p['appointment_id']),
            'payment.refund' => static function (array $p) use ($c): void {
                $c->get(PaymentService::class)->refund(
                    (int) $p['payment_id'],
                    (int) $p['amount_minor'],
                    (string) $p['reason'],
                    isset($p['user_id']) ? (int) $p['user_id'] : null,
                    'job:' . hash('sha256', $p['payment_id'] . '|' . $p['amount_minor'] . '|' . $p['reason']),
                );
            },
            'frontend.rebuild' => static function () use ($c): void {
                $url = (string) $c->get(Config::class)->get('app.rebuild_hook_url');
                if ($url === '') {
                    return; // Static prerender is rebuilt on the next deploy.
                }
                $response = $c->get(HttpClient::class)->request('POST', $url, ['json' => ['reason' => 'content_updated']]);
                if ($response->getStatusCode() >= 300) {
                    throw new \RuntimeException('Rebuild hook returned HTTP ' . $response->getStatusCode());
                }
            },
        ];
    }

    /** @param array<string, mixed> $payload */
    private function onJobFailure(string $type, array $payload, \Throwable $e): void
    {
        if ($type === 'calendar.sync') {
            $this->container->get(CalendarSyncService::class)->onFinalFailure((int) $payload['appointment_id'], $e);

            return;
        }
        $this->container->get(NotificationService::class)->queueAdmin('admin_integration_failure', [
            'integration' => $type,
            'reference' => (string) ($payload['appointment_id'] ?? $payload['payment_id'] ?? ''),
            'error' => mb_substr($e->getMessage(), 0, 200),
        ]);
    }

    private function schedule(): int
    {
        $c = $this->container;
        $db = $c->get(Database::class);
        $clock = $c->get(Clock::class);
        $now = $clock->nowString();
        $results = [];

        $bookings = $c->get(BookingService::class);
        $registrations = $c->get(EventRegistrationService::class);
        $results['holds_released'] = $bookings->releaseExpiredHolds();
        // Reconcile before expiring so a payment that did complete confirms instead of lapsing.
        $results['payments_reconciled'] = $c->get(PaymentService::class)->reconcilePending();
        $results['appointments_expired'] = $bookings->expireUnpaid();
        $results['registrations_expired'] = $registrations->expireHolds();
        $results['event_reminders'] = $registrations->sendReminders();
        $results['applications_retention'] = $c->get(ApplicationService::class)->applyRetention();
        $results['stale_jobs_released'] = $c->get(JobQueue::class)->releaseStale();
        $db->run(
            "UPDATE appointment_slots s JOIN appointments a ON a.id = s.appointment_id
             SET s.status = 'released', s.held_until = NULL, s.updated_at = ?
             WHERE s.status = 'held' AND a.status IN ('cancelled','expired')",
            [$now],
        );

        $results['sessions_purged'] = $db->run('DELETE FROM user_sessions WHERE expires_at < ? OR revoked_at < ?', [$now, $clock->now()->modify('-7 days')->format('Y-m-d H:i:s')])->rowCount();
        $db->run('DELETE FROM rate_limits WHERE reset_at < ?', [$now]);
        $db->run('DELETE FROM login_challenges WHERE expires_at < ?', [$now]);
        $db->run('DELETE FROM password_resets WHERE expires_at < ?', [$clock->now()->modify('-1 day')->format('Y-m-d H:i:s')]);
        // Delivered notification payloads (which contain personal data) are purged after 30 days.
        $db->run("DELETE FROM notifications WHERE status IN ('sent','cancelled') AND created_at < ?", [$clock->now()->modify('-30 days')->format('Y-m-d H:i:s')]);
        $db->run("DELETE FROM jobs WHERE status = 'done' AND finished_at < ?", [$clock->now()->modify('-14 days')->format('Y-m-d H:i:s')]);

        $this->line(json_encode($results, JSON_THROW_ON_ERROR));

        return 0;
    }

    /** Exit code 1 when any configured gateway fails its check, so deploy pipelines can gate on it. */
    private function checkPayments(): int
    {
        $config = $this->container->get(Config::class);
        $this->line('Environment: ' . $config->environment());
        $failed = false;
        foreach ($this->container->get(GatewayRegistry::class)->all() as $gateway) {
            if (!$gateway->isConfigured()) {
                $this->line(sprintf('%-9s not configured', $gateway->name()));
                continue;
            }
            $result = $gateway->healthCheck();
            $failed = $failed || !$result['ok'];
            $this->line(sprintf('%-9s %s · %s mode · currencies %s', $gateway->name(), $result['ok'] ? 'OK' : 'FAILED', $result['mode'], implode(', ', $gateway->supportedCurrencies())));
            foreach ($result['details'] as $key => $value) {
                $this->line(sprintf('          %s: %s', $key, is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value));
            }
            foreach ($result['warnings'] as $warning) {
                $this->line('          warning: ' . $warning);
            }
        }

        return $failed ? 1 : 0;
    }

    private function routes(): int
    {
        foreach ($this->container->get(Router::class)->list() as $route) {
            $this->line(sprintf('%-7s %s', $route['method'], $route['pattern']));
        }

        return 0;
    }

    private function help(): int
    {
        $this->line('Usage: php bin/console <command> [options]');
        foreach (self::COMMANDS as $name => $description) {
            $this->line(sprintf('  %-18s %s', $name, $description));
        }

        return 0;
    }

    // ------------------------------------------------------------------ helpers

    private function config(): Config
    {
        return $this->container->get(Config::class);
    }

    private function basePath(): string
    {
        return (string) $this->container->get('base_path');
    }

    /** @param list<string> $args @return array<string, string|bool> */
    private function parseOptions(array $args): array
    {
        $out = [];
        foreach ($args as $arg) {
            if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $arg, $m)) {
                $out[$m[1]] = $m[2] ?? true;
            }
        }

        return $out;
    }

    private function prompt(string $label): string
    {
        fwrite(STDOUT, $label);

        return trim((string) fgets(STDIN));
    }

    private function secret(string $label): string
    {
        fwrite(STDOUT, $label);
        $tty = stream_isatty(STDIN);
        if ($tty) {
            shell_exec('stty -echo');
        }
        $value = rtrim((string) fgets(STDIN), "\r\n");
        if ($tty) {
            shell_exec('stty echo');
            fwrite(STDOUT, PHP_EOL);
        }

        return $value;
    }

    private function line(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    private function error(string $message): void
    {
        fwrite(STDERR, "\033[31m{$message}\033[0m" . PHP_EOL);
    }
}
