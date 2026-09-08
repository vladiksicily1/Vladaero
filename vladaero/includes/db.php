<?php
declare(strict_types=1);

/**
 * VladAero - Database Connection & Query Engine
 * Supports MySQL / MariaDB via PDO, table prefix replacement, and safe query execution.
 */

namespace VladAero;

use PDO;
use PDOException;

class DB {
    private static ?PDO $instance = null;
    private static string $prefix = 'va_';
    private static array $config = [];

    /**
     * Get singleton PDO connection
     */
    public static function getConnection(): ?PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        self::loadConfig();

        if (empty(self::$config['db_host']) || empty(self::$config['db_name'])) {
            return null;
        }

        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                self::$config['db_host'],
                self::$config['db_port'] ?? '3306',
                self::$config['db_name']
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => true,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];

            self::$instance = new PDO($dsn, self::$config['db_user'], self::$config['db_pass'] ?? '', $options);
            self::$prefix = self::$config['db_prefix'] ?? 'va_';

            return self::$instance;
        } catch (PDOException $e) {
            self::logError('Database Connection Failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Load configuration from config.php or environment
     */
    public static function loadConfig(): void {
        $configFile = dirname(__DIR__) . '/config.php';
        if (file_exists($configFile)) {
            $loaded = require $configFile;
            if (is_array($loaded)) {
                self::$config = $loaded;
                if (!empty($loaded['db_prefix'])) {
                    self::$prefix = $loaded['db_prefix'];
                }
            }
        }
    }

    /**
     * Get table prefix
     */
    public static function getPrefix(): string {
        return self::$prefix;
    }

    /**
     * Replace standard 'va_' prefix with configured table prefix
     */
    public static function prefixQuery(string $sql): string {
        if (self::$prefix === 'va_') {
            return $sql;
        }
        return str_replace('`va_', '`' . self::$prefix, $sql);
    }

    /**
     * Execute SELECT query and fetch all rows
     */
    public static function fetchAll(string $sql, array $params = []): array {
        $db = self::getConnection();
        if (!$db) return [];

        try {
            $stmt = $db->prepare(self::prefixQuery($sql));
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            self::logError('Query Error [fetchAll]: ' . $e->getMessage() . ' | SQL: ' . $sql);
            return [];
        }
    }

    /**
     * Execute SELECT query and fetch single row
     */
    public static function fetchOne(string $sql, array $params = []): ?array {
        $db = self::getConnection();
        if (!$db) return null;

        try {
            $stmt = $db->prepare(self::prefixQuery($sql));
            $stmt->execute($params);
            $res = $stmt->fetch();
            return $res ?: null;
        } catch (PDOException $e) {
            self::logError('Query Error [fetchOne]: ' . $e->getMessage() . ' | SQL: ' . $sql);
            return null;
        }
    }

    /**
     * Fetch single scalar value (e.g. COUNT(*))
     */
    public static function fetchValue(string $sql, array $params = [], mixed $default = null): mixed {
        $db = self::getConnection();
        if (!$db) return $default;

        try {
            $stmt = $db->prepare(self::prefixQuery($sql));
            $stmt->execute($params);
            $val = $stmt->fetchColumn();
            return $val !== false ? $val : $default;
        } catch (PDOException $e) {
            self::logError('Query Error [fetchValue]: ' . $e->getMessage() . ' | SQL: ' . $sql);
            return $default;
        }
    }

    /**
     * Execute INSERT query and return last insert ID
     */
    public static function insert(string $table, array $data): int|string|false {
        $db = self::getConnection();
        if (!$db || empty($data)) return false;

        $table = str_starts_with($table, 'va_') ? self::$prefix . substr($table, 3) : $table;
        $fields = array_keys($data);
        $escapedFields = array_map(fn($f) => "`$f`", $fields);
        $placeholders = array_map(fn($f) => ":$f", $fields);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', $escapedFields),
            implode(', ', $placeholders)
        );

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($data);
            return $db->lastInsertId();
        } catch (PDOException $e) {
            self::logError('Insert Error in table ' . $table . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Execute UPDATE query
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): bool {
        $db = self::getConnection();
        if (!$db || empty($data)) return false;

        $table = str_starts_with($table, 'va_') ? self::$prefix . substr($table, 3) : $table;
        $setClauses = [];
        $params = [];

        foreach ($data as $key => $value) {
            $paramKey = 'set_' . $key;
            $setClauses[] = "`$key` = :$paramKey";
            $params[$paramKey] = $value;
        }

        $params = array_merge($params, $whereParams);
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $setClauses), $where);

        try {
            $stmt = $db->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            self::logError('Update Error in table ' . $table . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Execute generic statement (e.g. DELETE, ALTER, SET)
     */
    public static function execute(string $sql, array $params = []): bool {
        $db = self::getConnection();
        if (!$db) return false;

        try {
            $stmt = $db->prepare(self::prefixQuery($sql));
            return $stmt->execute($params);
        } catch (PDOException $e) {
            self::logError('Execute Error: ' . $e->getMessage() . ' | SQL: ' . $sql);
            return false;
        }
    }

    /**
     * Check if database is connected and installed
     */
    public static function isInstalled(): bool {
        $db = self::getConnection();
        if (!$db) return false;

        try {
            $testTable = self::$prefix . 'settings';
            $stmt = $db->query("SHOW TABLES LIKE '{$testTable}'");
            return $stmt->rowCount() > 0;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Log database error to logs/app.log
     */
    public static function logError(string $message): void {
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . '/app.log';
        $entry = sprintf("[%s] [DB] %s\n", date('Y-m-d H:i:s'), $message);
        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }
}
