<?php
/**
 * VladAero — Global Helper Functions
 */

use VladAero\Core\Session;
use VladAero\Core\Database;
use VladAero\Core\Cache;

// ─── Settings Helper ──────────────────────────────────────────

function setting(string $key, mixed $default = null): mixed
{
    static $settings = null;
    if ($settings === null) {
        try {
            $db = Database::getInstance();
            $prefix = $db->getPrefix();
            $rows = $db->fetchAll("SELECT setting_key, setting_value FROM `{$prefix}settings`");
            $settings = [];
            foreach ($rows as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (\Throwable $e) {
            $settings = [];
        }
    }
    return $settings[$key] ?? $GLOBALS['config'][$key] ?? $default;
}

// ─── URL Helpers ──────────────────────────────────────────────

function basePath(): string
{
    $base = rtrim($GLOBALS['config']['base_url'] ?? '', '/');
    if (empty($base)) {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = ($scriptDir === '/' || $scriptDir === '.' || $scriptDir === '') ? '' : rtrim($scriptDir, '/');
    }
    return $base;
}

function url(string $path = ''): string
{
    $base = basePath();
    $p = '/' . ltrim($path, '/');
    if ($path === '' || $path === '/') {
        return $base !== '' ? $base : '/';
    }
    return $base . $p;
}

function currentUrl(): string
{
    return $_SERVER['REQUEST_URI'] ?? '/';
}

function isActive(string $path): string
{
    $current = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '/';
    $base = basePath();
    if ($base !== '' && str_starts_with($current, $base)) {
        $current = substr($current, strlen($base));
    }
    $current = '/' . ltrim($current, '/');
    $checkPath = '/' . ltrim($path, '/');
    if ($checkPath === '/') {
        return ($current === '/' || $current === '') ? 'active' : '';
    }
    return str_starts_with($current, $checkPath) ? 'active' : '';
}

// ─── Sanitization ─────────────────────────────────────────────

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function clean(?string $value): string
{
    return trim(strip_tags($value ?? ''));
}

// ─── Slug Generation ──────────────────────────────────────────

function slugify(string $text): string
{
    // Transliterate Cyrillic to Latin
    $cyr = [
        'а','б','в','г','д','е','ё','ж','з','и','й','к','л','м','н','о',
        'п','р','с','т','у','ф','х','ц','ч','ш','щ','ъ','ы','ь','э','ю','я',
        'А','Б','В','Г','Д','Е','Ё','Ж','З','И','Й','К','Л','М','Н','О',
        'П','Р','С','Т','У','Ф','Х','Ц','Ч','Ш','Щ','Ъ','Ы','Ь','Э','Ю','Я',
    ];
    $lat = [
        'a','b','v','g','d','e','yo','zh','z','i','y','k','l','m','n','o',
        'p','r','s','t','u','f','kh','ts','ch','sh','shch','','y','','e','yu','ya',
        'A','B','V','G','D','E','Yo','Zh','Z','I','Y','K','L','M','N','O',
        'P','R','S','T','U','F','Kh','Ts','Ch','Sh','Shch','','Y','','E','Yu','Ya',
    ];
    $text = str_replace($cyr, $lat, $text);
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^a-z0-9\-]/', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    return trim($text, '-');
}

// ─── Date / Time ──────────────────────────────────────────────

function formatDate(?string $date, string $format = 'd.m.Y'): string
{
    if (empty($date)) return '';
    try {
        return (new DateTime($date))->format($format);
    } catch (\Throwable $e) {
        return '';
    }
}

function timeAgo(?string $datetime): string
{
    if (empty($datetime)) return '';
    try {
        $now = new DateTime();
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);

        if ($diff->y > 0) return $diff->y . ' ' . pluralize($diff->y, 'год', 'года', 'лет') . ' назад';
        if ($diff->m > 0) return $diff->m . ' ' . pluralize($diff->m, 'месяц', 'месяца', 'месяцев') . ' назад';
        if ($diff->d > 0) return $diff->d . ' ' . pluralize($diff->d, 'день', 'дня', 'дней') . ' назад';
        if ($diff->h > 0) return $diff->h . ' ' . pluralize($diff->h, 'час', 'часа', 'часов') . ' назад';
        if ($diff->i > 0) return $diff->i . ' ' . pluralize($diff->i, 'минуту', 'минуты', 'минут') . ' назад';
        return 'только что';
    } catch (\Throwable $e) {
        return '';
    }
}

