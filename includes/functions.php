<?php
/**
 * VladAero Core Helper Functions
 * Includes dynamic base URL support for deployment in subfolders (e.g. /aviation or vladinc.ru/aviation).
 */

if (!defined('VLADAERO_ROOT')) {
    define('VLADAERO_ROOT', dirname(__DIR__));
}
require_once VLADAERO_ROOT . '/includes/db.php';
require_once VLADAERO_ROOT . '/includes/auth.php';

function getBasePath(): string {
    static $basePath = null;
    if ($basePath !== null) {
        return $basePath;
    }

    if (Database::isConfigured()) {
        $customBase = getSetting('base_url', null);
        if ($customBase !== null && $customBase !== '') {
            $basePath = rtrim($customBase, '/');
            return $basePath;
        }
    }

    // Auto-detect from script execution path
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    // Strip trailing /admin or /api if executing from a sub-directory
    $scriptDir = preg_replace('~/(admin|api)$~', '', $scriptDir);
    $basePath = ($scriptDir === '/' || $scriptDir === '\\' || $scriptDir === '.') ? '' : rtrim(str_replace('\\', '/', $scriptDir), '/');
    return $basePath;
}

function url(string $path = ''): string {
    $base = getBasePath();
    $path = '/' . ltrim($path, '/');
    if ($path === '/' && !empty($base)) {
        return $base . '/';
    }
    return $base . $path;
}

function asset(string $path): string {
    return url($path);
}

function e(?string $str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function getSetting(string $key, $default = null) {
    if (!Database::isConfigured()) {
        return $default;
    }
    $settingsTable = Database::tableName('settings');
    $val = Database::fetchValue("SELECT setting_value FROM `{$settingsTable}` WHERE setting_key = :k LIMIT 1", ['k' => $key]);
    return ($val !== null) ? $val : $default;
}

function setSetting(string $key, $value, string $group = 'general'): bool {
    if (!Database::isConfigured()) return false;
    $settingsTable = Database::tableName('settings');
    $exists = Database::fetchOne("SELECT id FROM `{$settingsTable}` WHERE setting_key = :k", ['k' => $key]);
    if ($exists) {
        return Database::update('settings', ['setting_value' => (string)$value], 'setting_key = :k', ['k' => $key]);
    } else {
        return (bool)Database::insert('settings', [
            'setting_key' => $key,
            'setting_value' => (string)$value,
            'setting_group' => $group
        ]);
    }
}

function formatNumber($num, int $decimals = 0): string {
    if ($num === null || $num === '') return '—';
    return number_format((float)$num, $decimals, ',', ' ');
}

function formatDate(?string $dateStr, bool $withTime = false): string {
    if (!$dateStr) return '—';
    $time = strtotime($dateStr);
    if (!$time) return $dateStr;

    $months = ['', 'янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
    $d = date('j', $time);
    $m = $months[(int)date('n', $time)];
    $y = date('Y', $time);

    if ($withTime) {
        return "{$d} {$m} {$y}, " . date('H:i', $time) . ' UTC';
    }
    return "{$d} {$m} {$y}";
}

function slugify(string $text): string {
    $cyr = [
        'а','б','в','г','д','е','ё','ж','з','и','й','к','л','м','н','о','п',
        'р','с','т','у','ф','х','ц','ч','ш','щ','ъ','ы','ь','э','ю','я',
        'А','Б','В','Г','Д','Е','Ё','Ж','З','И','Й','К','Л','М','Н','О','П',
        'Р','С','Т','У','Ф','Х','Ц','Ч','Ш','Щ','Ъ','Ы','Ь','Э','Ю','Я'
    ];
    $lat = [
        'a','b','v','g','d','e','yo','zh','z','i','y','k','l','m','n','o','p',
        'r','s','t','u','f','kh','ts','ch','sh','shch','','y','','e','yu','ya',
        'a','b','v','g','d','e','yo','zh','z','i','y','k','l','m','n','o','p',
        'r','s','t','u','f','kh','ts','ch','sh','shch','','y','','e','yu','ya'
    ];
    $text = str_replace($cyr, $lat, $text);
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text);
    return strtolower($text ?: 'item-' . time());
}

function isMaintenanceMode(): bool {
    $lockFile = VLADAERO_ROOT . '/.maintenance_lock';
    if (!file_exists($lockFile)) {
        return false;
    }
    if (Auth::hasRole('admin')) {
        return false;
    }
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    $whitelist = getSetting('maintenance_ip_whitelist', '');
    if ($whitelist) {
        $ips = array_map('trim', explode(',', $whitelist));
        if (in_array($clientIp, $ips, true)) {
            return false;
        }
    }
    return true;
}

function checkAndHandleMaintenance(): void {
    if (isMaintenanceMode()) {
        header('HTTP/1.1 503 Service Unavailable');
        header('Retry-After: 3600');
        $siteName = getSetting('site_name', 'VladAero');
        $msg = getSetting('maintenance_message', 'На портале проводятся плановые технические регламентные работы. Скоро мы вернемся в строй!');
        ?>
        <!DOCTYPE html>
        <html lang="ru" class="dark">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?= e($siteName) ?> — Техническое обслуживание</title>
            <script src="https://cdn.tailwindcss.com"></script>
        </head>
        <body class="bg-slate-950 text-slate-100 flex items-center justify-center min-h-screen p-6">
            <div class="max-w-md w-full text-center bg-slate-900 border border-sky-900/50 rounded-2xl p-8 shadow-2xl">
                <div class="w-16 h-16 bg-sky-500/10 border border-sky-500/30 rounded-2xl flex items-center justify-center mx-auto mb-6 text-sky-400 text-3xl">
                    ✈️
                </div>
                <h1 class="text-2xl font-bold text-sky-400 mb-2"><?= e($siteName) ?></h1>
                <h2 class="text-lg font-semibold text-slate-300 mb-4">Технический регламент</h2>
                <p class="text-slate-400 text-sm leading-relaxed mb-6"><?= e($msg) ?></p>
                <div class="p-3 bg-slate-950/60 rounded-xl border border-slate-800 text-xs text-slate-400 flex items-center justify-between">
                    <span>Статус транспондера:</span>
                    <span class="font-mono text-amber-400 font-bold">STANDBY 7000</span>
                </div>
                <div class="mt-6 text-xs text-slate-400">
                    Администратор? <a href="<?= url('/install.php') ?>" class="text-sky-400 hover:underline">Техническая панель</a>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

function renderFlash(): string {
    Auth::startSession();
    if (empty($_SESSION['flash'])) return '';
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    $typeClass = ($flash['type'] === 'error') ? 'bg-red-500/10 border-red-500/30 text-red-400' : 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400';
    return '<div class="p-4 mb-6 rounded-xl border ' . $typeClass . ' text-sm flex items-center justify-between"><span>' . e($flash['message']) . '</span><button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-white">&times;</button></div>';
}

function setFlash(string $type, string $message): void {
    Auth::startSession();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}
