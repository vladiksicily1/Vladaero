<?php
declare(strict_types=1);

namespace VladAero;

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/weather_decoder.php';
require_once dirname(__DIR__) . '/includes/auth.php';

$botToken = get_setting('telegram_bot_token', '');
$content = file_get_contents('php://input');
$update = json_decode($content, true);

if (!$update || empty($update['message'])) {
    echo json_encode(['ok' => true]);
    exit;
}

$message = $update['message'];
$chatId = $message['chat']['id'];
$text = trim($message['text'] ?? '');
$from = $message['from'] ?? [];
$tgUserId = (string)($from['id'] ?? '');
$tgUsername = $from['username'] ?? '';

// Dispatch commands
if (str_starts_with($text, '/start')) {
    $reply = "✈️ **Добро пожаловать в VladAero Bot!**\n\n" .
             "Официальный бот главного авиационного портала VladAero.\n\n" .
             "Доступные команды:\n" .
             "• `/login` — Получить код для мгновенного входа на сайт\n" .
             "• `/metar UUEE` — Погода METAR любого аэропорта\n" .
             "• `/track AFL102` — Отследить рейс на радаре\n" .
             "• `/random` — Случайный факт или самолет из базы\n" .
             "• `/quiz` — Авиационный вопрос дня\n\n" .
             "🌐 Портал: https://vladaero.ru";
    sendTelegramMessage($botToken, $chatId, $reply);
} elseif (str_starts_with($text, '/login')) {
    // Generate one-time login code
    $code = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $expires = date('Y-m-d H:i:s', time() + 900);

    // Find or create user associated with this TG
    $user = DB::fetchOne("SELECT `id` FROM `va_users` WHERE `telegram_id` = :tg", ['tg' => $tgUserId]);
    if ($user) {
        DB::update('va_users', [
            'telegram_auth_code'    => $code,
            'telegram_auth_expires' => $expires
        ], '`id` = :id', ['id' => $user['id']]);
    } else {
        // Create user or assign to pending
        $uname = $tgUsername ? 'tg_' . $tgUsername : 'pilot_' . substr($tgUserId, -4);
        $userId = DB::insert('va_users', [
            'email'                 => $tgUserId . '@telegram.vladaero.ru',
            'username'              => $uname,
            'password_hash'         => password_hash(bin2hex(random_bytes(10)), PASSWORD_BCRYPT),
            'full_name'             => trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')),
            'telegram_id'           => $tgUserId,
            'telegram_username'     => $tgUsername,
            'telegram_auth_code'    => $code,
            'telegram_auth_expires' => $expires,
            'role'                  => 'user',
            'rank_title'            => 'Курсант'
        ]);
    }

    $reply = "🔑 **Ваш одноразовый код для входа на VladAero:**\n\n" .
             "👉 `{$code}`\n\n" .
             "Введите его на странице авторизации сайта [vladaero.ru/login.php](https://vladaero.ru/login.php).\n" .
             "⏱ Срок действия кода: 15 минут.";
    sendTelegramMessage($botToken, $chatId, $reply);
} elseif (str_starts_with($text, '/metar')) {
    $parts = explode(' ', $text);
    $icao = strtoupper(trim($parts[1] ?? 'UUEE'));
    $decoded = WeatherDecoder::getMetar($icao);
    
    $reply = "🌤 **METAR {$icao}:**\n\n" .
             "`{$decoded['raw_metar']}`\n\n" .
             "• **Категория:** {$decoded['flight_category']}\n" .
             "• **Расшифровка:** {$decoded['summary_ru']}\n" .
             "• **QNH:** {$decoded['qnh_hpa']} hPa";
    sendTelegramMessage($botToken, $chatId, $reply);
} elseif (str_starts_with($text, '/random')) {
    $plane = DB::fetchOne("SELECT `model_name`, `slug`, `short_desc`, `icao_code` FROM `va_aircraft` ORDER BY RAND() LIMIT 1");
    if ($plane) {
        $reply = "✈️ **Случайный борт из энциклопедии:**\n\n" .
                 "**{$plane['model_name']} ({$plane['icao_code']})**\n" .
                 "{$plane['short_desc']}\n\n" .
                 "🔗 Подробнее: https://vladaero.ru/aircraft.php?slug={$plane['slug']}";
    } else {
        $reply = "В базе пока нет бортов.";
    }
    sendTelegramMessage($botToken, $chatId, $reply);
} elseif (str_starts_with($text, '/quiz')) {
    $q = DB::fetchOne("SELECT * FROM `va_quiz_questions` ORDER BY RAND() LIMIT 1");
    if ($q) {
        $reply = "❓ **Авиационный вопрос:**\n\n" .
                 "{$q['question_text']}\n\n" .
                 "A) {$q['option_a']}\n" .
                 "B) {$q['option_b']}\n" .
                 "C) {$q['option_c']}\n" .
                 "D) {$q['option_d']}\n\n" .
                 "💡 Ответ и разбор смотрите на сайте в разделе [Викторины](https://vladaero.ru/quizzes.php).";
    } else {
        $reply = "Викторины временно недоступны.";
    }
    sendTelegramMessage($botToken, $chatId, $reply);
}

echo json_encode(['ok' => true]);

function sendTelegramMessage(string $token, int|string $chatId, string $text): void {
    if (empty($token)) return;
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $payload = [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'Markdown'
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    curl_exec($ch);
    curl_close($ch);
}