function pluralize(int $n, string $one, string $few, string $many): string
{
    $mod10 = $n % 10;
    $mod100 = $n % 100;
    if ($mod100 >= 11 && $mod100 <= 19) return $many;
    if ($mod10 === 1) return $one;
    if ($mod10 >= 2 && $mod10 <= 4) return $few;
    return $many;
}

// ─── Number Formatting ────────────────────────────────────────

function formatNumber(int|float $num, int $decimals = 0): string
{
    return number_format($num, $decimals, ',', ' ');
}

function formatDistance(int $km): string
{
    if ($km >= 1000) {
        return number_format($km / 1000, 1, ',', ' ') . ' тыс. км';
    }
    return formatNumber($km) . ' км';
}

function formatDuration(int $minutes): string
{
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h > 0) return "{$h}ч {$m}м";
    return "{$m}м";
}

// ─── Aviation Unit Conversion ──────────────────────────────────

function knotsToKmh(float $knots): float { return $knots * 1.852; }
function kmhToKnots(float $kmh): float { return $kmh / 1.852; }
function feetToMeters(float $ft): float { return $ft * 0.3048; }
function metersToFeet(float $m): float { return $m / 0.3048; }
function ftToFL(float $ft): int { return (int)($ft / 100); }
function kgToLbs(float $kg): float { return $kg * 2.20462; }
function lbsToKg(float $lbs): float { return $lbs / 2.20462; }
function hpaToInhg(float $hpa): float { return $hpa * 0.02953; }
function inhgToHpa(float $inhg): float { return $inhg / 0.02953; }

// ─── Great Circle Distance ────────────────────────────────────

function greatCircleDistance(float $lat1, float $lon1, float $lat2, float $lon2): int
{
    $earthRadius = 6371; // km
    $lat1 = deg2rad($lat1);
    $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2);
    $lon2 = deg2rad($lon2);

    $dLat = $lat2 - $lat1;
    $dLon = $lon2 - $lon1;

    $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return (int)round($earthRadius * $c);
}

// ─── METAR Decoding ───────────────────────────────────────────

function decodeMetar(string $metar): array
{
    $result = [
        'raw'           => $metar,
        'station'       => '',
        'time'          => '',
        'wind'          => '',
        'wind_speed'    => 0,
        'wind_dir'      => 0,
        'visibility'    => '',
        'weather'       => '',
        'clouds'        => [],
        'temperature'   => '',
        'dewpoint'      => '',
        'pressure'      => '',
        'decoded'       => '',
    ];

    $parts = preg_split('/\s+/', trim($metar));
    if (empty($parts)) return $result;

    $decodedParts = [];

    // Station
    if (preg_match('/^[A-Z]{4}$/', $parts[0])) {
        $result['station'] = $parts[0];
    }

    foreach ($parts as $part) {
        // Wind: 27015G25KT
        if (preg_match('/^(\d{3})(\d{2,3})(G(\d{2,3}))?(KT|MPS|KMH)$/', $part, $m)) {
            $result['wind_dir'] = (int)$m[1];
            $result['wind_speed'] = (int)$m[2];
            $result['wind'] = "{$m[1]}° {$m[2]} кн";
            if (!empty($m[4])) $result['wind'] .= " порывы {$m[4]} кн";
            $decodedParts[] = "Ветер {$result['wind']}";
        }

        // Visibility: 9999 or 5000
        if (preg_match('/^\d{4}$/', $part) && !preg_match('/^(\d{3})(\d{2,3})/', $part)) {
            $vis = (int)$part;
            if ($vis >= 9999) {
                $result['visibility'] = 'более 10 км';
            } else {
                $result['visibility'] = $vis . ' м';
            }
            $decodedParts[] = "Видимость {$result['visibility']}";
        }

        // Temperature
        if (preg_match('/^(M?\d{2})\/(M?\d{2})$/', $part, $m)) {
            $t = ($m[1][0] === 'M') ? '-' . substr($m[1], 1) : $m[1];
            $d = ($m[2][0] === 'M') ? '-' . substr($m[2], 1) : $m[2];
            $result['temperature'] = $t . '°C';
            $result['dewpoint'] = $d . '°C';
            $decodedParts[] = "Температура {$t}°C, точка росы {$d}°C";
        }

        // QNH
        if (preg_match('/^Q(\d{4})$/', $part, $m)) {
            $result['pressure'] = hpaToInhg((int)$m[1]) . ' inHg (' . $m[1] . ' гПа)';
            $decodedParts[] = "Давление {$result['pressure']}";
        }

        // Clouds: FEW030, SCT050, BKN080, OVC100
        if (preg_match('/^(FEW|SCT|BKN|OVC|CLR|SKC)(\d{3})$/', $part, $m)) {
            $coverages = ['FEW' => 'рассеянные', 'SCT' => 'рассеянные', 'BKN' => 'разбитые', 'OVC' => 'сплошная', 'CLR' => 'ясно', 'SKC' => 'ясно'];
            $result['clouds'][] = ($coverages[$m[1]] ?? $m[1]) . ' на ' . ((int)$m[2] * 100) . ' м';
            $decodedParts[] = "Облачность: " . end($result['clouds']);
        }
    }

    $result['decoded'] = implode('. ', $decodedParts) . '.';
    return $result;
}

