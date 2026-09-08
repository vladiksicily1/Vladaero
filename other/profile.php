<?php
/**
 * ShibaLingo - Public Student Profile Showcase (Feature #25)
 */

$pageTitle = 'Профиль ученика';
require_once __DIR__ . '/includes/header.php';

$db = getDb();
$targetId = (int)($_GET['id'] ?? 0);
$targetUsername = trim($_GET['u'] ?? '');

$isOwnProfile = true;
$profileUser = $user;

if ($targetId > 0 && $targetId !== (int)$user['id']) {
    $pStmt = $db->prepare("SELECT * FROM " . tbl('users') . " WHERE id = :id LIMIT 1");
    $pStmt->execute(['id' => $targetId]);
    $found = $pStmt->fetch(PDO::FETCH_ASSOC);
    if ($found) {
        $profileUser = $found;
        $isOwnProfile = false;
    }
} elseif (!empty($targetUsername) && $targetUsername !== $user['username']) {
    $pStmt = $db->prepare("SELECT * FROM " . tbl('users') . " WHERE username = :u LIMIT 1");
    $pStmt->execute(['u' => $targetUsername]);
    $found = $pStmt->fetch(PDO::FETCH_ASSOC);
    if ($found) {
        $profileUser = $found;
        $isOwnProfile = false;
    }
}

// Check friendship status
$isFriend = false;
if (!$isOwnProfile) {
    try {
        $fCheck = $db->prepare("SELECT id FROM friendships WHERE user_id = :uid AND friend_id = :fid");
        $fCheck->execute(['uid' => $user['id'], 'fid' => $profileUser['id']]);
        $isFriend = (bool)$fCheck->fetch();
    } catch (Exception $e) {}
}

// User stats
$completedLessonsCount = $db->query("SELECT COUNT(*) FROM user_progress WHERE user_id = {$profileUser['id']}")->fetchColumn();
?>

