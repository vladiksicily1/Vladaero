<?php
/**
 * ShibaLingo - Telegram User API
 * Endpoints for generating link codes, unlinking, toggling 2FA, and notification settings
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/telegram.php';

header('Content-Type: application/json; charset=utf-8');

$user = getCurrentUser();
if (!$user) {
    echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
    exit;
}

$db = getDb();
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

if ($action === 'get_link_info') {
    $botUsername = getTelegramBotUsername();
    $linkCode = generateTelegramLinkCode((int)$user['id']);
    $botUrl = !empty($botUsername) ? "https://t.me/{$botUsername}?start=link_{$linkCode}" : "#";

    echo json_encode([
        'success' => true,
        'bot_username' => $botUsername,
        'link_code' => $linkCode,
        'bot_url' => $botUrl,
        'is_linked' => !empty($user['telegram_chat_id']),
        'telegram_username' => $user['telegram_username'] ?? '',
        'two_factor_enabled' => !empty($user['two_factor_enabled']),
        'notifications' => [
            'streak' => (int)($user['notify_streak'] ?? 1),
            'quests' => (int)($user['notify_quests'] ?? 1),
            'duels' => (int)($user['notify_duels'] ?? 1),
            'friends' => (int)($user['notify_friends'] ?? 1),
            'security' => (int)($user['notify_security'] ?? 1)
        ]
    ]);
    exit;
}

elseif ($action === 'toggle_2fa') {
    if (empty($user['telegram_chat_id'])) {
        echo json_encode(['success' => false, 'error' => 'Сначала привяжите Telegram бота!']);
        exit;
    }

    $enable = isset($_POST['enabled']) ? (int)$_POST['enabled'] : (empty($user['two_factor_enabled']) ? 1 : 0);
    $up = $db->prepare("UPDATE " . tbl('users') . " SET two_factor_enabled = :val WHERE id = :id");
    $up->execute(['val' => $enable, 'id' => $user['id']]);

    // Send confirmation in telegram
    $msg = $enable 
        ? "🛡️ <b>Двухфакторная аутентификация (2FA) включена!</b>\n\nПри следующем входе на сайт вам потребуется ввести одноразовый код из этого бота."
        : "⚠️ <b>Двухфакторная аутентификация (2FA) отключена.</b>";
    sendTelegramNotificationToUser((int)$user['id'], 'security', $msg);

    echo json_encode([
        'success' => true,
        'two_factor_enabled' => (bool)$enable,
        'message' => $enable ? '2FA успешно включена!' : '2FA отключена.'
    ]);
    exit;
}

elseif ($action === 'unlink_telegram') {
    $up = $db->prepare("UPDATE " . tbl('users') . " SET telegram_chat_id = NULL, telegram_username = NULL, two_factor_enabled = 0 WHERE id = :id");
    $up->execute(['id' => $user['id']]);

    echo json_encode([
        'success' => true,
        'message' => 'Telegram успешно отвязан от вашего профиля.'
    ]);
    exit;
}

elseif ($action === 'save_notifications') {
    $streak = isset($_POST['notify_streak']) ? 1 : 0;
    $quests = isset($_POST['notify_quests']) ? 1 : 0;
    $duels  = isset($_POST['notify_duels']) ? 1 : 0;
    $friends = isset($_POST['notify_friends']) ? 1 : 0;
    $security = isset($_POST['notify_security']) ? 1 : 0;

    $up = $db->prepare("UPDATE " . tbl('users') . " SET notify_streak = :s, notify_quests = :q, notify_duels = :d, notify_friends = :f, notify_security = :sec WHERE id = :id");
    $up->execute([
        's' => $streak,
        'q' => $quests,
        'd' => $duels,
        'f' => $friends,
        'sec' => $security,
        'id' => $user['id']
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Настройки уведомлений сохранены!'
    ]);
    exit;
}

elseif ($action === 'send_test_notification') {
    if (empty($user['telegram_chat_id'])) {
        echo json_encode(['success' => false, 'error' => 'Telegram не привязан']);
        exit;
    }

    $sent = sendTelegramNotificationToUser((int)$user['id'], 'security',
        "🐕 <b>Тестовое уведомление от ShibaLingo!</b>\n\n" .
        "Поздравляем! Ваш Telegram успешно подключен и готов получать оповещения о стриках, дуэлях и защите аккаунта! 🎉🐾"
    );

    echo json_encode([
        'success' => $sent,
        'message' => $sent ? 'Тестовое сообщение отправлено в Telegram!' : 'Не удалось отправить сообщение.'
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
