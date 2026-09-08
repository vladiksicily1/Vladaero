<?php
namespace VladAero\Core;

/**
 * VladAero — Session Manager
 */
class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started) return;

        $lifetime = $GLOBALS['config']['session_lifetime'] ?? 7200;
        $savePath = PROJECT_ROOT . '/' . ($GLOBALS['config']['paths']['sessions'] ?? 'storage/sessions');

        if (!is_dir($savePath)) {
            @mkdir($savePath, 0700, true);
        }

        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.gc_maxlifetime', (string) $lifetime);
        if (is_dir($savePath) && is_writable($savePath)) {
            @session_save_path($savePath);
        }

        session_name('VLA_SESSION');
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        self::$started = true;

        // Regenerate session ID periodically
        if (!isset($_SESSION['_last_regenerate'])) {
            $_SESSION['_last_regenerate'] = time();
        } elseif (time() - $_SESSION['_last_regenerate'] > 300) {
            session_regenerate_id(true);
            $_SESSION['_last_regenerate'] = time();
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        self::start();
        session_destroy();
        $_SESSION = [];
        self::$started = false;
    }

    /**
     * Set authenticated user.
     */
    public static function setAuth(array $user): void
    {
        self::set('user_id', $user['id']);
        self::set('user_role', $user['role']);
        self::set('user_data', $user);
    }

    /**
     * Get authenticated user data.
     */
    public static function getAuth(): ?array
    {
        return self::get('user_data');
    }

    public static function isLoggedIn(): bool
    {
        return self::get('user_id') !== null;
    }

    public static function logout(): void
    {
        self::destroy();
    }

    /**
     * Flash messages (one-time messages).
     */
    public static function setFlash(string $type, string $message): void
    {
        self::start();
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function flash(): array
    {
        self::start();
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }

    /**
     * CSRF token management.
     */
    public static function csrfToken(): string
    {
        self::start();
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }

    public static function verifyCsrf(string $token): bool
    {
        self::start();
        return hash_equals($_SESSION['_csrf_token'] ?? '', $token);
    }

    /**
     * Get or create cart/data for anonymous users.
     */
    public static function getAnonymousId(): string
    {
        self::start();
        if (empty($_SESSION['_anon_id'])) {
            $_SESSION['_anon_id'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['_anon_id'];
    }
}
