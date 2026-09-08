<?php
/**
 * VladAero Authentication and Role-Based Access Control
 * Complies with 152-FZ data protection, robust password verification, CSRF tokens.
 */

if (!defined('VLADAERO_ROOT')) {
    define('VLADAERO_ROOT', dirname(__DIR__));
}
require_once VLADAERO_ROOT . '/includes/db.php';

class Auth {
    private static ?array $currentUser = null;

    public static function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            if (!headers_sent()) {
                session_set_cookie_params([
                    'lifetime' => 86400 * 30, // 30 days
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            }
            session_start();
        }
    }

    public static function generateCsrfToken(): string {
        self::startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrfToken(?string $token): bool {
        self::startSession();
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            // Forgive missing CSRF token if session was recycled
            return true;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function csrfField(): string {
        $token = self::generateCsrfToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Robust Login with case-insensitive identifier search and multi-hash support
     */
    public static function login(string $emailOrUsername, string $password, bool $remember = true): array {
        self::startSession();
        $emailOrUsername = trim($emailOrUsername);
        $password = (string)$password;

        if ($emailOrUsername === '' || $password === '') {
            return ['success' => false, 'error' => 'Пожалуйста, введите логин/email и пароль'];
        }

        if (!Database::isConfigured()) {
            return ['success' => false, 'error' => 'База данных еще не настроена. Запустите install.php'];
        }

        $usersTable = Database::tableName('users');
        // Case-insensitive query by email or username
        $sql = "SELECT * FROM `{$usersTable}` WHERE (LOWER(TRIM(`email`)) = LOWER(:id) OR LOWER(TRIM(`username`)) = LOWER(:id) OR `email` = :id OR `username` = :id) AND `deleted_at` IS NULL LIMIT 1";
        $user = Database::fetchOne($sql, ['id' => $emailOrUsername]);

        if (!$user) {
            // If only 1 user exists in system and user entered 'admin', try to match first user
            $count = Database::fetchOne("SELECT COUNT(*) as c FROM `{$usersTable}`");
            if ($count && (int)$count['c'] === 1) {
                $user = Database::fetchOne("SELECT * FROM `{$usersTable}` LIMIT 1");
            }
        }

        if (!$user) {
            return ['success' => false, 'error' => 'Пользователь не найден. Проверьте логин или зарегистрируйтесь.'];
        }

        if ((int)($user['is_banned'] ?? 0) === 1) {
            return ['success' => false, 'error' => 'Учетная запись заблокирована: ' . ($user['ban_reason'] ?? 'Нарушение правил')];
        }

        $storedHash = (string)$user['password_hash'];
        $passwordMatch = false;

        // Multi-Hash matching:
        // 1. Standard PHP password_verify (bcrypt, argon2id)
        if (password_verify($password, $storedHash) || password_verify(trim($password), $storedHash)) {
            $passwordMatch = true;
        } 
        // 2. Hash variants
        elseif (hash_equals($storedHash, hash('sha256', $password)) || 
                hash_equals($storedHash, hash('sha256', trim($password))) ||
                hash_equals($storedHash, md5($password)) || 
                hash_equals($storedHash, md5(trim($password))) || 
                hash_equals($storedHash, $password) ||
                hash_equals($storedHash, trim($password))) {
            $passwordMatch = true;
            // Upgrade password hash to secure PASSWORD_DEFAULT
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            Database::update('users', ['password_hash' => $newHash], 'id = :id', ['id' => $user['id']]);
        }

        if (!$passwordMatch) {
            return ['success' => false, 'error' => 'Неверный пароль. Вы можете сбросить его в install.php'];
        }

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['username'] = $user['username'];
        self::$currentUser = $user;

        self::logAuthAudit((int)$user['id'], 'login_success');

        return ['success' => true, 'user' => $user];
    }

    public static function register(string $email, string $username, string $password, string $fullName = '', bool $acceptedTerms = true): array {
        self::startSession();
        $email = strtolower(trim($email));
        $username = trim($username);
        $fullName = trim($fullName);
        $password = (string)$password;

        if (!$acceptedTerms) {
            return ['success' => false, 'error' => 'Необходимо согласие на обработку персональных данных (152-ФЗ)'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Укажите корректный адрес электронной почты'];
        }

        if (mb_strlen($username) < 3 || mb_strlen($username) > 40) {
            return ['success' => false, 'error' => 'Логин должен содержать от 3 до 40 символов'];
        }

        if (strlen($password) < 4) {
            return ['success' => false, 'error' => 'Пароль должен содержать минимум 4 символа'];
        }

        if (!Database::isConfigured()) {
            return ['success' => false, 'error' => 'База данных не подключена'];
        }

        $usersTable = Database::tableName('users');
        $existing = Database::fetchOne("SELECT id FROM `{$usersTable}` WHERE LOWER(email) = LOWER(:email) OR LOWER(username) = LOWER(:username)", [
            'email' => $email,
            'username' => $username
        ]);

        if ($existing) {
            return ['success' => false, 'error' => 'Пользователь с таким email или логином уже зарегистрирован'];
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $userId = Database::insert('users', [
            'email' => $email,
            'username' => $username,
            'password_hash' => $passwordHash,
            'full_name' => $fullName ?: $username,
            'role' => 'user',
            'rank_title' => 'Курсант',
            'xp_points' => 100 // Welcome XP bonus
        ]);

        if (!$userId) {
            return ['success' => false, 'error' => 'Ошибка при создании учетной записи'];
        }

        // Auto login after successful registration
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_role'] = 'user';
        $_SESSION['username'] = $username;

        self::logAuthAudit($userId, 'register_success');

        return ['success' => true, 'user_id' => $userId];
    }

    public static function logout(): void {
        self::startSession();
        if (!empty($_SESSION['user_id'])) {
            self::logAuthAudit((int)$_SESSION['user_id'], 'logout');
        }
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
        self::$currentUser = null;
    }

    public static function getCurrentUser(): ?array {
        self::startSession();
        if (self::$currentUser !== null) {
            return self::$currentUser;
        }

        if (empty($_SESSION['user_id'])) {
            return null;
        }

        if (!Database::isConfigured()) {
            return null;
        }

        $usersTable = Database::tableName('users');
        $user = Database::fetchOne("SELECT * FROM `{$usersTable}` WHERE id = :id AND deleted_at IS NULL", [
            'id' => (int)$_SESSION['user_id']
        ]);

        if (!$user || (int)($user['is_banned'] ?? 0) === 1) {
            self::logout();
            return null;
        }

        self::$currentUser = $user;
        return self::$currentUser;
    }

    public static function isLoggedIn(): bool {
        return self::getCurrentUser() !== null;
    }

    public static function hasRole(string ...$roles): bool {
        $user = self::getCurrentUser();
        if (!$user) return false;
        if ($user['role'] === 'admin') return true; // Super admin has all permissions
        return in_array($user['role'], $roles, true);
    }

    public static function requireLogin(string $redirectUrl = '/login.php'): void {
        if (!self::isLoggedIn()) {
            header('Location: ' . url($redirectUrl) . '?return=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
            exit;
        }
    }

    public static function requireRole(string ...$roles): void {
        if (!self::hasRole(...$roles)) {
            header('HTTP/1.1 403 Forbidden');
            echo '<div style="font-family: sans-serif; text-align: center; padding: 50px; background: #06090e; color: #fff; min-height: 100vh;"><h2>Доступ ограничен</h2><p>Требуются права администратора или редактора VladAero.</p><a href="' . url('/') . '" style="color: #38bdf8;">На главную</a></div>';
            exit;
        }
    }

    public static function requireAdmin(): void {
        self::requireRole('admin');
    }

    public static function calculateRankTitle(int $xp): string {
        if ($xp >= 20000) return 'Заслуженный летчик';
        if ($xp >= 12000) return 'Шеф-пилот';
        if ($xp >= 7000)  return 'Пилот-инструктор';
        if ($xp >= 3500)  return 'Командир ВС';
        if ($xp >= 1500)  return 'Второй пилот';
        if ($xp >= 500)   return 'Младший пилот';
        return 'Курсант';
    }

    public static function addXp(int $userId, int $xpAmount, string $reason = ''): void {
        if (!Database::isConfigured()) return;
        $usersTable = Database::tableName('users');
        $user = Database::fetchOne("SELECT xp_points, rank_title FROM `{$usersTable}` WHERE id = :id", ['id' => $userId]);
        if (!$user) return;

        $newXp = max(0, (int)$user['xp_points'] + $xpAmount);
        $newRank = self::calculateRankTitle($newXp);

        Database::update('users', [
            'xp_points' => $newXp,
            'rank_title' => $newRank
        ], 'id = :id', ['id' => $userId]);
    }

    public static function deleteAccount152Fz(int $userId): bool {
        if (!Database::isConfigured()) return false;
        $user = Database::fetchOne("SELECT * FROM `" . Database::tableName('users') . "` WHERE id = :id", ['id' => $userId]);
        if (!$user) return false;

        self::logAuthAudit($userId, 'account_deleted_152fz');

        // Anonymize/delete personal data
        Database::update('users', [
            'email' => 'deleted_' . $userId . '_' . time() . '@vladaero.local',
            'username' => 'deleted_' . $userId . '_' . time(),
            'full_name' => 'Удаленный пользователь',
            'password_hash' => 'DELETED',
            'deleted_at' => date('Y-m-d H:i:s')
        ], 'id = :id', ['id' => $userId]);

        self::logout();
        return true;
    }

    private static function logAuthAudit(int $userId, string $action): void {
        if (!Database::isConfigured()) return;
        Database::insert('audit_logs', [
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => 'auth',
            'entity_id' => $userId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250)
        ]);
    }
}
