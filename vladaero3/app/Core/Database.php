<?php
namespace App\Core;

use PDO;
use PDOException;

class Database {
    private static ?PDO $pdo = null;
    private static string $prefix = 'va_';
    private static ?array $config = null;

    public static function init(?array $customConfig = null): PDO {
        if (self::$pdo !== null && $customConfig === null) {
            return self::$pdo;
        }

        if ($customConfig !== null) {
            $cfg = $customConfig;
        } else {
            $configFile = __DIR__ . '/../../config/database.php';
            if (!file_exists($configFile)) {
                throw new \RuntimeException("Config file database.php not found. Please run install.php first.");
            }
            $cfg = require $configFile;
        }

        self::$config = $cfg;
        self::$prefix = $cfg['prefix'] ?? 'va_';

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $cfg['host'] ?? '127.0.0.1',
            $cfg['port'] ?? 3306,
            $cfg['database'] ?? 'vladaero'
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];

        try {
            self::$pdo = new PDO($dsn, $cfg['username'] ?? 'root', $cfg['password'] ?? '', $options);
            return self::$pdo;
        } catch (PDOException $e) {
            error_log("[VladAero DB Error] " . $e->getMessage());
            throw $e;
        }
    }

    public static function getPdo(): PDO {
        if (self::$pdo === null) {
            return self::init();
        }
        return self::$pdo;
    }

    public static function getPrefix(): string {
        return self::$prefix;
    }

    public static function prefixTable(string $table): string {
        return self::$prefix . $table;
    }

    public static function replacePrefix(string $sql): string {
        return str_replace('{prefix}', self::$prefix, $sql);
    }

    public static function query(string $sql, array $params = []): \PDOStatement {
        $pdo = self::getPdo();
        $sql = self::replacePrefix($sql);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetchAll(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    public static function fetchOne(string $sql, array $params = []): ?array {
        $row = self::query($sql, $params)->fetch();
        return $row ?: null;
    }

    public static function fetchValue(string $sql, array $params = []): mixed {
        $stmt = self::query($sql, $params);
        return $stmt->fetchColumn();
    }

    public static function insert(string $table, array $data): int {
        $pdo = self::getPdo();
        $tableName = self::prefixTable($table);
        $fields = array_keys($data);
        $placeholders = array_map(fn($f) => ':' . $f, $fields);

        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $tableName,
            implode('`, `', $fields),
            implode(', ', $placeholders)
        );

        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);
        return (int)$pdo->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        $pdo = self::getPdo();
        $tableName = self::prefixTable($table);
        $setClauses = [];
        $params = [];

        foreach ($data as $key => $val) {
            $setClauses[] = sprintf('`%s` = :set_%s', $key, $key);
            $params['set_' . $key] = $val;
        }

        foreach ($whereParams as $k => $v) {
            $params[$k] = $v;
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $tableName,
            implode(', ', $setClauses),
            $where
        );

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function delete(string $table, string $where, array $whereParams = []): int {
        $pdo = self::getPdo();
        $tableName = self::prefixTable($table);
        $sql = sprintf('DELETE FROM `%s` WHERE %s', $tableName, $where);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($whereParams);
        return $stmt->rowCount();
    }

    public static function beginTransaction(): bool {
        return self::getPdo()->beginTransaction();
    }

    public static function commit(): bool {
        return self::getPdo()->commit();
    }

    public static function rollBack(): bool {
        return self::getPdo()->rollBack();
    }

    public static function isConfigured(): bool {
        return file_exists(__DIR__ . '/../../config/database.php');
    }

    public static function isInstalled(): bool {
        return file_exists(__DIR__ . '/../../installed.lock') && self::isConfigured();
    }
}
