<!DOCTYPE html>
<html lang="<?= e($config['default_lang'] ?? 'ru') ?>" data-theme="<?= e($_COOKIE['theme'] ?? setting('default_theme', 'dark')) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">

    <?php
    $siteName = setting('site_name', 'VladAero');
    $pageTitle = $pageTitle ?? "{$siteName} — " . setting('site_tagline', 'Авиационный портал');
    $pageDesc = $pageDesc ?? setting('site_description', 'Энциклопедия авиации: самолёты, аэропорты, авиакомпании, радар, споттинг');
    $pageOgImage = $pageOgImage ?? url(setting('site_og_image', 'assets/images/og-default.png'));
    $logoPath = url(setting('site_logo', 'assets/images/logo.png'));
    ?>
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($pageDesc) ?>">
    <meta property="og:title" content="<?= e($pageTitle) ?>">
    <meta property="og:description" content="<?= e($pageDesc) ?>">
    <meta property="og:image" content="<?= e($pageOgImage) ?>">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="ru_RU">
    <meta name="theme-color" content="#0a1628">

    <!-- Preconnect -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <!-- Leaflet Maps -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

    <!-- Styles -->
    <link rel="stylesheet" href="<?= url('public/css/main.css') ?>">
    <link rel="stylesheet" href="<?= url('public/css/components.css') ?>">
    <link rel="stylesheet" href="<?= url('public/css/aviation.css') ?>">

    <!-- PWA & Favicons -->
    <link rel="manifest" href="<?= url('manifest.json') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= url(setting('site_favicon', 'assets/images/favicon-32.png')) ?>">
    <link rel="apple-touch-icon" href="<?= url(setting('site_favicon', 'assets/images/apple-touch-icon.png')) ?>">

    <?php if ($customCss = setting('custom_css')): ?>
        <style><?= $customCss ?></style>
    <?php endif; ?>
