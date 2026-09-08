<?php
declare(strict_types=1);

/**
 * VladAero - Global Helper Functions & Utilities
 */

namespace VladAero;

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

/**
 * Escape HTML output safely
 */
function e(?string $string): string {
    return htmlspecialchars((string)$string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Get system setting with caching
 */
function get_setting(string $key, mixed $default = ''): mixed {
    static $settingsCache = null;

    if ($settingsCache === null) {
        $rows = DB::fetchAll("SELECT `setting_key`, `setting_value` FROM `va_settings`");
        $settingsCache = [];
        foreach ($rows as $row) {
            $settingsCache[$row['setting_key']] = $row['setting_value'];
        }
    }

    return $settingsCache[$key] ?? $default;
}

/**
 * Update or set system setting
 */
function set_setting(string $key, mixed $value, string $group = 'general', bool $isSecret = false): bool {
    $existing = DB::fetchValue("SELECT `id` FROM `va_settings` WHERE `setting_key` = :k", ['k' => $key]);
    if ($existing) {
        return DB::update('va_settings', ['setting_value' => (string)$value], '`setting_key` = :k', ['k' => $key]);
    } else {
        return (bool)DB::insert('va_settings', [
            'setting_key'   => $key,
            'setting_value' => (string)$value,
            'setting_group' => $group,
            'is_secret'     => $isSecret ? 1 : 0
        ]);
    }
}

/**
 * Generate or get CSRF token
 */
function csrf_token(): string {
    if (empty($_SESSION['va_csrf_token'])) {
        $_SESSION['va_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['va_csrf_token'];
}

/**
 * Generate CSRF hidden input field
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Verify CSRF token
 */
function verify_csrf(?string $token): bool {
    if (empty($_SESSION['va_csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['va_csrf_token'], $token);
}

/**
 * Transliterate Russian string to clean URL slug
 */
function slugify(string $text): string {
    $cyr = [
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'yo','ж'=>'zh',
        'з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o',
        'п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'kh','ц'=>'ts',
        'ч'=>'ch','ш'=>'sh','щ'=>'shch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        'А'=>'a','Б'=>'b','В'=>'v','Г'=>'g','Д'=>'d','Е'=>'e','Ё'=>'yo','Ж'=>'zh',
        'З'=>'z','И'=>'i','Й'=>'y','К'=>'k','Л'=>'l','М'=>'m','Н'=>'n','О'=>'o',
        'П'=>'p','Р'=>'r','С'=>'s','Т'=>'t','У'=>'u','Ф'=>'f','Х'=>'kh','Ц'=>'ts',
        'Ч'=>'ch','Ш'=>'sh','Щ'=>'shch','Ъ'=>'','Ы'=>'y','Ь'=>'','Э'=>'e','Ю'=>'yu','Я'=>'ya'
    ];
    $str = strtr($text, $cyr);
    $str = preg_replace('~[^\pL\d]+~u', '-', $str);
    $str = iconv('utf-8', 'us-ascii//TRANSLIT', $str) ?: $str;
    $str = preg_replace('~[^-\w]+~', '', $str);
    $str = trim($str, '-');
    $str = preg_replace('~-+~', '-', $str);
    return strtolower($str) ?: 'n-a';
}

/**
 * Get Client IP Address safely
 */
function get_client_ip(): string {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            $ipList = explode(',', $_SERVER[$h]);
            $ip = trim($ipList[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * Rate Limiter (File-based)
 */
function rate_limit_check(string $key, int $maxRequests, int $windowSeconds): bool {
    $cacheDir = dirname(__DIR__) . '/cache/ratelimit';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }

    $file = $cacheDir . '/' . md5($key) . '.json';
    $now = time();
    $data = ['count' => 0, 'reset' => $now + $windowSeconds];

    if (file_exists($file)) {
        $loaded = @json_decode((string)file_get_contents($file), true);
        if (is_array($loaded) && $loaded['reset'] > $now) {
            $data = $loaded;
        }
    }

    $data['count']++;
    @file_put_contents($file, json_encode($data), LOCK_EX);

    return $data['count'] <= $maxRequests;
}

/**
 * File-based Cache Helper
 */
function cache_get(string $key, mixed $default = null): mixed {
    $file = dirname(__DIR__) . '/cache/' . md5($key) . '.cache';
    if (!file_exists($file)) return $default;

    $content = @file_get_contents($file);
    if (!$content) return $default;

    $payload = @unserialize($content);
    if (!is_array($payload) || !isset($payload['expires'], $payload['data'])) {
        return $default;
    }

    if ($payload['expires'] !== 0 && $payload['expires'] < time()) {
        @unlink($file);
        return $default;
    }

    return $payload['data'];
}

function cache_set(string $key, mixed $data, int $ttlSeconds = 1800): bool {
    $cacheDir = dirname(__DIR__) . '/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }

    $file = $cacheDir . '/' . md5($key) . '.cache';
    $payload = [
        'expires' => $ttlSeconds > 0 ? time() + $ttlSeconds : 0,
        'data'    => $data
    ];

    return (bool)@file_put_contents($file, serialize($payload), LOCK_EX);
}

function cache_clear(string $prefix = ''): void {
    $cacheDir = dirname(__DIR__) . '/cache';
    if (!is_dir($cacheDir)) return;

    $files = glob($cacheDir . '/*.cache');
    if ($files) {
        foreach ($files as $f) {
            @unlink($f);
        }
    }
}

/**
 * Flash Notification system
 */
function set_flash(string $type, string $message): void {
    if (!isset($_SESSION['va_flashes'])) {
        $_SESSION['va_flashes'] = [];
    }
    $_SESSION['va_flashes'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array {
    $flashes = $_SESSION['va_flashes'] ?? [];
    unset($_SESSION['va_flashes']);
    return $flashes;
}

/**
 * Record Admin and Security Audit Log
 */
function record_audit(string $action, ?string $entityType = null, ?int $entityId = null, array|string $details = []): void {
    $userId = Auth::id();
    $ip = get_client_ip();
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $detailsJson = is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : (string)$details;

    DB::insert('va_audit_logs', [
        'user_id'      => $userId,
        'action'       => $action,
        'entity_type'  => $entityType,
        'entity_id'    => $entityId,
        'ip_address'   => $ip,
        'user_agent'   => $ua,
        'details_json' => $detailsJson
    ]);
}

/**
 * App error/activity logger
 */
function app_log(string $channel, string $message): void {
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $logFile = $logDir . '/app.log';
    $entry = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), strtoupper($channel), $message);
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

/**
 * Aviation Unit Formatting Helpers
 */
function format_speed(?int $kmh, string $system = 'aviation_imperial'): string {
    if ($kmh === null || $kmh <= 0) return '—';
    if ($system === 'metric') {
        return number_format($kmh, 0, '', ' ') . ' км/ч';
    }
    $knots = round($kmh * 0.539957);
    return "{$knots} kts (" . number_format($kmh, 0, '', ' ') . " км/ч)";
}

function format_altitude(?int $meters, string $system = 'aviation_imperial'): string {
    if ($meters === null || $meters <= 0) return '—';
    $feet = round($meters * 3.28084);
    if ($system === 'metric') {
        return number_format($meters, 0, '', ' ') . ' м (' . number_format($feet, 0, '', ' ') . ' ft)';
    }
    return "FL" . round($feet / 100) . " (" . number_format($feet, 0, '', ' ') . " ft / " . number_format($meters, 0, '', ' ') . " м)";
}

function format_range(?int $km): string {
    if ($km === null || $km <= 0) return '—';
    $nm = round($km * 0.539957);
    return number_format($km, 0, '', ' ') . " км ({$nm} NM)";
}

function format_weight(?int $kg): string {
    if ($kg === null || $kg <= 0) return '—';
    $lbs = round($kg * 2.20462);
    $t = round($kg / 1000, 1);
    return "{$t} т (" . number_format($kg, 0, '', ' ') . " кг / " . number_format($lbs, 0, '', ' ') . " lbs)";
}

function time_ago(string|int $datetime): string {
    $timestamp = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
    $diff = time() - $timestamp;

    if ($diff < 60) return 'только что';
    if ($diff < 3600) return floor($diff / 60) . ' мин назад';
    if ($diff < 86400) return floor($diff / 3600) . ' ч назад';
    if ($diff < 604800) return floor($diff / 86400) . ' д назад';
    return date('d.m.Y', $timestamp);
}