// ─── Crosswind Calculation ────────────────────────────────────

function calculateCrosswind(int $runwayHeading, int $windDir, int $windSpeed): array
{
    $diff = abs($runwayHeading - $windDir);
    if ($diff > 180) $diff = 360 - $diff;
    $rad = deg2rad($diff);

    $headwind = round($windSpeed * cos($rad));
    $crosswind = round($windSpeed * sin($rad));

    return [
        'headwind'    => $headwind,
        'crosswind'   => $crosswind,
        'is_tailwind' => $diff > 90,
    ];
}

// ─── Descent Calculation ──────────────────────────────────────

function calculateDescentPoint(int $currentAltFt, int $targetAltFt, int $groundSpeedKnots, float $glideAngle = 3.0): array
{
    $altitudeDiff = $currentAltFt - $targetAltFt;
    if ($altitudeDiff <= 0) return ['distance_nm' => 0, 'fpm' => 0, 'tod_minutes' => 0];

    $distanceNM = $altitudeDiff / ($glideAngle * 100); // ~300 ft per NM at 3°
    $fpm = round(($groundSpeedKnots / 60) * $altitudeDiff / max($distanceNM, 1));
    $minutes = round($distanceNM / $groundSpeedKnots * 60);

    return [
        'distance_nm' => round($distanceNM, 1),
        'fpm'         => $fpm,
        'tod_minutes' => $minutes,
    ];
}

// ─── Flight Compensation Calculator ───────────────────────────

function calculateCompensation(int $distanceKm, int $delayMinutes): array
{
    if ($delayMinutes < 180) return ['amount' => 0, 'eligible' => false];

    // EC261 regulation
    if ($distanceKm <= 1500) {
        $amount = 250;
    } elseif ($distanceKm <= 3500) {
        $amount = 400;
    } else {
        $amount = 600;
    }

    return [
        'eligible'  => true,
        'amount'    => $amount,
        'currency'  => 'EUR',
        'regulation' => 'EC 261/2004',
    ];
}

// ─── Pagination ───────────────────────────────────────────────

function paginate(int $total, int $perPage, int $currentPage): array
{
    $totalPages = max(1, (int)ceil($total / $perPage));
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset = ($currentPage - 1) * $perPage;

    return [
        'total'        => $total,
        'per_page'     => $perPage,
        'current_page' => $currentPage,
        'total_pages'  => $totalPages,
        'offset'       => $offset,
        'has_prev'     => $currentPage > 1,
        'has_next'     => $currentPage < $totalPages,
    ];
}

