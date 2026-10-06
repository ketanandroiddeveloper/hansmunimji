<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Applies forward migrations from database/migrations. Each file returns
 * `['up' => string|list<string>, 'down' => string|list<string>]`.
 */
final class Migrator
{
    public function __construct(private Database $db, private string $directory)
    {
    }

    /** @return list<string> applied migration names */
    public function migrate(?callable $output = null): array
    {
        $this->ensureTable();
        $applied = array_column($this->db->all('SELECT name FROM migrations'), 'name');
        $batch = (int) $this->db->value('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations');
        $ran = [];

        foreach ($this->files() as $name => $file) {
            if (in_array($name, $applied, true)) {
                continue;
            }
            $definition = require $file;
            foreach ((array) $definition['up'] as $statement) {
                // DDL auto-commits in MySQL, so each migration must be written to be re-runnable or atomic.
                $this->db->pdo()->exec($statement);
            }
            $this->db->insert('migrations', ['name' => $name, 'batch' => $batch, 'applied_at' => gmdate('Y-m-d H:i:s')]);
            $ran[] = $name;
            if ($output) {
                $output("Migrated: {$name}");
            }
        }

        return $ran;
    }

    /** @return list<string> rolled back migration names */
    public function rollback(?callable $output = null): array
    {
        $this->ensureTable();
        $batch = (int) $this->db->value('SELECT COALESCE(MAX(batch), 0) FROM migrations');
        if ($batch === 0) {
            return [];
        }
        $names = array_column($this->db->all('SELECT name FROM migrations WHERE batch = ? ORDER BY id DESC', [$batch]), 'name');
        $files = $this->files();
        foreach ($names as $name) {
            $definition = require $files[$name];
            foreach ((array) $definition['down'] as $statement) {
                $this->db->pdo()->exec($statement);
            }
            $this->db->delete('migrations', ['name' => $name]);
            if ($output) {
                $output("Rolled back: {$name}");
            }
        }

        return $names;
    }

    /** @return array<string, string> */
    private function files(): array
    {
        $files = [];
        foreach (glob($this->directory . '/*.php') ?: [] as $file) {
            $files[basename($file, '.php')] = $file;
        }
        ksort($files);

        return $files;
    }

    private function ensureTable(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(191) NOT NULL UNIQUE,
                batch INT UNSIGNED NOT NULL,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
        );
    }
}
