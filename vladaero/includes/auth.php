<?php
declare(strict_types=1);

/**
 * VladAero - Authentication, Authorization & User Ranks Engine
 */

namespace VladAero;

class Auth {
    private static ?array $currentUser = null;

    /**
     * Check if user is logged in
     */
    public static function check(): bool {
        return self::user() !== null;
    }

    /**
     * Get currently logged-in user record
     */
    public static function user(): ?array {
        if (self::$currentUser !== null) {
            return self::$currentUser;
        }

        if (empty($_SESSION['va_user_id'])) {
            return null;
        }

        $userId = (int)$_SESSION['va_user_id'];
        $user = DB::fetchOne("SELECT * FROM `va_users` WHERE `id` = :id AND `is_banned` = 0", ['id' => $userId]);

        if (!$user) {
            unset($_SESSION['va_user_id']);
            return null;
        }

        self::$currentUser = $user;
        return self::$currentUser;
    }

    /**
     * Get current user ID
     */
    public static function id(): ?int {
        $u = self::user();
        return $u ? (int)$u['id'] : null;
    }

    /**
     * Role checks
     */
    public static function isAdmin(): bool {
        $u = self::user();
        return $u && $u['role'] === 'admin';
    }

    public static function isEditor(): bool {
        $u = self::user();
        return $u && in_array($u['role'], ['admin', 'editor'], true);
    }

    public static function isModerator(): bool {
        $u = self::user();
        return $u && in_array($u['role'], ['admin', 'editor', 'moderator'], true);
    }

    public static function isScreener(): bool {
        $u = self::user();
        return $u && in_array($u['role'], ['admin', 'moderator', 'screener'], true);
    }

    /**
     * Log in user by email/username and password
     */
    public static function attempt(string $login, string $password): array {
        $login = trim($login);
        $user = DB::fetchOne(
            "SELECT * FROM `va_users` WHERE (LOWER(TRIM(`email`)) = LOWER(:email) OR LOWER(TRIM(`username`)) = LOWER(:username)) AND `deleted_at` IS NULL",
            ['email' => $login, 'username' => $login]
        );

        if (!$user) {
            return ['success' => false, 'error' => 'Пользователь с логином или email «' . $login . '» не найден.'];
        }

        if (!empty($user['is_banned'])) {
            return ['success' => false, 'error' => 'Аккаунт заблокирован. Причина: ' . ($user['ban_reason'] ?: 'Нарушение правил портала.')];
        }

        if (!password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Неверный пароль.'];
        }

        // Check if 2FA is enabled
        if (!empty($user['two_factor_secret'])) {
            $_SESSION['va_2fa_pending_user_id'] = $user['id'];
            return ['success' => true, 'requires_2fa' => true];
        }

        self::loginUser($user);
        return ['success' => true, 'requires_2fa' => false];
    }