<div style="max-width: 750px; margin: 0 auto;">
    <!-- Profile Card -->
    <div class="card-duo anim-bounce" style="display: flex; align-items: center; gap: 24px; flex-wrap: wrap;">
        <div id="profile-mascot-avatar"></div>

        <div style="flex-grow: 1;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px; flex-wrap: wrap; gap: 8px;">
                <h1 style="font-size: 1.8rem; font-weight: 900;"><?= e($profileUser['username']) ?></h1>
                <div style="display: flex; gap: 8px;">
                    <?php if ($isOwnProfile): ?>
                        <span class="badge-tag" style="background: #fef08a; color: #854d0e; font-weight: 900;">
                            🥇 Золотая Лига
                        </span>
                        <button class="btn-duo btn-outline" style="padding: 4px 10px; font-size: 0.8rem;" onclick="document.getElementById('user-avatar-picker').click()">
                            📷 Сменить фото
                        </button>
                        <input type="file" id="user-avatar-picker" accept="image/*" style="display: none;" onchange="uploadUserAvatar(this.files)">
                    <?php else: ?>
                        <button class="btn-duo <?= $isFriend ? 'btn-outline' : 'btn-primary' ?>" id="btn-toggle-friend" onclick="toggleFriend(<?= (int)$profileUser['id'] ?>)" style="padding: 6px 14px; font-size: 0.85rem;">
                            <?= $isFriend ? '✓ В друзьях' : '+ Добавить в друзья' ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 16px;">
                Изучает: <strong><?= e($currentLangObj['name']) ?></strong> | На платформе с <?= date('d.m.Y', strtotime($profileUser['created_at'] ?? 'now')) ?>
            </p>

            <!-- Stats Row -->
            <div style="display: flex; gap: 24px; font-weight: 800; font-size: 1.05rem; flex-wrap: wrap;">
                <div>
                    <div style="color: var(--streak-color);">🔥 <?= (int)$profileUser['streak'] ?></div>
                    <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Стрик дней</div>
                </div>
                <div>
                    <div style="color: #eab308;">⚡ <?= (int)$profileUser['xp'] ?></div>
                    <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Всего XP</div>
                </div>
                <div>
                    <div style="color: var(--secondary);">💎 <?= (int)($profileUser['gems'] ?? 50) ?></div>
                    <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Кристаллов</div>
                </div>
                <div>
                    <div style="color: var(--primary);"><?= $completedLessonsCount ?></div>
                    <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Уроков пройдено</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Navigation Links -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-top: 24px;">
        <a href="shop.php" class="card-duo" style="text-decoration: none; color: inherit; text-align: center; margin-bottom: 0; padding: 16px;">
            <div style="font-size: 2rem; margin-bottom: 4px;">🛍️</div>
            <div style="font-weight: 800; font-size: 0.95rem;">Магазин скинов</div>
        </a>
        <a href="achievements.php" class="card-duo" style="text-decoration: none; color: inherit; text-align: center; margin-bottom: 0; padding: 16px;">
            <div style="font-size: 2rem; margin-bottom: 4px;">🏅</div>
            <div style="font-weight: 800; font-size: 0.95rem;">Достижения</div>
        </a>
        <a href="leaderboard.php" class="card-duo" style="text-decoration: none; color: inherit; text-align: center; margin-bottom: 0; padding: 16px;">
            <div style="font-size: 2rem; margin-bottom: 4px;">🏆</div>
            <div style="font-weight: 800; font-size: 0.95rem;">Таблица лидеров</div>
        </a>
        <a href="friends.php" class="card-duo" style="text-decoration: none; color: inherit; text-align: center; margin-bottom: 0; padding: 16px;">
            <div style="font-size: 2rem; margin-bottom: 4px;">👥</div>
            <div style="font-weight: 800; font-size: 0.95rem;">Мои друзья</div>
        </a>
        <a href="tamagotchi.php" class="card-duo" style="text-decoration: none; color: inherit; text-align: center; margin-bottom: 0; padding: 16px;">
            <div style="font-size: 2rem; margin-bottom: 4px;">🐕</div>
            <div style="font-weight: 800; font-size: 0.95rem;">Тамагочи</div>
        </a>
        <a href="referral.php" class="card-duo" style="text-decoration: none; color: inherit; text-align: center; margin-bottom: 0; padding: 16px;">
            <div style="font-size: 2rem; margin-bottom: 4px;">🎁</div>
            <div style="font-weight: 800; font-size: 0.95rem;">Пригласи друга</div>
        </a>
        <a href="certificate.php" class="card-duo" style="text-decoration: none; color: inherit; text-align: center; margin-bottom: 0; padding: 16px;">
            <div style="font-size: 2rem; margin-bottom: 4px;">🎓</div>
            <div style="font-weight: 800; font-size: 0.95rem;">Диплом Vladikish</div>
        </a>
    </div>

    <?php if ($isOwnProfile): 
        require_once __DIR__ . '/includes/telegram.php';
        $isTgLinked = !empty($profileUser['telegram_chat_id']);
        $botUsername = getTelegramBotUsername();
        $linkCode = $isTgLinked ? '' : generateTelegramLinkCode((int)$profileUser['id']);
        $tgBotUrl = !empty($botUsername) ? "https://t.me/{$botUsername}?start=link_{$linkCode}" : "telegram_bot.php";
    ?>
    <!-- Telegram Bot, 2FA & Notifications Card -->
    <div class="card-duo anim-bounce" style="margin-top: 24px; padding: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="font-size: 2.2rem; background: #e0f2fe; padding: 10px; border-radius: 16px; display: flex; align-items: center; justify-content: center;">
                    ✈️
                </div>
                <div>
                    <h2 style="font-size: 1.25rem; font-weight: 900; margin: 0;">Telegram Бот & Защита</h2>
                    <p style="color: var(--text-muted); font-size: 0.85rem; margin: 2px 0 0 0;">
                        Сброс пароля, двухфакторная аутентификация (2FA) и push-уведомления
                    </p>
                </div>
            </div>
            <span class="badge-tag" style="background: <?= $isTgLinked ? '#dcfce7; color: #166534;' : '#fee2e2; color: #991b1b;' ?> font-weight: 800; font-size: 0.85rem;">
                <?= $isTgLinked ? '✓ Telegram подключен' : '✕ Не привязан' ?>
            </span>
        </div>

        <?php if (!$isTgLinked): ?>
            <div style="background: var(--bg-main); padding: 18px; border-radius: 16px; border: 2px dashed var(--border-color); margin-bottom: 16px;">
                <h4 style="font-size: 1rem; font-weight: 800; margin-bottom: 6px;">Как подключить Telegram:</h4>
                <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 14px;">
                    1. Нажмите кнопку ниже для перехода в Telegram-бота <strong>@<?= e($botUsername ?: 'ShibaLingoBot') ?></strong><br>
                    2. Нажмите <strong>/start</strong>, и ваш профиль привяжется мгновенно!<br>
                    <i>Или отправьте боту команду вручную:</i> <code>/link <?= e($linkCode) ?></code>
                </p>
                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                    <a href="<?= e($tgBotUrl) ?>" target="_blank" class="btn-duo btn-primary" style="display: inline-flex; align-items: center; gap: 8px; text-decoration: none; padding: 10px 20px;">
                        <span>✈️ Открыть Telegram-бота</span> ↗
                    </a>
                    <button type="button" class="btn-duo btn-outline" onclick="location.reload()" style="padding: 10px 16px;">
                        🔄 Проверить привязку
                    </button>
                </div>
            </div>
        <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 20px;">
                <!-- 2FA Box -->
                <div style="background: var(--bg-main); padding: 18px; border-radius: 16px; border: 2px solid var(--border-color);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-weight: 800; font-size: 0.95rem;">🛡️ Двухфакторная защита (2FA)</span>
                        <label class="switch-duo" style="position: relative; display: inline-block; width: 44px; height: 24px; margin: 0;">
                            <input type="checkbox" id="profile-2fa-toggle" <?= !empty($profileUser['two_factor_enabled']) ? 'checked' : '' ?> onchange="toggle2fa(this.checked)" style="opacity: 0; width: 0; height: 0;">
                            <span class="slider round" style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .3s; border-radius: 24px;"></span>
                        </label>
                    </div>
                    <p style="font-size: 0.82rem; color: var(--text-muted); margin: 0;">
                        При входе в аккаунт бот будет отправлять 6-значный код безопасности.
                    </p>
                </div>

                <!-- Password Reset Box -->
                <div style="background: var(--bg-main); padding: 18px; border-radius: 16px; border: 2px solid var(--border-color);">
                    <div style="font-weight: 800; font-size: 0.95rem; margin-bottom: 4px;">🔑 Быстрый сброс пароля</div>
                    <p style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 10px;">
                        Восстанавливайте доступ к аккаунту в один клик через команду <code>/reset</code> в боте.
                    </p>
                    <a href="forgot_password.php" class="btn-duo btn-outline" style="padding: 6px 12px; font-size: 0.8rem; text-decoration: none; display: inline-block;">
                        Запросить сброс пароля ↗
                    </a>
                </div>
            </div>

            <!-- Notifications Settings -->
            <div style="background: var(--bg-main); padding: 18px; border-radius: 16px; border: 2px solid var(--border-color); margin-bottom: 16px;">
                <h4 style="font-size: 0.95rem; font-weight: 800; margin-bottom: 12px;">🔔 Настройка уведомлений Telegram:</h4>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; font-size: 0.88rem;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 700;">
                        <input type="checkbox" id="notify-streak" <?= (!isset($profileUser['notify_streak']) || $profileUser['notify_streak'] == 1) ? 'checked' : '' ?> onchange="saveNotificationSettings()">
                        <span>🔥 Напоминания о стрике</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 700;">
                        <input type="checkbox" id="notify-quests" <?= (!isset($profileUser['notify_quests']) || $profileUser['notify_quests'] == 1) ? 'checked' : '' ?> onchange="saveNotificationSettings()">
                        <span>🎯 Новые квесты и задания</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 700;">
                        <input type="checkbox" id="notify-duels" <?= (!isset($profileUser['notify_duels']) || $profileUser['notify_duels'] == 1) ? 'checked' : '' ?> onchange="saveNotificationSettings()">
                        <span>⚔️ Вызовы на PvP Дуэли</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 700;">
                        <input type="checkbox" id="notify-security" <?= (!isset($profileUser['notify_security']) || $profileUser['notify_security'] == 1) ? 'checked' : '' ?> onchange="saveNotificationSettings()">
                        <span>🔒 Входы и безопасность</span>
                    </label>
                </div>
            </div>

            <!-- Actions Row -->
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button type="button" class="btn-duo btn-outline" onclick="sendTestTelegramNotification()" style="padding: 8px 14px; font-size: 0.85rem;">
                        📩 Тестовое уведомление
                    </button>
                    <a href="https://t.me/<?= e($botUsername ?: '') ?>" target="_blank" class="btn-duo btn-secondary" style="padding: 8px 14px; font-size: 0.85rem; text-decoration: none;">
                        💬 Открыть диалог с ботом
                    </a>
                </div>
                <button type="button" class="btn-duo" onclick="unlinkTelegram()" style="padding: 8px 14px; font-size: 0.85rem; background: var(--danger-light); color: var(--danger-shadow); border-color: var(--danger);">
                    Отвязать Telegram 🔓
                </button>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Account Actions & Danger Zone -->
    <div class="card-duo" style="margin-top: 24px; padding: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div>
            <h3 style="font-size: 1.1rem; font-weight: 800; margin-bottom: 4px;">Управление аккаунтом</h3>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0;">Сессия и безопасность профиля</p>
        </div>

        <div style="display: flex; gap: 12px; align-items: center;">
            <a href="logout.php" class="btn-duo btn-outline" style="padding: 8px 18px; font-size: 0.9rem;">
                Выйти 🚪
            </a>
            <a href="delete_account.php" class="btn-duo" style="padding: 8px 18px; font-size: 0.9rem; background: var(--danger-light); color: var(--danger-shadow); border-color: var(--danger);">
                Удалить аккаунт 🗑️
            </a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    ShibaMascot.setSkin('<?= $profileUser['selected_skin'] ?? 'classic' ?>');
    ShibaMascot.update('profile-mascot-avatar', 'happy');
});

