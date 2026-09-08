<?php
/**
 * ShibaLingo - Comprehensive Telegram Bot Webhook & Handler
 * Features:
 *  - Password Reset via Telegram
 *  - Two-Factor Authentication (2FA) Codes & Management
 *  - Push Notifications (Streaks, Duels, Quests, Security)
 *  - Deep Linking & Account Association (/start link_<token>)
 *  - Interactive Inline Menus & Settings
 *  - AI Conlang Tutor & Word of the Day / Quizzes
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/telegram.php';

$botEnabled = getSetting('telegram_bot_enabled', '0');
$botToken = getSetting('telegram_bot_token', '');

if ($botEnabled !== '1' || empty($botToken)) {
    http_response_code(200);
    echo "Bot is currently disabled in Admin settings.";
    exit;
}

$updateRaw = file_get_contents('php://input');
$update = json_decode($updateRaw, true);

if (!$update) {
    http_response_code(200);
    echo "No update received.";
    exit;
}

$db = getDb();
$message = $update['message'] ?? null;
$callbackQuery = $update['callback_query'] ?? null;

// Determine current domain URL for links
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$domain = $protocol . ($_SERVER['HTTP_HOST'] ?? 'localhost');

/**
 * Helper to build the main Telegram bot menu
 */
function getBotMainMenuKeyboard(?array $linkedUser = null): array {
    global $domain;

    if ($linkedUser) {
        $twoFactorStatus = !empty($linkedUser['two_factor_enabled']) ? '🛡️ 2FA: ВКЛ' : '🛡️ 2FA: ВЫКЛ';
        return [
            'inline_keyboard' => [
                [
                    ['text' => '👤 Мой профиль', 'callback_data' => 'menu_profile'],
                    ['text' => $twoFactorStatus, 'callback_data' => 'menu_2fa']
                ],
                [
                    ['text' => '🔑 Сброс пароля', 'callback_data' => 'menu_reset_pwd'],
                    ['text' => '🔔 Уведомления', 'callback_data' => 'menu_notifications']
                ],
                [
                    ['text' => '📖 Слово дня', 'callback_data' => 'word_of_day'],
                    ['text' => '🎮 Быстрый квиз', 'callback_data' => 'quick_quiz']
                ],
                [
                    ['text' => '🌐 Открыть ShibaLingo', 'url' => $domain]
                ]
            ]
        ];
    } else {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🔗 Привязать аккаунт', 'url' => $domain . '/profile.php']
                ],
                [
                    ['text' => '📖 Слово дня', 'callback_data' => 'word_of_day'],
                    ['text' => '🎮 Быстрый квиз', 'callback_data' => 'quick_quiz']
                ],
                [
                    ['text' => '🌐 Открыть веб-сайт', 'url' => $domain]
                ]
            ]
        ];
    }
}

/**
 * Handle incoming user text messages
 */
