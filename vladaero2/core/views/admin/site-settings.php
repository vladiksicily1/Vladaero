<?php
/** @var array $settings */
$logo = $settings['site_logo'] ?? 'assets/images/logo.png';
$heroBg = $settings['hero_background'] ?? '';
$favicon = $settings['site_favicon'] ?? 'assets/images/favicon-32.png';
$ogImage = $settings['site_og_image'] ?? 'assets/images/og-default.png';
?>

<section class="section">
<div class="container" style="max-width: 1000px;">
    <div class="section-header">
        <div>
            <h1 class="page-title" style="margin-bottom: .25rem;">⚙️ Центр управления сайтом и брендингом</h1>
            <p class="text-muted">Настройка оформления, логотипов, фонов, текстов и системных параметров VladAero</p>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <div class="tabs" style="margin-bottom: 2rem;">
        <button class="tab active" onclick="switchAdminTab('tab-branding', this)">🎨 Медиа и Брендинг</button>
        <button class="tab" onclick="switchAdminTab('tab-content', this)">📝 Тексты и Контент</button>
        <button class="tab" onclick="switchAdminTab('tab-system', this)">⚙️ Системные параметры</button>
        <button class="tab" onclick="switchAdminTab('tab-custom-code', this)">💻 Кастомный CSS</button>
        <button class="tab" onclick="switchAdminTab('tab-tools', this)">⚡ Инструменты & БД</button>
    </div>

    <form method="POST" action="<?= url('/admin/settings/update') ?>" enctype="multipart/form-data" id="settingsForm">
        <?= csrf_field() ?>

        <!-- ── TAB 1: MEDIA & BRANDING ── -->
        <div class="tab-content active" id="tab-branding">
            <div class="card" style="margin-bottom: 1.5rem;">
                <div class="card__body">
                    <h3 style="margin-bottom: 1.25rem;">🖼️ Изображения и логотипы сайта</h3>

                    <div class="grid grid--2" style="gap: 1.5rem;">
                        <!-- Logo -->
                        <div class="media-setting-card">
                            <label class="form-label font-bold">Логотип сайта (Header & Footer)</label>
                            <p class="form-hint">Форматы: PNG, WEBP, SVG. Рекомендуемый размер: 80x80px или вектор.</p>
                            <div class="media-preview-box">
                                <img src="<?= url($logo) ?>" alt="Logo" class="media-preview-img" style="max-height: 60px;">
                            </div>
                            <input type="file" name="site_logo" accept="image/*" class="form-control" style="margin-top: .5rem;">
                            <?php if ($logo !== 'assets/images/logo.png'): ?>
                                <button type="button" class="btn btn--outline btn--sm mt-2" onclick="resetMediaItem('site_logo')">↺ Сбросить на стандартный</button>
                            <?php endif; ?>
                        </div>

                        <!-- Hero Background -->
                        <div class="media-setting-card">
                            <label class="form-label font-bold">Фоновое изображение баннера (Hero)</label>
                            <p class="form-hint">Широкоформатное фото (1920x600px). Если не задано — используется радарный градиент.</p>
                            <div class="media-preview-box">
                                <?php if (!empty($heroBg)): ?>
                                    <img src="<?= url($heroBg) ?>" alt="Hero BG" class="media-preview-img" style="max-height: 90px; width: 100%; object-fit: cover;">
                                <?php else: ?>
                                    <span class="text-muted" style="font-size: .85rem;">Стандартный градиент (Cockpit Blue)</span>
                                <?php endif; ?>
                            </div>
                            <input type="file" name="hero_background" accept="image/*" class="form-control" style="margin-top: .5rem;">
                            <?php if (!empty($heroBg)): ?>
                                <button type="button" class="btn btn--outline btn--sm mt-2" onclick="resetMediaItem('hero_background')">↺ Очистить фон (вернуть градиент)</button>
                            <?php endif; ?>
                        </div>

                        <!-- Favicon -->
                        <div class="media-setting-card">
                            <label class="form-label font-bold">Иконка вкладки (Favicon)</label>
                            <p class="form-hint">Иконка браузера 32x32 или 64x64 (PNG, ICO, SVG).</p>
                            <div class="media-preview-box">
                                <img src="<?= url($favicon) ?>" alt="Favicon" class="media-preview-img" style="width: 32px; height: 32px;">
                            </div>
                            <input type="file" name="site_favicon" accept="image/*" class="form-control" style="margin-top: .5rem;">
                        </div>

                        <!-- OpenGraph Image -->
                        <div class="media-setting-card">
                            <label class="form-label font-bold">Картинка для соцсетей (OpenGraph / Telegram)</label>
                            <p class="form-hint">Превью ссылки при отправке в Telegram, VK, WhatsApp (1200x630px).</p>
                            <div class="media-preview-box">
                                <img src="<?= url($ogImage) ?>" alt="OG Preview" class="media-preview-img" style="max-height: 80px; width: 100%; object-fit: cover;">
                            </div>
                            <input type="file" name="site_og_image" accept="image/*" class="form-control" style="margin-top: .5rem;">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── TAB 2: TEXT & CONTENT ── -->
        <div class="tab-content" id="tab-content">
            <div class="card" style="margin-bottom: 1.5rem;">
                <div class="card__body">
                    <h3 style="margin-bottom: 1.25rem;">📝 Текстовое наполнение и заголовки</h3>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Название проекта (Site Name)</label>
                            <input type="text" name="site_name" value="<?= e($settings['site_name'] ?? 'VladAero') ?>" class="form-control">
                            <span class="form-hint">Используется в title страниц и письмах</span>
                        </div>
                        <div class="form-group">
                            <label>HTML-логотип (текстовая часть)</label>
                            <input type="text" name="site_name_html" value="<?= e($settings['site_name_html'] ?? 'Vlad<span class="text-accent">Aero</span>') ?>" class="form-control font-mono">
                            <span class="form-hint">Поддерживает HTML-теги для стилизации частей названия</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Слоган сайта (Tagline)</label>
                        <input type="text" name="site_tagline" value="<?= e($settings['site_tagline'] ?? 'Авиационный портал') ?>" class="form-control">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Главный заголовок Hero-баннера</label>
                            <input type="text" name="hero_title" value="<?= e($settings['hero_title'] ?? 'V<span class="text-accent">A</span> VladAero') ?>" class="form-control font-mono">
                        </div>
                        <div class="form-group">
                            <label>Подзаголовок Hero-баннера</label>
                            <input type="text" name="hero_subtitle" value="<?= e($settings['hero_subtitle'] ?? 'Авиационный портал — энциклопедия, радар, сообщество') ?>" class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Мета-описание сайта (SEO Description)</label>
                        <textarea name="site_description" class="form-control" rows="2"><?= e($settings['site_description'] ?? 'Энциклопедия авиации: самолёты, аэропорты, авиакомпании, радар, споттинг') ?></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Описание проекта в подвале (Footer About)</label>
                            <textarea name="footer_about" class="form-control" rows="2"><?= e($settings['footer_about'] ?? 'Авиационный портал VladAero — энциклопедия, радар, сообщество споттеров и симмеров.') ?></textarea>
                        </div>
                        <div class="form-group">
                            <label>Ссылка на Telegram-канал / Чат</label>
                            <input type="url" name="telegram_link" value="<?= e($settings['telegram_link'] ?? 'https://t.me/vladaero') ?>" class="form-control">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── TAB 3: SYSTEM PARAMETERS ── -->
        <div class="tab-content" id="tab-system">
            <div class="card" style="margin-bottom: 1.5rem;">
                <div class="card__body">
                    <h3 style="margin-bottom: 1.25rem;">⚙️ Системные настройки и доступ</h3>

                    <div class="form-group" style="padding: 1rem; background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius);">
                        <label class="checkbox-label" style="display: flex; align-items: center; gap: .75rem; cursor: pointer; text-transform: none;">
                            <input type="checkbox" name="maintenance_mode" value="1" <?= !empty($settings['maintenance']) || !empty($settings['maintenance_mode']) ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                            <div>
                                <strong style="font-size: 1rem; display: block;">🚧 Режим технического обслуживания</strong>
                                <span class="text-muted" style="font-size: .85rem;">При включении обычные пользователи видят страницу регламентных работ. Администраторы имеют полный доступ к сайту.</span>
                            </div>
                        </label>
                    </div>

                    <div class="form-group" style="padding: 1rem; background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius);">
                        <label class="checkbox-label" style="display: flex; align-items: center; gap: .75rem; cursor: pointer; text-transform: none;">
                            <input type="checkbox" name="allow_registration" value="1" <?= ($settings['allow_registration'] ?? '1') === '1' ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                            <div>
                                <strong style="font-size: 1rem; display: block;">👥 Разрешить регистрацию новых пользователей</strong>
                                <span class="text-muted" style="font-size: .85rem;">Если отключено, форма регистрации будет заблокирована для гостей.</span>
                            </div>
                        </label>
                    </div>

                    <div class="form-group" style="max-width: 300px;">
                        <label>Тема интерфейса по умолчанию</label>
                        <select name="default_theme" class="form-control">
                            <option value="dark" <?= ($settings['default_theme'] ?? 'dark') === 'dark' ? 'selected' : '' ?>>🌙 Тёмная (Glass Cockpit Night)</option>
                            <option value="light" <?= ($settings['default_theme'] ?? '') === 'light' ? 'selected' : '' ?>>☀️ Светлая (Day Flight)</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── TAB 4: CUSTOM CSS ── -->
        <div class="tab-content" id="tab-custom-code">
            <div class="card" style="margin-bottom: 1.5rem;">
                <div class="card__body">
                    <h3 style="margin-bottom: .5rem;">💻 Пользовательские стили (Custom CSS)</h3>
                    <p class="text-muted" style="font-size: .85rem; margin-bottom: 1rem;">
                        CSS-код будет автоматически вставлен в тег &lt;style&gt; в шапке каждой страницы сайта.
                    </p>
                    <div class="form-group">
                        <textarea name="custom_css" class="form-control font-mono" rows="12" placeholder=":root { --accent: #3b82f6; }"><?= e($settings['custom_css'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Save Button Bar -->
        <div style="display: flex; gap: 1rem; align-items: center; margin-top: 1rem; margin-bottom: 2rem;">
            <button type="submit" class="btn btn--primary btn--lg" style="padding: .75rem 2rem;">💾 Сохранить все настройки</button>
        </div>
    </form>

    <!-- ── TAB 5: TOOLS & DIAGNOSTICS ── -->
    <div class="tab-content" id="tab-tools">
        <div class="grid grid--2" style="margin-bottom: 1.5rem;">
            <div class="card">
                <div class="card__body">
                    <h3>🧹 Управление кэшем</h3>
                    <p class="text-muted" style="font-size: .85rem; margin-bottom: 1rem;">Очистка кэша страниц, запросов БД и сгенерированных фрагментов.</p>
                    <form method="POST" action="<?= url('/admin/settings/clear-cache') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn--outline">🧹 Очистить весь кэш</button>
                    </form>
                </div>
            </div>
            <div class="card">
                <div class="card__body">
                    <h3>⚡ Оптимизация таблиц БД</h3>
                    <p class="text-muted" style="font-size: .85rem; margin-bottom: 1rem;">Выполняет дефрагментацию и команду OPTIMIZE TABLE для всех таблиц VladAero.</p>
                    <form method="POST" action="<?= url('/admin/settings/optimize-db') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn--outline">⚡ Оптимизировать БД</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card__body">
                <h3>ℹ️ Системная информация сервера</h3>
                <div class="grid grid--4" style="margin-top: 1rem;">
                    <div class="stat-box">
                        <div class="stat-label">PHP Версия</div>
                        <div class="stat-value"><?= phpversion() ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-label">MySQL Версия</div>
                        <div class="stat-value"><?= $this->db->fetchColumn('SELECT VERSION()') ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-label">Версия VladAero</div>
                        <div class="stat-value">v<?= VLD_VERSION ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-label">Размер БД</div>
                        <div class="stat-value"><?= $this->db->fetchColumn("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) FROM information_schema.tables WHERE table_schema = DATABASE()") ?> MB</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</section>

<!-- Hidden form for resetting media items -->
<form id="resetMediaForm" method="POST" action="<?= url('/admin/settings/reset-media') ?>" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="target" id="resetMediaTarget">
</form>

<style>
.media-setting-card {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1.25rem;
    display: flex;
    flex-direction: column;
}
.media-preview-box {
    margin: .75rem 0;
    padding: 1rem;
    background: var(--bg);
    border: 1px dashed var(--border);
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 90px;
}
.media-preview-img {
    border-radius: var(--radius-sm);
}
.stat-box {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 1rem;
    text-align: center;
}
.stat-label { font-size: .75rem; color: var(--text-dim); text-transform: uppercase; margin-bottom: .25rem; }
.stat-value { font-size: 1.2rem; font-weight: 700; font-family: var(--font-mono); color: var(--accent); }
.font-mono { font-family: var(--font-mono); }
</style>

<script>
function switchAdminTab(tabId, btn) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    if (btn) btn.classList.add('active');
    const target = document.getElementById(tabId);
    if (target) target.classList.add('active');
}

function resetMediaItem(targetName) {
    if (!confirm('Сбросить это изображение на стандартное?')) return;
    document.getElementById('resetMediaTarget').value = targetName;
    document.getElementById('resetMediaForm').submit();
}
</script>