async function uploadUserAvatar(files) {
    if (!files || !files.length) return;
    const file = files[0];
    const formData = new FormData();
    formData.append('file', file);
    formData.append('type', 'avatar');

    try {
        const res = await fetch('api/upload_api.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            SoundEngine.play('correct');
            alert('✓ Аватар успешно обновлен!');
            location.reload();
        } else {
            alert('Ошибка: ' + (data.error || 'Не удалось загрузить'));
        }
    } catch(err) {
        alert('Ошибка при загрузке аватара.');
    }
}

async function toggleFriend(friendId) {
    try {
        const formData = new FormData();
        formData.append('action', 'toggle_friend');
        formData.append('friend_id', friendId);
        const res = await fetch('api/social_api.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            SoundEngine.play('correct');
            const btn = document.getElementById('btn-toggle-friend');
            if (btn) {
                btn.textContent = data.is_friend ? '✓ В друзьях' : '+ Добавить в друзья';
                btn.className = 'btn-duo ' + (data.is_friend ? 'btn-outline' : 'btn-primary');
            }
            alert(data.message);
        }
    } catch (e) {
        console.error('Friend toggle error', e);
    }
}

async function toggle2fa(enabled) {
    try {
        const formData = new FormData();
        formData.append('action', 'toggle_2fa');
        formData.append('enabled', enabled ? '1' : '0');
        const res = await fetch('api/telegram_api.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            SoundEngine.play('correct');
            alert(data.message);
        } else {
            alert('Ошибка: ' + (data.error || 'Не удалось изменить настройки'));
            document.getElementById('profile-2fa-toggle').checked = !enabled;
        }
    } catch (e) {
        alert('Ошибка связи с сервером');
        document.getElementById('profile-2fa-toggle').checked = !enabled;
    }
}

