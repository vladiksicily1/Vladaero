<?php
/**
 * ShibaLingo - Telegram Integration Engine
 * Provides Telegram Bot API communications, 2FA generation/verification,
 * password reset via Telegram, and automated notification handling.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/**
 * Ensure database has necessary tables and columns for Telegram, 2FA, and password reset
 */
function ensureTelegramSchema(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $db = getDb();
        $driver = Database::getDriver();

        // 1. Check/Add columns to users table
        $columnsToAdd = [
            'telegram_chat_id' => ($driver === 'sqlite') ? 'TEXT' : 'VARCHAR(64) NULL',
            'telegram_username' => ($driver === 'sqlite') ? 'TEXT' : 'VARCHAR(100) NULL',
            'telegram_link_code' => ($driver === 'sqlite') ? 'TEXT' : 'VARCHAR(64) NULL',
            'two_factor_enabled' => ($driver === 'sqlite') ? 'INTEGER DEFAULT 0' : 'TINYINT(1) DEFAULT 0',
            'two_factor_code' => ($driver === 'sqlite') ? 'TEXT' : 'VARCHAR(10) NULL',
            'two_factor_expires' => ($driver === 'sqlite') ? 'DATETIME' : 'DATETIME NULL',
            'notify_streak' => ($driver === 'sqlite') ? 'INTEGER DEFAULT 1' : 'TINYINT(1) DEFAULT 1',
            'notify_quests' => ($driver === 'sqlite') ? 'INTEGER DEFAULT 1' : 'TINYINT(1) DEFAULT 1',
            'notify_friends' => ($driver === 'sqlite') ? 'INTEGER DEFAULT 1' : 'TINYINT(1) DEFAULT 1',
            'notify_duels' => ($driver === 'sqlite') ? 'INTEGER DEFAULT 1' : 'TINYINT(1) DEFAULT 1',
            'notify_security' => ($driver === 'sqlite') ? 'INTEGER DEFAULT 1' : 'TINYINT(1) DEFAULT 1'
        ];

        // Fetch existing columns in users table
        $existingCols = [];
        if ($driver === 'sqlite') {
            $prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
            $q = $db->query("PRAGMA table_info({$prefix}users)");
            if ($q) {
                while ($row = $q->fetch()) {
                    $existingCols[] = strtolower($row['name']);
                }
            }
        } else {
            $prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
            $tblName = $prefix . 'users';
            $q = $db->query("SHOW COLUMNS FROM `{$tblName}`");
            if ($q) {
                while ($row = $q->fetch()) {
                    $existingCols[] = strtolower($row['Field']);
                }
            }
        }

        $usersTable = tbl('users');
        foreach ($columnsToAdd as $col => $def) {
            if (!in_array(strtolower($col), $existingCols)) {
                try {
                    $db->exec("ALTER TABLE {$usersTable} ADD COLUMN {$col} {$def}");
                } catch (Exception $e) {
                    // Ignore if already added
                }
            }
        }

        // 2. Create password_resets table
        $resetsTable = tbl('password_resets');
        if ($driver === 'sqlite') {
            $db->exec("CREATE TABLE IF NOT EXISTS {$resetsTable} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                code TEXT NOT NULL,
                token TEXT NOT NULL UNIQUE,
                expires_at DATETIME NOT NULL,
                used INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS {$resetsTable} (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `code` VARCHAR(10) NOT NULL,
                `token` VARCHAR(64) NOT NULL UNIQUE,
                `expires_at` DATETIME NOT NULL,
                `used` TINYINT(1) DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (`user_id`),
                INDEX (`token`),
                INDEX (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // 3. Create telegram_logs table for audit & analytics
        $logsTable = tbl('telegram_logs');
        if ($driver === 'sqlite') {
            $db->exec("CREATE TABLE IF NOT EXISTS {$logsTable} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id TEXT NOT NULL,
                user_id INTEGER NULL,
                direction TEXT DEFAULT 'out',
                message_type TEXT DEFAULT 'general',
                message_text TEXT,
                status TEXT DEFAULT 'sent',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS {$logsTable} (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `chat_id` VARCHAR(64) NOT NULL,
                `user_id` INT NULL,
                `direction` ENUM('in', 'out') DEFAULT 'out',
                `message_type` VARCHAR(50) DEFAULT 'general',
                `message_text` TEXT NULL,
                `status` VARCHAR(50) DEFAULT 'sent',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (`chat_id`),
                INDEX (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

    } catch (Exception $e) {
        error_log("ensureTelegramSchema error: " . $e->getMessage());
    }
}

// Ensure schema is prepared
ensureTelegramSchema();

/**
 * Send raw Telegram Message via Bot API
 */
function sendTelegramMessage(string|int $chatId, string $text, ?array $keyboard = null): array {
    $botEnabled = getSetting('telegram_bot_enabled', '0');
    $botToken = getSetting('telegram_bot_token', '');

    if ($botEnabled !== '1' || empty($botToken) || empty($chatId)) {
        return ['ok' => false, 'error' => 'Bot is disabled or token/chat_id missing'];
    }

    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => false
    ];

    if ($keyboard !== null) {
        $payload['reply_markup'] = json_encode($keyboard);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['ok' => false, 'error' => "cURL error: {$curlError}"];
    }

    $data = json_decode($response, true);
    return is_array($data) ? $data : ['ok' => false, 'error' => "HTTP {$httpCode}: {$response}"];
}

/**
 * Send notification to a registered user by User ID
 * Respects user notification settings (notify_streak, notify_security, notify_quests, etc.)
 */
function sendTelegramNotificationToUser(int $userId, string $type, string $text, ?array $keyboard = null): bool {
    ensureTelegramSchema();
    $db = getDb();
    $stmt = $db->prepare("SELECT * FROM " . tbl('users') . " WHERE id = :id");
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if (!$user || empty($user['telegram_chat_id'])) {
        return false;
    }

    // Check specific notification type preferences
    if ($type === 'streak' && isset($user['notify_streak']) && (int)$user['notify_streak'] === 0) {
        return false;
    }
    if ($type === 'quests' && isset($user['notify_quests']) && (int)$user['notify_quests'] === 0) {
        return false;
    }
    if ($type === 'friends' && isset($user['notify_friends']) && (int)$user['notify_friends'] === 0) {
        return false;
    }
    if ($type === 'duels' && isset($user['notify_duels']) && (int)$user['notify_duels'] === 0) {
        return false;
    }
    if ($type === 'security' && isset($user['notify_security']) && (int)$user['notify_security'] === 0) {
        // Security notifications like 2FA and password resets are critical and should bypass disabled flag
    }

    $res = sendTelegramMessage($user['telegram_chat_id'], $text, $keyboard);
    
    // Log outbound message
    try {
        $logStmt = $db->prepare("INSERT INTO " . tbl('telegram_logs') . " (chat_id, user_id, direction, message_type, message_text, status) VALUES (:cid, :uid, 'out', :mtype, :mtext, :st)");
        $logStmt->execute([
            'cid' => $user['telegram_chat_id'],
            'uid' => $userId,
            'mtype' => $type,
            'mtext' => mb_substr($text, 0, 1000),
            'st' => (!empty($res['ok'])) ? 'sent' : 'failed'
        ]);
    } catch (Exception $e) {}

    return !empty($res['ok']);
}

/**
 * Generate a unique Telegram deep-link code for account binding
 */
function generateTelegramLinkCode(int $userId): string {
    ensureTelegramSchema();
    $db = getDb();
    $code = bin2hex(random_bytes(16)); // 32 characters hex token
    $stmt = $db->prepare("UPDATE " . tbl('users') . " SET telegram_link_code = :code WHERE id = :id");
    $stmt->execute(['code' => $code, 'id' => $userId]);
    return $code;
}

/**
 * Get the bot username from Telegram API or settings
 */
function getTelegramBotUsername(): string {
    $cached = getSetting('telegram_bot_username', '');
    if (!empty($cached)) {
        return $cached;
    }

    $token = getSetting('telegram_bot_token', '');
    if (empty($token)) return '';

    $ch = curl_init("https://api.telegram.org/bot{$token}/getMe");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($res, true);
    if (!empty($data['result']['username'])) {
        $username = $data['result']['username'];
        setSetting('telegram_bot_username', $username);
        return $username;
    }
    return '';
}

/**
 * Link Telegram chat to User by link token
 */
function linkTelegramAccountByToken(string $token, string|int $chatId, string $username = ''): ?array {
    ensureTelegramSchema();
    $db = getDb();
    $stmt = $db->prepare("SELECT * FROM " . tbl('users') . " WHERE telegram_link_code = :t LIMIT 1");
    $stmt->execute(['t' => $token]);
    $user = $stmt->fetch();

    if (!$user) {
        return null;
    }

    // Bind chat ID and clear one-time link token
    $up = $db->prepare("UPDATE " . tbl('users') . " SET telegram_chat_id = :cid, telegram_username = :tguser, telegram_link_code = NULL WHERE id = :id");
    $up->execute([
        'cid' => (string)$chatId,
        'tguser' => $username,
        'id' => $user['id']
    ]);

    // Send security confirmation
    sendTelegramNotificationToUser((int)$user['id'], 'security', 
        "✅ <b>Telegram успешно привязан к аккаунту!</b>\n\n" .
        "👤 Аккаунт: <b>" . e($user['username']) . "</b>\n" .
        "🐕 Теперь вам доступны:\n" .
        "• 🔐 Двухфакторная защита (2FA)\n" .
        "• 🔑 Быстрый сброс пароля\n" .
        "• 🔥 Уведомления о стриках, дуэлях и квестах"
    );

    return $user;
}

/**
 * Generate and send 2FA Code via Telegram
 */
function generateAndSend2FACode(int $userId): array {
    ensureTelegramSchema();
    $db = getDb();
    $stmt = $db->prepare("SELECT * FROM " . tbl('users') . " WHERE id = :id");
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if (!$user || empty($user['telegram_chat_id'])) {
        return ['success' => false, 'error' => 'Telegram не привязан к этому аккаунту'];
    }

    // 6-digit random verification PIN
    $code = (string)random_int(100000, 999999);
    $expires = date('Y-m-d H:i:s', time() + 300); // 5 minutes expiration

    $up = $db->prepare("UPDATE " . tbl('users') . " SET two_factor_code = :code, two_factor_expires = :exp WHERE id = :id");
    $up->execute(['code' => $code, 'exp' => $expires, 'id' => $userId]);

    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'Неизвестно';
    $clientAgent = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'Браузер', 0, 80);

    $msg = "🔐 <b>Код двухфакторной аутентификации (2FA):</b>\n\n"
         . "<code>{$code}</code>\n\n"
         . "⏱ Код действителен <b>5 минут</b>.\n"
         . "📍 Запрос входа с IP: <code>{$clientIp}</code>\n"
         . "💻 Устройство: <i>" . e($clientAgent) . "</i>\n\n"
         . "⚠️ <i>Если это были не вы, не передавайте код никому и смените пароль!</i>";

    $sent = sendTelegramMessage($user['telegram_chat_id'], $msg);

    if (!empty($sent['ok'])) {
        return ['success' => true, 'code' => $code, 'expires' => $expires];
    } else {
        return ['success' => false, 'error' => 'Не удалось отправить сообщение в Telegram: ' . ($sent['description'] ?? 'Ошибка сети')];
    }
}

/**
 * Verify 2FA code for user
 */
function verify2FACode(int $userId, string $code): bool {
    ensureTelegramSchema();
    $db = getDb();
    $stmt = $db->prepare("SELECT two_factor_code, two_factor_expires FROM " . tbl('users') . " WHERE id = :id");
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();

    if (!$row || empty($row['two_factor_code'])) {
        return false;
    }

    if (trim((string)$row['two_factor_code']) !== trim($code)) {
        return false;
    }

    // Check expiration
    if (strtotime($row['two_factor_expires']) < time()) {
        return false;
    }

    // Clear code on successful verification
    $clear = $db->prepare("UPDATE " . tbl('users') . " SET two_factor_code = NULL, two_factor_expires = NULL WHERE id = :id");
    $clear->execute(['id' => $userId]);

    return true;
}

/**
 * Request Password Reset via Telegram
 */
function createPasswordResetRequest(int $userId, ?string $domainUrl = null): array {
    ensureTelegramSchema();
    $db = getDb();
    $stmt = $db->prepare("SELECT * FROM " . tbl('users') . " WHERE id = :id");
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if (!$user || empty($user['telegram_chat_id'])) {
        return ['success' => false, 'error' => 'К данному аккаунту не привязан Telegram'];
    }

    $code = (string)random_int(100000, 999999);
    $token = bin2hex(random_bytes(24));
    $expires = date('Y-m-d H:i:s', time() + 900); // 15 minutes expiration

    // Invalidate previous active reset requests for this user
    $inv = $db->prepare("UPDATE " . tbl('password_resets') . " SET used = 1 WHERE user_id = :uid AND used = 0");
    $inv->execute(['uid' => $userId]);

    // Insert new request
    $ins = $db->prepare("INSERT INTO " . tbl('password_resets') . " (user_id, code, token, expires_at, used) VALUES (:uid, :code, :token, :exp, 0)");
    $ins->execute([
        'uid' => $userId,
        'code' => $code,
        'token' => $token,
        'exp' => $expires
    ]);

    if (!$domainUrl) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $domainUrl = $protocol . $host;
    }

    $resetUrl = rtrim($domainUrl, '/') . '/forgot_password.php?token=' . $token;

    $msg = "🔑 <b>Запрос на сброс пароля в ShibaLingo</b>\n\n"
         . "👤 Для аккаунта: <b>" . e($user['username']) . "</b>\n\n"
         . "Ваш 6-значный код сброса:\n"
         . "<code>{$code}</code>\n\n"
         . "Или перейдите по прямой ссылке для смены пароля:\n"
         . "<a href=\"{$resetUrl}\">🔗 Нажмите сюда, чтобы сбросить пароль</a>\n\n"
         . "⏱ Код и ссылка действуют <b>15 минут</b>.\n"
         . "⚠️ <i>Если вы не запрашивали сброс пароля, просто проигнорируйте это сообщение.</i>";

    $kb = [
        'inline_keyboard' => [
            [
                ['text' => '🔑 Сбросить пароль на сайте', 'url' => $resetUrl]
            ]
        ]
    ];

    $sent = sendTelegramMessage($user['telegram_chat_id'], $msg, $kb);

    return [
        'success' => !empty($sent['ok']),
        'code' => $code,
        'token' => $token,
        'user' => $user
    ];
}

/**
 * Verify and complete password reset
 */
function completePasswordReset(string $codeOrToken, string $newPassword): array {
    ensureTelegramSchema();
    $db = getDb();
    
    // Find valid request
    $stmt = $db->prepare("SELECT * FROM " . tbl('password_resets') . " WHERE (code = :val OR token = :val) AND used = 0 AND expires_at > " . (Database::getDriver() === 'sqlite' ? "datetime('now')" : "NOW()") . " ORDER BY id DESC LIMIT 1");
    $stmt->execute(['val' => trim($codeOrToken)]);
    $req = $stmt->fetch();

    if (!$req) {
        return ['success' => false, 'error' => 'Неверный или устаревший код / ссылка сброса'];
    }

    if (mb_strlen($newPassword) < 4) {
        return ['success' => false, 'error' => 'Пароль должен содержать минимум 4 символа'];
    }

    $userId = (int)$req['user_id'];
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);

    // Update user password
    $upUser = $db->prepare("UPDATE " . tbl('users') . " SET password_hash = :hash WHERE id = :id");
    $upUser->execute(['hash' => $hash, 'id' => $userId]);

    // Mark reset request as used
    $markUsed = $db->prepare("UPDATE " . tbl('password_resets') . " SET used = 1 WHERE id = :id");
    $markUsed->execute(['id' => $req['id']]);

    // Send security notification
    sendTelegramNotificationToUser($userId, 'security',
        "🔒 <b>Пароль успешно изменен!</b>\n\n" .
        "Пароль от вашего аккаунта ShibaLingo был только что обновлен.\n" .
        "Если вы не совершали это действие, немедленно свяжитесь с поддержкой!"
    );

    return ['success' => true];
}
