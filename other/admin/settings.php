<?php
/**
 * Admin System Settings
 */

$adminTitle = 'Настройки системы';
require_once __DIR__ . '/header.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $siteTitle = trim($_POST['site_title'] ?? 'ShibaLingo');
    $siteTagline = trim($_POST['site_tagline'] ?? '');
    $mascotName = trim($_POST['mascot_name'] ?? 'Сиба-сэнсэй');
    $mascotAvatar = trim($_POST['mascot_custom_avatar'] ?? '');

    setSetting('site_title', $siteTitle);
    setSetting('site_tagline', $siteTagline);
    setSetting('mascot_name', $mascotName);
    setSetting('mascot_custom_avatar', $mascotAvatar);

    $message = 'Системные настройки успешно обновлены!';
}

$siteTitle = getSetting('site_title', 'ShibaLingo');
$siteTagline = getSetting('site_tagline', 'Учи языки и тайный язык Vladikish вместе с Шиба-Ину!');
$mascotName = getSetting('mascot_name', 'Сиба-сэнсэй');
$mascotAvatar = getSetting('mascot_custom_avatar', '');
?>

<div style="max-width: 750px;">
    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 1.8rem; font-weight: 900;">⚙️ Системные настройки</h1>
        <p style="color: var(--text-muted);">Название сайта, слоган, имя маскота и кастомный аватар</p>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-success" style="background: var(--primary-light); color: var(--primary-shadow); padding: 14px; border-radius: 12px; font-weight: 700; margin-bottom: 20px;">
            ✓ <?= e($message) ?>
        </div>
    <?php endif; ?>

    <div class="card-duo" style="background: linear-gradient(135deg, #eff6ff, #f0fdf4); border-color: var(--secondary); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 24px;">
        <div>
            <div style="font-weight: 800; font-size: 1.1rem; color: var(--secondary);">🤖 Настройки ИИ, Base URL и выбор моделей</div>
            <div style="font-size: 0.85rem; color: var(--text-muted);">Настройка ключей NVIDIA NIM, OpenAI Base URL и загрузка моделей через /v1/models</div>
        </div>
        <a href="ai_prompts.php" class="btn-duo btn-secondary" style="padding: 8px 16px; font-size: 0.9rem;">
            Настроить ИИ & Base URL ⚙️
        </a>
    </div>

    <form method="POST">
        <div class="card-duo">
            <h3 style="font-size: 1.2rem; font-weight: 800; margin-bottom: 16px;">Общая информация о сайте</h3>

            <div style="margin-bottom: 16px;">
                <label style="font-weight: 700; font-size: 0.9rem; margin-bottom: 6px; display: block;">Название платформы:</label>
                <input type="text" name="site_title" value="<?= e($siteTitle) ?>" class="chat-input" required>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="font-weight: 700; font-size: 0.9rem; margin-bottom: 6px; display: block;">Слоган (Tagline):</label>
                <input type="text" name="site_tagline" value="<?= e($siteTagline) ?>" class="chat-input">
            </div>
        </div>

        <div class="card-duo">
            <h3 style="font-size: 1.2rem; font-weight: 800; margin-bottom: 16px;">🐕 Настройки маскота Шиба-Ину</h3>

            <div style="margin-bottom: 16px;">
                <label style="font-weight: 700; font-size: 0.9rem; margin-bottom: 6px; display: block;">Имя маскота-учителя:</label>
                <input type="text" name="mascot_name" value="<?= e($mascotName) ?>" class="chat-input" required>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="font-weight: 700; font-size: 0.9rem; margin-bottom: 6px; display: block;">Кастомное изображение маскота (PNG, JPG, WEBP, SVG):</label>
                <div style="display: flex; gap: 10px; margin-bottom: 8px;">
                    <input type="text" id="mascot-avatar-input" name="mascot_custom_avatar" value="<?= e($mascotAvatar) ?>" class="chat-input" placeholder="uploads/mascot_example.png" style="margin-bottom: 0;">
                    <button type="button" class="btn-duo btn-secondary" onclick="document.getElementById('mascot-file-picker').click()" style="white-space: nowrap;">
                        📤 Загрузить файл
                    </button>
                    <input type="file" id="mascot-file-picker" accept="image/*" style="display: none;" onchange="uploadMascotImage(this.files)">
                </div>

                <?php if (!empty($mascotAvatar)): ?>
                    <div style="margin-top: 12px; display: flex; align-items: center; gap: 14px; background: var(--bg-main); padding: 10px; border-radius: 12px;">
                        <img src="../<?= e($mascotAvatar) ?>" alt="Mascot Preview" style="width: 50px; height: 50px; object-fit: cover; border-radius: 10px;">
                        <span style="font-size: 0.85rem; font-weight: 700; color: var(--text-muted);">Текущее изображение маскота</span>
                    </div>
                <?php endif; ?>

                <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 6px;">
                    Если поле пустое, используется встроенный анимированный векторный SVG-маскот.
                </div>
            </div>
        </div>

        <button type="submit" class="btn-duo btn-primary" style="width: 100%; padding: 16px;">
            Сохранить настройки 💾
        </button>
    </form>
</div>

<script>
async function uploadMascotImage(files) {
    if (!files || !files.length) return;
    const file = files[0];
    const formData = new FormData();
    formData.append('file', file);
    formData.append('type', 'mascot');

    try {
        const res = await fetch('../api/upload_api.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            SoundEngine.play('correct');
            document.getElementById('mascot-avatar-input').value = data.url;
            alert('✓ Изображение маскота успешно загружено в ' + data.url);
        } else {
            SoundEngine.play('wrong');
            alert('Ошибка: ' + data.error);
        }
    } catch(err) {
        alert('Ошибка при загрузке файла.');
    }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