function renderPagination(array $pagination, callable $urlBuilder): string
{
    if ($pagination['total_pages'] <= 1) return '';

    $html = '<nav class="pagination" aria-label="Навигация по страницам">';
    $html .= '<ul class="pagination__list">';

    if ($pagination['has_prev']) {
        $html .= '<li><a href="' . e($urlBuilder($pagination['current_page'] - 1)) . '" class="pagination__link">« Назад</a></li>';
    }

    $range = range(max(1, $pagination['current_page'] - 2), min($pagination['total_pages'], $pagination['current_page'] + 2));

    if (reset($range) > 1) {
        $html .= '<li><a href="' . e($urlBuilder(1)) . '" class="pagination__link">1</a></li>';
        if (reset($range) > 2) $html .= '<li><span class="pagination__dots">…</span></li>';
    }

    foreach ($range as $page) {
        $active = $page === $pagination['current_page'] ? ' pagination__link--active' : '';
        $html .= '<li><a href="' . e($urlBuilder($page)) . '" class="pagination__link' . $active . '">' . $page . '</a></li>';
    }

    if (end($range) < $pagination['total_pages']) {
        if (end($range) < $pagination['total_pages'] - 1) $html .= '<li><span class="pagination__dots">…</span></li>';
        $html .= '<li><a href="' . e($urlBuilder($pagination['total_pages'])) . '" class="pagination__link">' . $pagination['total_pages'] . '</a></li>';
    }

    if ($pagination['has_next']) {
        $html .= '<li><a href="' . e($urlBuilder($pagination['current_page'] + 1)) . '" class="pagination__link">Далее »</a></li>';
    }

    $html .= '</ul></nav>';
    return $html;
}

// ─── CSRF Helper ──────────────────────────────────────────────

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Session::csrfToken()) . '">';
}

// ─── Flash Messages ───────────────────────────────────────────

function flashMessages(): string
{
    $messages = Session::flash();
    $html = '';
    foreach ($messages as $msg) {
        $type = e($msg['type']);
        $text = e($msg['message']);
        $html .= "<div class=\"flash flash--{$type}\" role=\"alert\">{$text}<button class=\"flash__close\" onclick=\"this.parentElement.remove()\">×</button></div>";
    }
    return $html;
}

// ─── Image Helpers ────────────────────────────────────────────

function getThumbPath(string $originalPath): string
{
    $pathInfo = pathinfo($originalPath);
    return $pathInfo['dirname'] . '/thumbnails/' . $pathInfo['filename'] . '_thumb.' . ($pathInfo['extension'] ?? 'jpg');
}

function imageTag(string $src, string $alt, array $attrs = []): string
{
    $attrStr = '';
    foreach ($attrs as $key => $value) {
        $attrStr .= ' ' . e($key) . '="' . e((string)$value) . '"';
    }
    return '<img src="' . e($src) . '" alt="' . e($alt) . '"' . $attrStr . ' loading="lazy">';
}

// ─── JSON Helpers ─────────────────────────────────────────────

function jsonEncode(mixed $data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// ─── Gravatar / Default Avatar ────────────────────────────────

function avatarUrl(?string $avatar, string $email = ''): string
{
    if ($avatar) return url('public/uploads/avatars/' . $avatar);
    return url('public/images/default-avatar.svg');
}

// ─── Schema.org Helpers ───────────────────────────────────────

function schemaAircraft(array $aircraft): string
{
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $aircraft['name'] ?? '',
        'description' => strip_tags($aircraft['history'] ?? ''),
        'manufacturer' => ['@type' => 'Organization', 'name' => $aircraft['manufacturer_name'] ?? ''],
    ];
    return '<script type="application/ld+json">' . jsonEncode($data) . '</script>';
}

function schemaAirport(array $airport): string
{
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'Airport',
        'name' => $airport['name'] ?? '',
        'iataCode' => $airport['iata_code'] ?? '',
        'icaoCode' => $airport['icao_code'] ?? '',
        'address' => ['@type' => 'PostalAddress', 'addressLocality' => $airport['city'] ?? '', 'addressCountry' => $airport['country_code'] ?? ''],
    ];
    if (!empty($airport['latitude']) && !empty($airport['longitude'])) {
        $data['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $airport['latitude'], 'longitude' => $airport['longitude']];
    }
    return '<script type="application/ld+json">' . jsonEncode($data) . '</script>';
}

// ─── Telegram Bot Helper ───────────────────────────────────────

function sendTelegramMessage(int|string|null $chatId, string $text): bool
{
    if (empty($chatId)) return false;
    $token = setting('telegram_bot_token', $GLOBALS['config']['telegram']['bot_token'] ?? '');
    if (empty($token)) return false;

    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode([
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ]),
            'timeout' => 5,
        ],
    ]);
    $res = @file_get_contents($url, false, $context);
    return $res !== false;
}
