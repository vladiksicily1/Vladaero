<?php
/**
 * Admin System Broadcast & Global Announcements
 */

$adminTitle = 'Системные объявления и Рассылки';
require_once __DIR__ . '/header.php';

if (!hasPermission($admin, 'manage_settings')) {
    die("<h1>403 Доступ запрещен</h1>");
}

$db = getDb();
$message = '';
$error = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_broadcast') {
        $bText = trim($_POST['broadcast_message'] ?? '');
        $bType = trim($_POST['broadcast_type'] ?? 'info');
        $bActive = isset($_POST['broadcast_active']) ? '1' : '0';

        setSetting('broadcast_message', $bText);
        setSetting('broadcast_type', $bType);
        setSetting('broadcast_active', $bActive);

        $message = 'Настройки глобального объявления успешно сохранены!';
    }
}

$broadcastMessage = getSetting('broadcast_message', '');
$broadcastType = getSetting('broadcast_type', 'info');
$broadcastActive = getSetting('broadcast_active', '0');
?>

<div style="max-width: 800px; margin: 0 auto; padding-bottom: 40px;">
    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 1.8rem; font-weight: 900; display: flex; align-items: center; gap: 10px;">
            <span>📢</span> Системные объявления и Рассылки
        </h1>
        <p style="color: var(--text-muted);">Вывод глобального баннера-уведомления для всех учеников в шапке сайта</p>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert-duo alert-success" style="margin-bottom: 20px;"><?= e($message) ?></div>
    <?php endif; ?>

    <!-- Broadcast Form -->
    <div class="card-duo" style="padding: 28px;">
        <form method="POST">
            <input type="hidden" name="action" value="save_broadcast">

            <!-- Active Checkbox -->
            <div style="margin-bottom: 20px; background: var(--bg-main); padding: 14px 18px; border-radius: 14px; border: 2px solid var(--border-color); display: flex; align-items: center; justify-content: space-between;">
                <div>
                    <div style="font-weight: 800; font-size: 1rem;">Показывать объявление на сайте</div>
                    <div style="font-size: 0.85rem; color: var(--text-muted);">Баннер будет закреплен в верхней части всех страниц платформы</div>
                </div>
                <label class="switch" style="position: relative; display: inline-block; width: 48px; height: 26px;">
                    <input type="checkbox" name="broadcast_active" value="1" <?= ($broadcastActive === '1') ? 'checked' : '' ?> style="opacity: 0; width: 0; height: 0;" onchange="this.nextElementSibling.style.backgroundColor = this.checked ? '#58cc02' : '#cbd5e1';">
                    <span style="position: absolute; cursor: pointer; inset: 0; background-color: <?= ($broadcastActive === '1') ? '#58cc02' : '#cbd5e1' ?>; transition: .3s; border-radius: 26px;"></span>
                </label>
            </div>

            <!-- Type -->
            <div style="margin-bottom: 18px;">
                <label style="display: block; font-weight: 800; font-size: 0.9rem; margin-bottom: 6px;">Тип и цвет уведомления:</label>
                <select name="broadcast_type" class="chat-input" style="font-weight: 700;">
                    <option value="info" <?= ($broadcastType === 'info') ? 'selected' : '' ?>>🔵 Информационное (Синее: новости, обновления, новые уроки)</option>
                    <option value="success" <?= ($broadcastType === 'success') ? 'selected' : '' ?>>🟢 Праздничное (Зеленое: скидки, 2x XP, победа в рейде)</option>
                    <option value="warning" <?= ($broadcastType === 'warning') ? 'selected' : '' ?>>🟡 Внимание (Желтое: технические работы, перерыв)</option>
                    <option value="danger" <?= ($broadcastType === 'danger') ? 'selected' : '' ?>>🔴 Срочное (Красное: важное предупреждение)</option>
                </select>
            </div>

            <!-- Message Text -->
            <div style="margin-bottom: 24px;">
                <label style="display: block; font-weight: 800; font-size: 0.9rem; margin-bottom: 6px;">Текст объявления:</label>
                <textarea name="broadcast_message" rows="4" class="chat-input" placeholder="Например: 🎉 Выходные 2x XP! Проходите уроки и побеждайте в рейдах с удвоенной наградой!" style="font-size: 1rem; resize: vertical;"><?= e($broadcastMessage) ?></textarea>
            </div>

            <!-- Preview -->
            <div style="margin-bottom: 24px;">
                <div style="font-weight: 800; font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px;">
                    Предпросмотр баннера:
                </div>
                <div style="padding: 14px 20px; border-radius: 12px; font-weight: 800; font-size: 0.95rem; display: flex; align-items: center; gap: 10px; background: rgba(88,204,2,0.15); color: #166534; border: 1.5px solid #58cc02;">
                    <span>📢</span>
                    <span><?= !empty($broadcastMessage) ? e($broadcastMessage) : 'Здесь будет отображаться ваш текст объявления для всех учеников' ?></span>
                </div>
            </div>

            <button type="submit" class="btn-duo btn-primary" style="padding: 12px 32px; font-size: 1rem; width: 100%;">
                Сохранить и опубликовать 🚀
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
