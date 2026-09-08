<?php
/**
 * VladInc Database Connection & PDO Wrapper
 */

if (!defined('VLADINC_INIT')) {
    require_once __DIR__ . '/config.php';
}

class DB {
    private static ?PDO $instance = null;

    public static function connect(): ?PDO {
        if (self::$instance === null) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
                ];
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                // Return null if database is not installed or unreachable yet (for install.php compatibility)
                return null;
            }
        }
        return self::$instance;
    }

    public static function query(string $sql, array $params = []): PDOStatement {
        $pdo = self::connect();
        if (!$pdo) {
            throw new Exception("База данных не подключена. Пожалуйста, запустите install.php");
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetch(string $sql, array $params = []): ?array {
        $res = self::query($sql, $params)->fetch();
        return $res ?: null;
    }

    public static function fetchAll(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    public static function fetchColumn(string $sql, array $params = []) {
        return self::query($sql, $params)->fetchColumn();
    }

    public static function insert(string $table, array $data): int {
        $keys = array_keys($data);
        $fields = implode('`, `', $keys);
        $placeholders = ':' . implode(', :', $keys);
        $sql = "INSERT INTO `{$table}` (`{$fields}`) VALUES ({$placeholders})";
        self::query($sql, $data);
        return (int)self::connect()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        $setParts = [];
        $params = [];
        foreach ($data as $key => $value) {
            $setParts[] = "`{$key}` = :set_{$key}";
            $params["set_{$key}"] = $value;
        }
        $setSql = implode(', ', $setParts);
        $sql = "UPDATE `{$table}` SET {$setSql} WHERE {$where}";
        $stmt = self::query($sql, array_merge($params, $whereParams));
        return $stmt->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int {
        $sql = "DELETE FROM `{$table}` WHERE {$where}";
        return self::query($sql, $params)->rowCount();
    }
}
