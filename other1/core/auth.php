<?php
/**
 * VladInc - VladID Authentication and Authorization System
 */

if (!defined('VLADINC_INIT')) {
    require_once __DIR__ . '/config.php';
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

class Auth {
    private static ?array $currentUser = null;
    private static bool $checked = false;

    public static function check(): bool {
        return self::user() !== null;
    }

    public static function id(): ?int {
        $u = self::user();
        return $u ? (int)$u['id'] : null;
    }

    public static function isAdmin(): bool {
        $u = self::user();
        return $u && $u['role'] === 'admin';
    }

    public static function user(): ?array {
        if (self::$checked) {
            return self::$currentUser;
        }

        self::$checked = true;
        $pdo = DB::connect();
        if (!$pdo) {
            return null;
        }

        // 1. Check active session
        $userId = $_SESSION['vladinc_user_id'] ?? null;

        // 2. Check Remember-Me cookie if session is empty
        if (!$userId && !empty($_COOKIE['vladinc_token'])) {
            $token = $_COOKIE['vladinc_token'];
            $session = DB::fetch(
                "SELECT user_id FROM user_sessions WHERE session_token = ? AND expires_at > NOW() LIMIT 1",
                [$token]
            );
            if ($session) {
                $userId = $session['user_id'];
                $_SESSION['vladinc_user_id'] = $userId;
            }
        }

        if ($userId) {
            $user = DB::fetch("SELECT * FROM users WHERE id = ? AND is_banned = 0 LIMIT 1", [$userId]);
            if ($user) {
                self::$currentUser = $user;
                // Update last seen occasionally (once every 3 mins)
                if (strtotime($user['last_seen']) < time() - 180) {
                    DB::update('users', ['last_seen' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $user['id']]);
                }
            } else {
                self::logout();
            }
        }

        return self::$currentUser;
    }

    public static function login(string $login, string $password, bool $remember = true): array {
        $login = trim($login);
        if (empty($login) || empty($password)) {
            return ['success' => false, 'error' => 'Заполните все поля'];
        }

        $user = DB::fetch(
            "SELECT * FROM users WHERE (username = ? OR email = ?) LIMIT 1",
            [$login, $login]
        );

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Неверный логин или пароль'];
        }

        if ($user['is_banned']) {
            return ['success' => false, 'error' => 'Ваш аккаунт заблокирован администратором'];
        }

        $_SESSION['vladinc_user_id'] = $user['id'];
        self::$currentUser = $user;

        if ($remember) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 86400 * 30);
            DB::insert('user_sessions', [
                'user_id' => $user['id'],
                'session_token' => $token,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                'expires_at' => $expires
            ]);
            setcookie('vladinc_token', $token, time() + 86400 * 30, '/', '', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on', true);
        }

        return ['success' => true, 'user' => $user];
    }

    public static function register(string $username, string $email, string $password, string $displayName = ''): array {
        $username = strtolower(trim($username));
        $email = strtolower(trim($email));
        $displayName = trim($displayName) ?: $username;

        if (!preg_match('/^[a-z0-9_]{3,30}$/', $username)) {
            return ['success' => false, 'error' => 'Логин должен содержать от 3 до 30 символов (только латиница, цифры и _)'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Укажите корректный email адрес'];
        }

        if (mb_strlen($password) < 6) {
            return ['success' => false, 'error' => 'Пароль должен содержать не менее 6 символов'];
        }

        // Check uniqueness
        $existing = DB::fetch("SELECT id, username, email FROM users WHERE username = ? OR email = ? LIMIT 1", [$username, $email]);
        if ($existing) {
            if ($existing['username'] === $username) {
                return ['success' => false, 'error' => 'Пользователь с таким никнеймом уже зарегистрирован'];
            }
            return ['success' => false, 'error' => 'Пользователь с таким email уже зарегистрирован'];
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $apiToken = bin2hex(random_bytes(32));

        $userId = DB::insert('users', [
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
            'display_name' => $displayName,
            'coins' => 150, // Welcome bonus
            'xp' => 50,
            'level' => 1,
            'api_token' => $apiToken
        ]);

        // Record welcome bonus transaction
        DB::insert('transactions', [
            'from_user_id' => null,
            'to_user_id' => $userId,
            'amount' => 150,
            'type' => 'bonus',
            'note' => 'Приветственный бонус в экосистеме VladInc!'
        ]);

        // Auto login
        return self::login($username, $password, true);
    }

    public static function logout(): void {
        if (!empty($_COOKIE['vladinc_token'])) {
            try {
                DB::delete('user_sessions', 'session_token = ?', [$_COOKIE['vladinc_token']]);
            } catch (Exception $e) {}
            setcookie('vladinc_token', '', time() - 3600, '/');
        }
        unset($_SESSION['vladinc_user_id']);
        self::$currentUser = null;
        self::$checked = false;
    }

    public static function requireAuth(): array {
        $user = self::user();
        if (!$user) {
            flash_set('warning', 'Для доступа к этой странице войдите через Vlad ID');
            redirect('login');
        }
        return $user;
    }

    public static function requireAdmin(): array {
        $user = self::requireAuth();
        if ($user['role'] !== 'admin') {
            flash_set('error', 'Доступ ограничен. Требуются права администратора VladInc');
            redirect('social');
        }
        return $user;
    }

    public static function awardCoinsAndXp(int $userId, int $coins, int $xp, string $type = 'bonus', string $note = ''): void {
        $user = DB::fetch("SELECT id, coins, xp, level FROM users WHERE id = ?", [$userId]);
        if (!$user) return;

        $newCoins = max(0, $user['coins'] + $coins);
        $newXp = max(0, $user['xp'] + $xp);
        $newLevel = calculate_level($newXp);

        DB::update('users', [
            'coins' => $newCoins,
            'xp' => $newXp,
            'level' => $newLevel
        ], 'id = :id', ['id' => $userId]);

        if ($coins > 0) {
            DB::insert('transactions', [
                'from_user_id' => null,
                'to_user_id' => $userId,
                'amount' => $coins,
                'type' => $type,
                'note' => $note ?: "Награда: +{$coins} VladCoins"
            ]);
        }

        // Level up notification
        if ($newLevel > $user['level']) {
            create_notification($userId, null, 'system', "🎉 Поздравляем! Вы достигли {$newLevel} уровня в экосистеме VladInc!", url('id'));
        }
    }
}
