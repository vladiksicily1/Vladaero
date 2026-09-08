<?php
/**
 * Admin Telegram Bot Manager (Enable/Disable, Token, Webhook, Broadcast & 2FA Management)
 */

$adminTitle = 'Telegram Бот, 2FA и Уведомления';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/telegram.php';

$message = '';
$msgType = 'success';
$db = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['form_action'] ?? 'save_settings';

    if ($action === 'save_settings') {
        $token = trim($_POST['telegram_bot_token'] ?? '');
        $enabled = isset($_POST['telegram_bot_enabled']) ? '1' : '0';
        $welcomeMsg = trim($_POST['telegram_welcome_msg'] ?? '');

        setSetting('telegram_bot_token', $token);
        setSetting('telegram_bot_enabled', $enabled);
        setSetting('telegram_welcome_msg', $welcomeMsg);

        // Fetch bot username
        if (!empty($token)) {
            $botUsername = getTelegramBotUsername();
            if (!empty($botUsername)) {
                setSetting('telegram_bot_username', $botUsername);
            }
        }

        // Auto set webhook if requested
        if (isset($_POST['set_webhook']) && !empty($token)) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            $currentDomain = $protocol . $_SERVER['HTTP_HOST'];
            $webhookUrl = $currentDomain . dirname(dirname($_SERVER['PHP_SELF'])) . '/telegram_bot.php';
            
            $ch = curl_init("https://api.telegram.org/bot{$token}/setWebhook?url=" . urlencode($webhookUrl));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $res = curl_exec($ch);
            curl_close($ch);
            $resData = json_decode($res, true);
            
            if (!empty($resData['ok'])) {
                $message = "Настройки сохранены! Webhook успешно установлен на: {$webhookUrl}";
            } else {
                $message = "Настройки сохранены, но ошибка Webhook: " . ($resData['description'] ?? 'неизвестная ошибка');
                $msgType = 'warning';
            }
        } else {
            $message = 'Настройки Telegram бота успешно обновлены!';
        }
    } elseif ($action === 'send_broadcast') {
        $broadcastText = trim($_POST['broadcast_text'] ?? '');
        if (empty($broadcastText)) {
            $message = 'Текст рассылки не может быть пустым!';
            $msgType = 'danger';
        } else {
            $users = $db->query("SELECT id, username, telegram_chat_id FROM " . tbl('users') . " WHERE telegram_chat_id IS NOT NULL AND telegram_chat_id != ''")->fetchAll();
            $sentCount = 0;
            $failCount = 0;

            foreach ($users as $u) {
                $formatted = "📢 <b>Оповещение от администрации ShibaLingo</b>\n\n" . $broadcastText;
                $res = sendTelegramMessage($u['telegram_chat_id'], $formatted);
                if (!empty($res['ok'])) {
                    $sentCount++;
                } else {
                    $failCount++;
                }
            }

            $message = "Рассылка завершена! Отправлено: {$sentCount}, Ошибок: {$failCount}";
        }
    } elseif ($action === 'send_test') {
        $testChatId = trim($_POST['test_chat_id'] ?? '');
        $testMsg = trim($_POST['test_message'] ?? 'Тестовое сообщение от ShibaLingo Bot 🐕🐾');

        if (empty($testChatId)) {
            $message = 'Укажите Chat ID для отправки!';
            $msgType = 'danger';
        } else {
            $res = sendTelegramMessage($testChatId, "🧪 <b>Тестовое сообщение:</b>\n\n" . $testMsg);
            if (!empty($res['ok'])) {
                $message = "Сообщение успешно доставлено в чат {$testChatId}!";
            } else {
                $message = "Ошибка отправки: " . ($res['description'] ?? $res['error'] ?? 'Неизвестная ошибка');
                $msgType = 'danger';
            }
        }
    }
}

$botToken = getSetting('telegram_bot_token', '');
$botEnabled = getSetting('telegram_bot_enabled', '0');
$botUsername = getTelegramBotUsername();
$welcomeMsg = getSetting('telegram_welcome_msg', 'Привет! Я бот Сиба-сэнсэй 🐕. Готов учить слова Vladikish и напоминать о стрике!');

