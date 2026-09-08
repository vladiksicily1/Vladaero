<?php
/**
 * VladInc Core Helpers & Security Utilities
 */

if (!defined('VLADINC_INIT')) {
    require_once __DIR__ . '/config.php';
}

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string {
    $path = ltrim($path, '/');
    return rtrim(BASE_URL, '/') . '/' . $path;
}

function asset(string $path = ''): string {
    return url('assets/' . ltrim($path, '/'));
}

function redirect(string $path): void {
    header("Location: " . url($path));
    exit;
}

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_validate(): bool {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function flash_set(string $type, string $message): void {
    $_SESSION['flash'] = [
        'type' => $type, // success, error, warning, info
        'message' => $message
    ];
}

function flash_get(): ?array {
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function time_ago(?string $datetime): string {
    if (!$datetime) return 'недавно';
    $time = strtotime($datetime);
    $diff = time() - $time;

    if ($diff < 5) return 'только что';
    if ($diff < 60) return $diff . ' сек. назад';
    
    $minutes = round($diff / 60);
    if ($minutes < 60) {
        return $minutes . ' мин. назад';
    }
    
    $hours = round($diff / 3600);
    if ($hours < 24) {
        return $hours . ' ч. назад';
    }
    
    $days = round($diff / 86400);
    if ($days < 7) {
        return $days . ' дн. назад';
    }
    
    return date('d.m.Y H:i', $time);
}

function format_coins(int|float $coins): string {
    if ($coins >= 1000000) {
        return round($coins / 1000000, 1) . 'M';
    }
    if ($coins >= 1000) {
        return round($coins / 1000, 1) . 'K';
    }
    return number_format($coins, 0, '.', ' ');
}

function parse_content(string $text): string {
    // 1. Sanitize HTML
    $text = e($text);
    
    // 2. Convert URLs to clickable links
    $text = preg_replace(
        '~(https?://[^\s<]+)~i',
        '<a href="$1" target="_blank" rel="noopener noreferrer" class="link-highlight">$1</a>',
        $text
    );
    
    // 3. Mentions (@username)
    $text = preg_replace(
        '/@([a-zA-Z0-9_]{3,30})/',
        '<a href="' . url('profile/@$1') . '" class="mention-tag">@$1</a>',
        $text
    );
    
    // 4. Hashtags (#tag)
    $text = preg_replace(
        '/#([a-zA-Z0-9_а-яА-ЯёЁ]+)/u',
        '<a href="' . url('explore?q=%23$1') . '" class="hashtag-tag">#$1</a>',
        $text
    );
    
    // 5. Line breaks
    return nl2br($text);
}

function calculate_level(int $xp): int {
    // Formula: Level = floor(sqrt(XP / 50)) + 1
    return max(1, (int)floor(sqrt($xp / 50)) + 1);
}

function level_progress(int $xp): array {
    $currentLevel = calculate_level($xp);
    $currentLevelBaseXp = 50 * pow($currentLevel - 1, 2);
    $nextLevelBaseXp = 50 * pow($currentLevel, 2);
    $xpNeeded = max(1, $nextLevelBaseXp - $currentLevelBaseXp);
    $xpCurrent = max(0, $xp - $currentLevelBaseXp);
    $percentage = min(100, max(0, round(($xpCurrent / $xpNeeded) * 100)));

    return [
        'level' => $currentLevel,
        'percentage' => $percentage,
        'current_xp' => $xpCurrent,
        'needed_xp' => $xpNeeded,
        'total_xp' => $xp
    ];
}

function create_notification(int $userId, ?int $senderId, string $type, string $message, ?string $link = null): bool {
    try {
        DB::insert('notifications', [
            'user_id' => $userId,
            'sender_id' => $senderId,
            'type' => $type,
            'message' => $message,
            'link' => $link
        ]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}
