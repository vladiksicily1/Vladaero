<?php
/**
 * VladAero Database Connection Manager
 * Supports MySQL / MariaDB via PDO with UTF-8 and prepared statements.
 */

if (!defined('VLADAERO_ROOT')) {
    define('VLADAERO_ROOT', dirname(__DIR__));
}

class Database {
    private static ?PDO $instance = null;
    private static string $tablePrefix = 'va_';
    private static bool $isConfigured = false;

    public static function init(): ?PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $configFile = VLADAERO_ROOT . '/config.php';
        if (!file_exists($configFile)) {
            // Check if SQLite fallback exists for local CLI / test environments
            $sqliteFile = VLADAERO_ROOT . '/uploads/vladaero.sqlite';
            if (file_exists($sqliteFile)) {
                try {
                    self::$instance = new PDO('sqlite:' . $sqliteFile, null, null, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_TIMEOUT => 5
                    ]);
                    self::$isConfigured = true;
                    return self::$instance;
                } catch (PDOException $e) {
                    // ignore
                }
            }
            return null;
        }

        $config = require $configFile;
        self::$tablePrefix = $config['db']['prefix'] ?? 'va_';

        $driver = $config['db']['driver'] ?? 'mysql';
        $host = $config['db']['host'] ?? 'localhost';
        $port = $config['db']['port'] ?? 3306;
        $dbname = $config['db']['dbname'] ?? 'vladaero';
        $user = $config['db']['user'] ?? 'root';
        $pass = $config['db']['pass'] ?? '';

        try {
            if ($driver === 'sqlite') {
                $dsn = 'sqlite:' . ($config['db']['file'] ?? VLADAERO_ROOT . '/uploads/vladaero.sqlite');
                self::$instance = new PDO($dsn, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);
            } else {
                $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
                self::$instance = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
                ]);
            }
            self::$isConfigured = true;
            return self::$instance;
        } catch (PDOException $e) {
            error_log('Database connection error: ' . $e->getMessage());
            return null;
        }
    }

    public static function getConnection(): ?PDO {
        return self::init();
    }

    public static function getPrefix(): string {
        return self::$tablePrefix;
    }

    public static function tableName(string $name): string {
        return self::$tablePrefix . $name;
    }

    public static function isConfigured(): bool {
        if (!self::$isConfigured) {
            self::init();
        }
        return self::$isConfigured && self::$instance !== null;
    }

    public static function query(string $sql, array $params = []): ?PDOStatement {
        $pdo = self::getConnection();
        if (!$pdo) {
            return null;
        }
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("SQL Error [{$sql}]: " . $e->getMessage());
            return null;
        }
    }

    public static function fetchAll(string $sql, array $params = []): array {
        $stmt = self::query($sql, $params);
        return $stmt ? $stmt->fetchAll() : [];
    }

    public static function fetchOne(string $sql, array $params = []): ?array {
        $stmt = self::query($sql, $params);
        if (!$stmt) return null;
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function fetchValue(string $sql, array $params = [], $default = null) {
        $stmt = self::query($sql, $params);
        if (!$stmt) return $default;
        $val = $stmt->fetchColumn();
        return ($val !== false) ? $val : $default;
    }

    public static function insert(string $table, array $data): ?int {
        $pdo = self::getConnection();
        if (!$pdo) return null;

        $tableName = (strpos($table, self::$tablePrefix) === 0) ? $table : self::tableName($table);
        $keys = array_keys($data);
        $fields = implode('`, `', $keys);
        $placeholders = ':' . implode(', :', $keys);

        $sql = "INSERT INTO `{$tableName}` (`{$fields}`) VALUES ({$placeholders})";
        $stmt = self::query($sql, $data);
        return $stmt ? (int)$pdo->lastInsertId() : null;
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): bool {
        $tableName = (strpos($table, self::$tablePrefix) === 0) ? $table : self::tableName($table);
        $sets = [];
        $params = [];

        foreach ($data as $key => $val) {
            $sets[] = "`{$key}` = :set_{$key}";
            $params["set_{$key}"] = $val;
        }

        $sql = "UPDATE `{$tableName}` SET " . implode(', ', $sets) . " WHERE {$where}";
        $stmt = self::query($sql, array_merge($params, $whereParams));
        return $stmt !== null;
    }

    public static function delete(string $table, string $where, array $whereParams = []): bool {
        $tableName = (strpos($table, self::$tablePrefix) === 0) ? $table : self::tableName($table);
        $sql = "DELETE FROM `{$tableName}` WHERE {$where}";
        $stmt = self::query($sql, $whereParams);
        return $stmt !== null;
    }
}