    /**
     * Register a new user
     */
    public static function register(string $username, string $email, string $password, ?string $fullName = null): array {
        $username = trim($username);
        $email = strtolower(trim($email));

        if (strlen($username) < 3 || strlen($username) > 30) {
            return ['success' => false, 'error' => 'Имя пользователя должно содержать от 3 до 30 символов.'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Введите корректный адрес электронной почты.'];
        }

        if (strlen($password) < 6) {
            return ['success' => false, 'error' => 'Пароль должен содержать не менее 6 символов.'];
        }

        $existing = DB::fetchOne(
            "SELECT `id` FROM `va_users` WHERE `email` = :email OR `username` = :username",
            ['email' => $email, 'username' => $username]
        );

        if ($existing) {
            return ['success' => false, 'error' => 'Пользователь с таким email или логином уже зарегистрирован.'];
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $userId = DB::insert('va_users', [
            'email'         => $email,
            'username'      => $username,
            'password_hash' => $hash,
            'full_name'     => $fullName ?: $username,
            'role'          => 'user',
            'rank_title'    => 'Курсант',
            'xp_points'     => 100
        ]);

        if (!$userId) {
            return ['success' => false, 'error' => 'Ошибка при создании аккаунта в базе данных.'];
        }

        // Award first flight achievement
        self::awardAchievement((int)$userId, 'first_flight');

        $user = DB::fetchOne("SELECT * FROM `va_users` WHERE `id` = :id", ['id' => $userId]);
        self::loginUser($user);

        return ['success' => true];
    }

    /**
     * Telegram Auth Code generation & login
     */
    public static function generateTelegramAuthCode(int $userId): string {
        $code = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $expires = date('Y-m-d H:i:s', time() + 900); // 15 mins

        DB::update('va_users', [
            'telegram_auth_code'    => $code,
            'telegram_auth_expires' => $expires
        ], '`id` = :id', ['id' => $userId]);

        return $code;
    }

    public static function loginByTelegramCode(string $code): array {
        $code = strtoupper(trim($code));
        $user = DB::fetchOne(
            "SELECT * FROM `va_users` WHERE `telegram_auth_code` = :code AND `telegram_auth_expires` > NOW() AND `is_banned` = 0",
            ['code' => $code]
        );

        if (!$user) {
            return ['success' => false, 'error' => 'Неверный или просроченный код авторизации Telegram.'];
        }

        // Clear used code
        DB::update('va_users', [
            'telegram_auth_code'    => null,
            'telegram_auth_expires' => null
        ], '`id` = :id', ['id' => $user['id']]);

        self::loginUser($user);
        return ['success' => true];
    }

    /**
     * Complete login session
     */
    public static function loginUser(array $user): void {
        $_SESSION['va_user_id'] = $user['id'];
        self::$currentUser = $user;
        record_audit('user_login', 'user', (int)$user['id'], ['username' => $user['username']]);
    }

    /**
     * Logout
     */
    public static function logout(): void {
        if (self::check()) {
            record_audit('user_logout', 'user', self::id());
        }
        unset($_SESSION['va_user_id'], $_SESSION['va_2fa_pending_user_id']);
        self::$currentUser = null;
    }

    /**
     * Add XP and recalculate Rank
     */
    public static function addXp(int $userId, int $xp): void {
        $user = DB::fetchOne("SELECT `xp_points`, `rank_title` FROM `va_users` WHERE `id` = :id", ['id' => $userId]);
        if (!$user) return;

        $newXp = (int)$user['xp_points'] + $xp;
        $rankTitle = self::calculateRankTitle($newXp);

        DB::update('va_users', [
            'xp_points'  => $newXp,
            'rank_title' => $rankTitle
        ], '`id` = :id', ['id' => $userId]);

        // Check for achievements
        if ($newXp >= 500) self::awardAchievement($userId, 'cadet_pilot');
        if ($newXp >= 5000) self::awardAchievement($userId, 'captain_rank');
    }

    public static function calculateRankTitle(int $xp): string {
        if ($xp >= 10000) return 'Шеф-пилот';
        if ($xp >= 5000) return 'Пилот-инструктор';
        if ($xp >= 1500) return 'Командир ВС (КВС)';
        if ($xp >= 500) return 'Второй пилот';
        return 'Курсант';
    }

    /**
     * Add reputation points (tips/gratitude)
     */
    public static function addReputation(int $userId, int $points): void {
        DB::execute("UPDATE `va_users` SET `reputation` = `reputation` + :pts WHERE `id` = :id", [
            'pts' => $points,
            'id'  => $userId
        ]);
    }

    /**
     * Award Achievement to User
     */
    public static function awardAchievement(int $userId, string $code): bool {
        $ach = DB::fetchOne("SELECT `id` FROM `va_achievements` WHERE `code` = :code", ['code' => $code]);
        if (!$ach) return false;

        $exists = DB::fetchOne("SELECT `id` FROM `va_user_achievements` WHERE `user_id` = :u AND `achievement_id` = :a", [
            'u' => $userId,
            'a' => $ach['id']
        ]);

        if ($exists) return false;

        DB::insert('va_user_achievements', [
            'user_id'        => $userId,
            'achievement_id' => $ach['id']
        ]);

        return true;
    }
}