if ($message) {
    $chatId = (string)($message['chat']['id'] ?? '');
    $text = trim($message['text'] ?? '');
    $tgUser = $message['from']['username'] ?? ($message['from']['first_name'] ?? 'User');

    // Find linked user in DB
    $uStmt = $db->prepare("SELECT * FROM " . tbl('users') . " WHERE telegram_chat_id = :cid LIMIT 1");
    $uStmt->execute(['cid' => $chatId]);
    $linkedUser = $uStmt->fetch();

    // 1. Command: /start [param]
    if (strpos($text, '/start') === 0) {
        $param = trim(substr($text, 6));

        // Check deep-link token (e.g. /start link_abcdef123...)
        if (strpos($param, 'link_') === 0) {
            $linkToken = substr($param, 5);
            $userFound = linkTelegramAccountByToken($linkToken, $chatId, $tgUser);

            if ($userFound) {
                $linkedUser = $userFound;
                $kb = getBotMainMenuKeyboard($linkedUser);
                $welcome = "🎉 <b>Ура! Аккаунт успешно привязан!</b> 🐕\n\n"
                         . "👤 Пользователь: <b>" . e($linkedUser['username']) . "</b>\n"
                         . "🔥 Стрик: <b>" . (int)$linkedUser['streak'] . " дн.</b> | 💎 Кристаллы: <b>" . (int)($linkedUser['gems'] ?? 50) . "</b>\n\n"
                         . "Теперь бот будет отправлять вам:\n"
                         . "• 🔐 Коды двухфакторной аутентификации (2FA)\n"
                         . "• 🔑 Коды и ссылки для мгновенного сброса пароля\n"
                         . "• 🔥 Напоминания о стрике и квестах\n\n"
                         . "Выберите действие в меню:";
                sendTelegramMessage($chatId, $welcome, $kb);
                exit;
            } else {
                sendTelegramMessage($chatId, "⚠️ <b>Ссылка для привязки недействительна или устарела.</b>\n\nПожалуйста, перейдите в ваш личный кабинет на сайте и нажмите кнопку «Привязать Telegram» заново.");
                exit;
            }
        }

        // Standard /start
        $welcomeSetting = getSetting('telegram_welcome_msg', 'Привет! Я Сиба-сэнсэй 🐕. Готов учить языки и язык Vladikish!');
        $kb = getBotMainMenuKeyboard($linkedUser);

        $statusText = $linkedUser 
            ? "✅ Привязан аккаунт: <b>" . e($linkedUser['username']) . "</b>"
            : "ℹ️ Аккаунт сайта пока не привязан. Войдите в профиль на сайте и нажмите «Привязать Telegram».";

        $msg = "🐕 <b>ShibaLingo Bot</b>\n\n"
             . "{$welcomeSetting}\n\n"
             . "{$statusText}\n\n"
             . "Выберите действие ниже:";

        sendTelegramMessage($chatId, $msg, $kb);
        exit;
    }

    // 2. Command: /link <code>
    elseif (strpos($text, '/link') === 0) {
        $linkToken = trim(substr($text, 5));
        if (empty($linkToken)) {
            sendTelegramMessage($chatId, "ℹ️ <b>Как привязать аккаунт:</b>\n\nВведите команду с кодом из личного кабинета, например:\n<code>/link your_code_here</code>");
            exit;
        }

        $userFound = linkTelegramAccountByToken($linkToken, $chatId, $tgUser);
        if ($userFound) {
            $linkedUser = $userFound;
            $kb = getBotMainMenuKeyboard($linkedUser);
            sendTelegramMessage($chatId, "✅ <b>Успешно! Аккаунт {$userFound['username']} привязан!</b> 🐾", $kb);
        } else {
            sendTelegramMessage($chatId, "✕ Код привязки неверен или истек. Получите новый в профиле на сайте.");
        }
        exit;
    }

    // 3. Command: /unlink
    elseif ($text === '/unlink') {
        if (!$linkedUser) {
            sendTelegramMessage($chatId, "ℹ️ Ваш Telegram не привязан ни к одному аккаунту.");
            exit;
        }

        $unl = $db->prepare("UPDATE " . tbl('users') . " SET telegram_chat_id = NULL, telegram_username = NULL, two_factor_enabled = 0 WHERE id = :id");
        $unl->execute(['id' => $linkedUser['id']]);

        $kb = getBotMainMenuKeyboard(null);
        sendTelegramMessage($chatId, "🔓 <b>Telegram отвязан от аккаунта {$linkedUser['username']}.</b>\n\nДвухфакторная аутентификация (2FA) для этого аккаунта также отключена.", $kb);
        exit;
    }

    // 4. Command: /reset (Password Reset)
    elseif ($text === '/reset' || $text === '/password') {
        if (!$linkedUser) {
            sendTelegramMessage($chatId, "⚠️ <b>Аккаунт не привязан!</b>\n\nЧтобы сбрасывать пароль через бота, сначала привяжите Telegram в настройках вашего профиля на сайте.");
            exit;
        }

        $res = createPasswordResetRequest((int)$linkedUser['id'], $domain);
        if ($res['success']) {
            // Confirmation already sent by createPasswordResetRequest
        } else {
            sendTelegramMessage($chatId, "✕ Ошибка генерации сброса пароля: " . ($res['error'] ?? 'Неизвестная ошибка'));
        }
        exit;
    }

    // 5. Command: /2fa
    elseif ($text === '/2fa') {
        if (!$linkedUser) {
            sendTelegramMessage($chatId, "⚠️ Сначала привяжите аккаунт через профиль на сайте.");
            exit;
        }

        $is2fa = !empty($linkedUser['two_factor_enabled']);
        $statusMsg = $is2fa ? "🟢 <b>2FA включена</b> (аккаунт защищен кодами в Telegram)" : "🔴 <b>2FA отключена</b>";

        $kb = [
            'inline_keyboard' => [
                [
                    ['text' => ($is2fa ? '❌ Отключить 2FA' : '✅ Включить 2FA'), 'callback_data' => 'toggle_2fa']
                ],
                [
                    ['text' => '🔙 В главное меню', 'callback_data' => 'menu_main']
                ]
            ]
        ];

        sendTelegramMessage($chatId, "🛡️ <b>Двухфакторная аутентификация (2FA):</b>\n\nТекущий статус: {$statusMsg}\n\nПри включенной 2FA при каждом входе в аккаунт бот будет присылать 6-значный код подтверждения.", $kb);
        exit;
    }

    // 6. Command: /notify
    elseif ($text === '/notify' || $text === '/notifications') {
        if (!$linkedUser) {
            sendTelegramMessage($chatId, "⚠️ Сначала привяжите аккаунт через профиль на сайте.");
            exit;
        }

        $sStreak = !empty($linkedUser['notify_streak']) ? '🔔 ВКЛ' : '🔕 ВЫКЛ';
        $sQuests = !empty($linkedUser['notify_quests']) ? '🔔 ВКЛ' : '🔕 ВЫКЛ';
        $sDuels  = !empty($linkedUser['notify_duels']) ? '🔔 ВКЛ' : '🔕 ВЫКЛ';
        $sSecurity = !empty($linkedUser['notify_security']) ? '🔔 ВКЛ' : '🔕 ВЫКЛ';

        $kb = [
            'inline_keyboard' => [
                [['text' => "🔥 Напоминания о стрике: {$sStreak}", 'callback_data' => 'toggle_notify_streak']],
                [['text' => "🎯 Новые квесты: {$sQuests}", 'callback_data' => 'toggle_notify_quests']],
                [['text' => "⚔️ Вызовы на дуэли: {$sDuels}", 'callback_data' => 'toggle_notify_duels']],
                [['text' => "🔒 Оповещения входа: {$sSecurity}", 'callback_data' => 'toggle_notify_security']],
                [['text' => '🔙 В главное меню', 'callback_data' => 'menu_main']]
            ]
        ];

        sendTelegramMessage($chatId, "🔔 <b>Управление уведомлениями Telegram:</b>\n\nНажмите на кнопку ниже, чтобы включить или отключить интересующие вас оповещения:", $kb);
        exit;
    }

    // 7. Command: /profile
    elseif ($text === '/profile' || $text === '/me') {
        if (!$linkedUser) {
            sendTelegramMessage($chatId, "⚠️ Аккаунт не привязан. Войдите на сайт и привяжите Telegram.");
            exit;
        }

        $langStmt = $db->prepare("SELECT name, flag FROM " . tbl('languages') . " WHERE code = :code");
        $langStmt->execute(['code' => $linkedUser['current_language'] ?? 'vladikish']);
        $lang = $langStmt->fetch() ?: ['name' => 'Vladikish', 'flag' => '🐕'];

        $msg = "👤 <b>Профиль ученика ShibaLingo</b>\n\n"
             . "🏷️ Имя: <b>" . e($linkedUser['username']) . "</b>\n"
             . "🌐 Язык: {$lang['flag']} <b>" . e($lang['name']) . "</b>\n"
             . "🔥 Стрик: <b>" . (int)$linkedUser['streak'] . " дней</b>\n"
             . "⚡ Опыт (XP): <b>" . (int)$linkedUser['xp'] . "</b>\n"
             . "💎 Кристаллы: <b>" . (int)($linkedUser['gems'] ?? 50) . "</b>\n"
             . "❤️ Жизни: <b>" . (int)$linkedUser['hearts'] . " / 5</b>\n"
             . "🛡️ 2FA Защита: <b>" . (!empty($linkedUser['two_factor_enabled']) ? 'Включена 🟢' : 'Отключена 🔴') . "</b>";

        $kb = [
            'inline_keyboard' => [
                [
                    ['text' => '🔑 Сбросить пароль', 'callback_data' => 'menu_reset_pwd'],
                    ['text' => '🛡️ Настроить 2FA', 'callback_data' => 'menu_2fa']
                ],
                [
                    ['text' => '🌐 Открыть профиль на сайте', 'url' => $domain . '/profile.php']
                ]
            ]
        ];

        sendTelegramMessage($chatId, $msg, $kb);
        exit;
    }

    // 8. Command: /word
    elseif ($text === '/word') {
        $w = $db->query("SELECT * FROM " . tbl('conlang_dictionary') . " ORDER BY " . (Database::getDriver() === 'sqlite' ? 'RANDOM()' : 'RAND()') . " LIMIT 1")->fetch();
        if ($w) {
            $msg = "🐕 <b>Слово на Vladikish:</b> <code>" . e($w['word']) . "</code>\n"
                 . "<b>Часть речи:</b> " . e($w['part_of_speech']) . "\n"
                 . "<b>Перевод (RU):</b> " . e($w['translation_ru']) . "\n"
                 . "<b>Произношение:</b> [" . e($w['pronunciation']) . "]\n"
                 . "<b>Пример:</b> <i>" . e($w['example_sentence']) . "</i>";
            sendTelegramMessage($chatId, $msg);
        } else {
            sendTelegramMessage($chatId, "🐕 Словарь Vladikish обновляется!");
        }
        exit;
    }

    // 9. Command: /quiz
    elseif ($text === '/quiz') {
        $w = $db->query("SELECT * FROM " . tbl('conlang_dictionary') . " ORDER BY " . (Database::getDriver() === 'sqlite' ? 'RANDOM()' : 'RAND()') . " LIMIT 1")->fetch();
        if ($w) {
            $kb = [
                'inline_keyboard' => [
                    [['text' => $w['translation_ru'] . ' (Правильно)', 'callback_data' => 'quiz_correct']],
                    [['text' => 'Ночь', 'callback_data' => 'quiz_wrong']],
                    [['text' => 'Огонь', 'callback_data' => 'quiz_wrong']]
                ]
            ];
            sendTelegramMessage($chatId, "❓ <b>Как переводится слово:</b> <code>" . e($w['word']) . "</code>?", $kb);
        }
        exit;
    }

    // 10. Command: /streak
    elseif ($text === '/streak') {
        if ($linkedUser) {
            sendTelegramMessage($chatId, "🔥 <b>Ваш стрик: " . (int)$linkedUser['streak'] . " дн.!</b>\n\nНе забудьте пройти сегодняшний урок на сайте, чтобы не потерять огонёк!");
        } else {
            sendTelegramMessage($chatId, "🔥 Стрик активен! Привяжите аккаунт через <code>/start link_code</code>, чтобы видеть точную статистику.");
        }
        exit;
    }

    // 11. Command: /chat <message>
    elseif (strpos($text, '/chat') === 0) {
        $query = trim(substr($text, 5));
        if (empty($query)) {
            sendTelegramMessage($chatId, "Напишите: <code>/chat Привет, Шиба!</code>");
        } else {
            $conlangContext = getVladikishContext();
            $messages = [
                ['role' => 'system', 'content' => "You are Shiba-sensei, a cute Japanese Shiba Inu tutor teaching Vladikish and English. Answer concisely with puppy emotes!\nContext:\n{$conlangContext}"],
                ['role' => 'user', 'content' => $query]
            ];
            $res = callNvidiaApi($messages);
            $reply = $res['success'] ? $res['content'] : "Гав! Ошибка AI: {$res['error']}";
            sendTelegramMessage($chatId, "🐕 <b>Сиба-сэнсэй:</b>\n" . $reply);
        }
        exit;
    }

    // Fallback: Show menu
    $kb = getBotMainMenuKeyboard($linkedUser);
    sendTelegramMessage($chatId, "🐕 Гав! Я вас слышу. Выберите команду или опцию в меню ниже:", $kb);
    exit;
}