</head>
<body class="app-body">

    <!-- ═══ HEADER ═══ -->
    <header class="header" id="header">
        <div class="header__inner">
            <!-- Logo -->
            <a href="<?= url('/') ?>" class="header__logo" aria-label="<?= e($siteName) ?> — Главная">
                <img src="<?= $logoPath ?>" alt="<?= e($siteName) ?>" width="40" height="40" class="header__logo-img">
                <span class="header__logo-text"><?= setting('site_name_html', 'Vlad<span class="text-accent">Aero</span>') ?></span>
            </a>

            <!-- Navigation Toggle (Mobile) -->
            <button class="header__menu-toggle" id="menuToggle" aria-label="Меню">
                <span></span><span></span><span></span>
            </button>

            <!-- Main Navigation -->
            <nav class="header__nav" id="mainNav">
                <a href="<?= url('/aircraft') ?>" class="header__nav-link <?= isActive('/aircraft') ?>">
                    <svg class="icon" viewBox="0 0 24 24"><path d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/></svg>
                    Энциклопедия
                </a>
                <a href="<?= url('/airports') ?>" class="header__nav-link <?= isActive('/airports') ?>">
                    <svg class="icon" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>
                    Аэропорты
                </a>
                <a href="<?= url('/airlines') ?>" class="header__nav-link <?= isActive('/airlines') ?>">
                    <svg class="icon" viewBox="0 0 24 24"><path d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/></svg>
                    Авиакомпании
                </a>
                <a href="<?= url('/radar') ?>" class="header__nav-link <?= isActive('/radar') ?>">
                    <svg class="icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" fill="currentColor"/><line x1="12" y1="2" x2="12" y2="6" stroke="currentColor" stroke-width="2"/></svg>
                    Радар
                </a>
                <a href="<?= url('/photos') ?>" class="header__nav-link <?= isActive('/photos') ?>">
                    <svg class="icon" viewBox="0 0 24 24"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>
                    Споттинг
                </a>
                <a href="<?= url('/news') ?>" class="header__nav-link <?= isActive('/news') ?>">Новости</a>
                <a href="<?= url('/quizzes') ?>" class="header__nav-link <?= isActive('/quizzes') ?>">Тесты</a>
                <a href="<?= url('/calculators') ?>" class="header__nav-link <?= isActive('/calculators') ?>">Инструменты</a>
                <a href="<?= url('/events') ?>" class="header__nav-link <?= isActive('/events') ?>">События</a>
            </nav>

            <!-- Right Actions -->
            <div class="header__actions">
                <!-- Search -->
                <button class="header__search-btn" id="searchToggle" aria-label="Поиск (Ctrl+K)">
                    <svg class="icon" viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                </button>

                <!-- Theme Toggle -->
                <button class="header__theme-btn" id="themeToggle" aria-label="Сменить тему">
                    <svg class="icon icon--sun" viewBox="0 0 24 24"><path d="M6.76 4.84l-1.8-1.79-1.41 1.41 1.79 1.79 1.42-1.41zM4 10.5H1v2h3v-2zm9-9.95h-2V3.5h2V.55zm7.45 3.91l-1.41-1.41-1.79 1.79 1.41 1.41 1.79-1.79zm-3.21 13.7l1.79 1.8 1.41-1.41-1.8-1.79-1.4 1.4zM20 10.5v2h3v-2h-3zm-8-5c-3.31 0-6 2.69-6 6s2.69 6 6 6 6-2.69 6-6-2.69-6-6-6zm-1 16.95h2V19.5h-2v2.95zm-7.45-3.91l1.41 1.41 1.79-1.8-1.41-1.41-1.79 1.8z"/></svg>
                    <svg class="icon icon--moon" viewBox="0 0 24 24"><path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9c0-.46-.04-.92-.1-1.36-.98 1.37-2.58 2.26-4.4 2.26-2.98 0-5.4-2.42-5.4-5.4 0-1.81.89-3.42 2.26-4.4-.44-.06-.9-.1-1.36-.1z"/></svg>
                </button>

                <!-- Sound Toggle -->
                <button class="header__sound-btn" id="soundToggle" aria-label="Звук вкл/выкл">
                    <svg class="icon" viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
                </button>

                <!-- User Menu -->
                <?php if (!empty($auth)): ?>
                    <div class="header__user-menu" id="userMenu">
                        <button class="header__user-btn" id="userMenuToggle">
                            <img src="<?= avatarUrl($auth['avatar'] ?? null) ?>" alt="" class="header__user-avatar" width="32" height="32">
                            <span class="header__user-name"><?= e($auth['display_name'] ?? $auth['username']) ?></span>
                        </button>
                        <div class="header__dropdown" id="userDropdown">
                            <a href="<?= url('/profile/' . e($auth['username'])) ?>" class="dropdown__item">👤 Мой профиль</a>
                            <a href="<?= url('/settings') ?>" class="dropdown__item">⚙️ Настройки</a>
                            <a href="<?= url('/photos/upload') ?>" class="dropdown__item">📸 Загрузить фото</a>
                            <?php if (in_array($auth['role'] ?? '', ['admin', 'moderator'])): ?>
                                <a href="<?= url('/admin') ?>" class="dropdown__item dropdown__item--accent">🛡️ Админ-панель</a>
                            <?php endif; ?>
                            <hr class="dropdown__divider">
                            <form method="POST" action="<?= url('/auth/logout') ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="dropdown__item">🚪 Выйти</button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="<?= url('/auth/login') ?>" class="btn btn--outline btn--sm">Войти</a>
                    <a href="<?= url('/auth/register') ?>" class="btn btn--primary btn--sm">Регистрация</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- ═══ MOBILE SIDEBAR ═══ -->
    <aside class="sidebar" id="mobileSidebar">
        <div class="sidebar__overlay" id="sidebarOverlay"></div>
        <div class="sidebar__content">
            <div class="sidebar__header">
                <img src="<?= $logoPath ?>" alt="<?= e($siteName) ?>" width="36" height="36" class="sidebar__logo">
                <span class="sidebar__title"><?= e($siteName) ?></span>
                <button class="sidebar__close" id="sidebarClose" aria-label="Закрыть меню">✕</button>
            </div>

            <!-- Mobile User / Auth Status -->
            <div class="sidebar__user-box">
                <?php if (!empty($auth)): ?>
                    <div class="sidebar__user-profile">
                        <img src="<?= avatarUrl($auth['avatar'] ?? null) ?>" alt="" class="sidebar__user-avatar">
                        <div class="sidebar__user-info">
                            <div class="sidebar__user-name"><?= e($auth['display_name'] ?? $auth['username']) ?></div>
                            <div class="sidebar__user-rank"><?= e($auth['rank'] ?? 'Пилот') ?></div>
                        </div>
                    </div>
                    <div class="sidebar__user-links">
                        <a href="<?= url('/profile/' . e($auth['username'])) ?>" class="sidebar__user-btn">👤 Профиль</a>
                        <a href="<?= url('/settings') ?>" class="sidebar__user-btn">⚙️ Настройки</a>
                        <?php if (in_array($auth['role'] ?? '', ['admin', 'moderator'])): ?>
                            <a href="<?= url('/admin') ?>" class="sidebar__user-btn sidebar__user-btn--admin">🛡️ Админка</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="sidebar__auth-buttons">
                        <a href="<?= url('/auth/login') ?>" class="btn btn--outline btn--sm btn--block">Войти</a>
                        <a href="<?= url('/auth/register') ?>" class="btn btn--primary btn--sm btn--block">Регистрация</a>
                    </div>
                <?php endif; ?>
            </div>

            <nav class="sidebar__nav">
                <div class="sidebar__nav-group">Каталог & Энциклопедия</div>
                <a href="<?= url('/aircraft') ?>" class="sidebar__link <?= isActive('/aircraft') ?>">✈️ Самолёты</a>
                <a href="<?= url('/airports') ?>" class="sidebar__link <?= isActive('/airports') ?>">🛫 Аэропорты</a>
                <a href="<?= url('/airlines') ?>" class="sidebar__link <?= isActive('/airlines') ?>">🏢 Авиакомпании</a>
                <a href="<?= url('/radar') ?>" class="sidebar__link <?= isActive('/radar') ?>">📡 Интерактивный радар</a>
                <a href="<?= url('/photos') ?>" class="sidebar__link <?= isActive('/photos') ?>">📸 Фотогалерея (Споттинг)</a>

                <div class="sidebar__nav-group">Контент & Сообщество</div>
                <a href="<?= url('/news') ?>" class="sidebar__link <?= isActive('/news') ?>">📰 Новости</a>
                <a href="<?= url('/articles') ?>" class="sidebar__link <?= isActive('/articles') ?>">📝 Статьи</a>
                <a href="<?= url('/events') ?>" class="sidebar__link <?= isActive('/events') ?>">📅 События</a>
                <a href="<?= url('/clubs') ?>" class="sidebar__link <?= isActive('/clubs') ?>">👥 Клубы</a>

                <div class="sidebar__nav-group">Инструменты & Сим</div>
                <a href="<?= url('/calculators') ?>" class="sidebar__link <?= isActive('/calculators') ?>">🧮 Калькуляторы</a>
                <a href="<?= url('/checklists') ?>" class="sidebar__link <?= isActive('/checklists') ?>">✅ Чек-листы</a>
                <a href="<?= url('/quizzes') ?>" class="sidebar__link <?= isActive('/quizzes') ?>">🧠 Викторины</a>
                <a href="<?= url('/glossary') ?>" class="sidebar__link <?= isActive('/glossary') ?>">📖 Глоссарий</a>
                <a href="<?= url('/phraseology') ?>" class="sidebar__link <?= isActive('/phraseology') ?>">🎙️ Фразеология</a>

                <div class="sidebar__nav-group">О проекте</div>
                <a href="<?= url('/about') ?>" class="sidebar__link <?= isActive('/about') ?>">ℹ️ О проекте</a>
                <?php if (!empty($auth)): ?>
                    <form method="POST" action="<?= url('/auth/logout') ?>" style="margin-top:1rem;padding:0 1rem;">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn--danger btn--sm btn--block">🚪 Выйти из аккаунта</button>
                    </form>
                <?php endif; ?>
            </nav>
        </div>
    </aside>

    <!-- ═══ SEARCH MODAL ═══ -->
    <div class="search-modal" id="searchModal">
        <div class="search-modal__overlay"></div>
        <div class="search-modal__dialog">
            <div class="search-modal__input-wrap">
                <svg class="search-modal__icon" viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                <input type="text" class="search-modal__input" id="searchInput" placeholder="Поиск самолётов, аэропортов, статей..." autofocus autocomplete="off">
                <kbd class="search-modal__kbd">ESC</kbd>
            </div>
            <div class="search-modal__results" id="searchResults">
                <div class="search-modal__hint">Введите запрос для поиска по сайту</div>
            </div>
        </div>
    </div>

    <!-- ═══ MAIN CONTENT ═══ -->
    <main class="main" id="mainContent">
        <?= flashMessages() ?>
        <?= $content ?>
    </main>

    <!-- ═══ FOOTER ═══ -->
    <footer class="footer">
        <div class="footer__inner">
            <div class="footer__grid">
                <div class="footer__col">
                    <img src="<?= url('assets/images/logo.png') ?>" alt="VladAero" width="40" height="40" class="footer__logo">
                    <p class="footer__about">Авиационный портал VladAero — энциклопедия, радар, сообщество споттеров и симмеров.</p>
                </div>
                <div class="footer__col">
                    <h4 class="footer__heading">Каталог</h4>
                    <a href="<?= url('/aircraft') ?>" class="footer__link">Самолёты</a>
                    <a href="<?= url('/airports') ?>" class="footer__link">Аэропорты</a>
                    <a href="<?= url('/airlines') ?>" class="footer__link">Авиакомпании</a>
                    <a href="<?= url('/photos') ?>" class="footer__link">Фотогалерея</a>
                </div>
                <div class="footer__col">
                    <h4 class="footer__heading">Инструменты</h4>
                    <a href="<?= url('/radar') ?>" class="footer__link">Радар полётов</a>
                    <a href="<?= url('/calculators') ?>" class="footer__link">Калькуляторы</a>
                    <a href="<?= url('/quizzes') ?>" class="footer__link">Викторины</a>
                    <a href="<?= url('/checklists') ?>" class="footer__link">Чек-листы</a>
                </div>
                <div class="footer__col">
                    <h4 class="footer__heading">Информация</h4>
                    <a href="<?= url('/about') ?>" class="footer__link">О проекте</a>
                    <a href="<?= url('/glossary') ?>" class="footer__link">Глоссарий</a>
                    <a href="<?= url('/phraseology') ?>" class="footer__link">Радиообмен</a>
                    <a href="<?= url('/rss.xml') ?>" class="footer__link">RSS</a>
                </div>
            </div>
            <div class="footer__bottom">
                <p>© <?= date('Y') ?> VladAero v<?= VLD_VERSION ?>. Все права защищены.</p>
                <p class="footer__tg">📡 Telegram: <a href="https://t.me/vladaero" target="_blank">@vladaero</a></p>
            </div>
        </div>
    </footer>

    <!-- ═══ AI WIDGET ═══ -->
    <?php if (empty($hideAiWidget)): ?>
    <div class="ai-widget" id="aiWidget">
        <button class="ai-widget__trigger" id="aiTrigger" aria-label="AI Ассистент">
            <div class="ai-widget__radar-ring"></div>
            <svg class="ai-widget__icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="12" cy="12" r="2" fill="currentColor"/><line x1="12" y1="2" x2="12" y2="8" stroke="currentColor" stroke-width="1.5"/></svg>
        </button>
        <div class="ai-widget__panel" id="aiPanel">
            <div class="ai-widget__header">
                <div class="ai-widget__header-info">
                    <div class="ai-widget__status-dot"></div>
                    <span>VladAero AI</span>
                </div>
                <div class="ai-widget__header-actions">
                    <button class="ai-widget__minimize" id="aiMinimize">—</button>
                    <button class="ai-widget__close" id="aiClose">✕</button>
                </div>
            </div>
            <div class="ai-widget__messages" id="aiMessages">
                <div class="ai-widget__welcome">
                    <p>👋 Привет! Я AI-ассистент VladAero.</p>
                    <p>Спросите про самолёты, погоду, рассчитайте маршрут или сравните технику.</p>
                    <div class="ai-widget__chips">
                        <button class="ai-chip" data-prompt="Погода в Шереметьево">🌤 Погода в SVO</button>
                        <button class="ai-chip" data-prompt="Сравни A320neo и B737MAX">⚖️ A320neo vs B737MAX</button>
                        <button class="ai-chip" data-prompt="ТТХ Су-57">✈️ ТТХ Су-57</button>
                        <button class="ai-chip" data-prompt="Расстояние Москва — Петербург">📏 Маршрут МСК-СПБ</button>
                    </div>
                </div>
            </div>
            <div class="ai-widget__input-area">
                <textarea class="ai-widget__input" id="aiInput" placeholder="Задайте вопрос..." rows="1"></textarea>
                <button class="ai-widget__send" id="aiSend">
                    <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══ SCRIPTS ═══ -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="<?= url('public/js/app.js') ?>"></script>
    <script src="<?= url('public/js/ai-widget.js') ?>"></script>
    <script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('<?= url('sw.js') ?>').catch(() => {});
    }
    </script>
</body>
</html>
