<?php
namespace App\Core;

class Auth {
    public static function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('vladaero_session');
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax'
            ]);
        }
    }

    public static function check(): bool {
        self::startSession();
        return !empty($_SESSION['user_id']);
    }

    public static function user(): ?array {
        self::startSession();
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        static $cachedUser = null;
        if ($cachedUser !== null && $cachedUser['id'] === $_SESSION['user_id']) {
            return $cachedUser;
        }

        try {
            $user = Database::fetchOne(
                "SELECT * FROM `{prefix}users` WHERE `id` = :id AND `is_banned` = 0",
                ['id' => $_SESSION['user_id']]
            );
            $cachedUser = $user ?: null;
            return $cachedUser;
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function id(): ?int {
        self::startSession();
        return $_SESSION['user_id'] ?? null;
    }

    public static function role(): string {
        $u = self::user();
        return $u['role'] ?? 'guest';
    }

    public static function hasRole(string|array $roles): bool {
        $current = self::role();
        if ($current === 'admin') return true; // admin has all permissions
        if (is_string($roles)) {
            return $current === $roles;
        }
        return in_array($current, $roles, true);
    }

    public static function attempt(string $usernameOrEmail, string $password): bool {
        self::startSession();

        $user = Database::fetchOne(
            "SELECT * FROM `{prefix}users` WHERE (`username` = :u OR `email` = :e) AND `is_banned` = 0",
            ['u' => $usernameOrEmail, 'e' => $usernameOrEmail]
        );

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['username'] = $user['username'];
            return true;
        }
        return false;
    }

    public static function loginUsingId(int $userId): bool {
        self::startSession();
        $user = Database::fetchOne("SELECT * FROM `{prefix}users` WHERE `id` = :id AND `is_banned` = 0", ['id' => $userId]);
        if ($user) {
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['username'] = $user['username'];
            return true;
        }
        return false;
    }

    public static function logout(): void {
        self::startSession();
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }

    public static function register(string $username, string $email, string $password): int {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $id = Database::insert('users', [
            'username' => $username,
            'email' => $email,
            'password_hash' => $hash,
            'role' => 'user',
            'rank_title' => 'Курсант',
            'flight_hours' => 0.0,
            'reputation_points' => 10,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        self::loginUsingId($id);
        return $id;
    }

    public static function calculateRank(float $flightHours, int $reputation): string {
        if ($flightHours >= 1000 || $reputation >= 5000) {
            return 'Пилот-инструктор (Chief)';
        } elseif ($flightHours >= 250 || $reputation >= 1500) {
            return 'Командир ВС (Captain)';
        } elseif ($flightHours >= 50 || $reputation >= 300) {
            return 'Второй пилот (First Officer)';
        } else {
            return 'Курсант (Cadet)';
        }
    }
}