/**
 * Handle inline button callbacks
 */
if ($callbackQuery) {
    $chatId = (string)($callbackQuery['message']['chat']['id'] ?? '');
    $data = $callbackQuery['data'] ?? '';

    // Fetch linked user
    $uStmt = $db->prepare("SELECT * FROM " . tbl('users') . " WHERE telegram_chat_id = :cid LIMIT 1");
    $uStmt->execute(['cid' => $chatId]);
    $linkedUser = $uStmt->fetch();

    if ($data === 'menu_main') {
        $kb = getBotMainMenuKeyboard($linkedUser);
        sendTelegramMessage($chatId, "🐕 <b>Главное меню ShibaLingo:</b>", $kb);
    }

    elseif ($data === 'menu_profile') {
        if (!$linkedUser) {
            sendTelegramMessage($chatId, "⚠️ Аккаунт не привязан.");
            exit;
        }

        $langStmt = $db->prepare("SELECT name, flag FROM " . tbl('languages') . " WHERE code = :code");
        $langStmt->execute(['code' => $linkedUser['current_language'] ?? 'vladikish']);
        $lang = $langStmt->fetch() ?: ['name' => 'Vladikish', 'flag' => '🐕'];

        $msg = "👤 <b>Ваш профиль в ShibaLingo:</b>\n\n"
             . "🏷️ Имя пользователя: <b>" . e($linkedUser['username']) . "</b>\n"
             . "🌐 Изучаемый язык: {$lang['flag']} <b>" . e($lang['name']) . "</b>\n"
             . "🔥 Ударный стрик: <b>" . (int)$linkedUser['streak'] . " дн.</b>\n"
             . "⚡ Накоплено XP: <b>" . (int)$linkedUser['xp'] . "</b>\n"
             . "💎 Кристаллы: <b>" . (int)($linkedUser['gems'] ?? 50) . "</b>\n"
             . "❤️ Жизни: <b>" . (int)$linkedUser['hearts'] . " / 5</b>\n"
             . "🛡️ 2FA защита: <b>" . (!empty($linkedUser['two_factor_enabled']) ? 'ВКЛ 🟢' : 'ВЫКЛ 🔴') . "</b>";

        $kb = [
            'inline_keyboard' => [
                [['text' => '🔑 Сбросить пароль', 'callback_data' => 'menu_reset_pwd']],
                [['text' => '🛡️ Управление 2FA', 'callback_data' => 'menu_2fa']],
                [['text' => '🔙 В главное меню', 'callback_data' => 'menu_main']]
            ]
        ];

        sendTelegramMessage($chatId, $msg, $kb);
    }

    elseif ($data === 'menu_reset_pwd') {
        if (!$linkedUser) {
            sendTelegramMessage($chatId, "⚠️ Сначала привяжите ваш аккаунт на сайте.");
            exit;
        }

        $res = createPasswordResetRequest((int)$linkedUser['id'], $domain);
        if (!$res['success']) {
            sendTelegramMessage($chatId, "✕ Ошибка: " . ($res['error'] ?? 'Не удалось создать запрос'));
        }
    }

    elseif ($data === 'menu_2fa') {
        if (!$linkedUser) {
            sendTelegramMessage($chatId, "⚠️ Аккаунт не привязан.");
            exit;
        }

        $is2fa = !empty($linkedUser['two_factor_enabled']);
        $statusMsg = $is2fa ? "🟢 <b>ВКЛЮЧЕНА</b>" : "🔴 <b>ОТКЛЮЧЕНА</b>";

        $kb = [
            'inline_keyboard' => [
                [['text' => ($is2fa ? '❌ Отключить 2FA' : '✅ Включить 2FA'), 'callback_data' => 'toggle_2fa']],
                [['text' => '🔙 В главное меню', 'callback_data' => 'menu_main']]
            ]
        ];

        sendTelegramMessage($chatId, "🛡️ <b>Двухфакторная защита (2FA):</b>\n\nСтатус: {$statusMsg}\n\nПри входе на сайт бот отправляет 6-значный одноразовый код.", $kb);
    }

    elseif ($data === 'toggle_2fa') {
        if (!$linkedUser) {
            sendTelegramMessage($chatId, "⚠️ Аккаунт не привязан.");
            exit;
        }

        $newVal = empty($linkedUser['two_factor_enabled']) ? 1 : 0;
        $up = $db->prepare("UPDATE " . tbl('users') . " SET two_factor_enabled = :val WHERE id = :id");
        $up->execute(['val' => $newVal, 'id' => $linkedUser['id']]);
        $linkedUser['two_factor_enabled'] = $newVal;

        $msg = $newVal 
            ? "✅ <b>2FA успешно ВКЛЮЧЕНА!</b>\n\nТеперь при каждом входе в аккаунт вы будете получать код подтверждения сюда в Telegram."
            : "⚠️ <b>2FA отключена.</b>\n\nВход в аккаунт теперь осуществляется только по логину и паролю.";

        $kb = getBotMainMenuKeyboard($linkedUser);
        sendTelegramMessage($chatId, $msg, $kb);
    }

    elseif ($data === 'menu_notifications') {
        if (!$linkedUser) {
            sendTelegramMessage($chatId, "⚠️ Аккаунт не привязан.");
            exit;
        }

        $sStreak = !empty($linkedUser['notify_streak']) ? '🔔 ВКЛ' : '🔕 ВЫКЛ';
        $sQuests = !empty($linkedUser['notify_quests']) ? '🔔 ВКЛ' : '🔕 ВЫКЛ';
        $sDuels  = !empty($linkedUser['notify_duels']) ? '🔔 ВКЛ' : '🔕 ВЫКЛ';
        $sSecurity = !empty($linkedUser['notify_security']) ? '🔔 ВКЛ' : '🔕 ВЫКЛ';

        $kb = [
            'inline_keyboard' => [
                [['text' => "🔥 Стрик: {$sStreak}", 'callback_data' => 'toggle_notify_streak']],
                [['text' => "🎯 Квесты: {$sQuests}", 'callback_data' => 'toggle_notify_quests']],
                [['text' => "⚔️ Дуэли: {$sDuels}", 'callback_data' => 'toggle_notify_duels']],
                [['text' => "🔒 Входы & Безопасность: {$sSecurity}", 'callback_data' => 'toggle_notify_security']],
                [['text' => '🔙 В главное меню', 'callback_data' => 'menu_main']]
            ]
        ];

        sendTelegramMessage($chatId, "🔔 <b>Настройки уведомлений:</b>\n\nНажмите на нужный пункт, чтобы изменить его статус:", $kb);
    }

    elseif (strpos($data, 'toggle_notify_') === 0) {
        if (!$linkedUser) exit;
        $type = substr($data, 14); // streak, quests, duels, security
        $col = 'notify_' . $type;

        $cur = (int)($linkedUser[$col] ?? 1);
        $newVal = $cur === 1 ? 0 : 1;

        $up = $db->prepare("UPDATE " . tbl('users') . " SET {$col} = :val WHERE id = :id");
        $up->execute(['val' => $newVal, 'id' => $linkedUser['id']]);
        $linkedUser[$col] = $newVal;

        // Re-render menu
        $sStreak = !empty($linkedUser['notify_streak']) ? '🔔 ВКЛ' : '🔕 ВЫКЛ';
        $sQuests = !empty($linkedUser['notify_quests']) ? '🔔 ВКЛ' : '🔕 ВЫКЛ';
        $sDuels  = !empty($linkedUser['notify_duels']) ? '🔔 ВКЛ' : '🔕 ВЫКЛ';
        $sSecurity = !empty($linkedUser['notify_security']) ? '🔔 ВКЛ' : '🔕 ВЫКЛ';

        $kb = [
            'inline_keyboard' => [
                [['text' => "🔥 Стрик: {$sStreak}", 'callback_data' => 'toggle_notify_streak']],
                [['text' => "🎯 Квесты: {$sQuests}", 'callback_data' => 'toggle_notify_quests']],
                [['text' => "⚔️ Дуэли: {$sDuels}", 'callback_data' => 'toggle_notify_duels']],
                [['text' => "🔒 Входы & Безопасность: {$sSecurity}", 'callback_data' => 'toggle_notify_security']],
                [['text' => '🔙 В главное меню', 'callback_data' => 'menu_main']]
            ]
        ];

        sendTelegramMessage($chatId, "✓ Настройки уведомлений обновлены!\n\nТекущее состояние:", $kb);
    }

    elseif ($data === 'word_of_day') {
        $w = $db->query("SELECT * FROM " . tbl('conlang_dictionary') . " ORDER BY " . (Database::getDriver() === 'sqlite' ? 'RANDOM()' : 'RAND()') . " LIMIT 1")->fetch();
        if ($w) {
            $msg = "🐕 <b>Слово на Vladikish:</b> <code>" . e($w['word']) . "</code>\n"
                 . "<b>Перевод (RU):</b> " . e($w['translation_ru']) . "\n"
                 . "<b>Произношение:</b> [" . e($w['pronunciation'] ?? '') . "]\n"
                 . "<b>Пример:</b> <i>" . e($w['example_sentence']) . "</i>";
            sendTelegramMessage($chatId, $msg);
        }
    }

    elseif ($data === 'quick_quiz') {
        $w = $db->query("SELECT * FROM " . tbl('conlang_dictionary') . " ORDER BY " . (Database::getDriver() === 'sqlite' ? 'RANDOM()' : 'RAND()') . " LIMIT 1")->fetch();
        if ($w) {
            $kb = [
                'inline_keyboard' => [
                    [['text' => $w['translation_ru'] . ' (Правильно)', 'callback_data' => 'quiz_correct']],
                    [['text' => 'Ночь', 'callback_data' => 'quiz_wrong']],
                    [['text' => 'Огонь', 'callback_data' => 'quiz_wrong']]
                ]
            ];
            sendTelegramMessage($chatId, "❓ <b>Как переводится:</b> <code>" . e($w['word']) . "</code>?", $kb);
        }
    }

    elseif ($data === 'quiz_correct') {
        sendTelegramMessage($chatId, "🎉 <b>Верно! +10 XP</b> Отличная работа, держи лапу! 🐾");
    }

    elseif ($data === 'quiz_wrong') {
        sendTelegramMessage($chatId, "💔 Не совсем так! Попробуй еще раз.");
    }
}

http_response_code(200);
echo "OK";
