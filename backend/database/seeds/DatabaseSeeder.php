<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Container;

/**
 * Idempotent seeding: every step inserts only what is missing, so re-running never overwrites
 * content an administrator has edited.
 */
final class DatabaseSeeder
{
    public function __construct(private Container $container)
    {
    }

    /** @return iterable<string> progress messages */
    public function run(bool $demo = false): iterable
    {
        yield from (new SettingsSeeder($this->container))->run();
        yield from (new EmailTemplateSeeder($this->container))->run();
        yield from (new ContentSeeder($this->container))->run();
        if ($demo) {
            yield from (new DemoSeeder($this->container))->run();
        }
    }
}
