<?php
/**
 * Admin Maintenance Mode Control
 */

$adminTitle = 'Режим техобслуживания';
require_once __DIR__ . '/header.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = (int)($_POST['maintenance_mode'] ?? 0);
    $msg = trim($_POST['maintenance_message'] ?? 'Сиба-сэнсэй проводит техническое обслуживание! Скоро вернемся 🐾');
    $roles = json_encode($_POST['allowed_roles'] ?? ['superadmin', 'admin']);

    setSetting('maintenance_mode', (string)$mode);
    setSetting('maintenance_message', $msg);
    setSetting('maintenance_allowed_roles', $roles);

    $message = ($mode === 1) ? '🚧 Режим техобслуживания ВКЛЮЧЕН.' : '🟢 Режим техобслуживания ВЫКЛЮЧЕН.';
}

$currentMode = getSetting('maintenance_mode', '0');
$currentMsg = getSetting('maintenance_message', 'Сиба-сэнсэй проводит техническое обслуживание! Скоро вернемся 🐾');
$allowedRoles = json_decode(getSetting('maintenance_allowed_roles', '["superadmin","admin"]'), true) ?: ['superadmin', 'admin'];

$db = getDb();
$allRoles = $db->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll();
?>

<div style="max-width: 750px;">
    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 1.8rem; font-weight: 900;">🚧 Управление техническим обслуживанием</h1>
        <p style="color: var(--text-muted);">Включение режима обслуживания для проведения обновлений и работ</p>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-success" style="background: var(--primary-light); color: var(--primary-shadow); padding: 14px; border-radius: 12px; font-weight: 700; margin-bottom: 20px;">
            ✓ <?= e($message) ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="card-duo">
            <div style="margin-bottom: 20px;">
                <label style="font-weight: 800; font-size: 0.95rem; margin-bottom: 8px; display: block;">Состояние сайта:</label>
                <select name="maintenance_mode" class="chat-input" style="font-size: 1.05rem; font-weight: 700;">
                    <option value="0" <?= ($currentMode === '0') ? 'selected' : '' ?>>🟢 Выключен (Сайт открыт для всех учеников)</option>
                    <option value="1" <?= ($currentMode === '1') ? 'selected' : '' ?>>🚧 Включен (Доступ только для выбранных ролей)</option>
                </select>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="font-weight: 700; font-size: 0.9rem; margin-bottom: 8px; display: block;">Сообщение на экране заглушки:</label>
                <textarea name="maintenance_message" rows="3" class="chat-input"><?= e($currentMsg) ?></textarea>
            </div>

            <div style="margin-bottom: 24px;">
                <label style="font-weight: 800; font-size: 0.9rem; margin-bottom: 10px; display: block;">Роли, которым разрешен вход в режиме техобслуживания:</label>
                <div style="display: flex; flex-direction: column; gap: 8px; background: var(--bg-main); padding: 14px; border-radius: 12px; border: 2px solid var(--border-color);">
                    <?php foreach ($allRoles as $r): ?>
                        <label style="font-weight: 700; font-size: 0.9rem; display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="allowed_roles[]" value="<?= $r['slug'] ?>" <?= in_array($r['slug'], $allowedRoles) ? 'checked' : '' ?>>
                            <span><?= e($r['name']) ?> (<code><?= e($r['slug']) ?></code>)</span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" class="btn-duo btn-primary" style="width: 100%;">
                Сохранить параметры техобслуживания 💾
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