// Statistics
$totalLinkedUsers = $db->query("SELECT COUNT(*) FROM " . tbl('users') . " WHERE telegram_chat_id IS NOT NULL AND telegram_chat_id != ''")->fetchColumn();
$total2faUsers = $db->query("SELECT COUNT(*) FROM " . tbl('users') . " WHERE two_factor_enabled = 1 AND telegram_chat_id IS NOT NULL")->fetchColumn();
$linkedUsersList = $db->query("SELECT id, username, email, telegram_username, telegram_chat_id, two_factor_enabled, streak, created_at FROM " . tbl('users') . " WHERE telegram_chat_id IS NOT NULL AND telegram_chat_id != '' ORDER BY id DESC LIMIT 50")->fetchAll();
?>

<div style="max-width: 960px;">
    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 1.8rem; font-weight: 900;">✈️ Управление Telegram-ботом, 2FA и рассылками</h1>
        <p style="color: var(--text-muted);">Настройка интеграции: 2FA авторизация, мгновенный сброс пароля, квизы и массовые push-уведомления</p>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?= $msgType ?>" style="background: <?= ($msgType === 'success') ? 'var(--primary-light)' : (($msgType === 'warning') ? '#fef08a' : 'var(--danger-light)') ?>; color: <?= ($msgType === 'success') ? 'var(--primary-shadow)' : (($msgType === 'warning') ? '#854d0e' : 'var(--danger-shadow)') ?>; padding: 14px; border-radius: 12px; font-weight: 700; margin-bottom: 20px;">
            <?= ($msgType === 'success' ? '✓ ' : '✕ ') . e($message) ?>
        </div>
    <?php endif; ?>

    <!-- Stats row -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div class="card-duo" style="padding: 16px; margin-bottom: 0;">
            <div style="font-size: 0.8rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase;">Статус бота</div>
            <div style="font-size: 1.3rem; font-weight: 900; margin-top: 4px; color: <?= ($botEnabled === '1') ? 'var(--primary)' : 'var(--danger)' ?>;">
                <?= ($botEnabled === '1') ? '🟢 Активен' : '🔴 Отключен' ?>
            </div>
            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">
                @<?= e($botUsername ?: 'Не определен') ?>
            </div>
        </div>

        <div class="card-duo" style="padding: 16px; margin-bottom: 0;">
            <div style="font-size: 0.8rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase;">Привязано Telegram</div>
            <div style="font-size: 1.5rem; font-weight: 900; margin-top: 4px; color: var(--secondary);">
                ✈️ <?= (int)$totalLinkedUsers ?>
            </div>
            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">
                активных пользователей
            </div>
        </div>

        <div class="card-duo" style="padding: 16px; margin-bottom: 0;">
            <div style="font-size: 0.8rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase;">Защита 2FA включена</div>
            <div style="font-size: 1.5rem; font-weight: 900; margin-top: 4px; color: #10b981;">
                🛡️ <?= (int)$total2faUsers ?>
            </div>
            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">
                пользователей под защитой
            </div>
        </div>
    </div>

    <!-- Bot Configuration Form -->
    <form method="POST">
        <input type="hidden" name="form_action" value="save_settings">
        <div class="card-duo" style="margin-bottom: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="font-size: 1.2rem; font-weight: 800;">⚙️ Токен и основные настройки бота</h3>
                <label style="font-weight: 800; font-size: 1rem; display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" name="telegram_bot_enabled" value="1" <?= ($botEnabled === '1') ? 'checked' : '' ?>>
                    <span>Включить бота</span>
                </label>
            </div>

            <div style="margin-bottom: 16px;">
                <label style="font-weight: 700; font-size: 0.9rem; margin-bottom: 6px; display: block;">
                    Telegram Bot Token (от @BotFather):
                </label>
                <input type="password" name="telegram_bot_token" value="<?= e($botToken) ?>" class="chat-input" placeholder="123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ">
                <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 4px;">
                    Токен необходим для отправки кодов 2FA, сброса пароля и push-оповещений.
                </div>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="font-weight: 700; font-size: 0.9rem; margin-bottom: 6px; display: block;">
                    Приветственное сообщение бота (/start):
                </label>
                <textarea name="telegram_welcome_msg" rows="3" class="chat-input"><?= e($welcomeMsg) ?></textarea>
            </div>

            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                <button type="submit" class="btn-duo btn-primary" style="flex: 1;">
                    Сохранить настройки 💾
                </button>
                <button type="submit" name="set_webhook" value="1" class="btn-duo btn-secondary" style="flex: 1;">
                    🔗 Установить Webhook
                </button>
            </div>
        </div>
    </form>

    <!-- Broadcast and Test Notification Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 20px; margin-bottom: 24px;">
        <!-- Mass Broadcast -->
        <div class="card-duo" style="margin-bottom: 0;">
            <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 10px;">📢 Массовая рассылка</h3>
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 12px;">
                Отправить сообщение всем пользователям, привязавшим Telegram (<?= (int)$totalLinkedUsers ?> получателей).
            </p>
            <form method="POST">
                <input type="hidden" name="form_action" value="send_broadcast">
                <div style="margin-bottom: 12px;">
                    <textarea name="broadcast_text" rows="3" class="chat-input" placeholder="Введите текст рассылки (HTML разметка поддерживается)..." required></textarea>
                </div>
                <button type="submit" class="btn-duo btn-secondary" onclick="return confirm('Отправить рассылку всем <?= (int)$totalLinkedUsers ?> пользователям?')" style="width: 100%; padding: 10px;">
                    🚀 Отправить рассылку
                </button>
            </form>
        </div>

        <!-- Direct Test Sender -->
        <div class="card-duo" style="margin-bottom: 0;">
            <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 10px;">🧪 Прямой тест отправки</h3>
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 12px;">
                Отправить тестовое сообщение в конкретный Chat ID для проверки токена.
            </p>
            <form method="POST">
                <input type="hidden" name="form_action" value="send_test">
                <div style="margin-bottom: 8px;">
                    <input type="text" name="test_chat_id" class="chat-input" placeholder="Chat ID (например, 12345678)" required>
                </div>
                <div style="margin-bottom: 12px;">
                    <input type="text" name="test_message" class="chat-input" value="Тестовое сообщение от ShibaLingo Bot 🐕" required>
                </div>
                <button type="submit" class="btn-duo btn-outline" style="width: 100%; padding: 10px;">
                    📨 Отправить тест
                </button>
            </form>
        </div>
    </div>

    <!-- Table of Linked Users -->
    <div class="card-duo">
        <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 14px;">👥 Привязанные пользователи Telegram</h3>
        <?php if (empty($linkedUsersList)): ?>
            <p style="color: var(--text-muted); font-size: 0.9rem;">Пока ни один пользователь не привязал свой Telegram.</p>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="table-duo" style="width: 100%; font-size: 0.9rem;">
                    <thead>
                        <tr style="text-align: left; border-bottom: 2px solid var(--border-color);">
                            <th style="padding: 8px;">Пользователь</th>
                            <th style="padding: 8px;">Telegram Chat ID</th>
                            <th style="padding: 8px;">Telegram User</th>
                            <th style="padding: 8px;">2FA</th>
                            <th style="padding: 8px;">Стрик</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($linkedUsersList as $lu): ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 10px 8px; font-weight: 800;">
                                    <?= e($lu['username']) ?>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($lu['email']) ?></div>
                                </td>
                                <td style="padding: 10px 8px; font-family: monospace;">
                                    <code><?= e($lu['telegram_chat_id']) ?></code>
                                </td>
                                <td style="padding: 10px 8px;">
                                    <?= !empty($lu['telegram_username']) ? '@' . e($lu['telegram_username']) : '—' ?>
                                </td>
                                <td style="padding: 10px 8px;">
                                    <?php if (!empty($lu['two_factor_enabled'])): ?>
                                        <span class="badge-tag" style="background: #dcfce7; color: #166534; font-weight: 800;">ВКЛ 🟢</span>
                                    <?php else: ?>
                                        <span class="badge-tag" style="background: #f1f5f9; color: #64748b;">ВЫКЛ</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px 8px; font-weight: 800; color: var(--streak-color);">
                                    🔥 <?= (int)$lu['streak'] ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
