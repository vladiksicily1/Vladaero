<?php
namespace VladAero\Core;

/**
 * VladAero — Database (PDO wrapper)
 */
class Database
{
    private static ?Database $instance = null;
    private \PDO $pdo;
    private string $prefix;

    private function __construct(array $config)
    {
        $charset = $config['charset'] ?? 'utf8mb4';
        $dsn = "mysql:host={$config['host']};dbname={$config['name']};charset={$charset}";
        $this->pdo = new \PDO($dsn, $config['user'], $config['pass'], [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => true,
        ]);
        $this->prefix = $config['prefix'] ?? 'vld_';
    }

    public static function init(array $config): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Database not initialized');
        }
        return self::$instance;
    }

    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function t(string $table): string
    {
        return $this->prefix . $table;
    }

    /**
     * Execute a prepared query and return the statement.
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        // Replace {prefix} placeholders
        $sql = str_replace('{prefix}', $this->prefix, $sql);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch all rows.
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Fetch a single row.
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch();
        return $result ?: null;
    }

    /**
     * Fetch a single scalar value.
     */
    public function fetchColumn(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    /**
     * Fetch a single scalar value (alias to fetchColumn).
     */
    public function single(string $sql, array $params = []): mixed
    {
        return $this->fetchColumn($sql, $params);
    }

    /**
     * Fetch a single scalar value (alias).
     */
    public function fetchScalar(string $sql, array $params = []): mixed
    {
        return $this->fetchColumn($sql, $params);
    }

    /**
     * Insert a row. Returns the last insert ID.
     */
    public function insert(string $table, array $data): int
    {
        $table = $this->prefix . $table;
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));

        $sql = "INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})";
        $this->query($sql, $data);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update rows. Returns affected row count.
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $table = $this->prefix . $table;
        $setParts = [];
        $params = [];

        foreach ($data as $column => $value) {
            $paramKey = 'set_' . $column;
            $setParts[] = "`{$column}` = :{$paramKey}";
            $params[$paramKey] = $value;
        }

        $params = array_merge($params, $whereParams);
        $sql = "UPDATE `{$table}` SET " . implode(', ', $setParts) . " WHERE {$where}";
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Delete rows. Returns affected row count.
     */
    public function delete(string $table, string $where, array $params = []): int
    {
        $table = $this->prefix . $table;
        $sql = "DELETE FROM `{$table}` WHERE {$where}";
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Count rows.
     */
    public function count(string $table, string $where = '1', array $params = []): int
    {
        $table = $this->prefix . $table;
        return (int) $this->fetchColumn("SELECT COUNT(*) FROM `{$table}` WHERE {$where}", $params);
    }

    /**
     * Begin transaction.
     */
    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }

    /**
     * Run migration SQL file.
     */
    public function migrate(string $file): bool
    {
        $sql = file_get_contents($file);
        $sql = str_replace('{prefix}', $this->prefix, $sql);

        // Split by semicolons (basic approach for migrations)
        $statements = array_filter(array_map('trim', explode(';', $sql)));

        $this->beginTransaction();
        try {
            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    $this->pdo->exec($statement);
                }
            }
            $this->commit();
            return true;
        } catch (\Exception $e) {
            $this->rollBack();
            throw $e;
        }
    }
}
