<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper. Every query uses prepared statements; identifiers passed to helper
 * methods (table/column names) must come from code, never from user input.
 */
final class Database
{
    private int $transactionDepth = 0;

    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string, mixed> $config */
    public static function connect(array $config, bool $asMigrator = false): self
    {
        $dsn = $config['socket'] !== ''
            ? "mysql:unix_socket={$config['socket']};dbname={$config['database']};charset=utf8mb4"
            : "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4";

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        if ($config['ssl_ca'] !== '') {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $config['ssl_ca'];
        }

        $username = $asMigrator && $config['migration_username'] !== '' ? $config['migration_username'] : $config['username'];
        $password = $asMigrator && $config['migration_username'] !== '' ? $config['migration_password'] : $config['password'];

        $pdo = new PDO($dsn, $username, $password, $options);
        $pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");

        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param array<string|int, mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($name, $value, $type);
        }
        $statement->execute();

        return $statement;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<string|int, mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn ($c) => "`{$c}`", $columns)),
            implode(', ', array_map(static fn ($c) => ":{$c}", $columns)),
        );
        $this->run($sql, $this->normalize($data));

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === []) {
            return 0;
        }
        $set = implode(', ', array_map(static fn ($c) => "`{$c}` = :set_{$c}", array_keys($data)));
        $conditions = implode(' AND ', array_map(static fn ($c) => "`{$c}` = :where_{$c}", array_keys($where)));
        $params = [];
        foreach ($this->normalize($data) as $k => $v) {
            $params["set_{$k}"] = $v;
        }
        foreach ($where as $k => $v) {
            $params["where_{$k}"] = $v;
        }

        return $this->run("UPDATE `{$table}` SET {$set} WHERE {$conditions}", $params)->rowCount();
    }

    /** @param array<string, mixed> $where */
    public function delete(string $table, array $where): int
    {
        $conditions = implode(' AND ', array_map(static fn ($c) => "`{$c}` = :{$c}", array_keys($where)));

        return $this->run("DELETE FROM `{$table}` WHERE {$conditions}", $where)->rowCount();
    }

    /**
     * Runs the callback in a transaction; nested calls join the outer transaction.
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        if ($this->transactionDepth > 0) {
            $this->transactionDepth++;
            try {
                return $callback($this);
            } finally {
                $this->transactionDepth--;
            }
        }

        $this->pdo->beginTransaction();
        $this->transactionDepth = 1;
        try {
            $result = $callback($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->transactionDepth = 0;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } elseif ($value instanceof \DateTimeInterface) {
                $data[$key] = $value->format('Y-m-d H:i:s');
            }
        }

        return $data;
    }
}