async function saveNotificationSettings() {
    try {
        const formData = new FormData();
        formData.append('action', 'save_notifications');
        if (document.getElementById('notify-streak')?.checked) formData.append('notify_streak', '1');
        if (document.getElementById('notify-quests')?.checked) formData.append('notify_quests', '1');
        if (document.getElementById('notify-duels')?.checked) formData.append('notify_duels', '1');
        if (document.getElementById('notify-security')?.checked) formData.append('notify_security', '1');

        const res = await fetch('api/telegram_api.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            console.log('Notification settings updated');
        }
    } catch (e) {
        console.error('Save notifications error', e);
    }
}

async function sendTestTelegramNotification() {
    try {
        const formData = new FormData();
        formData.append('action', 'send_test_notification');
        const res = await fetch('api/telegram_api.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            SoundEngine.play('correct');
            alert('✓ ' + data.message);
        } else {
            alert('Ошибка: ' + (data.error || data.message));
        }
    } catch (e) {
        alert('Ошибка при отправке тестового сообщения');
    }
}

async function unlinkTelegram() {
    if (!confirm('Вы действительно хотите отвязать Telegram? Двухфакторная защита (2FA) и сброс пароля через бота будут отключены.')) {
        return;
    }
    try {
        const formData = new FormData();
        formData.append('action', 'unlink_telegram');
        const res = await fetch('api/telegram_api.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            SoundEngine.play('correct');
            alert('✓ ' + data.message);
            location.reload();
        } else {
            alert('Ошибка: ' + (data.error || 'Не удалось отвязать'));
        }
    } catch (e) {
        alert('Ошибка при отвязке Telegram');
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
