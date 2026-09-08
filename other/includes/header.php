<?php
/**
 * Header template for ShibaLingo
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

$user = getCurrentUser();
$languages = getLanguages();
$currentLangCode = $_SESSION['current_language'] ?? $user['current_language'];

// Handle language switch via query param
if (isset($_GET['switch_lang'])) {
    $newLang = $_GET['switch_lang'];
    setCurrentLanguage($newLang);
    $currentLangCode = $newLang;
    $cleanUrl = strtok($_SERVER["REQUEST_URI"], '?');
    header("Location: $cleanUrl");
    exit;
}

$currentLangObj = null;
foreach ($languages as $l) {
    if ($l['code'] === $currentLangCode) {
        $currentLangObj = $l;
        break;
    }
}
if (!$currentLangObj) {
    $currentLangObj = $languages[0] ?? ['name' => 'Vladikish', 'flag' => '🐕', 'code' => 'vladikish'];
}

$currentPage = basename($_SERVER['PHP_SELF']);

// Maintenance Mode Enforcement
$mMode = getSetting('maintenance_mode', '0');
if ($mMode === '1') {
    $allowedRoles = json_decode(getSetting('maintenance_allowed_roles', '["superadmin","admin"]'), true) ?: ['superadmin', 'admin'];
    $userRoleSlug = 'student';
    
    // Check role of current user or admin
    $adminId = $_SESSION['admin_id'] ?? null;
    if ($adminId) {
        $db = getDb();
        $rStmt = $db->prepare("SELECT r.slug FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = :id");
        $rStmt->execute(['id' => $adminId]);
        $userRoleSlug = $rStmt->fetchColumn() ?: 'student';
    }

    if (!in_array($userRoleSlug, $allowedRoles) && !in_array('*', $allowedRoles)) {
        $mMsg = getSetting('maintenance_message', 'Сиба-сэнсэй проводит техническое обслуживание! Скоро вернемся 🐾');
        ?>
        <!DOCTYPE html>
        <html lang="ru">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Техническое обслуживание — <?= e(APP_NAME) ?></title>
            <link rel="stylesheet" href="assets/css/style.css">
            <link rel="stylesheet" href="assets/css/animations.css">
        </head>
        <body style="display: flex; align-items: center; justify-content: center; min-height: 100vh; background: var(--bg-main); text-align: center; padding: 20px;">
            <div class="card-duo anim-bounce" style="max-width: 500px; padding: 40px;">
                <div style="font-size: 4rem; margin-bottom: 12px;">🐕 💤 🚧</div>
                <h1 style="font-size: 1.8rem; font-weight: 900; color: var(--primary); margin-bottom: 12px;">
                    Техническое обслуживание
                </h1>
                <p style="font-size: 1.1rem; color: var(--text-muted); margin-bottom: 24px;">
                    <?= nl2br(e($mMsg)) ?>
                </p>
                <a href="admin/login.php" class="btn-duo btn-outline" style="font-size: 0.9rem;">
                    Вход для администраторов →
                </a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? APP_NAME) ?> — Изучай языки и Vladikish с Шиба-Ину</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/animations.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#58cc02">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="ShibaLingo">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🐕</text></svg>">
    <script src="assets/js/markdown-renderer.js"></script>
    <script>
    function toggleAppSidebar(e) {
        if (e && e.stopPropagation) e.stopPropagation();
        var sidebar = document.getElementById('app-sidebar');
        var backdrop = document.getElementById('app-sidebar-backdrop');
        if (!sidebar) return;
        var isOpen = sidebar.classList.toggle('open');
        if (backdrop) backdrop.classList.toggle('active', isOpen);
        if (window.innerWidth <= 992) {
            document.body.style.overflow = isOpen ? 'hidden' : '';
        }
    }
    </script>
</head>
<body>

<div class="app-container">
    <!-- Sidebar Navigation -->
    <aside class="sidebar" id="app-sidebar">
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 0 4px 16px 4px;">
            <a href="index.php" class="logo-brand" style="padding: 0;">
                <span class="logo-icon">🐕</span>
                <span>ShibaLingo</span>
            </a>
            <button type="button" class="sidebar-close-btn" onclick="toggleAppSidebar(event)" aria-label="Закрыть" title="Закрыть меню">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>

        <ul class="nav-menu" style="overflow-y: auto; max-height: calc(100vh - 180px); padding-right: 4px;">
            <!-- Обучение -->
            <li class="nav-item <?= ($currentPage === 'index.php') ? 'active' : '' ?>">
                <a href="index.php"><span class="nav-icon">🏠</span><span>Обучение</span></a>
            </li>
            <li class="nav-item <?= ($currentPage === 'worldmap.php') ? 'active' : '' ?>">
                <a href="worldmap.php"><span class="nav-icon">🗺️</span><span>Карта мира</span></a>
            </li>
            <li class="nav-item <?= ($currentPage === 'review.php') ? 'active' : '' ?>">
                <a href="review.php"><span class="nav-icon">🧠</span><span>Повторение SM-2</span></a>
            </li>
            <li class="nav-item <?= ($currentPage === 'stories.php') ? 'active' : '' ?>">
                <a href="stories.php"><span class="nav-icon">📖</span><span>Истории</span></a>
            </li>
            <li class="nav-item <?= ($currentPage === 'podcast.php') ? 'active' : '' ?>">
                <a href="podcast.php"><span class="nav-icon">🎙️</span><span>AI Подкасты</span></a>
            </li>

            <!-- Игры & Активности -->
            <li style="margin: 14px 0 4px 12px; font-size: 0.72rem; font-weight: 900; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.6px;">
                🎮 Игры & Активности
            </li>
            <li class="nav-item <?= ($currentPage === 'tamagotchi.php') ? 'active' : '' ?>">
                <a href="tamagotchi.php"><span class="nav-icon">🐕</span><span>Тамагочи</span></a>
            </li>
            <li class="nav-item <?= ($currentPage === 'blitz.php') ? 'active' : '' ?>">
                <a href="blitz.php"><span class="nav-icon">⚡</span><span>Блиц-игра</span></a>
            </li>
            <li class="nav-item <?= ($currentPage === 'duel.php') ? 'active' : '' ?>">
                <a href="duel.php"><span class="nav-icon">⚔️</span><span>PvP Дуэли</span></a>
            </li>
            <li class="nav-item <?= ($currentPage === 'raid.php') ? 'active' : '' ?>">
                <a href="raid.php"><span class="nav-icon">🐉</span><span>Босс-рейды</span></a>
            </li>
            <li class="nav-item <?= ($currentPage === 'crossword.php') ? 'active' : '' ?>">
                <a href="crossword.php"><span class="nav-icon">🧩</span><span>Кроссворды</span></a>
            </li>
            <li class="nav-item <?= ($currentPage === 'roleplay.php') ? 'active' : '' ?>">
                <a href="roleplay.php"><span class="nav-icon">🎭</span><span>AI Роли</span></a>
            </li>

            <!-- Инструменты -->
            <li style="margin: 14px 0 4px 12px; font-size: 0.72rem; font-weight: 900; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.6px;">
                🛠️ Инструменты
            </li>
            <li class="nav-item <?= ($currentPage === 'translator.php') ? 'active' : '' ?>">
                <a href="translator.php"><span class="nav-icon">🌐</span><span>Переводчик</span></a>
            </li>
            <li class="nav-item <?= ($currentPage === 'camera_scanner.php') ? 'active' : '' ?>">
                <a href="camera_scanner.php"><span class="nav-icon">📷</span><span>AR Сканер</span></a>
            </li>
            <li class="nav-item <?= ($currentPage === 'chat.php') ? 'active' : '' ?>">
                <a href="chat.php"><span class="nav-icon">💬</span><span>Чат с Шибой</span></a>
            </li>
            <li class="nav-item <?= ($currentPage === 'mnemonics.php') ? 'active' : '' ?>">
                <a href="mnemonics.php"><span class="nav-icon">💡</span><span>Мнемоники</span></a>
            </li>
            <li class="nav-item <?= ($currentPage === 'conlang.php' || $currentPage === 'conjugator.php' || $currentPage === 'lore.php') ? 'active' : '' ?>">
                <a href="conlang.php"><span class="nav-icon">📜</span><span>Студия Vladikish</span></a>
            </li>

            <!-- Сообщество & Награды -->
            <li style="margin: 14px 0 4px 12px; font-size: 0.72rem; font-weight: 900; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.6px;">
                🏆 Награды & Сообщество
            </li>
            <li class="nav-item <?= ($currentPage === 'shop.php') ? 'active' : '' ?>">
                <a href="shop.php"><span class="nav-icon">🛍️</span><span>Магазин</span></a>
            </li>
            <li class="nav-item <?= ($currentPage === 'leaderboard.php') ? 'active' : '' ?>">
                <a href="leaderboard.php"><span class="nav-icon">🏆</span><span>Лиги</span></a>
            </li>
            <li class="nav-item <?= ($currentPage === 'friends.php') ? 'active' : '' ?>">
                <a href="friends.php"><span class="nav-icon">👥</span><span>Друзья</span></a>
            </li>
            <li class="nav-item <?= ($currentPage === 'quests.php') ? 'active' : '' ?>">
                <a href="quests.php"><span class="nav-icon">🎯</span><span>Квесты</span></a>
            </li>
            <li class="nav-item <?= ($currentPage === 'achievements.php') ? 'active' : '' ?>">
                <a href="achievements.php"><span class="nav-icon">🏅</span><span>Награды</span></a>
            </li>
            <li class="nav-item <?= ($currentPage === 'referral.php') ? 'active' : '' ?>">
                <a href="referral.php"><span class="nav-icon">🎁</span><span>Пригласи друга</span></a>
            </li>
            <li class="nav-item <?= ($currentPage === 'redeem.php') ? 'active' : '' ?>">
                <a href="redeem.php"><span class="nav-icon">🎟️</span><span>Промокод</span></a>
            </li>
            <li class="nav-item <?= ($currentPage === 'faq.php') ? 'active' : '' ?>">
                <a href="faq.php"><span class="nav-icon">❓</span><span>База знаний</span></a>
            </li>
            <li class="nav-item <?= ($currentPage === 'teacher.php') ? 'active' : '' ?>">
                <a href="teacher.php"><span class="nav-icon">🧑‍🏫</span><span>Для учителей</span></a>
            </li>
        </ul>

        <div style="margin-top: auto; padding: 12px; border-top: 2px solid var(--border-color); display: flex; flex-direction: column; gap: 8px;">
            <?php if ($user): ?>
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <a href="profile.php" style="display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit;">
                        <div style="font-size: 1.8rem;">🐕</div>
                        <div>
                            <div style="font-weight: 800; font-size: 0.95rem;"><?= e($user['username']) ?></div>
                            <div style="font-size: 0.75rem; color: var(--primary); font-weight: 700;">Профиль ↗</div>
                        </div>
                    </a>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <a href="admin/login.php" style="color: var(--text-muted); font-size: 1.1rem; text-decoration: none;" title="Панель администратора">
                            🛡️
                        </a>
                        <a href="logout.php" style="color: var(--danger); font-size: 1.1rem; text-decoration: none;" title="Выйти из аккаунта">
                            🚪
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <a href="login.php" class="btn-duo btn-outline" style="padding: 8px; font-size: 0.85rem; text-align: center;">
                    Войти 🔑
                </a>
                <a href="register.php" class="btn-duo btn-primary" style="padding: 8px; font-size: 0.85rem; text-align: center;">
                    Регистрация 🚀
                </a>
            <?php endif; ?>
        </div>
    </aside>

    <!-- Sidebar Mobile Backdrop -->
    <div class="sidebar-backdrop" id="app-sidebar-backdrop" onclick="toggleAppSidebar()"></div>

    <!-- Main Wrapper -->
    <div class="main-wrapper">
        <!-- Sticky Top Bar -->
        <header class="top-bar">
            <div class="top-bar-left" style="display: flex; align-items: center; gap: 10px;">
                <button type="button" class="mobile-menu-toggle" id="mobile-sidebar-toggle" onclick="toggleAppSidebar(event)" aria-label="Меню" title="Меню">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
                </button>

                <!-- Language Selector -->
                <div class="language-picker">
                    <button class="lang-btn" id="lang-select-btn">
                        <span><?= $currentLangObj['flag'] ?></span>
                        <span><?= e($currentLangObj['name']) ?></span>
                        <span style="font-size: 0.8rem; color: var(--text-muted);">▼</span>
                    </button>

                    <div class="lang-dropdown" id="lang-dropdown-menu">
                        <?php foreach ($languages as $lang): ?>
                            <a href="?switch_lang=<?= e($lang['code']) ?>" class="lang-option <?= ($lang['code'] === $currentLangCode) ? 'active' : '' ?>">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <span style="font-size: 1.3rem;"><?= $lang['flag'] ?></span>
                                    <span><?= e($lang['name']) ?></span>
                                </div>
                                <?php if ($lang['is_conlang']): ?>
                                    <span class="badge-tag" style="background: var(--primary-light); color: var(--primary-shadow);">Conlang</span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Stats: Streak, Hearts, Gems, XP, Theme -->
            <div class="user-stats">
                <?php if ($user): ?>
                    <a href="shop.php" style="text-decoration: none;" class="stat-badge" title="Кристаллы для покупок в магазине">
                        <span>💎</span>
                        <span style="color: #0284c7;"><?= (int)($user['gems'] ?? 50) ?></span>
                    </a>

                    <div class="stat-badge streak" title="Ваш непрерывный стрик дней">
                        <span>🔥</span>
                        <span><?= (int)$user['streak'] ?></span>
                    </div>

                    <div class="stat-badge hearts" id="topbar-hearts-badge" onclick="openHeartsModal()" style="cursor: pointer;" title="Нажмите, чтобы восстановить сердца">
                        <span>❤️</span>
                        <span id="topbar-hearts-count"><?= (int)$user['hearts'] ?></span>
                    </div>

                    <div class="stat-badge xp" title="Накопленные очки опыта">
                        <span>⚡</span>
                        <span><?= (int)$user['xp'] ?> XP</span>
                    </div>
                <?php else: ?>
                    <a href="login.php" class="btn-duo btn-outline" style="padding: 6px 14px; font-size: 0.85rem;">
                        Войти 🔑
                    </a>
                    <a href="register.php" class="btn-duo btn-primary" style="padding: 6px 14px; font-size: 0.85rem;">
                        Регистрация 🚀
                    </a>
                <?php endif; ?>

                <button class="theme-toggle" onclick="toggleTheme()" title="Переключить темную/светлую тему">
                    <span id="theme-icon">🌙</span>
                </button>
            </div>
        </header>

        <!-- Duolingo Hearts Modal -->
        <?php if ($user): ?>
        <div id="modal-hearts-refill" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
            <div class="card-duo anim-bounce" style="max-width: 440px; width: 100%; text-align: center; margin-bottom: 0; padding: 32px 24px;">
                <div style="display: flex; justify-content: flex-end;">
                    <button onclick="closeHeartsModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer; color: var(--text-muted);">✕</button>
                </div>

                <div style="font-size: 3rem; margin-bottom: 12px;">❤️</div>
                <h3 style="font-size: 1.5rem; font-weight: 900; margin-bottom: 8px;">Сердечки и Жизни</h3>
                
                <div style="display: flex; justify-content: center; gap: 8px; font-size: 1.8rem; margin-bottom: 16px;">
                    <?php 
                    $hCount = (int)$user['hearts'];
                    for ($i = 1; $i <= 5; $i++): 
                    ?>
                        <span><?= ($i <= $hCount) ? '❤️' : '🤍' ?></span>
                    <?php endfor; ?>
                </div>

                <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 24px;">
                    Сердца нужны для прохождения уроков. За каждую ошибку тратится 1 сердце.
                </p>

                <div style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 20px;">
                    <a href="practice.php" class="btn-duo btn-secondary" style="padding: 12px; font-size: 1rem; display: flex; align-items: center; justify-content: center; gap: 8px;">
                        <span>💪</span>
                        <span>Потренироваться (+1 ❤️ бесплатно)</span>
                    </a>

                    <button class="btn-duo btn-primary" onclick="refillHeartsWithGems()" style="padding: 12px; font-size: 1rem; display: flex; align-items: center; justify-content: center; gap: 8px;">
                        <span>💎</span>
                        <span>Восстановить все жизни (350 💎)</span>
                    </button>
                </div>

                <div style="background: linear-gradient(135deg, rgba(168,85,247,0.1), rgba(236,72,153,0.1)); border: 2px dashed #a855f7; border-radius: 16px; padding: 14px;">
                    <div style="font-weight: 800; color: #a855f7; margin-bottom: 4px;">✨ Super Shiba</div>
                    <div style="font-size: 0.85rem; color: var(--text-muted);">Бесконечные жизни ∞ ❤️ и персонализированные тренировки в Магазине!</div>
                </div>
            </div>
        </div>

        <script>
        function openHeartsModal() {
            document.getElementById('modal-hearts-refill').style.display = 'flex';
        }
        function closeHeartsModal() {
            document.getElementById('modal-hearts-refill').style.display = 'none';
        }
        async function refillHeartsWithGems() {
            try {
                const res = await fetch('api/hearts.php?action=refill_gems', { method: 'POST' });
                const data = await res.json();
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert(data.error);
                }
            } catch(e) {
                alert('Ошибка сети');
            }
        }
        </script>
        <?php endif; ?>

        <!-- Main Body Content -->
        <main class="content-area">
